<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Database;
use ForumIntelligence\Domain\Failure;
/** Custom SQL boundary; identifiers never come from request input. */
final class Store {
	private \wpdb $db;
	/** @var \WeakMap<\wpdb,int>|null Shared native connection savepoint sequence. */
	private static ?\WeakMap $transactions = null;
	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb; }
	public function table( string $name ): string {
		if ( ! in_array( $name, Schema::TABLES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown table' ); }
		return $this->db->prefix . 'fi_' . $name;
	}
	public function wordpressTable( string $name ): string {
		if ( ! in_array( $name, array( 'posts', 'postmeta', 'users', 'usermeta', 'options', 'term_relationships', 'term_taxonomy', 'terms' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown WordPress table' ); }
		return $this->db->{$name};
	}
	public function now(): string {
		return gmdate( 'Y-m-d H:i:s' ); }
	public function rows( string $sql, array $arguments = array() ): array {
		$rows = $this->db->get_results( $arguments ? $this->db->prepare( $sql, ...$arguments ) : $sql, ARRAY_A );
		$this->check();
		return $rows ?: array();
	}
	public function row( string $sql, array $arguments = array() ): ?array {
		return $this->rows( $sql, $arguments )[0] ?? null; }
	public function execute( string $sql, array $arguments = array() ): int {
		$result = $this->db->query( $arguments ? $this->db->prepare( $sql, ...$arguments ) : $sql );
		$this->check();
		return (int) $result;
	}
	public function insert( string $table, array $data ): int {
		$this->db->insert( $this->table( $table ), $data );
		$this->check();
		return (int) $this->db->insert_id;
	}
	public function transaction( callable $operation ): mixed {
		self::$transactions ??= new \WeakMap();
		if ( isset( self::$transactions[ $this->db ] ) ) {
			$sequence                        = self::$transactions[ $this->db ] + 1;
			self::$transactions[ $this->db ] = $sequence;
			$savepoint                       = 'fi_nested_' . $sequence;
			$this->execute( 'SAVEPOINT ' . $savepoint );
			try {
				$result = $operation();
				$this->execute( 'RELEASE SAVEPOINT ' . $savepoint );
				return $result;
			} catch ( \Throwable $failure ) {
				$this->execute( 'ROLLBACK TO SAVEPOINT ' . $savepoint );
				$this->execute( 'RELEASE SAVEPOINT ' . $savepoint );
				throw $failure;
			}
		}
		$this->execute( 'START TRANSACTION' );
		self::$transactions[ $this->db ] = 0;
		try {
			$result = $operation();
			$this->execute( 'COMMIT' );
			return $result;
		} catch ( \Throwable $failure ) {
			$this->execute( 'ROLLBACK' );
			throw $failure;
		} finally {
			unset( self::$transactions[ $this->db ] );
		}
	}
	public function event( string $kind, int $objectId, int $actor, array $payload = array(), int $subject = 0, int $points = 0, ?string $key = null ): int {
		return $this->insert(
			'events',
			array(
				'kind'       => $kind,
				'object_id'  => $objectId,
				'actor_id'   => $actor,
				'subject_id' => $subject,
				'points'     => $points,
				'event_key'  => $key,
				'payload'    => wp_json_encode( $payload ),
				'created_at' => $this->now(),
			)
		);
	}
	public function metric( string $kind, int $amount = 1 ): void {
		$this->execute( "INSERT INTO {$this->table('metrics')} (day,kind,count) VALUES (%s,%s,%d) ON DUPLICATE KEY UPDATE count=count+VALUES(count)", array( gmdate( 'Y-m-d' ), $kind, max( 0, $amount ) ) );
	}
	public function limit( int $user, string $feature, int $maximum = 30, int $seconds = 60 ): void {
		$bucket = hash( 'sha256', $user . ':' . $feature );
		$window = intdiv( time(), $seconds ) * $seconds;
		$this->execute( "INSERT INTO {$this->table('limits')} (bucket,window_start,count) VALUES (%s,%d,1) ON DUPLICATE KEY UPDATE count=count+1", array( $bucket, $window ) );
		$row = $this->row( "SELECT count FROM {$this->table('limits')} WHERE bucket=%s AND window_start=%d", array( $bucket, $window ) );
		if ( (int) $row['count'] > $maximum ) {
			throw new Failure( 'rate_limit', __( 'Please wait before trying again.', 'forum-intelligence-for-bbpress' ), 429 ); }
	}
	public function record( string $key ): ?array {
		$row = $this->row( "SELECT * FROM {$this->table('records')} WHERE record_key=%s", array( $key ) );
		if ( $row ) {
			$row['data'] = json_decode( $row['payload'], true ) ?: array();
		} return $row;
	}
	public function saveRecord( string $kind, string $key, int $owner, int $objectId, array $data ): void {
		$this->execute( "INSERT INTO {$this->table('records')} (kind,record_key,owner_id,object_id,payload,created_at,updated_at) VALUES (%s,%s,%d,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=version+1,updated_at=VALUES(updated_at)", array( $kind, $key, $owner, $objectId, wp_json_encode( $data ), $this->now(), $this->now() ) );
	}
	private function check(): void {
		if ( $this->db->last_error !== '' ) {
			throw new Failure( 'storage_error', __( 'The operation could not be saved. Please retry.', 'forum-intelligence-for-bbpress' ), 503 ); }
	}
}
