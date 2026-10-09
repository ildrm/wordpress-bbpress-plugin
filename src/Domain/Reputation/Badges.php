<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Reputation;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Queue\Queue;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
/** Optional, reversible achievements based on useful public evidence or human review. */
final class Badges {
	public function __construct( private Store $store, private Access $access ) {}
	public static function preferenceKey(): string {
		return '_fi_badges_optout_' . get_current_blog_id();}
	public function preference( int $member, bool $enabled ): array {
		if ( ! $this->access->activeMember( $member ) ) {
			throw new Failure( 'forbidden', __( 'Sign in to manage achievements.', 'forum-intelligence-for-bbpress' ), 403 );}
		$this->store->transaction(
			function () use ( $member, $enabled ): void {
				$this->store->row( "SELECT ID FROM {$this->store->wordpressTable('users')} WHERE ID=%d FOR UPDATE", array( $member ) );
				update_user_meta( $member, self::preferenceKey(), ! $enabled );
				if ( ! $enabled ) {
					$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE kind='badge' AND owner_id=%d", array( $member ) );}
			}
		);
		if ( $enabled ) {
			$this->reconcile( $member );}
		return array( 'enabled' => $enabled );
	}
	public static function catalog(): array {
		return array(
			'accepted_answer'     => array(
				'label'     => __( 'Accepted answer contributor', 'forum-intelligence-for-bbpress' ),
				'rule'      => __( 'At least one currently accepted public reply.', 'forum-intelligence-for-bbpress' ),
				'automatic' => true,
			),
			'helpful_contributor' => array(
				'label'     => __( 'Repeated helpful contributor', 'forum-intelligence-for-bbpress' ),
				'rule'      => __( 'Three distinct public contributions, each with a net helpful vote count of at least three.', 'forum-intelligence-for-bbpress' ),
				'automatic' => true,
			),
			'verified_expert'     => array(
				'label'     => __( 'Verified expert', 'forum-intelligence-for-bbpress' ),
				'rule'      => __( 'A moderator reviewed expertise and recorded an explanation. This badge grants no permissions.', 'forum-intelligence-for-bbpress' ),
				'automatic' => false,
			),
			'community_mentor'    => array(
				'label'     => __( 'Community mentor', 'forum-intelligence-for-bbpress' ),
				'rule'      => __( 'A moderator reviewed mentoring contributions and recorded an explanation. This badge grants no permissions.', 'forum-intelligence-for-bbpress' ),
				'automatic' => false,
			),
		);
	}
	public function register( Queue $queue ): void {
		$queue->handle( 'badge_member', fn( array $data )=>$this->reconcile( (int) $data['member'] ) );
		$enqueue = function ( int $member ) use ( $queue ): void {
			if ( $member > 0 ) {
				$queue->enqueue( 'badge_member', array( 'member' => $member ), (string) $member );}
		};
		add_action( 'fi_reputation_source_reconciled', $enqueue );
		add_action( 'fi_signal_changed', fn( int $objectId )=>$enqueue( (int) get_post_field( 'post_author', $objectId ) ) );
		add_action(
			'fi_solution_changed',
			function ( int $topic ) use ( $enqueue ): void {
				// Include former award owners so removals revoke badges as well as adding new awards.
				$rows = $this->store->rows( "SELECT DISTINCT owner_id FROM {$this->store->table('records')} WHERE kind='badge' AND object_id IN (SELECT ID FROM {$this->store->wordpressTable('posts')} WHERE post_parent=%d)", array( $topic ) );
				foreach ( $rows as $row ) {
					$enqueue( (int) $row['owner_id'] );}
				$solution = $this->store->row( "SELECT answers FROM {$this->store->table('solutions')} WHERE topic_id=%d", array( $topic ) );
				foreach ( json_decode( $solution['answers'] ?? '[]', true ) ?: array() as $reply ) {
					$enqueue( (int) get_post_field( 'post_author', (int) $reply ) );}
			}
		);
		add_action(
			'post_updated',
			function ( int $id, \WP_Post $after, \WP_Post $before ) use ( $enqueue ): void {
				if ( in_array( $after->post_type, array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) ) {
					$enqueue( (int) $before->post_author );
					$enqueue( (int) $after->post_author );
				}
			},
			30,
			3
		);
		add_action(
			'before_delete_post',
			function ( int $id, \WP_Post $post ) use ( $enqueue ): void {
				if ( in_array( $post->post_type, array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) ) {
					$enqueue( (int) $post->post_author );}
			},
			30,
			2
		);
	}
	/** Evidence retrieval is indexed by author and bounded; current visibility is checked again. */
	private function evidence( int $member ): array {
		$posts     = $this->store->rows( "SELECT p.ID,p.post_type,p.post_parent FROM {$this->store->wordpressTable('posts')} p WHERE p.post_author=%d AND p.post_type IN (%s,%s) AND p.post_status=%s ORDER BY p.ID DESC LIMIT 1000", array( $member, bbp_get_topic_post_type(), bbp_get_reply_post_type(), 'publish' ) );
		$ids       = array_map( 'intval', array_column( $posts, 'ID' ) );
		$topics    = array_values( array_unique( array_map( 'intval', array_column( $posts, 'post_parent' ) ) ) );
		$votes     = array();
		$solutions = array();
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			foreach ( $this->store->rows( "SELECT object_id,SUM(value) AS total FROM {$this->store->table('signals')} WHERE object_id IN ($placeholders) AND kind='vote' AND user_id<>%d GROUP BY object_id", array( ...$ids, $member ) ) as $row ) {
				$votes[ (int) $row['object_id'] ] = (int) $row['total'];}
		}
		if ( $topics ) {
			_prime_post_caches( $topics, false, true );
			$placeholders = implode( ',', array_fill( 0, count( $topics ), '%d' ) );
			foreach ( $this->store->rows( "SELECT topic_id,answers FROM {$this->store->table('solutions')} WHERE topic_id IN ($placeholders)", $topics ) as $row ) {
				$solutions[ (int) $row['topic_id'] ] = array_map( 'intval', json_decode( $row['answers'], true ) ?: array() );}
		}
		$accepted = array();
		$helpful  = array();
		foreach ( $posts as $post ) {
			$id = (int) $post['ID'];
			if ( ! $this->access->isPublic( $id ) || ! $this->access->canRead( $id, $member ) ) {
				continue;}
			if ( $post['post_type'] === bbp_get_reply_post_type() && in_array( $id, $solutions[ (int) $post['post_parent'] ] ?? array(), true ) ) {
				$accepted[] = $id;}
			if ( ( $votes[ $id ] ?? 0 ) >= 3 ) {
				$helpful[] = $id;}
			if ( $accepted && count( $helpful ) >= 3 ) {
				break;}
		}
		return array(
			'accepted_answer'     => array_slice( $accepted, 0, 1 ),
			'helpful_contributor' => count( $helpful ) >= 3 ? array_slice( $helpful, 0, 3 ) : array(),
		);
	}
	public function reconcile( int $member ): void {
		if ( ! $this->access->activeMember( $member ) || get_user_meta( $member, self::preferenceKey(), true ) ) {
			return;}
		$evidence = $this->evidence( $member );
		foreach ( $evidence as $badge => $sources ) {
			$this->change( $member, $badge, (bool) $sources, $sources, __( 'Current public contribution evidence.', 'forum-intelligence-for-bbpress' ), 0 );
		}
	}
	public function review( int $member, string $badge, bool $active, string $reason, int $actor ): array {
		$this->access->moderate( $actor );
		if ( ! get_userdata( $member ) || bbp_is_user_inactive( $member ) || ! isset( self::catalog()[ $badge ] ) || self::catalog()[ $badge ]['automatic'] || mb_strlen( trim( $reason ) ) < 10 || mb_strlen( $reason ) > 1000 ) {
			throw new Failure( 'invalid_badge', __( 'Choose a review-based badge, active member and an explanation of 10–1000 characters.', 'forum-intelligence-for-bbpress' ) );
		}
		$this->store->limit( $actor, 'badge_review', 20 );
		$this->change( $member, $badge, $active, array(), sanitize_textarea_field( $reason ), $actor );
		return $this->listing( $member, $actor, true );
	}
	private function change( int $member, string $badge, bool $active, array $sources, string $reason, int $actor ): void {
		$key   = 'badge:' . $member . ':' . $badge;
		$event = $this->store->transaction(
			function () use ( $member, $badge, $active, $sources, $reason, $actor, $key ): ?array {
				// Erasure and preference changes hold the same native-user lock.
				$this->store->row( "SELECT ID FROM {$this->store->wordpressTable('users')} WHERE ID=%d FOR UPDATE", array( $member ) );
				$preference = $this->store->row( "SELECT meta_value FROM {$this->store->wordpressTable('usermeta')} WHERE user_id=%d AND meta_key=%s AND meta_value='1' LIMIT 1", array( $member, self::preferenceKey() ) );
				if ( $preference ) {
					return null;}

				$this->store->execute(
					"INSERT IGNORE INTO {$this->store->table('records')} (kind,record_key,owner_id,object_id,payload,created_at,updated_at) VALUES ('badge',%s,%d,%d,%s,%s,%s)",
					array(
						$key,
						$member,
						(int) ( $sources[0] ?? 0 ),
						wp_json_encode(
							array(
								'active'  => false,
								'history' => array(),
							)
						),
						$this->store->now(),
						$this->store->now(),
					)
				);
				$locked = $this->store->row( "SELECT id,payload,version FROM {$this->store->table('records')} WHERE record_key=%s FOR UPDATE", array( $key ) );
				$data   = json_decode( $locked['payload'], true );
				if ( (bool) $data['active'] === $active ) {
					if ( $active && ( $data['sources'] ?? array() ) !== $sources ) {
						$data['sources'] = $sources;
						$this->store->execute( "UPDATE {$this->store->table('records')} SET payload=%s,object_id=%d,updated_at=%s WHERE id=%d", array( wp_json_encode( $data ), (int) ( $sources[0] ?? 0 ), $this->store->now(), $locked['id'] ) );
					}
					return null;}
				$history   = $data['history'] ?? array();
				$history[] = array(
					'active' => $active,
					'date'   => $this->store->now(),
					'reason' => $reason,
				);
				$data      = array(
					'badge'   => $badge,
					'active'  => $active,
					'sources' => $sources,
					'history' => array_slice( $history, -20 ),
				);
				$this->store->execute( "UPDATE {$this->store->table('records')} SET payload=%s,object_id=%d,version=version+1,updated_at=%s WHERE id=%d", array( wp_json_encode( $data ), (int) ( $sources[0] ?? 0 ), $this->store->now(), $locked['id'] ) );
				$this->store->event(
					$active ? 'badge_awarded' : 'badge_revoked',
					(int) ( $sources[0] ?? 0 ),
					$actor,
					array(
						'badge'  => $badge,
						'reason' => $reason,
					),
					$member
				);
				return array(
					'id'      => (int) $locked['id'],
					'version' => (int) $locked['version'] + 1,
					'active'  => $active,
				);
			}
		);
		if ( $event && $event['active'] ) {
			do_action( 'fi_badge_awarded', $member, $event['id'], $actor, 'badge:' . $event['id'] . ':' . $event['version'] );}
	}
	public function visible( int $record, int $viewer ): bool {
		$row = $this->store->row( "SELECT owner_id,payload FROM {$this->store->table('records')} WHERE kind='badge' AND id=%d", array( $record ) );
		if ( ! $row || get_user_meta( (int) $row['owner_id'], self::preferenceKey(), true ) ) {
			return false;}
		$data  = json_decode( $row['payload'], true );
		$badge = $data['badge'] ?? '';
		if ( empty( $data['active'] ) || ! isset( self::catalog()[ $badge ] ) ) {
			return false;}
		if ( self::catalog()[ $badge ]['automatic'] ) {
			$sources = array_map( 'intval', $data['sources'] ?? array() );
			if ( count( $sources ) < ( $badge === 'accepted_answer' ? 1 : 3 ) ) {
				return false;}
			foreach ( $sources as $source ) {
				if ( (int) get_post_field( 'post_author', $source ) !== (int) $row['owner_id'] || ! $this->access->isPublic( $source ) || ! $this->access->canRead( $source, $viewer ) ) {
					return false;}
				if ( $badge === 'accepted_answer' ) {
					$solution = $this->store->row( "SELECT answers FROM {$this->store->table('solutions')} WHERE topic_id=%d", array( $this->access->topic( $source ) ) );
					if ( ! in_array( $source, array_map( 'intval', json_decode( $solution['answers'] ?? '[]', true ) ?: array() ), true ) ) {
						return false;}
				} else {
					$votes = $this->store->row( "SELECT SUM(value) AS total FROM {$this->store->table('signals')} WHERE object_id=%d AND kind='vote' AND user_id<>%d", array( $source, (int) $row['owner_id'] ) );
					if ( (int) $votes['total'] < 3 ) {
						return false;}
				}
			}
			return true;
		}
		return $this->access->activeMember( (int) $row['owner_id'] );
	}
	public function listing( int $member, int $viewer, bool $history = false ): array {
		if ( ! get_userdata( $member ) ) {
			throw new Failure( 'not_found', __( 'Member unavailable.', 'forum-intelligence-for-bbpress' ), 404 );}
		if ( $history && ( $viewer !== $member && ! user_can( $viewer, 'moderate' ) ) ) {
			throw new Failure( 'forbidden', __( 'Badge history is available to its owner and moderators.', 'forum-intelligence-for-bbpress' ), 403 );}
		$rows  = $this->store->rows( "SELECT id,payload,updated_at FROM {$this->store->table('records')} WHERE owner_id=%d AND kind='badge' ORDER BY id DESC LIMIT 20", array( $member ) );
		$items = array();
		foreach ( $rows as $row ) {
			$data  = json_decode( $row['payload'], true );
			$badge = $data['badge'] ?? '';
			if ( ! isset( self::catalog()[ $badge ] ) ) {
				continue;}
			$visible = $this->visible( (int) $row['id'], $viewer );
			if ( ! $history && ! $visible ) {
				continue;}
			$item = array(
				'id'         => (int) $row['id'],
				'badge'      => $badge,
				'label'      => self::catalog()[ $badge ]['label'],
				'rule'       => self::catalog()[ $badge ]['rule'],
				'active'     => $visible,
				'updated_at' => $row['updated_at'],
			);
			if ( $history ) {
				$item['history'] = $data['history'] ?? array();}
			$items[] = $item;
		}
		return array(
			'items'           => $items,
			'member'          => $member,
			'bounded_sources' => 1000,
		);
	}
}
