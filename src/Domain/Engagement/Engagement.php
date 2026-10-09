<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Engagement;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Solutions\Solutions;
use ForumIntelligence\Domain\Failure;
final class Engagement {
	private array $cache   = array();
	public const REACTIONS = array( 'like', 'helpful', 'insightful', 'thanks' );
	public function __construct( private Store $store, private Access $access, private Solutions $solutions ) {}
	public static function normalizeReactions( mixed $selection ): array {
		if ( ! is_array( $selection ) || count( $selection ) > 16 ) {
			return array();
		}
		return array_values( array_filter( self::REACTIONS, static fn( string $id ): bool=>in_array( $id, $selection, true ) ) );
	}
	public static function enabledReactions(): array {
		$settings = get_option( 'fi_settings', array() );
		if ( ! is_array( $settings ) ) {
			return array();
		}
		return array_key_exists( 'reactions', $settings ) ? self::normalizeReactions( $settings['reactions'] ) : self::REACTIONS;
	}
	public static function reactionLabels(): array {
		return array(
			'like'       => __( 'Like', 'forum-intelligence-for-bbpress' ),
			'helpful'    => __( 'Helpful reaction', 'forum-intelligence-for-bbpress' ),
			'insightful' => __( 'Insightful', 'forum-intelligence-for-bbpress' ),
			'thanks'     => __( 'Thanks', 'forum-intelligence-for-bbpress' ),
		);
	}
	public static function removalLabels(): array {
		return array(
			'like'       => __( 'Remove Like reaction', 'forum-intelligence-for-bbpress' ),
			'helpful'    => __( 'Remove Helpful reaction', 'forum-intelligence-for-bbpress' ),
			'insightful' => __( 'Remove Insightful reaction', 'forum-intelligence-for-bbpress' ),
			'thanks'     => __( 'Remove Thanks reaction', 'forum-intelligence-for-bbpress' ),
		);
	}
	private function reactionPolicy( int $objectId, int $user, string $kind, int $value ): void {
		if ( in_array( $kind, array( 'vote', 'confirm' ), true ) ) {
			return;
		}
		$id = substr( $kind, 9 );
		if ( ! str_starts_with( $kind, 'reaction_' ) || ! in_array( $id, self::REACTIONS, true ) ) {
			throw new Failure( 'invalid_signal', __( 'Invalid reaction.', 'forum-intelligence-for-bbpress' ) );
		}
		if ( in_array( $id, self::enabledReactions(), true ) ) {
			return;
		}
		// A disabled reaction remains withdrawable only by its existing owner.
		if ( $value === 0 && $this->store->row( "SELECT value FROM {$this->store->table('signals')} WHERE object_id=%d AND user_id=%d AND kind=%s", array( $objectId, $user, $kind ) ) ) {
			return;
		}
		throw new Failure( 'invalid_signal', __( 'Invalid reaction.', 'forum-intelligence-for-bbpress' ) );
	}
	private function sourceState( int $objectId, int $user ): array {
		$post       = get_post( $objectId );
		$topic      = $this->access->topic( $objectId );
		$forum      = $this->access->forum( $objectId );
		$ancestors  = $forum ? get_post_ancestors( $forum ) : array();
		$actor      = get_userdata( $user );
		$actorState = $actor ? array( $actor->roles, $actor->caps, $actor->allcaps, (int) $actor->user_status, (int) $actor->spam, (int) $actor->deleted ) : array();
		$scope      = array();
		foreach ( array_unique( array_filter( array( $objectId, $topic, $forum, ...$ancestors ) ) ) as $id ) {
			$native       = get_post( $id );
			$scope[ $id ] = $native ? array( $native->post_type, (int) $native->post_author, (int) $native->post_parent, $native->post_status, $native->post_password ) : null;
		}
		return array(
			'site'      => get_current_blog_id(),
			'author'    => (int) ( $post->post_author ?? 0 ),
			'type'      => $post->post_type ?? '',
			'parent'    => (int) ( $post->post_parent ?? 0 ),
			'status'    => $post->post_status ?? '',
			'password'  => $post->post_password ?? '',
			'topic'     => $topic,
			'forum'     => $forum,
			'ancestors' => $ancestors,
			'scope'     => $scope,
			'actor'     => $actorState,
		);
	}
	private function signalPolicy( int $objectId, int $user, string $kind, int $value, bool $lock = false ): void {
		$this->reactionPolicy( $objectId, $user, $kind, $value );
		$settings = (array) get_option( 'fi_settings', array() );
		if ( isset( $settings['modules']['engagement'] ) && ! $settings['modules']['engagement'] ) {
			throw new Failure( 'module_disabled', __( 'Votes and reactions are disabled.', 'forum-intelligence-for-bbpress' ), 503 );
		}
		if ( ! in_array( $value, array( -1, 0, 1 ), true ) || ( $kind !== 'vote' && $value < 0 ) || ( $value < 0 && empty( $settings['downvotes'] ) ) ) {
			throw new Failure( 'invalid_value', __( 'This vote is not enabled.', 'forum-intelligence-for-bbpress' ) );
		}
		if ( $value !== 0 && $user === (int) get_post_field( 'post_author', $objectId, 'raw' ) && empty( $settings['self_voting'] ) ) {
			throw new Failure( 'self_vote', __( 'You cannot vote on your own content.', 'forum-intelligence-for-bbpress' ) );
		}
		if ( $kind === 'confirm' && $value === 1 ) {
			$topic   = $this->access->topic( $objectId );
			$suffix  = $lock ? ' FOR UPDATE' : '';
			$row     = $this->store->row( "SELECT answers,solved FROM {$this->store->table('solutions')} WHERE topic_id=%d$suffix", array( $topic ) );
			$answers = $row ? json_decode( $row['answers'], true ) : null;
			if ( get_post_type( $objectId ) !== bbp_get_reply_post_type() || (int) ( $row['solved'] ?? 0 ) !== 1 || ! is_array( $answers ) || ! array_is_list( $answers ) || count( $answers ) > 10 || ! in_array( $objectId, $answers, true ) || array_filter( $answers, static fn( mixed $id ): bool=> ! is_int( $id ) || $id < 1 ) ) {
				throw new Failure( 'not_solution', __( 'Only accepted solutions can be confirmed.', 'forum-intelligence-for-bbpress' ) );
			}
		}
	}
	public function cast( int $objectId, int $user, string $kind, int $value ): array {
		$this->access->participate( $objectId, $user );
		if ( ! $this->access->topic( $objectId ) ) {
			throw new Failure( 'invalid_object', __( 'Vote on a topic or reply.', 'forum-intelligence-for-bbpress' ) ); }
		$source = $this->sourceState( $objectId, $user );
		$author = $source['author'];
		$this->signalPolicy( $objectId, $user, $kind, $value );
		$this->store->limit( $user, 'engagement', 30 );
		try {
			$this->store->transaction(
				function () use ( $objectId, $user, $kind, $value, $author, $source ): void {
					$this->access->participate( $objectId, $user );
					$this->access->read( $objectId, $user );
					if ( $this->sourceState( $objectId, $user ) !== $source ) {
						throw new Failure( 'source_changed', __( 'This discussion changed. Refresh and retry.', 'forum-intelligence-for-bbpress' ), 409 );
					}
					$this->signalPolicy( $objectId, $user, $kind, $value, true );
					$table = $this->store->table( 'signals' );
					$this->store->execute( "INSERT IGNORE INTO $table (object_id,user_id,kind,value,created_at,updated_at) VALUES (%d,%d,%s,0,%s,%s)", array( $objectId, $user, $kind, $this->store->now(), $this->store->now() ) );
					$old = $this->store->row( "SELECT value FROM $table WHERE object_id=%d AND user_id=%d AND kind=%s FOR UPDATE", array( $objectId, $user, $kind ) );
					$this->access->participate( $objectId, $user );
					$this->access->read( $objectId, $user );
					$this->signalPolicy( $objectId, $user, $kind, $value, true );
					if ( ! $this->access->activeMember( $user ) || ! user_can( $user, 'publish_replies' ) ) {
						throw new Failure( 'forbidden', __( 'You cannot participate in this discussion.', 'forum-intelligence-for-bbpress' ), 403 );
					}
					if ( $this->sourceState( $objectId, $user ) !== $source ) {
						throw new Failure( 'source_changed', __( 'This discussion changed. Refresh and retry.', 'forum-intelligence-for-bbpress' ), 409 );
					}
					if ( (int) $old['value'] === $value ) {
						return; }
					$this->store->execute( "UPDATE $table SET value=%d,updated_at=%s WHERE object_id=%d AND user_id=%d AND kind=%s", array( $value, $this->store->now(), $objectId, $user, $kind ) );
					$settings = (array) get_option( 'fi_settings', array() );
					$weight   = $kind === 'vote' ? (int) ( $settings[ $value < 0 ? 'downvote_weight' : 'vote_weight' ] ?? 2 ) : ( $kind === 'confirm' ? (int) ( $settings['confirmation_weight'] ?? 3 ) : 0 );
					$this->store->event(
						'signal_changed',
						$objectId,
						$user,
						array(
							'kind'   => $kind,
							'before' => (int) $old['value'],
							'after'  => $value,
						)
					);
					if ( in_array( $kind, array( 'vote', 'confirm' ), true ) ) {
						( new \ForumIntelligence\Domain\Reputation\Credits( $this->store ) )->set( 'signal_reputation', $objectId, $user, $author, $value * $weight, $kind . ':' . $objectId . ':' . $user, array( 'signal' => $kind ) ); }
				}
			);
		} catch ( \Throwable $failure ) {
			$current = $this->sourceState( $objectId, $user );
			foreach ( array_unique( array_filter( array( $objectId, $source['topic'], $source['forum'], $current['topic'], $current['forum'], ...$source['ancestors'], ...$current['ancestors'] ) ) ) as $id ) {
				clean_post_cache( (int) $id );
			}
			$this->cache = array();
			$this->solutions->forget( (int) $source['topic'] );
			$this->solutions->forget( (int) $current['topic'] );
			clean_user_cache( $user );
			wp_cache_delete( $user, 'user_meta' );
			if ( get_current_user_id() === $user ) {
				wp_set_current_user( 0 );
				wp_set_current_user( $user );
			}
			foreach ( array( 'fi_settings', 'alloptions', 'notoptions' ) as $key ) {
				wp_cache_delete( $key, 'options' );
			}
			throw $failure;
		}
		$this->cache = array();
		do_action( 'fi_signal_changed', $objectId, $user, $kind, $value );
		return $this->counts( $objectId, $user );
	}
	public function counts( int $objectId, int $user ): array {
		$this->access->read( $objectId, $user );
		$key = get_current_blog_id() . ':' . $user . ':' . $objectId;
		if ( ! isset( $this->cache[ $key ] ) ) {
			$this->prime( array( $objectId ), $user );}
		return $this->cache[ $key ];
	}
	public function prime( array $objects, int $user ): void {
		$objects = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $objects ) ) ) ), 0, 500 );
		if ( ! $objects ) {
			return;}
		$prefix = get_current_blog_id() . ':' . $user . ':';
		foreach ( $objects as $id ) {
			$this->cache[ $prefix . $id ] = array(
				'counts' => array(),
				'mine'   => array(),
			);}
		$marks = implode( ',', array_fill( 0, count( $objects ), '%d' ) );
		foreach ( $this->store->rows( "SELECT object_id,kind,SUM(value) AS score,SUM(value<>0) AS participants FROM {$this->store->table('signals')} WHERE object_id IN ($marks) GROUP BY object_id,kind", $objects ) as $row ) {
			$id = (int) $row['object_id'];
			unset( $row['object_id'] );
			$this->cache[ $prefix . $id ]['counts'][] = $row;
		}
		if ( $user ) {
			foreach ( $this->store->rows( "SELECT object_id,kind,value FROM {$this->store->table('signals')} WHERE object_id IN ($marks) AND user_id=%d", array( ...$objects, $user ) ) as $row ) {
				$id = (int) $row['object_id'];
				unset( $row['object_id'] );
				$this->cache[ $prefix . $id ]['mine'][] = $row;}
		}
	}
}
