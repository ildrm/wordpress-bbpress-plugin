<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Solutions;
use ForumIntelligence\Domain\Failure;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
final class Solutions {
	private array $cache       = array();
	private array $validations = array();
	public function __construct( private Store $store, private Access $access ) {}
	public function state( int $topic, int $user ): array {
		$this->access->read( $topic, $user );
		$key = get_current_blog_id() . ':' . $topic;
		if ( ! array_key_exists( $key, $this->cache ) ) {
			$this->cache[ $key ] = $this->store->row( "SELECT * FROM {$this->store->table('solutions')} WHERE topic_id=%d", array( $topic ) ); }
		$row     = $this->cache[ $key ];
		$answers = array_values( array_filter( array_map( 'intval', $row ? ( json_decode( $row['answers'], true ) ?: array() ) : array() ), fn( int $id ): bool => $this->access->topic( $id ) === $topic && $this->access->canRead( $id, $user ) ) );
		if ( ! isset( $this->validations[ $key ] ) ) {
			$this->primeValidations( array( $topic ) );}
		$confirmations = array();
		foreach ( $answers as $answer ) {
			$confirmations[ $answer ] = $this->validations[ $key ][ $answer ] ?? 0;}
		$count = array_sum( $confirmations );
		return array(
			'confirmations'         => $confirmations,
			'community_validations' => $count,
			'confidence'            => ! $answers ? 'unvalidated' : ( $count >= 3 ? 'community_validated' : ( $count > 0 ? 'confirmed' : 'accepted' ) ),
			'topic_id'              => $topic,
			'solved'                => (bool) ( $row['solved'] ?? false ) && ( ! $row || ( json_decode( $row['answers'], true ) === array() || (bool) $answers ) ),
			'primary_reply'         => in_array( (int) ( $row['primary_reply'] ?? 0 ), $answers, true ) ? (int) $row['primary_reply'] : 0,
			'answers'               => $answers,
			'version'               => (int) ( $row['version'] ?? 0 ),
			'accepted_by'           => (int) ( $row['actor_id'] ?? 0 ),
			'updated_at'            => $row['updated_at'] ?? null,
		);
	}
	public function history( int $topic, int $viewer, int $before = PHP_INT_MAX ): array {
		$this->access->read( $topic, $viewer );
		$rows = $this->store->rows( "SELECT id,kind,actor_id,payload,created_at FROM {$this->store->table('events')} WHERE object_id=%d AND kind LIKE 'solution_%' AND id<%d ORDER BY id DESC LIMIT 50", array( $topic, $before ) );
		foreach ( $rows as &$row ) {
			$payload = json_decode( $row['payload'], true );
			foreach ( array( 'before', 'after' ) as $key ) {
				$payload[ $key ] = array_values( array_filter( (array) ( $payload[ $key ] ?? array() ), fn( $id ): bool=>$this->access->canRead( (int) $id, $viewer ) ) );
			}$row['payload'] = $payload;
		}unset( $row );
		return array(
			'items'    => $rows,
			'has_more' => count( $rows ) === 50,
		);
	}
	public function forget( int $topic ): void {
		unset( $this->cache[ get_current_blog_id() . ':' . $topic ], $this->validations[ get_current_blog_id() . ':' . $topic ] ); }
	public function change( int $topic, int $reply, string $action, int $user, ?int $expected = null ): array {
		if ( get_post_type( $topic ) !== bbp_get_topic_post_type() ) {
			throw new Failure( 'invalid_topic', __( 'A topic is required.', 'forum-intelligence-for-bbpress' ) ); }
		$this->access->solve( $topic, $user );
		if ( ! in_array( $action, array( 'accept', 'remove', 'solve', 'reopen', 'primary' ), true ) ) {
			throw new Failure( 'invalid_action', __( 'Invalid solution action.', 'forum-intelligence-for-bbpress' ) ); }
		if ( in_array( $action, array( 'accept', 'remove', 'primary' ), true ) ) {
			$this->access->read( $reply, $user );
			if ( get_post_type( $reply ) !== bbp_get_reply_post_type() || $this->access->topic( $reply ) !== $topic ) {
				throw new Failure( 'invalid_reply', __( 'The reply must belong to this topic.', 'forum-intelligence-for-bbpress' ) ); }
		}
		$this->store->limit( $user, 'solution', 20 );
		$this->store->transaction(
			function () use ( $topic, $reply, $action, $user, $expected ): void {
				$table = $this->store->table( 'solutions' );
				$this->store->execute( "INSERT IGNORE INTO $table (topic_id,answers,updated_at) VALUES (%d,'[]',%s)", array( $topic, $this->store->now() ) );
				$row = $this->store->row( "SELECT * FROM $table WHERE topic_id=%d FOR UPDATE", array( $topic ) );
				if ( $expected !== null && $expected !== (int) $row['version'] ) {
					throw new Failure( 'conflict', __( 'The solution changed. Refresh and retry.', 'forum-intelligence-for-bbpress' ), 409 ); }
				$answers = array_map( 'intval', json_decode( $row['answers'], true ) ?: array() );
				$primary = (int) $row['primary_reply'];
				$solved  = (bool) $row['solved'];
				if ( $action === 'accept' ) {
					$multiple = (bool) ( get_option( 'fi_settings', array() )['multiple_solutions'] ?? false );
					if ( $multiple && count( $answers ) >= 10 && ! in_array( $reply, $answers, true ) ) {
						throw new Failure( 'solution_limit', __( 'A topic can have at most ten accepted answers.', 'forum-intelligence-for-bbpress' ) ); }
					$answers = $multiple ? array_values( array_unique( array( ...$answers, $reply ) ) ) : array( $reply );
					if ( ! $multiple || ! $primary ) {
						$primary = $reply;
					} $solved = true;
				} elseif ( $action === 'remove' ) {
					$answers = array_values( array_diff( $answers, array( $reply ) ) );
					if ( $primary === $reply ) {
						$primary = $answers[0] ?? 0;
					} $solved = (bool) $answers;
				} elseif ( $action === 'primary' ) {
					if ( ! in_array( $reply, $answers, true ) ) {
						throw new Failure( 'not_accepted', __( 'Accept the reply first.', 'forum-intelligence-for-bbpress' ) );
					} $primary = $reply;
				} elseif ( $action === 'reopen' ) {
					$answers = array();
					$primary = 0;
					$solved  = false; } else {
								$solved = true; }
					$this->store->execute( "UPDATE $table SET answers=%s,primary_reply=%d,solved=%d,actor_id=%d,version=version+1,updated_at=%s WHERE topic_id=%d", array( wp_json_encode( $answers ), $primary, (int) $solved, $user, $this->store->now(), $topic ) );
					$this->store->execute( "DELETE FROM {$this->store->table('links')} WHERE kind='accepted_answer' AND source_id=%d", array( $topic ) );
					foreach ( $answers as $accepted ) {
						$this->store->insert(
							'links',
							array(
								'user_id'    => 0,
								'source_id'  => $topic,
								'target_id'  => $accepted,
								'kind'       => 'accepted_answer',
								'payload'    => '{}',
								'created_at' => $this->store->now(),
							)
						); }
					$this->store->event(
						'solution_' . $action,
						$topic,
						$user,
						array(
							'reply'   => $reply,
							'before'  => json_decode( $row['answers'], true ),
							'after'   => $answers,
							'version' => (int) $row['version'] + 1,
						)
					);
				$this->store->metric( 'solution_' . $action );
			}
		);
		unset( $this->cache[ get_current_blog_id() . ':' . $topic ] );
		do_action( 'fi_solution_changed', $topic, $reply, $action, $user );
		return $this->state( $topic, $user );
	}
	public function prime( array $topics ): void {
		$topics = array_values( array_unique( array_map( 'intval', $topics ) ) );
		if ( ! $topics ) {
			return; }
		$topics       = array_slice( $topics, 0, 500 );
		$placeholders = implode( ',', array_fill( 0, count( $topics ), '%d' ) );
		foreach ( $topics as $topic ) {
			$this->cache[ get_current_blog_id() . ':' . $topic ] = null; }
		foreach ( $this->store->rows( "SELECT * FROM {$this->store->table('solutions')} WHERE topic_id IN ($placeholders)", $topics ) as $row ) {
			$this->cache[ get_current_blog_id() . ':' . $row['topic_id'] ] = $row; }
		$this->primeValidations( $topics );
	}
	private function primeValidations( array $topics ): void {
		$ids         = array();
		$replyTopics = array();
		$prefix      = get_current_blog_id() . ':';
		foreach ( $topics as $topic ) {
			$this->validations[ $prefix . $topic ] = array();
			foreach ( json_decode( $this->cache[ $prefix . $topic ]['answers'] ?? '[]', true ) ?: array() as $reply ) {
				$ids[]                       = (int) $reply;
				$replyTopics[ (int) $reply ] = $topic;}
		}
		if ( ! $ids ) {
			return;}
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		foreach ( $this->store->rows( "SELECT object_id,SUM(value=1) AS n FROM {$this->store->table('signals')} WHERE kind='confirm' AND object_id IN ($marks) GROUP BY object_id", $ids ) as $row ) {
			$id = (int) $row['object_id'];
			$this->validations[ $prefix . $replyTopics[ $id ] ][ $id ] = (int) $row['n'];}
	}
	public function invalidate( int $objectId ): void {
		$links  = $this->store->rows( "SELECT source_id FROM {$this->store->table('links')} WHERE kind='accepted_answer' AND target_id=%d", array( $objectId ) );
		$topics = array_values( array_unique( array_filter( array( $this->access->topic( $objectId ), ...array_column( $links, 'source_id' ) ) ) ) );
		foreach ( $topics as $topic ) {
			$topic   = (int) $topic;
			$table   = $this->store->table( 'solutions' );
			$changed = false;
			$this->store->transaction(
				function () use ( $topic, $objectId, $table, &$changed ): void {
					$row = $this->store->row( "SELECT * FROM $table WHERE topic_id=%d FOR UPDATE", array( $topic ) );
					if ( ! $row ) {
						return; }
					$old   = array_map( 'intval', json_decode( $row['answers'], true ) ?: array() );
					$valid = array_values( array_filter( $old, fn( int $id ): bool=>$id !== $objectId && $this->access->topic( $id ) === $topic && get_post_status( $id ) === 'publish' ) );
					if ( $old === $valid ) {
						return; }
					$primary = in_array( (int) $row['primary_reply'], $valid, true ) ? (int) $row['primary_reply'] : ( $valid[0] ?? 0 );
					$this->store->execute( "UPDATE $table SET answers=%s,primary_reply=%d,solved=%d,version=version+1,updated_at=%s WHERE topic_id=%d", array( wp_json_encode( $valid ), $primary, (int) (bool) $valid, $this->store->now(), $topic ) );
					$this->store->execute( "DELETE FROM {$this->store->table('links')} WHERE kind='accepted_answer' AND source_id=%d AND target_id=%d", array( $topic, $objectId ) );
					$this->store->event(
						'solution_invalidated',
						$topic,
						0,
						array(
							'before' => $old,
							'after'  => $valid,
						)
					);
					$changed = true;
				}
			);
			unset( $this->cache[ get_current_blog_id() . ':' . $topic ] );
			if ( $changed ) {
				do_action( 'fi_solution_changed', $topic, $objectId, 'invalidated', 0 ); }
		}
	}
}
