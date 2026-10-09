<?php
declare(strict_types=1);
namespace ForumIntelligence\Privacy;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Storage\ProtectedLocalStorage;
use ForumIntelligence\Domain\Failure;
/** Remove only referenced plugin-owned protected bytes before dropping their database references. */
final class Uninstall {
	public function __construct( private Store $store ) {}
	public function protectedFiles(): void {
		global $wpdb;
		if ( ! $this->store->row( 'SHOW TABLES LIKE %s', array( $wpdb->esc_like( $this->store->table( 'records' ) ) ) ) ) {
			return;}
		$cursor  = 0;
		$storage = null;
		do {
			$rows = $this->store->rows( "SELECT id,payload FROM {$this->store->table('records')} WHERE kind='attachment' AND id>%d ORDER BY id LIMIT 100", array( $cursor ) );
			foreach ( $rows as $row ) {
				$cursor = (int) $row['id'];
				$data   = json_decode( $row['payload'], true );
				if ( empty( $data['protected'] ) ) {
					continue;}
				$key = (string) ( $data['key'] ?? '' );
				if ( ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
					throw new Failure( 'unsafe_storage_reference', __( 'A protected file reference is invalid. Restore or repair storage before removing plugin data.', 'forum-intelligence-for-bbpress' ), 503 );
				}
				$storage ??= new ProtectedLocalStorage( defined( 'FI_PROTECTED_STORAGE_DIR' ) ? (string) constant( 'FI_PROTECTED_STORAGE_DIR' ) : '' );
				try {
					$storage->delete( $key );
				} catch ( Failure $failure ) {
					if ( $failure->reason !== 'not_found' ) {
						throw $failure;}
				}
				$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE id=%d AND kind='attachment'", array( $cursor ) );
			}
		} while ( count( $rows ) === 100 );
	}
	/** Bounded deletion also invalidates WordPress's shared metadata cache per affected object. */
	public function metadata(): void {
		global $wpdb;
		$this->removeMetadata( 'postmeta', 'meta_id', 'post_id', 'post_meta', 'meta_key LIKE %s', array( $wpdb->esc_like( '_fi_' ) . '%' ) );
		$this->removeMetadata( 'usermeta', 'umeta_id', 'user_id', 'user_meta', 'meta_key IN (%s,%s)', array( \ForumIntelligence\Domain\Notifications\Notifications::metaKey(), \ForumIntelligence\Domain\Reputation\Badges::preferenceKey() ) );
	}
	private function removeMetadata( string $table, string $id, string $owner, string $cacheGroup, string $where, array $arguments ): void {
		$sqlTable = $this->store->wordpressTable( $table );
		do {
			$rows = $this->store->rows( "SELECT $id AS id,$owner AS owner FROM $sqlTable WHERE $where ORDER BY $id LIMIT 500", $arguments );
			if ( ! $rows ) {
				break;}
			$ids          = array_map( 'intval', array_column( $rows, 'id' ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$this->store->execute( "DELETE FROM $sqlTable WHERE $id IN ($placeholders)", $ids );
			foreach ( array_unique( array_map( 'intval', array_column( $rows, 'owner' ) ) ) as $objectId ) {
				wp_cache_delete( $objectId, $cacheGroup );}
		} while ( count( $rows ) === 500 );
	}
}
