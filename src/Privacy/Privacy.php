<?php
declare(strict_types=1);
namespace ForumIntelligence\Privacy;
use ForumIntelligence\Infrastructure\Database\Store;
final class Privacy {
	public function __construct( private Store $store ) {}
	public function register(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( array $exporters ): array {
				$exporters['forum-intelligence'] = array(
					'exporter_friendly_name' => __( 'Forum Intelligence', 'forum-intelligence-for-bbpress' ),
					'callback'               => array( $this, 'export' ),
				);
				return $exporters;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( array $erasers ): array {
				$erasers['forum-intelligence'] = array(
					'eraser_friendly_name' => __( 'Forum Intelligence', 'forum-intelligence-for-bbpress' ),
					'callback'             => array( $this, 'erase' ),
				);
				return $erasers;
			}
		);
		add_action(
			'admin_init',
			function (): void {
				if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
					wp_add_privacy_policy_content( 'Forum Intelligence', wpautop( __( 'Community interactions, follows, drafts, preferences and messages are stored on this site. Search analytics store aggregate counts rather than raw questions. Optional external AI sends redacted questions and public discussion excerpts to the configured provider. Restricted discussions are excluded. Public media URLs remain public even if a discussion later becomes restricted. Optional push stores browser subscriptions and sends generic community activity notifications through the browser vendor. Retention is controlled by the site administrator. Optional encrypted composer copies are stored on your device, separately from site drafts; the plugin does not store a passphrase or key, or send them to this site. Recovery expires after 24 hours, requires a current online permission check and sends decrypted text to this site for sanitization and possible normal draft autosave. Remove device copies in the composer or browser storage; site export, erasure and uninstall cannot remotely delete them. Composer previews do not publish or contact an AI provider.', 'forum-intelligence-for-bbpress' ) ) );
				}
			}
		);
		add_action( 'fi_retention_tick', array( $this, 'retain' ) );
	}
	public function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			); }
		$items    = array();
		$offset   = ( max( 1, $page ) - 1 ) * 100;
		$datasets = array(
			'records'       => array( 'owner_id', 'id' ),
			'signals'       => array( 'user_id', 'id' ),
			'links'         => array( 'user_id', 'id' ),
			'notifications' => array( 'user_id', 'id' ),
			'cases'         => array( 'reporter_id', 'id' ),
			'events'        => array( 'actor_id', 'id' ),
		);
		$done     = true;
		foreach ( $datasets as $table => [$column, $order] ) {
			$where     = $table === 'links' ? "(user_id=%d OR (kind='relationship' AND CASE WHEN JSON_VALID(payload) THEN JSON_UNQUOTE(JSON_EXTRACT(payload,'$.creator')) ELSE NULL END=%s))" : "$column=%d";
			$arguments = $table === 'links' ? array( $user->ID, (string) $user->ID, $offset ) : array( $user->ID, $offset );
			$rows      = $this->store->rows( "SELECT * FROM {$this->store->table($table)} WHERE $where ORDER BY $order LIMIT 100 OFFSET %d", $arguments );
			if ( count( $rows ) === 100 ) {
				$done = false; }
			foreach ( $rows as $row ) {
				$data = array();
				foreach ( $row as $key => $value ) {
					if ( $key === 'payload' ) {
						$payload = json_decode( (string) $value, true ) ?: array();
						if ( $table === 'records' && ( $row['kind'] ?? '' ) === 'push_subscription' ) {
							try {
								$device  = isset( $payload['sealed'] ) ? \ForumIntelligence\Infrastructure\Storage\Secrets::open( $payload['sealed'] ) : $payload;
								$payload = array( 'endpoint' => $device['endpoint'] ?? '' ); } catch ( \Throwable $failure ) {
								$payload = array( 'status' => __( 'Subscription credentials expired.', 'forum-intelligence-for-bbpress' ) ); }
						}
						// A storage key is operational, not portable personal data.
						unset( $payload['key'] );
						$value = wp_json_encode( $payload );
					}
					if ( $key === 'lease_token' || $key === 'event_key' || $key === 'record_key' ) {
						continue; }
					$data[] = array(
						'name'  => $key,
						'value' => (string) $value,
					);
				}
				$items[] = array(
					'group_id'    => 'forum-intelligence',
					'group_label' => __( 'Community participation', 'forum-intelligence-for-bbpress' ),
					'item_id'     => 'fi-' . $table . '-' . $row['id'],
					'data'        => $data,
				);
			}
		}
		if ( $page === 1 ) {
			$items[] = array(
				'group_id'    => 'forum-intelligence',
				'group_label' => __( 'Community participation', 'forum-intelligence-for-bbpress' ),
				'item_id'     => 'fi-preferences-' . $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'Preferences', 'forum-intelligence-for-bbpress' ),
						'value' => wp_json_encode( get_user_meta( $user->ID, \ForumIntelligence\Domain\Notifications\Notifications::metaKey(), true ) ),
					),
					array(
						'name'  => __( 'Achievements enabled', 'forum-intelligence-for-bbpress' ),
						'value' => get_user_meta( $user->ID, \ForumIntelligence\Domain\Reputation\Badges::preferenceKey(), true ) ? '0' : '1',
					),

				),
			); }
		return array(
			'data' => $items,
			'done' => $done,
		);
	}
	public function erase( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			); }
		$id = (int) $user->ID;

		$initialRemoved = $this->store->transaction(
			function () use ( $id ): int {
				$this->store->row( "SELECT ID FROM {$this->store->wordpressTable('users')} WHERE ID=%d FOR UPDATE", array( $id ) );
				update_user_meta( $id, \ForumIntelligence\Domain\Reputation\Badges::preferenceKey(), true );
				// Cancel owned queries before notification erasure. A running consumer holds
				// its query row until delivery commits; this delete waits, then the
				// subsequent notification batch removes that completed delivery.
				return $this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE owner_id=%d AND kind IN ('badge','saved_search','saved_search_lock')", array( $id ) );
			}
		);

		$removed       = $initialRemoved > 0;
		$done          = true;
		$retainedFiles = false;
		// Lock and delete exactly this batch, keeping reputation reversals in the same transaction.
		$n       = $this->store->transaction(
			function () use ( $id ): int {
				$signals = $this->store->rows( "SELECT id,object_id,kind FROM {$this->store->table('signals')} WHERE user_id=%d ORDER BY id LIMIT 100 FOR UPDATE", array( $id ) );
				$credits = new \ForumIntelligence\Domain\Reputation\Credits( $this->store );
				foreach ( $signals as $signal ) {
					if ( in_array( $signal['kind'], array( 'vote', 'confirm' ), true ) ) {
						$object = (int) $signal['object_id'];
						$credits->set( 'signal_reputation', $object, $id, (int) get_post_field( 'post_author', $object ), 0, $signal['kind'] . ':' . $object . ':' . $id, array( 'reason' => 'signal_erased' ) );
					}
					$this->store->execute( "DELETE FROM {$this->store->table('signals')} WHERE id=%d AND user_id=%d", array( $signal['id'], $id ) );
				}
				return count( $signals );
			}
		);
		$removed = $removed || $n > 0;
		$done    = $n < 100;
		foreach ( array( 'links', 'notifications' ) as $table ) {
			$n       = $this->store->execute( "DELETE FROM {$this->store->table($table)} WHERE user_id=%d LIMIT 100", array( $id ) );
			$removed = $removed || $n > 0;
			$done    = $done && $n < 100;
		}
		$records = $this->store->rows( "SELECT * FROM {$this->store->table('records')} WHERE owner_id=%d ORDER BY id LIMIT 100", array( $id ) );
		foreach ( $records as $record ) {
			if ( $record['kind'] === 'poll_vote' ) {
				( new \ForumIntelligence\Domain\Polls\Polls( $this->store, new \ForumIntelligence\Integration\BbPress\Access() ) )->eraseVote( (int) $record['object_id'], $id ); } elseif ( $record['kind'] === 'conversation' ) {
				$this->store->execute( "UPDATE {$this->store->table('records')} SET owner_id=0,payload='{}' WHERE id=%d", array( $record['id'] ) );
				} elseif ( $record['kind'] === 'message' ) {
					$this->store->execute( "UPDATE {$this->store->table('records')} SET owner_id=0,payload=%s WHERE id=%d", array( wp_json_encode( array( 'text' => __( 'Message removed by its author.', 'forum-intelligence-for-bbpress' ) ) ), $record['id'] ) );
				} elseif ( $record['kind'] === 'attachment' ) {
					$data = json_decode( $record['payload'], true );
					try {
						$storage = ! empty( $data['protected'] ) ? new \ForumIntelligence\Infrastructure\Storage\ProtectedLocalStorage( defined( 'FI_PROTECTED_STORAGE_DIR' ) ? (string) constant( 'FI_PROTECTED_STORAGE_DIR' ) : '' ) : new \ForumIntelligence\Infrastructure\Storage\PublicMediaStorage();
						$storage->delete( (string) $data['key'] );
						$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE id=%d", array( $record['id'] ) );
					} catch ( \Throwable $failure ) {
						// Keep a recoverable reference for operators if original storage is unavailable.
						$this->store->execute( "UPDATE {$this->store->table('records')} SET owner_id=0 WHERE id=%d", array( $record['id'] ) );
						$retainedFiles = true;
					}
				} else {
					$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE id=%d", array( $record['id'] ) ); }
				$removed = true;
		}
		$done = $done && count( $records ) < 100;
		$this->store->execute( "UPDATE {$this->store->table('cases')} SET reporter_id=NULL,notes='' WHERE reporter_id=%d LIMIT 100", array( $id ) );
		$this->store->execute( "UPDATE {$this->store->table('events')} SET actor_id=0,subject_id=IF(subject_id=%d,0,subject_id),payload='{}' WHERE actor_id=%d OR subject_id=%d LIMIT 100", array( $id, $id, $id ) );
		$this->store->execute( "UPDATE {$this->store->table('solutions')} SET actor_id=0 WHERE actor_id=%d LIMIT 100", array( $id ) );
		// Reports are retained only for moderation; remove the erased sender's copied evidence.
		$reports = $this->store->rows( "SELECT id,payload FROM {$this->store->table('records')} WHERE kind='message_report' AND JSON_EXTRACT(payload,'$.sender')=%d LIMIT 100", array( $id ) );
		foreach ( $reports as $report ) {
			$data             = json_decode( $report['payload'], true );
			$data['sender']   = 0;
			$data['evidence'] = __( 'Message removed by its author.', 'forum-intelligence-for-bbpress' );
			$this->store->execute( "UPDATE {$this->store->table('records')} SET payload=%s WHERE id=%d", array( wp_json_encode( $data ), $report['id'] ) );}
		if ( count( $reports ) === 100 ) {
			$done = false;}
		// Retained moderation evidence must not retain the erased requester's identity.
		$advisories = $this->store->rows( "SELECT id FROM {$this->store->table('records')} WHERE kind='moderation_advisory' AND JSON_EXTRACT(payload,'$.requested_by')=%d ORDER BY id LIMIT 100", array( $id ) );
		foreach ( $advisories as $advisory ) {
			$this->store->execute( "UPDATE {$this->store->table('records')} SET payload=JSON_SET(payload,'$.requested_by',0) WHERE id=%d", array( $advisory['id'] ) );
		}
		$done = $done && count( $advisories ) < 100;
		// Human-approved edges remain, with their erased creator's personal reference removed.
		$relationships = $this->store->rows( "SELECT id FROM {$this->store->table('links')} WHERE kind='relationship' AND CASE WHEN JSON_VALID(payload) THEN JSON_UNQUOTE(JSON_EXTRACT(payload,'$.creator')) ELSE NULL END=%s ORDER BY id LIMIT 100", array( (string) $id ) );
		foreach ( $relationships as $relationship ) {
			$this->store->execute( "UPDATE {$this->store->table('links')} SET payload=JSON_SET(payload,'$.creator',0) WHERE id=%d AND kind='relationship'", array( $relationship['id'] ) );
		}
		$done = $done && count( $relationships ) < 100;
		delete_user_meta( $id, \ForumIntelligence\Domain\Notifications\Notifications::metaKey() );
		foreach ( array(
			'cases'     => 'reporter_id',
			'events'    => 'actor_id',
			'solutions' => 'actor_id',
		) as $table => $column ) {
			if ( $this->store->row( "SELECT $column FROM {$this->store->table($table)} WHERE $column=%d LIMIT 1", array( $id ) ) ) {
				$done = false; }
		}
		if ( $this->store->row( "SELECT id FROM {$this->store->table('events')} WHERE subject_id=%d LIMIT 1", array( $id ) ) ) {
			$done = false; }
		return array(
			'items_removed'  => $removed,
			'items_retained' => true,
			'messages'       => array( __( 'Forum content and editorial articles require their respective content retention workflows. Attachment files were removed where the original storage was available; unavailable storage requires operator cleanup. Operational audit event types and timestamps are retained with user references removed.', 'forum-intelligence-for-bbpress' ) ),
			'done'           => $done,
		);
	}
	public function retain(): void {
		$days   = min( 3650, max( 7, (int) ( get_option( 'fi_settings', array() )['retention_days'] ?? 90 ) ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * 86400 );
		foreach ( array( 'notifications', 'events', 'cases' ) as $table ) {
			$column = $table === 'cases' ? 'updated_at' : 'created_at';
			$extra  = $table === 'cases' ? " AND status IN ('actioned','dismissed')" : ( $table === 'events' ? ' AND points=0' : '' );
			$this->store->execute( "DELETE FROM {$this->store->table($table)} WHERE $column<%s $extra LIMIT 1000", array( $cutoff ) );
		}
		$this->store->execute( "DELETE FROM {$this->store->table('jobs')} WHERE status IN ('completed','dead') AND updated_at<%s LIMIT 1000", array( $cutoff ) );
		$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE kind IN ('draft','message','message_report','push_subscription','ai_feedback','moderation_advisory') AND updated_at<%s LIMIT 1000", array( $cutoff ) );
		$this->store->execute( "DELETE FROM {$this->store->table('limits')} WHERE window_start<%d LIMIT 1000", array( time() - 2 * 86400 ) );
		$this->store->execute( "DELETE FROM {$this->store->table('metrics')} WHERE day<%s LIMIT 1000", array( substr( $cutoff, 0, 10 ) ) );
	}
}
