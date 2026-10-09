<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Reputation;
use ForumIntelligence\Infrastructure\Database\Store;
/** Credits are reversible; a daily gain cap is enforced atomically at award time. */
final class Credits {
	public function __construct( private Store $store ) {}
	public function set( string $kind, int $objectId, int $actor, int $subject, int $desired, string $identity, array $context = array() ): void {
		if ( ! $subject || ( $actor === $subject && $desired > 0 ) ) {
			$desired = 0; }
		$this->store->transaction(
			function () use ( $kind, $objectId, $actor, $subject, $desired, $identity, $context ): void {
				$table  = $this->store->table( 'records' );
				$day    = gmdate( 'Y-m-d' );
				$budget = 'credit_budget:' . $subject . ':' . $day;
				$key    = 'credit:' . hash( 'sha256', $identity );
				$this->store->execute( "INSERT IGNORE INTO $table (kind,owner_id,record_key,payload,created_at,updated_at) VALUES ('credit_budget',%d,%s,'{\"earned\":0}',%s,%s)", array( $subject, $budget, $this->store->now(), $this->store->now() ) );
				$row    = $this->store->row( "SELECT payload FROM $table WHERE record_key=%s FOR UPDATE", array( $budget ) );
				$earned = (int) ( json_decode( $row['payload'], true )['earned'] ?? 0 );
				$this->store->execute( "INSERT IGNORE INTO $table (kind,owner_id,object_id,record_key,payload,created_at,updated_at) VALUES ('credit',%d,%d,%s,'{\"points\":0}',%s,%s)", array( $subject, $objectId, $key, $this->store->now(), $this->store->now() ) );
				$locked = $this->store->row( "SELECT owner_id,payload FROM $table WHERE record_key=%s FOR UPDATE", array( $key ) );
				$credit = array( 'data' => json_decode( $locked['payload'], true ) );
				$before = (int) ( $credit['data']['points'] ?? 0 );
				if ( (int) $locked['owner_id'] !== $subject ) {
					if ( $before ) {
						$this->store->event( $kind, $objectId, $actor, $context + array( 'reason' => 'authorship_changed' ), (int) $locked['owner_id'], -$before ); }
					$this->store->execute( "UPDATE $table SET owner_id=%d,payload='{\"points\":0}' WHERE record_key=%s", array( $subject, $key ) );
					$before = 0;
				}
				$cap    = min( 1000, max( 1, (int) ( get_option( 'fi_settings', array() )['reputation_daily_cap'] ?? 100 ) ) );
				$target = $desired;
				if ( $target > $before ) {
					$target = $before + min( $target - $before, max( 0, $cap - $earned ) ); }
				$delta = $target - $before;
				if ( ! $delta ) {
					return; }
				$this->store->event(
					$kind,
					$objectId,
					$actor,
					$context + array(
						'desired'   => $desired,
						'credited'  => $target,
						'daily_cap' => $cap,
					),
					$subject,
					$delta
				);
				$this->store->saveRecord( 'credit', $key, $subject, $objectId, array( 'points' => $target ) );
				$this->store->execute( "UPDATE $table SET payload=%s,updated_at=%s WHERE record_key=%s", array( wp_json_encode( array( 'earned' => $earned + max( 0, $delta ) ) ), $this->store->now(), $budget ) );
			}
		);
	}
}
