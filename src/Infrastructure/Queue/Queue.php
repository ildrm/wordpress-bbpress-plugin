<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Queue;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Domain\Failure;
final class Queue {
	private array $handlers = array();
	public function __construct( private Store $store ) {}
	public function handle( string $kind, callable $handler ): void {
		$this->handlers[ $kind ] = $handler; }
	public function enqueue( string $kind, array $payload, string $key, int $delay = 0 ): void {
		if ( ! isset( $this->handlers[ $kind ] ) ) {
			throw new \InvalidArgumentException( 'Unknown job kind' ); }
		$this->store->execute( "INSERT INTO {$this->store->table('jobs')} (kind,job_key,payload,status,available_at,updated_at) VALUES (%s,%s,%s,'queued',%s,%s) ON DUPLICATE KEY UPDATE payload=VALUES(payload),revision=revision+1,status=IF(status='running','running','queued'),available_at=VALUES(available_at),attempts=0,updated_at=VALUES(updated_at)", array( $kind, substr( $kind . ':' . $key, 0, 191 ), wp_json_encode( $payload ), gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay ) ), $this->store->now() ) );
		if ( ! wp_next_scheduled( 'fi_queue_soon' ) ) {
			wp_schedule_single_event( time() + 60, 'fi_queue_soon' ); }
	}
	public function run( int $maximum = 20 ): array {
		$done    = 0;
		$failed  = 0;
		$started = microtime( true );
		$table   = $this->store->table( 'jobs' );
		for ( $i = 0;$i < min( 100, max( 1, $maximum ) ) && microtime( true ) - $started < 20;$i++ ) {
			$job = $this->store->transaction(
				function () use ( $table ): ?array {
					$job = $this->store->row( "SELECT * FROM $table WHERE ((status IN ('queued','retrying') AND available_at<=%s) OR (status='running' AND lease_until<%s)) AND attempts<5 ORDER BY available_at,id LIMIT 1 FOR UPDATE", array( $this->store->now(), $this->store->now() ) );
					if ( ! $job ) {
						return null; }
					$token = bin2hex( random_bytes( 16 ) );
					$this->store->execute( "UPDATE $table SET status='running',attempts=attempts+1,lease_token=%s,lease_until=%s,updated_at=%s WHERE id=%d", array( $token, gmdate( 'Y-m-d H:i:s', time() + 180 ), $this->store->now(), $job['id'] ) );
					$job['lease_token'] = $token;
					$job['attempts']    = (int) $job['attempts'] + 1;
					return $job;
				}
			);
			if ( ! $job ) {
				break; }
			try {
				if ( ! isset( $this->handlers[ $job['kind'] ] ) ) {
					throw new Failure( 'module_disabled', 'Module disabled', 503 ); }
				( $this->handlers[ $job['kind'] ] )( json_decode( $job['payload'], true ) ?: array() );
				$this->store->execute( "UPDATE $table SET status=IF(revision=%d,'completed','queued'),attempts=IF(revision=%d,attempts,0),lease_until=NULL,error_code='',updated_at=%s WHERE id=%d AND lease_token=%s", array( $job['revision'], $job['revision'], $this->store->now(), $job['id'], $job['lease_token'] ) );
				++$done;
			} catch ( \Throwable $failure ) {
				$deferred = $failure instanceof Failure && $failure->status === 429;
				$status   = ! $deferred && $job['attempts'] >= 5 ? 'dead' : 'retrying';
				$code     = $failure instanceof Failure ? $failure->reason : 'job_error';
				$this->store->execute( "UPDATE $table SET status=IF(revision=%d,%s,'queued'),attempts=IF(revision=%d,%d,0),error_code=IF(revision=%d,%s,''),available_at=%s,lease_until=NULL,updated_at=%s WHERE id=%d AND lease_token=%s", array( $job['revision'], $status, $job['revision'], $deferred ? max( 0, $job['attempts'] - 1 ) : $job['attempts'], $job['revision'], substr( $code, 0, 80 ), gmdate( 'Y-m-d H:i:s', time() + ( $deferred ? 86400 - intdiv( time() % 86400, 1 ) : min( 3600, 60 * ( 2 ** $job['attempts'] ) ) ) ), $this->store->now(), $job['id'], $job['lease_token'] ) );
				++$failed;
			}
		}
		$this->store->execute( "UPDATE $table SET status='dead',error_code='lease_expired' WHERE status='running' AND attempts>=5 AND lease_until<%s", array( $this->store->now() ) );
		return array(
			'completed' => $done,
			'failed'    => $failed,
		);
	}
	public function retry( int $id, int $user ): void {
		if ( ! user_can( $user, 'manage_options' ) ) {
			throw new Failure( 'forbidden', __( 'Settings permission is required.', 'forum-intelligence-for-bbpress' ), 403 ); }
		$this->store->execute( "UPDATE {$this->store->table('jobs')} SET status='queued',attempts=0,error_code='',available_at=%s WHERE id=%d AND status IN ('dead','retrying')", array( $this->store->now(), $id ) );
	}
}
