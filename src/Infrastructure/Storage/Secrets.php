<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Storage;
use ForumIntelligence\Domain\Failure;
/** Authenticated encryption for browser endpoint credentials; salt rotation invalidates old devices. */
final class Secrets {
	public static function seal( array $data ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( wp_json_encode( $data ), $nonce, self::key() ) );
	}
	public static function open( string $sealed ): array {
		$binary = base64_decode( $sealed, true );
		if ( $binary === false || strlen( $binary ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			throw new Failure( 'invalid_secret', 'Invalid stored credentials', 503 ); }
		$nonce = substr( $binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, self::key() );
		$data  = $plain === false ? null : json_decode( $plain, true );
		if ( ! is_array( $data ) ) {
			throw new Failure( 'invalid_secret', 'Stored credentials could not be decrypted', 503 ); }
		return $data;
	}
	private static function key(): string {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . ':' . get_current_blog_id() . ':forum-intelligence-push', true ); }
}
