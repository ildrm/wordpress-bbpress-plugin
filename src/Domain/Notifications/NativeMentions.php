<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Notifications;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Community\Following;
use ForumIntelligence\Domain\Failure;
/** Constrain native BuddyPress delivery without replacing its parser, inbox or email preferences. */
final class NativeMentions {
	/** @var \WeakMap<object,bool>|null */
	private static ?\WeakMap $processed = null;
	private static array $quotas        = array();
	public function __construct( private Store $store, private Access $access, private Following $following, private Notifications $notifications ) {}
	public function register(): void {
		add_action( 'bp_activity_before_save', array( self::class, 'begin' ), 5 );
		add_action( 'bp_activity_before_save', array( $this, 'prepare' ), 20 );
		add_action( 'bp_activity_after_save', array( $this, 'recheck' ), 9 );
	}
	private static function scoped( object $activity ): bool {
		return in_array( $activity->component ?? '', array( 'bbpress', 'groups' ), true ) && in_array( $activity->type ?? '', array( 'bbp_topic_create', 'bbp_reply_create' ), true );
	}
	public static function begin( object $activity ): void {
		if ( ! self::scoped( $activity ) || ! function_exists( 'buddypress' ) || ! function_exists( 'bp_activity_do_mentions' ) || ! bp_activity_do_mentions() ) {
			return;
		}
		self::$processed            ??= new \WeakMap();
		self::$processed[ $activity ] = false;
		// A failed earlier save may have left native recipients in the shared temporary slot.
		unset( buddypress()->activity->mentioned_users );
	}
	private function source( object $activity ): int {
		$id   = (int) ( $activity->component === 'groups' ? ( $activity->secondary_item_id ?? 0 ) : ( $activity->item_id ?? 0 ) );
		$type = $activity->type === 'bbp_topic_create' ? bbp_get_topic_post_type() : bbp_get_reply_post_type();
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== $type || ! empty( $activity->is_spam ) || (int) $post->post_author !== (int) ( $activity->user_id ?? 0 ) || ! $this->access->activeMember( (int) $post->post_author ) || ! user_can( (int) $post->post_author, 'publish_replies' ) || ! $this->access->canRead( $id, (int) $post->post_author ) ) {
			return 0;
		}
		if ( $activity->component === 'groups' && ( ! function_exists( 'bbp_get_forum_group_ids' ) || ! in_array( (int) ( $activity->item_id ?? 0 ), array_map( 'intval', bbp_get_forum_group_ids( $this->access->forum( $id ) ) ), true ) ) ) {
			return 0;
		}
		return $id;
	}
	public function prepare( object $activity ): void {
		if ( ! self::scoped( $activity ) || ! function_exists( 'buddypress' ) || ! function_exists( 'bp_activity_do_mentions' ) || ! bp_activity_do_mentions() || ( self::$processed[ $activity ] ?? false ) ) {
			return;
		}
		self::$processed            ??= new \WeakMap();
		self::$processed[ $activity ] = true;
		$source                       = $this->source( $activity );
		if ( ! $source ) {
			unset( buddypress()->activity->mentioned_users );
			return;
		}
		$recipients = (array) ( buddypress()->activity->mentioned_users ?? array() );
		unset( buddypress()->activity->mentioned_users );
		if ( ! $recipients ) {
			return;
		}
		$key = get_current_blog_id() . ':' . $source . ':' . hash( 'sha256', (string) $activity->content );
		try {
			if ( ! array_key_exists( $key, self::$quotas ) ) {
				if ( count( self::$quotas ) >= 1000 ) {
					self::$quotas = array();
				}
				self::$quotas[ $key ] = false;
				$this->store->limit( (int) $activity->user_id, 'mentions_publish', 20, 300 );
				self::$quotas[ $key ] = true;
			}
			if ( self::$quotas[ $key ] ) {
				buddypress()->activity->mentioned_users = $this->eligible( $source, $recipients );
			}
		} catch ( Failure $failure ) {
			do_action( 'fi_mention_delivery_failed', $source, $failure->reason );
		}
	}
	private function eligible( int $source, array $recipients ): array {
		$allowed = array();
		foreach ( array_slice( $recipients, 0, 20, true ) as $id => $name ) {
			$id = (int) $id;
			if ( $id === (int) get_post_field( 'post_author', $source ) || ! $this->access->activeMember( $id ) || ! $this->access->canRead( $source, $id ) || $this->following->muted( $id, $source ) || in_array( 'mention', $this->notifications->preferences( $id )['muted_categories'], true ) ) {
				continue;
			}
			$allowed[ $id ] = $name;
			if ( count( $allowed ) === 5 ) {
				break;
			}
		}
		return $allowed;
	}
	public function recheck( object $activity ): void {
		if ( ! self::scoped( $activity ) || ! function_exists( 'buddypress' ) || ! function_exists( 'bp_activity_do_mentions' ) || ! bp_activity_do_mentions() ) {
			return;
		}
		$recipients = (array) ( buddypress()->activity->mentioned_users ?? array() );
		unset( buddypress()->activity->mentioned_users );
		$source = $this->source( $activity );
		try {
			if ( $source && $recipients ) {
				buddypress()->activity->mentioned_users = $this->eligible( $source, $recipients );
			}
		} catch ( Failure $failure ) {
			do_action( 'fi_mention_delivery_failed', $source, $failure->reason );
		}
	}
}
