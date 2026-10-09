<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Community;
use ForumIntelligence\Infrastructure\Search\LexicalSearch;
use ForumIntelligence\Infrastructure\Queue\Queue;
use ForumIntelligence\Domain\Notifications\Notifications;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
/** Explicit owned query storage; does not contribute raw text to analytics or external AI. */
final class SavedSearches {
	public function __construct( private Store $store, private Access $access, private LexicalSearch $local, private Following $following ) {}
	private function user( int $user ): void {
		if ( ! $this->access->activeMember( $user ) || ! user_can( $user, 'publish_replies' ) ) {
			throw new Failure( 'forbidden', __( 'Sign in with participation permission.', 'forum-intelligence-for-bbpress' ), 403 );}}
	public function save( int $user, string $query, array $filters ): array {
		$this->user( $user );
		$query = mb_substr( sanitize_text_field( $query ), 0, 100 );
		if ( mb_strlen( trim( $query ) ) < 3 ) {
			throw new Failure( 'invalid_query', __( 'Enter at least three characters.', 'forum-intelligence-for-bbpress' ) );}
		$clean = array();
		foreach ( array( 'forum', 'tag', 'type', 'solved', 'after', 'before', 'unanswered', 'min_votes' ) as $key ) {
			if ( ! array_key_exists( $key, $filters ) || $filters[ $key ] === '' ) {
				continue;}
			$value = $filters[ $key ];
			if ( ! is_scalar( $value ) ) {
				throw new Failure( 'invalid_filter', __( 'Use a valid saved-search filter.', 'forum-intelligence-for-bbpress' ) );}
			if ( in_array( $key, array( 'forum', 'min_votes', 'solved' ), true ) ) {
				$clean[ $key ] = (int) $value;
			} elseif ( $key === 'unanswered' ) {
				$clean[ $key ] = (bool) $value;
			} else {
				$clean[ $key ] = mb_substr( sanitize_text_field( (string) $value ), 0, 100 );}
		}
		if ( isset( $clean['type'] ) && ! in_array( $clean['type'], array( 'topic', 'reply', 'forum' ), true ) || isset( $clean['solved'] ) && ! in_array( $clean['solved'], array( 0, 1 ), true ) || isset( $clean['after'] ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $clean['after'] ) ) {
			throw new Failure( 'invalid_filter', __( 'Use a valid saved-search filter.', 'forum-intelligence-for-bbpress' ) );}
		if ( ! empty( $clean['forum'] ) ) {
			$this->access->read( $clean['forum'], $user );}
		\ForumIntelligence\Domain\Search\DateRange::validate( $clean );
		ksort( $clean );
		$key = 'saved_search:' . $user . ':' . hash( 'sha256', $query . ':' . wp_json_encode( $clean ) );
		$this->store->limit( $user, 'saved_search', 10, 300 );
		$this->store->transaction(
			function () use ( $user, $query, $clean, $key ): void {
				$table = $this->store->table( 'records' );
				$lock  = 'saved_search_lock:' . $user;
				$this->store->execute( "INSERT IGNORE INTO $table (kind,record_key,owner_id,payload,created_at,updated_at) VALUES ('saved_search_lock',%s,%d,'{}',%s,%s)", array( $lock, $user, $this->store->now(), $this->store->now() ) );
				$this->store->row( "SELECT id FROM $table WHERE record_key=%s FOR UPDATE", array( $lock ) );
				$existing     = $this->store->row( "SELECT payload FROM $table WHERE record_key=%s FOR UPDATE", array( $key ) );
				$existingData = $existing ? ( json_decode( $existing['payload'], true ) ?: array() ) : array();
				$count        = $this->store->row( "SELECT COUNT(*) AS n FROM $table WHERE kind='saved_search' AND owner_id=%d", array( $user ) );
				if ( (int) $count['n'] >= 5 && ! $existing ) {
					throw new Failure( 'saved_search_limit', __( 'You can save up to five searches.', 'forum-intelligence-for-bbpress' ), 409 );}
				$this->store->saveRecord(
					'saved_search',
					$key,
					$user,
					0,
					array(
						'query'        => $query,
						'filters'      => $clean,
						'alerts'       => (bool) ( $existingData['alerts'] ?? false ),
						'alerts_since' => (float) ( $existingData['alerts_since'] ?? 0 ),
					)
				);
			}
		);
		return array( 'saved' => true );
	}

