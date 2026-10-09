<?php
declare(strict_types=1);
namespace ForumIntelligence\Bootstrap;
use ForumIntelligence\Infrastructure\Database\Schema;
final class Lifecycle {
	public static function activate( bool $network = false ): void {
		if ( $network && is_multisite() ) {
			update_site_option( 'fi_network_enabled', true ); }
		// Large networks provision each site lazily on init; activation does not block on all sites.
		( new Schema() )->install();
		if ( ! wp_next_scheduled( 'fi_queue_tick' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'fi_queue_tick' ); }
		if ( ! wp_next_scheduled( 'fi_retention_tick' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'fi_retention_tick' ); }
	}
	public static function deactivate( bool $network = false ): void {
		$clear = static function (): void {
			foreach ( array( 'fi_queue_soon', 'fi_queue_tick', 'fi_retention_tick' ) as $hook ) {
				wp_clear_scheduled_hook( $hook );
			} };
		if ( $network && is_multisite() ) {
			$offset = 0;
			do {
				$sites = get_sites(
					array(
						'fields' => 'ids',
						'number' => 100,
						'offset' => $offset,
					)
				);
				foreach ( $sites as $id ) {
					switch_to_blog( (int) $id );
					try {
						$clear();
					} finally {
						restore_current_blog(); }
				}
				$offset += count( $sites );
			} while ( count( $sites ) === 100 );
			delete_site_option( 'fi_network_enabled' );
		} else {
			$clear(); }
	}
}
