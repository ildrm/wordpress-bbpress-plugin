<?php
declare(strict_types=1);
if ( is_file( __DIR__ . '/vendor/scoper-autoload.php' ) ) {
	require_once __DIR__ . '/vendor/scoper-autoload.php';
} elseif ( is_file( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php'; }
spl_autoload_register(
	static function ( string $className ): void {
		$prefix = 'ForumIntelligence\\';
		if ( ! str_starts_with( $className, $prefix ) ) {
			return; }
		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $className, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $path ) ) {
			require_once $path; }
	}
);
