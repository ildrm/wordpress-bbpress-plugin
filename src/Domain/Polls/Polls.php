<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Polls;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
final class Polls {
	public function __construct( private Store $store, private Access $access, private ?\ForumIntelligence\Infrastructure\Queue\Queue $queue = null ) {}
	public function register(): void {
		$this->queue?->handle( 'poll_expire', fn( array $data )=>$this->finish( (int) $data['topic'], 0, true ) );
	}
	public function create( int $topic, int $user, array $choices, int $maximum, string $ends, string $visibility, bool $change, string $starts = '' ): array {
		$this->access->solve( $topic, $user );
		if ( get_post_type( $topic ) !== bbp_get_topic_post_type() ) {
			throw new Failure( 'not_found', __( 'Topic unavailable.', 'forum-intelligence-for-bbpress' ), 404 );}
		foreach ( $choices as $choice ) {
			if ( ! is_string( $choice ) ) {
				throw new Failure( 'invalid_choices', __( 'Provide plain-text poll options.', 'forum-intelligence-for-bbpress' ) ); }
		}
		$choices = array_values( array_filter( array_map( fn( $value ): string=>mb_substr( sanitize_text_field( (string) $value ), 0, 200 ), $choices ) ) );
		if ( count( $choices ) < 2 || count( $choices ) > 10 || count( array_unique( $choices ) ) !== count( $choices ) || $maximum < 1 || $maximum > count( $choices ) || ! in_array( $visibility, array( 'public', 'voters', 'closed' ), true ) ) {
			throw new Failure( 'invalid_poll', __( 'Provide 2–10 distinct choices and valid poll settings.', 'forum-intelligence-for-bbpress' ) ); }
		$end = strtotime( $ends . ' UTC' );
		if ( ! $end || $end < time() || $end > time() + 366 * 86400 ) {
			throw new Failure( 'invalid_date', __( 'Choose a closing date within one year.', 'forum-intelligence-for-bbpress' ) ); }
		$start = $starts === '' ? time() : strtotime( $starts . ' UTC' );
		if ( ! $start || $start >= $end || $start < time() - 60 ) {
			throw new Failure( 'invalid_date', __( 'The poll must start before it closes.', 'forum-intelligence-for-bbpress' ) ); }
		$this->store->transaction(
			function () use ( $topic, $user, $choices, $maximum, $end, $visibility, $change, $start ): void {
				$payload  = array(
					'choices'      => $choices,
					'maximum'      => $maximum,
					'ends'         => $end,
					'visibility'   => $visibility,
					'change'       => $change,
					'starts'       => $start,
					'closed'       => false,
					'counts'       => array_fill( 0, count( $choices ), 0 ),
					'participants' => 0,
				);
				$inserted = $this->store->execute( "INSERT IGNORE INTO {$this->store->table('records')} (kind,record_key,owner_id,object_id,payload,created_at,updated_at) VALUES ('poll',%s,%d,%d,%s,%s,%s)", array( 'poll:' . $topic, $user, $topic, wp_json_encode( $payload ), $this->store->now(), $this->store->now() ) );
				if ( ! $inserted ) {
					throw new Failure( 'poll_exists', __( 'A poll already exists.', 'forum-intelligence-for-bbpress' ), 409 ); }
				$this->queue?->enqueue( 'poll_expire', array( 'topic' => $topic ), (string) $topic, max( 0, $end - time() ) );
			}
		);
		return $this->view( $topic, $user );
	}
	public function vote( int $topic, int $user, array $choices ): array {
		$this->access->participate( $topic, $user );
		foreach ( $choices as $choice ) {
			if ( ( ! is_int( $choice ) && ! is_string( $choice ) ) || ! preg_match( '/^[0-9]$/D', (string) $choice ) ) {
				throw new Failure( 'invalid_choices', __( 'Choose valid poll options.', 'forum-intelligence-for-bbpress' ) ); }
		}
		$choices = array_values( array_unique( array_map( 'intval', $choices ) ) );
		sort( $choices );
		$this->store->limit( $user, 'poll', 10 );
		$this->store->transaction(
			function () use ( $topic, $user, $choices ): void {
				$table = $this->store->table( 'records' );
				$row   = $this->store->row( "SELECT * FROM $table WHERE record_key=%s FOR UPDATE", array( 'poll:' . $topic ) );
				if ( ! $row ) {
					throw new Failure( 'not_found', __( 'Poll unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
				$poll = json_decode( $row['payload'], true );
				if ( time() < ( $poll['starts'] ?? 0 ) ) {
					throw new Failure( 'poll_not_started', __( 'Voting has not started.', 'forum-intelligence-for-bbpress' ), 409 ); }
				if ( $poll['closed'] || time() >= $poll['ends'] ) {
					throw new Failure( 'poll_closed', __( 'Voting has closed.', 'forum-intelligence-for-bbpress' ), 409 ); }
				if ( ! $choices || count( $choices ) > $poll['maximum'] || min( $choices ) < 0 || max( $choices ) >= count( $poll['choices'] ) ) {
					throw new Failure( 'invalid_choices', __( 'Choose valid poll options.', 'forum-intelligence-for-bbpress' ) ); }
				$key      = 'poll_vote:' . $topic . ':' . $user;
				$previous = $this->store->record( $key );
				if ( ! $poll['change'] && $previous ) {
					throw new Failure( 'already_voted', __( 'Your vote is already recorded.', 'forum-intelligence-for-bbpress' ), 409 ); }
				$counts = $this->totals( $topic, $poll );
				foreach ( $previous['data']['choices'] ?? array() as $choice ) {
					--$counts['counts'][ $choice ]; }
				foreach ( $choices as $choice ) {
					++$counts['counts'][ $choice ]; }
				$poll['counts']       = $counts['counts'];
				$poll['participants'] = $counts['participants'] + ( $previous ? 0 : 1 );
				$this->store->saveRecord( 'poll_vote', $key, $user, $topic, array( 'choices' => $choices ) );
				$this->store->execute( "UPDATE $table SET payload=%s,updated_at=%s,version=version+1 WHERE id=%d", array( wp_json_encode( $poll ), $this->store->now(), $row['id'] ) );
			}
		);
		return $this->view( $topic, $user );
	}
	/** Remove a vote under the same poll lock used by vote changes and aggregate updates. */
	public function eraseVote( int $topic, int $user ): bool {
		return $this->store->transaction(
			function () use ( $topic, $user ): bool {
				$table = $this->store->table( 'records' );
				$row   = $this->store->row( "SELECT id,payload FROM $table WHERE record_key=%s FOR UPDATE", array( 'poll:' . $topic ) );
				$vote  = $this->store->record( 'poll_vote:' . $topic . ':' . $user );
				if ( ! $vote ) {
					return false;}
				if ( $row ) {
					$poll   = json_decode( $row['payload'], true );
					$totals = $this->totals( $topic, $poll );
					foreach ( $vote['data']['choices'] as $choice ) {
						$totals['counts'][ $choice ] = max( 0, $totals['counts'][ $choice ] - 1 );}
					$poll['counts']       = $totals['counts'];
					$poll['participants'] = max( 0, $totals['participants'] - 1 );
					$this->store->execute( "UPDATE $table SET payload=%s,version=version+1,updated_at=%s WHERE id=%d", array( wp_json_encode( $poll ), $this->store->now(), $row['id'] ) );
				}
				$this->store->execute( "DELETE FROM $table WHERE record_key=%s AND owner_id=%d", array( 'poll_vote:' . $topic . ':' . $user, $user ) );
				return true;
			}
		);
	}
	public function close( int $topic, int $user ): array {
		$this->access->moderate( $user );
		$this->access->read( $topic, $user );
		$this->finish( $topic, $user );
		return $this->view( $topic, $user );
	}
	private function finish( int $topic, int $actor, bool $expired = false ): void {
		$event = $this->store->transaction(
			function () use ( $topic, $actor, $expired ): ?array {
				$locked = $this->store->row( "SELECT * FROM {$this->store->table('records')} WHERE kind='poll' AND record_key=%s FOR UPDATE", array( 'poll:' . $topic ) );
				if ( ! $locked || get_post_type( $topic ) !== bbp_get_topic_post_type() ) {
					if ( $expired ) {
						return null;}
					throw new Failure( 'not_found', __( 'Poll unavailable.', 'forum-intelligence-for-bbpress' ), 404 );
				}
				$data = json_decode( $locked['payload'], true );
				if ( $data['closed'] || ( $expired && time() < (int) $data['ends'] ) ) {
					return null;}
				$data['closed'] = true;
				$this->store->saveRecord( 'poll', 'poll:' . $topic, (int) $locked['owner_id'], $topic, $data );
				$key = 'poll:' . $topic . ':' . ( (int) $locked['version'] + 1 );
				$this->store->event( 'poll_closed', $topic, $actor );
				if ( $this->queue && ( get_option( 'fi_settings', array() )['modules']['notifications'] ?? true ) ) {
					$this->queue->enqueue(
						'poll_notify',
						array(
							'topic'  => $topic,
							'actor'  => $actor,
							'key'    => $key,
							'cursor' => 0,
						),
						$key . ':0'
					);
				}
				return array( 'key' => $key );
			}
		);
		if ( $event ) {
			do_action( 'fi_poll_closed', $topic, $actor, $event['key'] );}
	}
	public function view( int $topic, int $user ): ?array {
		$this->access->read( $topic, $user );
		$row = $this->store->record( 'poll:' . $topic );
		if ( ! $row ) {
			return null;
		} $data             = $row['data'];
		$mine               = $user ? $this->store->record( 'poll_vote:' . $topic . ':' . $user ) : null;
		$data['my_choices'] = $mine['data']['choices'] ?? array();
		$visible            = $data['visibility'] === 'public' || user_can( $user, 'moderate' ) || ( $data['visibility'] === 'voters' && $mine ) || ( $data['visibility'] === 'closed' && ( $data['closed'] || time() >= $data['ends'] ) );
		if ( $visible ) {
			$data = array_merge( $data, $this->totals( $topic, $data ) );
		}
		if ( ! $visible ) {
			unset( $data['counts'], $data['participants'] ); }
		return $data;
	}
	private function totals( int $topic, array $poll ): array {
		if ( isset( $poll['counts'], $poll['participants'] ) ) {
			return array(
				'counts'       => $poll['counts'],
				'participants' => $poll['participants'],
			); }
		// Upgrade pre-aggregate records using indexed object/kind ranges, without loading votes.
		$columns = array( 'COUNT(*) AS participants' );
		foreach ( array_keys( $poll['choices'] ) as $choice ) {
			$columns[] = "SUM(JSON_CONTAINS(payload,'" . (int) $choice . "','$.choices')) AS c" . (int) $choice; }
		$row    = $this->store->row( 'SELECT ' . implode( ',', $columns ) . " FROM {$this->store->table('records')} WHERE kind='poll_vote' AND object_id=%d", array( $topic ) );
		$counts = array();
		foreach ( array_keys( $poll['choices'] ) as $choice ) {
			$counts[] = (int) ( $row[ 'c' . $choice ] ?? 0 ); }
		return array(
			'counts'       => $counts,
			'participants' => (int) $row['participants'],
		);
	}
}
