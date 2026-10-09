<?php
declare(strict_types=1);
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
require_once __DIR__ . '/autoload.php';
$cleanup = static function (): void {
	$settings = (array) get_option( 'fi_settings', array() );
	wp_clear_scheduled_hook( 'fi_queue_tick' );
	wp_clear_scheduled_hook( 'fi_queue_soon' );
	wp_clear_scheduled_hook( 'fi_retention_tick' );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return; }
	( new \ForumIntelligence\Privacy\Uninstall( new \ForumIntelligence\Infrastructure\Database\Store() ) )->protectedFiles();
	global $wpdb;
	foreach ( \ForumIntelligence\Infrastructure\Database\Schema::TABLES as $suffix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Central SQL boundary uses prepared values and whitelisted identifiers; transactional/current-permission data cannot use a shared cache.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'fi_' . $suffix ) ); }
	foreach ( array( 'fi_settings', 'fi_schema_version', 'fi_field_definitions', 'fi_templates', 'fi_template_variants', 'fi_last_queue_tick', 'fi_vector_cache_upgrade' ) as $option ) {
		delete_option( $option ); }
	( new \ForumIntelligence\Privacy\Uninstall( new \ForumIntelligence\Infrastructure\Database\Store() ) )->metadata();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Central SQL boundary uses prepared values and whitelisted identifiers; transactional/current-permission data cannot use a shared cache.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_fi\\_%' OR option_name LIKE '\\_transient\\_timeout\\_fi\\_%'" );
	// Editorial knowledge articles and public media remain with WordPress.
};
if ( is_multisite() ) {
	$cursor = 0;
	do {
		$sites = get_sites(
			array(
				'number' => 100,
				'offset' => $cursor,
				'fields' => 'ids',
			)
		);
		foreach ( $sites as $id ) {
			switch_to_blog( (int) $id );
			try {
				$cleanup();
			} finally {
				restore_current_blog(); }
		}
		$cursor += count( $sites );
	} while ( count( $sites ) === 100 );
	delete_site_option( 'fi_network_enabled' );
} else {
	$cleanup(); }
