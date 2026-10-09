<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Relationships;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
final class Relationships {
	public const TYPES = array( 'duplicate_of', 'related_to', 'solved_by', 'supersedes', 'prerequisite_for', 'caused_by', 'alternative_to', 'documentation_for', 'successor_of', 'product_related' );
	public function __construct( private Store $store, private Access $access ) {}
	public function change( int $source, int $target, string $type, string $status, int $user ): array {
		$this->access->moderate( $user );
		$this->access->read( $source, $user );
		$this->access->read( $target, $user );
		if ( $source === $target || get_post_type( $source ) !== bbp_get_topic_post_type() || get_post_type( $target ) !== bbp_get_topic_post_type() || ! in_array( $type, self::TYPES, true ) || ! in_array( $status, array( 'approved', 'suggested', 'rejected' ), true ) ) {
			throw new Failure( 'invalid_relationship', __( 'Invalid topic relationship.', 'forum-intelligence-for-bbpress' ) );
		}
		if ( $type === 'related_to' || $type === 'alternative_to' ) {
			[$source, $target] = array( min( $source, $target ), max( $source, $target ) ); }
		return $this->store->transaction(
			function () use ( $source, $target, $type, $status, $user ): array {
				$this->admit( $source, $target, $user );
				$this->store->execute(
					"INSERT INTO {$this->store->table('links')} (user_id,source_id,target_id,kind,label,payload,created_at) VALUES (0,%d,%d,'relationship',%s,%s,%s) ON DUPLICATE KEY UPDATE payload=VALUES(payload)",
					array(
						$source,
						$target,
						$type,
						wp_json_encode(
							array(
								'status'     => $status,
								'creator'    => $user,
								'confidence' => 1,
								'provenance' => 'manual',
							)
						),

						$this->store->now(),
					)
				);
				$this->admit( $source, $target, $user );
				$this->store->event(
					'relationship_changed',
					$source,
					$user,
					array(
						'target' => $target,
						'type'   => $type,
						'status' => $status,
					)
				);
				$this->admit( $source, $target, $user );
				return array( 'saved' => true );
			}
		);
	}
	/** @phpstan-impure Renew native type, actor and source rights across storage callbacks. */
	private function admit( int $source, int $target, int $user ): void {
		$this->access->moderate( $user );
		$this->access->read( $source, $user );
		$this->access->read( $target, $user );
		if ( get_post_type( $source ) !== bbp_get_topic_post_type() || get_post_type( $target ) !== bbp_get_topic_post_type() ) {
			throw new Failure( 'invalid_relationship', __( 'Invalid topic relationship.', 'forum-intelligence-for-bbpress' ) );
		}
	}
	public function listing( int $source, int $user ): array {
		$this->access->read( $source, $user );
		$rows  = $this->store->rows( "SELECT * FROM {$this->store->table('links')} WHERE kind='relationship' AND (source_id=%d OR target_id=%d) ORDER BY id DESC LIMIT 50", array( $source, $source ) );
		$items = array();
		foreach ( $rows as $row ) {
			$data  = json_decode( $row['payload'], true ) ?: array();
			$other = (int) $row['source_id'] === $source ? (int) $row['target_id'] : (int) $row['source_id'];
			if ( ! is_array( $data ) || ( $data['status'] ?? '' ) !== 'approved' || ! in_array( $row['label'], self::TYPES, true ) || get_post_type( $other ) !== bbp_get_topic_post_type() || ! $this->access->canRead( $other, $user ) ) {
				continue; }
			$items[] = array(
				'id'        => $other,
				'type'      => $row['label'],
				'direction' => (int) $row['source_id'] === $source ? 'outgoing' : 'incoming',
				'title'     => wp_strip_all_tags( (string) get_post_field( 'post_title', $other ) ),
				'url'       => get_permalink( $other ),
			);
		}
		return $items;
	}
}
