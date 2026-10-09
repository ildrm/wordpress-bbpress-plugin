<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Search;
/** Immutable scaled direction; compiled frames are disposable derivatives of canonical JSON. */
final class PreparedVector {
	private function __construct( private readonly array $values, private readonly float $norm ) {}
	public static function fromArray( array $vector ): ?self {
		if ( ! Ranking::validVector( $vector ) ) {
			return null;
		}
		$scale = 0.0;
		foreach ( $vector as $value ) {
			$scale = max( $scale, abs( (float) $value ) );
		}
		$values = array();
		$norm   = 0.0;
		foreach ( $vector as $value ) {
			$scaled   = $scale === 0.0 ? 0.0 : (float) $value / $scale;
			$values[] = $scaled;
			$norm    += $scaled * $scaled;
		}
		return new self( $values, $norm );
	}
	public static function fromJson( string $json ): ?self {
		$vector = json_decode( $json, true );
		return is_array( $vector ) ? self::fromArray( $vector ) : null;
	}
	/** Versioned little-endian doubles; unsupported platforms retain the JSON fallback. */
	public static function encode( string $json ): ?string {
		if ( strlen( pack( 'e', 1.0 ) ) !== 8 ) {
			return null;
		}
		$vector = self::fromJson( $json );
		if ( ! $vector ) {
			return null;
		}
		$payload = 'FIV1' . pack( 'N', count( $vector->values ) ) . pack( 'e', $vector->norm ) . pack( 'e*', ...$vector->values );
		return $payload . hash( 'sha256', $json . "\0" . $payload, true );
	}
	/** Verify bounds/integrity before unpacking; never deserialize executable PHP objects. */
	public static function fromFrame( string $frame, string $json ): ?self {
		$length = strlen( $frame );
		if ( $length < 56 || $length > 32816 || substr( $frame, 0, 4 ) !== 'FIV1' || strlen( pack( 'e', 1.0 ) ) !== 8 ) {
			return null;
		}
		$header = unpack( 'Ncount/enorm', substr( $frame, 4, 12 ) );
		if ( ! $header ) {
			return null;
		}
		$count = (int) $header['count'];
		$norm  = (float) $header['norm'];
		if ( $count < 1 || $count > 4096 || $length !== 48 + 8 * $count || ! is_finite( $norm ) || $norm < 0.0 || $norm > $count ) {
			return null;
		}
		$payload = substr( $frame, 0, -32 );
		if ( ! hash_equals( substr( $frame, -32 ), hash( 'sha256', $json . "\0" . $payload, true ) ) ) {
			return null;
		}
		$unpacked = unpack( 'e*', substr( $frame, 16, 8 * $count ) );
		if ( ! $unpacked ) {
			return null;
		}
		$sum = 0.0;
		foreach ( $unpacked as $value ) {
			if ( ! is_finite( $value ) || abs( $value ) > 1.0 ) {
				return null;
			}
			$sum += $value * $value;
		}
		if ( $sum !== $norm ) {
			return null;
		}
		return new self( array_values( $unpacked ), $norm );
	}
	public function cosine( self $other ): float {
		if ( count( $this->values ) !== count( $other->values ) || $this->norm === 0.0 || $other->norm === 0.0 ) {
			return 0.0;
		}
		$dot = 0.0;
		foreach ( $this->values as $index => $value ) {
			$dot += $value * $other->values[ $index ];
		}
		return max( -1.0, min( 1.0, $dot / sqrt( $this->norm * $other->norm ) ) );
	}
}
