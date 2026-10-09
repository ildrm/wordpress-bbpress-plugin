<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Notifications;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Queue\Queue;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Community\Following;
use ForumIntelligence\Domain\Failure;
/** Browser-vendor endpoints are credentials and must never be logged. */
final class Push {
	public function __construct( private Store $store, private Queue $queue, private Access $access, private Following $following ) {}
	public function available(): bool {
		return class_exists( \ForumIntelligence\Infrastructure\Push\Runtime::className( 'WebPush' ) ) && defined( 'FI_VAPID_PRIVATE_KEY' ) && defined( 'FI_VAPID_PUBLIC_KEY' ) && defined( 'FI_VAPID_SUBJECT' );
	}
	public function register(): void {
		$this->queue->handle( 'push', fn( array $data )=>$this->send( $data ) );
		add_action(
			'fi_notification_created',
			function ( int $user, int $objectId, string $kind, string $key ): void {
				if ( $this->available() ) {
					$this->queue->enqueue(
						'push',
						array(
							'user'   => $user,
							'object' => $objectId,
							'kind'   => $kind,
							'key'    => hash( 'sha256', $user . ':' . $key ),
						),
						hash( 'sha256', $user . ':' . $key )
					); }
			},
			10,
			4
		);
	}
	public function subscribe( int $user, array $subscription ): array {
		$this->requireUser( $user );
		if ( ! $this->available() ) {
			throw new Failure( 'push_unavailable', __( 'Push requires the WebPush library and server VAPID configuration.', 'forum-intelligence-for-bbpress' ), 503 ); }
		if ( ! is_string( $subscription['endpoint'] ?? null ) ) {
			throw new Failure( 'invalid_subscription', __( 'Invalid browser subscription.', 'forum-intelligence-for-bbpress' ) ); }
		$endpoint = $subscription['endpoint'];
		$this->validateEndpoint( $endpoint );
		$keys = $subscription['keys'] ?? array();
		if ( ! is_array( $keys ) || ! is_string( $keys['p256dh'] ?? null ) || ! is_string( $keys['auth'] ?? null ) || ! $this->validKey( $keys['p256dh'], 65 ) || ! $this->validKey( $keys['auth'], 16 ) ) {
			throw new Failure( 'invalid_subscription', __( 'Invalid browser subscription.', 'forum-intelligence-for-bbpress' ) ); }
		$encoding = (string) ( $subscription['contentEncoding'] ?? 'aes128gcm' );
		if ( ! in_array( $encoding, array( 'aes128gcm', 'aesgcm' ), true ) ) {
			throw new Failure( 'invalid_subscription', __( 'Unsupported push encoding.', 'forum-intelligence-for-bbpress' ) ); }
		$this->store->limit( $user, 'push_subscription', 10, 3600 );
		$key   = 'push:' . $user . ':' . hash( 'sha256', $endpoint );
		$count = $this->store->row( "SELECT COUNT(*) AS n FROM {$this->store->table('records')} WHERE kind='push_subscription' AND owner_id=%d", array( $user ) );
		if ( (int) $count['n'] >= 5 && ! $this->store->record( $key ) ) {
			throw new Failure( 'device_limit', __( 'Remove a device before adding another.', 'forum-intelligence-for-bbpress' ) ); }
		$this->store->saveRecord(
			'push_subscription',
			$key,
			$user,
			0,
			array(
				'sealed' => \ForumIntelligence\Infrastructure\Storage\Secrets::seal(
					array(
						'endpoint'        => $endpoint,
						'keys'            => $keys,
						'contentEncoding' => $encoding,
						'opted_in'        => true,
					)
				),
			)
		);
		return array( 'subscribed' => true );
	}
	public function unsubscribe( int $user, string $endpoint = '' ): array {
		$this->requireUser( $user );
		if ( $endpoint === '' ) {
			$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE kind='push_subscription' AND owner_id=%d", array( $user ) ); } else {
			$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE kind='push_subscription' AND owner_id=%d AND record_key=%s", array( $user, 'push:' . $user . ':' . hash( 'sha256', $endpoint ) ) ); }
			return array( 'unsubscribed' => true );
	}
	public function send( array $data ): void {
		if ( ! $this->available() ) {
			throw new Failure( 'push_unavailable', 'Push unavailable', 503 ); }
		$user     = (int) $data['user'];
		$objectId = (int) $data['object'];
		if ( ! get_userdata( $user ) || bbp_is_user_inactive( $user ) || ! ( new Visibility( $this->store, $this->access, $this->following ) )->allows( $user, $objectId, (string) $data['kind'] ) ) {
			return; }
		$preferences = (array) get_user_meta( $user, Notifications::metaKey(), true );
		if ( in_array( $data['kind'], $preferences['muted_categories'] ?? array(), true ) ) {
			return; }
		$rows              = $this->store->rows( "SELECT id,payload FROM {$this->store->table('records')} WHERE kind='push_subscription' AND owner_id=%d AND id>%d ORDER BY id LIMIT 2", array( $user, (int) ( $data['cursor'] ?? 0 ) ) );
		$class             = \ForumIntelligence\Infrastructure\Push\Runtime::className( 'WebPush' );
		$subscriptionClass = \ForumIntelligence\Infrastructure\Push\Runtime::className( 'Subscription' );
		$sender            = new $class(
			array(
				'VAPID' => array(
					'subject'    => (string) constant( 'FI_VAPID_SUBJECT' ),
					'publicKey'  => (string) constant( 'FI_VAPID_PUBLIC_KEY' ),
					'privateKey' => (string) constant( 'FI_VAPID_PRIVATE_KEY' ),
				),
			),
			array( 'TTL' => 3600 ),
			15,
			apply_filters(
				'fi_push_client_options',
				array(
					'allow_redirects' => false,
					'timeout'         => 15,
				)
			)
		);
		foreach ( array_slice( $rows, 0, 1 ) as $row ) {
			$stored = json_decode( $row['payload'], true );
			try {
				$subscription = isset( $stored['sealed'] ) ? \ForumIntelligence\Infrastructure\Storage\Secrets::open( $stored['sealed'] ) : $stored; } catch ( Failure $failure ) {
				$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE id=%d AND owner_id=%d", array( $row['id'], $user ) );
				continue; }
				if ( empty( $subscription['opted_in'] ) ) {
					continue; }
				try {
					$this->validateEndpoint( (string) $subscription['endpoint'] );
					// Recheck immediately before transport; payload contains no source title, text or URL.
					if ( ! ( new Visibility( $this->store, $this->access, $this->following ) )->allows( $user, $objectId, (string) $data['kind'] ) ) {
						return; }
					$report = $sender->sendOneNotification(
						$subscriptionClass::create( $subscription ),
						wp_json_encode(
							array(
								'title' => __( 'Community update', 'forum-intelligence-for-bbpress' ),
								'body'  => __( 'Sign in to view your notifications.', 'forum-intelligence-for-bbpress' ),
							)
						)
					);
					if ( $report->isSubscriptionExpired() ) {
						$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE id=%d AND owner_id=%d", array( $row['id'], $user ) ); } elseif ( ! $report->isSuccess() ) {
						throw new Failure( 'push_delivery_failed', 'Push delivery failed', 503 ); } else {
							$this->store->metric( 'push_delivered' ); }
				} catch ( Failure $failure ) {
					throw $failure; } catch ( \Throwable $failure ) {
					throw new Failure( 'push_delivery_failed', 'Push delivery failed', 503 ); }
		}
		if ( count( $rows ) === 2 ) {
			$data['cursor'] = (int) $rows[0]['id'];
			$this->queue->enqueue( 'push', $data, ( $data['key'] ?? hash( 'sha256', $user . ':' . $objectId . ':' . $data['kind'] ) ) . ':' . $data['cursor'] ); }
	}
	public function validateEndpoint( string $endpoint ): void {
		$parts   = wp_parse_url( $endpoint );
		$host    = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		$allowed = $host === 'fcm.googleapis.com' || $host === 'updates.push.services.mozilla.com' || $host === 'web.push.apple.com' || ( str_ends_with( $host, '.notify.windows.com' ) && $host !== '.notify.windows.com' );
		if ( strlen( $endpoint ) > 2048 || ! $allowed || ( $parts['scheme'] ?? '' ) !== 'https' || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && (int) $parts['port'] !== 443 ) || ! wp_http_validate_url( $endpoint ) ) {
			throw new Failure( 'invalid_push_endpoint', __( 'The push endpoint is not an approved browser service.', 'forum-intelligence-for-bbpress' ) ); }
	}
	private function validKey( string $key, int $bytes ): bool {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+={0,2}$/D', $key ) ) {
			return false; }
		$decoded = base64_decode( strtr( $key, '-_', '+/' ), true );
		return $decoded !== false && strlen( $decoded ) === $bytes;
	}
	private function requireUser( int $user ): void {
		if ( ! $user || ! user_can( $user, 'publish_replies' ) || bbp_is_user_inactive( $user ) ) {
			throw new Failure( 'forbidden', __( 'Sign in with participation permission.', 'forum-intelligence-for-bbpress' ), 403 ); }
	}
}
