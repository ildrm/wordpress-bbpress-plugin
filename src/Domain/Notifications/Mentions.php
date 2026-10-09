<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Notifications;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Community\Following;
use ForumIntelligence\Domain\Failure;
/** Bounded native fallback; an enabled supported integration retains mention ownership. */
final class Mentions {
	private static array $processed = array();
	public function __construct( private Store $store, private Access $access, private Following $following, private Notifications $notifications ) {}
	public static function managed(): bool {
		$native = function_exists( 'bp_activity_do_mentions' ) && function_exists( 'bp_is_active' ) && bp_is_active( 'activity' ) && bp_activity_do_mentions();
		return (bool) apply_filters( 'fi_mentions_managed', $native );
	}
	public function register(): void {
		( new NativeMentions( $this->store, $this->access, $this->following, $this->notifications ) )->register();
		foreach ( array( 'bbp_new_topic', 'bbp_new_reply', 'bbp_edit_topic', 'bbp_edit_reply' ) as $hook ) {
			add_action( $hook, array( $this, 'notify' ), 20 );
		}
		add_filter(
			'bp_activity_maybe_load_mentions_scripts',
			static function ( bool $load, bool $enabled ): bool {
				return $load || ( $enabled && self::managed() && function_exists( 'is_bbpress' ) && is_bbpress() && current_user_can( 'publish_replies' ) );
			},
			20,
			2
		);
	}
	public function lookup( string $prefix, int $objectId, int $user ): array {
		if ( ! $this->access->activeMember( $user ) || ! user_can( $user, 'publish_replies' ) ) {
			throw new Failure( 'forbidden', __( 'Participation permission is required to find mention recipients.', 'forum-intelligence-for-bbpress' ), 403 );
		}
		if ( ! in_array( get_post_type( $objectId ), array( bbp_get_forum_post_type(), bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) || ! $this->access->canRead( $objectId, $user ) ) {
			throw new Failure( 'not_found', __( 'Discussion unavailable.', 'forum-intelligence-for-bbpress' ), 404 );
		}
		if ( self::managed() ) {
			throw new Failure( 'mentions_managed', __( 'Use the mention tools provided by the active community integration.', 'forum-intelligence-for-bbpress' ), 409 );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_.-]{2,30}$/D', $prefix ) ) {
			throw new Failure( 'invalid_prefix', __( 'Type 2–30 username characters to find a member.', 'forum-intelligence-for-bbpress' ), 400 );
		}
		$this->store->limit( $user, 'mentions_lookup', 20 );
		$users = get_users(
			array(
				'blog_id'        => get_current_blog_id(),
				'search'         => $prefix . '*',
				'search_columns' => array( 'user_login' ),
				'number'         => 20,
				'orderby'        => 'login',
				'order'          => 'ASC',
			)
		);
		$items = array();
		foreach ( $users as $candidate ) {
			$id = (int) $candidate->ID;
			if ( $id === $user || ! preg_match( '/^[A-Za-z0-9_.-]{1,60}$/D', $candidate->user_login ) || ! $this->access->activeMember( $id ) || ! $this->access->canRead( $objectId, $id ) || $this->following->muted( $id, $objectId ) || in_array( 'mention', $this->notifications->preferences( $id )['muted_categories'], true ) ) {
				continue;
			}
			$items[] = array(
				'id'           => $id,
				'name'         => $candidate->user_login,
				'display_name' => mb_substr( wp_strip_all_tags( $candidate->display_name ), 0, 200 ),
			);
			if ( count( $items ) === 10 ) {
				break;
			}
		}
		return array( 'items' => $items );
	}
	public function notify( int $objectId ): void {
		if ( self::managed() || ! in_array( get_post_type( $objectId ), array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) ) {
			return;
		}
		$post = get_post( $objectId );
		if ( ! $post || ! $this->access->activeMember( (int) $post->post_author ) || ! user_can( (int) $post->post_author, 'publish_replies' ) || ! $this->access->canRead( $objectId, (int) $post->post_author ) ) {
			return;
		}
		$names = self::names( $post->post_content );
		if ( ! $names ) {
			return;
		}
		$key = get_current_blog_id() . ':' . $objectId . ':' . hash( 'sha256', $post->post_content );
		if ( isset( self::$processed[ $key ] ) ) {
			return;
		}
		if ( count( self::$processed ) >= 1000 ) {
			self::$processed = array();
		}
		self::$processed[ $key ] = true;
		try {
			$this->store->limit( (int) $post->post_author, 'mentions_publish', 20, 300 );
			foreach ( $names as $name ) {
				$user = get_user_by( 'login', $name );
				if ( ! $user && str_ends_with( $name, '.' ) ) {
					$user = get_user_by( 'login', rtrim( $name, '.' ) );
				}
				if ( $user ) {
					$this->notifications->deliver( (int) $user->ID, $objectId, (int) $post->post_author, 'mention', 'mention:' . $objectId . ':' . $user->ID );
				}
			}
		} catch ( Failure $failure ) {
			// Optional mention alerts must not interrupt a successful native post/edit.
			do_action( 'fi_mention_delivery_failed', $objectId, $failure->reason );
		}
	}
	public static function names( string $content ): array {
		if ( ! str_contains( $content, '@' ) ) {
			return array();
		}
		$content = mb_substr( $content, 0, 60000 );
		$content = preg_replace( '/\x60{3}[\s\S]*?(?:\x60{3}|$)|\x60[^\x60\r\n]*(?:\x60|(?=\r?\n)|$)/', ' ', $content ) ?? '';
		$text    = '';
		$depth   = 0;
		foreach ( wp_html_split( $content ) as $part ) {
			if ( str_starts_with( $part, '<' ) ) {
				if ( preg_match( '/^<\s*(pre|code|script|style)\b/i', $part ) ) {
					++$depth;
				} elseif ( preg_match( '/^<\s*\/\s*(pre|code|script|style)\s*>/i', $part ) ) {
					$depth = max( 0, $depth - 1 );
				}
				$text .= ' ';
			} elseif ( $depth === 0 ) {
				$text .= $part;
			}
		}
		preg_match_all( '/(?<![A-Za-z0-9_@])@([A-Za-z0-9_.-]{1,60})(?![A-Za-z0-9_.-])/', $text, $matches );
		return array_slice( array_values( array_unique( $matches[1] ) ), 0, 5 );
	}
}
