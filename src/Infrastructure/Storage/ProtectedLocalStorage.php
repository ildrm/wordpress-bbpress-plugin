<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Storage;
use ForumIntelligence\Contracts\AttachmentStorage;
use ForumIntelligence\Domain\Failure;
final class ProtectedLocalStorage implements AttachmentStorage {
	private string $root;
	public function __construct( string $directory ) {
		$root = realpath( $directory );
		if ( ! $root || ! is_dir( $root ) || ! is_writable( $root ) ) {
			throw new Failure( 'storage_unavailable', __( 'Protected attachment storage is unavailable.', 'forum-intelligence-for-bbpress' ), 503 ); }
		$normalize  = static fn( string $path ): string=>strtolower( rtrim( str_replace( '\\', '/', $path ), '/' ) ) . '/';
		$knownRoots = array( ABSPATH, dirname( __DIR__, 3 ), (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' ) );
		foreach ( $knownRoots as $webRoot ) {
			if ( ! $webRoot || ! realpath( $webRoot ) ) {
				continue; }
			if ( str_starts_with( $normalize( $root ), $normalize( (string) realpath( $webRoot ) ) ) ) {
				throw new Failure( 'unsafe_storage', __( 'Protected storage must be outside all served web directories.', 'forum-intelligence-for-bbpress' ), 503 ); }
		}
		if ( ! defined( 'FI_PROTECTED_STORAGE_CONFIRMED' ) || ! constant( 'FI_PROTECTED_STORAGE_CONFIRMED' ) ) {
			throw new Failure( 'storage_unconfirmed', __( 'Confirm that the attachment directory is not served by your web server.', 'forum-intelligence-for-bbpress' ), 503 ); }
		$this->root = $root;
	}
	public function store( string $temporary, string $name ): string {
		$key  = bin2hex( random_bytes( 32 ) );
		$path = $this->root . DIRECTORY_SEPARATOR . $key;
		if ( ! is_file( $temporary ) || ! copy( $temporary, $path ) ) {
			throw new Failure( 'upload_failed', __( 'The file could not be stored.', 'forum-intelligence-for-bbpress' ), 503 ); }
		chmod( $path, 0640 );
		return $key;
	}
	public function path( string $key ): string {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
			throw new Failure( 'not_found', __( 'Attachment unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
		$path = realpath( $this->root . DIRECTORY_SEPARATOR . $key );
		if ( ! $path || dirname( $path ) !== $this->root || ! is_file( $path ) ) {
			throw new Failure( 'not_found', __( 'Attachment unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
		return $path;
	}
	public function delete( string $key ): void {
		$path = $this->path( $key );
		if ( ! unlink( $path ) ) {
			throw new Failure( 'delete_failed', __( 'The attachment could not be removed.', 'forum-intelligence-for-bbpress' ), 503 ); }
	}
}