	/** Explicit opt-in; enabling never sends historical matches. */
	public function alerts( int $user, int $id, bool $enabled ): array {
		$this->user( $user );
		$this->store->limit( $user, 'saved_search_alerts', 20, 300 );
		$this->store->transaction(
			function () use ( $user, $id, $enabled ): void {
				$row = $this->store->row( "SELECT * FROM {$this->store->table('records')} WHERE id=%d AND owner_id=%d AND kind='saved_search' FOR UPDATE", array( $id, $user ) );
				if ( ! $row ) {
					throw new Failure( 'not_found', __( 'Saved search unavailable.', 'forum-intelligence-for-bbpress' ), 404 );}
				$data = json_decode( $row['payload'], true ) ?: array();
				if ( $enabled && empty( $data['alerts'] ) ) {
					$data['alerts_since'] = microtime( true );}
				$data['alerts'] = $enabled;
				$this->store->saveRecord( 'saved_search', $row['record_key'], $user, 0, $data );
			}
		);
		return array(
			'saved'  => true,
			'alerts' => $enabled,
		);
	}
	public function register( Queue $queue, Notifications $notifications ): void {
		$queue->handle( 'saved_search_notify', fn( array $data )=>$this->notify( $data, $queue, $notifications ) );
		add_action(
			'transition_post_status',
			function ( string $newStatus, string $oldStatus, \WP_Post $post ) use ( $queue ): void {
				if ( ! in_array( $post->post_type, array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) || ! in_array( $newStatus, array( 'publish', 'closed' ), true ) || in_array( $oldStatus, array( 'publish', 'closed' ), true ) ) {
					return;}
				$this->store->transaction(
					function () use ( $post, $queue ): void {
						$table   = $this->store->table( 'records' );
						$key     = 'saved_search_publication:' . $post->ID;
						$event   = microtime( true );
						$cutoff  = $this->store->row( "SELECT MAX(id) AS id FROM $table WHERE kind='saved_search'" );
						$created = $this->store->execute( "INSERT IGNORE INTO $table (kind,record_key,object_id,payload,created_at,updated_at) VALUES ('saved_search_publication',%s,%d,'{}',%s,%s)", array( $key, $post->ID, $this->store->now(), $this->store->now() ) );
						if ( $created && ! empty( $cutoff['id'] ) ) {
							$queue->enqueue(
								'saved_search_notify',
								array(
									'object'   => $post->ID,
									'event_at' => $event,
									'cutoff'   => (int) $cutoff['id'],
									'cursor'   => 0,
								),
								(string) $post->ID . ':0'
							);}
					}
				);
			},
			30,
			3
		);
		add_action(
			'deleted_post',
			function ( int $id ): void {
				$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE kind='saved_search_publication' AND record_key=%s", array( 'saved_search_publication:' . $id ) );
			}
		);
	}
	/** Keyset batches re-read each owned record under lock before local matching. */
	private function notify( array $event, Queue $queue, Notifications $notifications ): void {
		$id = (int) $event['object'];
		if ( ! ( get_option( 'fi_settings', array() )['modules']['notifications'] ?? true ) || ! $this->access->isPublic( $id ) || ! $this->access->canRead( $id, 0 ) ) {
			return;}
		$rows = $this->store->rows( "SELECT id FROM {$this->store->table('records')} WHERE kind='saved_search' AND id>%d AND id<=%d ORDER BY id LIMIT 20", array( (int) $event['cursor'], (int) $event['cutoff'] ) );
		foreach ( $rows as $entry ) {
			$this->store->transaction(
				function () use ( $entry, $id, $event, $notifications ): void {
					$row = $this->store->row( "SELECT owner_id,payload FROM {$this->store->table('records')} WHERE kind='saved_search' AND id=%d FOR UPDATE", array( (int) $entry['id'] ) );
					if ( ! $row ) {
						return;}
					$data = json_decode( $row['payload'], true ) ?: array();
					$user = (int) $row['owner_id'];
					if ( ! $this->access->isPublic( $id ) || ! $this->access->canRead( $id, 0 ) || empty( $data['alerts'] ) || (float) ( $data['alerts_since'] ?? PHP_FLOAT_MAX ) > (float) $event['event_at'] || ! $this->access->activeMember( $user ) || ! user_can( $user, 'publish_replies' ) || ! $this->access->canRead( $id, $user ) || $this->following->muted( $user, $id ) ) {
						return;}
					if ( $this->local->matchesObject( (string) ( $data['query'] ?? '' ), (array) ( $data['filters'] ?? array() ), $user, $id ) ) {
						$notifications->deliver( $user, $id, (int) get_post_field( 'post_author', $id ), 'saved_search', 'saved_search:' . $id );}
				}
			);
		}
		if ( count( $rows ) === 20 ) {
			$event['cursor'] = (int) end( $rows )['id'];
			$queue->enqueue( 'saved_search_notify', $event, $id . ':' . $event['cursor'] );}
	}

	public function remove( int $user, int $id ): array {
		$this->user( $user );
		$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE id=%d AND owner_id=%d AND kind='saved_search'", array( $id, $user ) );
		return array( 'saved' => true );
	}
	public function listing( int $user ): array {
		$this->user( $user );
		$rows = $this->store->rows( "SELECT id,payload FROM {$this->store->table('records')} WHERE owner_id=%d AND kind='saved_search' ORDER BY id LIMIT 5", array( $user ) );
		return array_map(
			static fn( array $row ): array=>array(
				'id'      => (int) $row['id'],
				'query'   => json_decode( $row['payload'], true )['query'],
				'filters' => json_decode( $row['payload'], true )['filters'],
				'alerts'  => (bool) ( json_decode( $row['payload'], true )['alerts'] ?? false ),
			),
			$rows
		);
	}
	public function feed( int $user ): array {
		$this->user( $user );
		$this->store->limit( $user, 'saved_search_feed', 10 );
		$items = array();
		foreach ( $this->listing( $user ) as $saved ) {
			if ( ! empty( $saved['filters']['forum'] ) && ! $this->access->canRead( (int) $saved['filters']['forum'], $user ) ) {
				continue;}
			$results = $this->local->search( $saved['query'], $saved['filters'] + array( 'per_page' => 10 ), $user );
			foreach ( $results['items'] as $item ) {
				if ( ! $this->following->muted( $user, (int) $item['id'] ) ) {
					$item['saved_search'] = $saved['query'];
					$items[ $item['id'] ] = $item;}
			}
		}
		return array(
			'items'               => array_slice( array_values( $items ), 0, 20 ),
			'external_processing' => false,
		);
	}
}
