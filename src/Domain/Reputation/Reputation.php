<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Reputation;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
final class Reputation {
	public function __construct( private Store $store, private Access $access ) {}
	private ?\ForumIntelligence\Infrastructure\Queue\Queue $queue = null;
	public function profile( int $member, int $viewer ): array {
		if ( ! get_userdata( $member ) ) {
			throw new Failure( 'not_found', __( 'Member unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
		$events      = $this->store->rows( "SELECT object_id,SUM(points) AS points,MAX(created_at) AS created_at,'ledger_total' AS kind FROM {$this->store->table('events')} WHERE subject_id=%d AND points<>0 GROUP BY object_id ORDER BY object_id DESC LIMIT 1000", array( $member ) );
		$total       = 0;
		$expertise   = array();
		$explanation = array();
		$samples     = array();
		foreach ( $events as $event ) {
			$objectId = (int) $event['object_id'];
			if ( $objectId && ( ! $this->access->canRead( $objectId, $viewer ) || ( $member !== $viewer && ! $this->access->isPublic( $objectId ) ) ) ) {
				continue; }
			if ( ! $objectId && $member !== $viewer && ! user_can( $viewer, 'moderate' ) ) {
				continue; }
			$points        = (int) $event['points'];
			$total        += $points;
			$explanation[] = array(
				'kind'   => $event['kind'],
				'points' => $points,
				'object' => $objectId,
				'date'   => $event['created_at'],
			);
			$topic         = $objectId ? $this->access->topic( $objectId ) : 0;
			if ( $topic ) {
				$tags = wp_get_object_terms( $topic, bbp_get_topic_tag_tax_id(), array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $tags ) ) {
					foreach ( $tags as $tag ) {
						$expertise[ $tag ] = ( $expertise[ $tag ] ?? 0 ) + $points;
						if ( $points > 0 ) {
							$samples[ $tag ][ $objectId ] = true; }
					}
				}
			}
		}
		foreach ( $expertise as $tag => $points ) {
			if ( count( $samples[ $tag ] ?? array() ) < 3 || $points <= 0 ) {
				unset( $expertise[ $tag ] ); }
		}
		arsort( $expertise );
		return array(
			'member'          => $member,
			'score'           => $total,
			'ledger'          => $explanation,
			'expertise'       => $expertise,
			'samples'         => array_map( 'count', $samples ),
			'minimum_samples' => 3,
			'bounded_sources' => 1000,
			'badges'          => ! empty( get_option( 'fi_settings', array() )['modules']['badges'] ) ? array_column( ( new Badges( $this->store, $this->access ) )->listing( $member, $viewer )['items'], 'badge' ) : array(),
		);
	}

	public function register( \ForumIntelligence\Infrastructure\Queue\Queue $queue ): void {
		$this->queue = $queue;
		$queue->handle( 'reputation_source', fn( array $data )=>$this->reconcile( (int) $data['object'], (int) ( $data['cursor'] ?? 0 ) ) );
		$queue->handle(
			'reputation_rebuild',
			function ( array $data ) use ( $queue ): void {
				$rows = $this->store->rows( "SELECT ID FROM {$this->store->wordpressTable('posts')} WHERE ID>%d AND post_type IN (%s,%s) ORDER BY ID LIMIT 50", array( (int) $data['cursor'], bbp_get_topic_post_type(), bbp_get_reply_post_type() ) );
				foreach ( $rows as $row ) {
					$queue->enqueue(
						'reputation_source',
						array(
							'object' => (int) $row['ID'],
							'cursor' => 0,
						),
						(string) $row['ID']
					);}
				if ( count( $rows ) === 50 ) {
					$data['cursor'] = (int) end( $rows )['ID'];
					$queue->enqueue( 'reputation_rebuild', $data, $data['run'] . ':' . $data['cursor'] );}
			}
		);
		add_action(
			'post_updated',
			function ( int $id, \WP_Post $after, \WP_Post $before ) use ( $queue ): void {
				if ( $after->post_author !== $before->post_author && in_array( $after->post_type, array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) ) {
					$queue->enqueue(
						'reputation_source',
						array(
							'object' => $id,
							'cursor' => 0,
						),
						(string) $id
					);}
			},
			20,
			3
		);
	}
	public function rebuild( int $actor ): array {
		if ( ! user_can( $actor, 'manage_options' ) || ! $this->queue ) {
			throw new Failure( 'forbidden', __( 'Settings permission is required.', 'forum-intelligence-for-bbpress' ), 403 );}
		$run = bin2hex( random_bytes( 8 ) );
		$this->queue->enqueue(
			'reputation_rebuild',
			array(
				'cursor' => 0,
				'run'    => $run,
			),
			$run . ':0'
		);
		$this->store->event( 'reputation_rebuild_requested', 0, $actor );
		return array( 'queued' => true );
	}
	public function reconcile( int $objectId, int $cursor = 0 ): void {
		$post = get_post( $objectId );
		if ( ! $post || ! in_array( $post->post_type, array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) ) {
			return;}
		$settings = get_option( 'fi_settings', array() );
		$credits  = new Credits( $this->store );
		$rows     = $this->store->rows( "SELECT id,user_id,kind,value FROM {$this->store->table('signals')} WHERE object_id=%d AND id>%d AND kind IN ('vote','confirm') ORDER BY id LIMIT 100", array( $objectId, $cursor ) );
		$topic    = $this->access->topic( $objectId );
		$solution = $this->store->row( "SELECT answers,actor_id FROM {$this->store->table('solutions')} WHERE topic_id=%d", array( $topic ) );
		foreach ( $rows as $candidate ) {
			// Re-read a locked signal: erasure or a concurrent vote may have changed the candidate.
			$this->store->transaction(
				function () use ( $candidate, $objectId, $topic, $settings, $credits ): void {
					$solution = $this->store->row( "SELECT answers FROM {$this->store->table('solutions')} WHERE topic_id=%d FOR UPDATE", array( $topic ) );
					$accepted = $solution ? array_map( 'intval', json_decode( $solution['answers'], true ) ?: array() ) : array();
					$row      = $this->store->row( "SELECT user_id,kind,value FROM {$this->store->table('signals')} WHERE id=%d AND object_id=%d FOR UPDATE", array( $candidate['id'], $objectId ) );
					if ( ! $row ) {
						return;
					}
					$author = $this->store->row( "SELECT post_author FROM {$this->store->wordpressTable('posts')} WHERE ID=%d FOR UPDATE", array( $objectId ) );
					$value  = (int) $row['value'];
					$kind   = $row['kind'];
					$weight = $kind === 'vote' ? (int) ( $settings[ $value < 0 ? 'downvote_weight' : 'vote_weight' ] ?? 2 ) : (int) ( $settings['confirmation_weight'] ?? 3 );
					if ( $kind === 'confirm' && ! in_array( $objectId, $accepted, true ) ) {
						$value = 0;
					}
					$credits->set( 'signal_reputation', $objectId, (int) $row['user_id'], (int) ( $author['post_author'] ?? 0 ), $value * $weight, $kind . ':' . $objectId . ':' . $row['user_id'], array( 'signal' => $kind ) );
				}
			);
		}
		if ( $solution ) {
			$this->solutionsChanged( $topic, $objectId, 'reconcile', (int) $solution['actor_id'] );
		}
		do_action( 'fi_reputation_source_reconciled', (int) $post->post_author );
		if ( count( $rows ) === 100 && $this->queue ) {
			$next = (int) end( $rows )['id'];
			$this->queue->enqueue(
				'reputation_source',
				array(
					'object' => $objectId,
					'cursor' => $next,
				),
				$objectId . ':' . $next
			);}
	}
	public function adjust( int $member, int $points, string $reason, int $actor ): array {
		$this->access->moderate( $actor );
		if ( ! get_userdata( $member ) || abs( $points ) > 1000 || mb_strlen( $reason ) < 5 ) {
			throw new Failure( 'invalid_adjustment', __( 'Provide a member, bounded points and an explanation.', 'forum-intelligence-for-bbpress' ) ); }
		$this->store->event( 'reputation_adjustment', 0, $actor, array( 'reason' => mb_substr( sanitize_textarea_field( $reason ), 0, 1000 ) ), $member, $points );
		return $this->profile( $member, $actor );
	}
	public function solutionsChanged( int $topic, int $reply, string $action, int $actor ): void {
		$replies = array();
		$this->store->transaction(
			function () use ( $topic, $actor, &$replies ): void {
				$row = $this->store->row( "SELECT answers FROM {$this->store->table('solutions')} WHERE topic_id=%d FOR UPDATE", array( $topic ) );
				if ( ! $row ) {
					return; }
				$accepted = array_map( 'intval', json_decode( $row['answers'], true ) ?: array() );
				$previous = $this->store->record( 'solution_awards:' . $topic );
				$ids      = $previous['data']['ids'] ?? array();
				$credits  = new Credits( $this->store );
				$replies  = array_unique( array( ...$ids, ...$accepted ) );
				foreach ( $replies as $id ) {
					$author = (int) get_post_field( 'post_author', $id );
					$credits->set( 'solution_reputation', (int) $id, $actor, $author, in_array( $id, $accepted, true ) ? (int) ( get_option( 'fi_settings', array() )['solution_weight'] ?? 10 ) : 0, 'solution:' . $topic . ':' . $id, array( 'topic' => $topic ) );
				}
				$this->store->saveRecord( 'solution_awards', 'solution_awards:' . $topic, 0, $topic, array( 'ids' => $accepted ) );
			}
		);
		if ( $action !== 'reconcile' && $this->queue ) {
			foreach ( $replies as $id ) {
				$this->queue->enqueue(
					'reputation_source',
					array(
						'object' => (int) $id,
						'cursor' => 0,
					),
					(string) $id
				);}
		}
	}
}
