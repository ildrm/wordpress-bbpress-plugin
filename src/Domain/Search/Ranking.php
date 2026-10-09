<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Search;
final class Ranking {
	public static function tokens( string $text ): array {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text ) ) ?: array();
		return array_values( array_unique( array_filter( $words, static fn( string $word ): bool => mb_strlen( $word ) >= 2 ) ) );
	}
	public static function lexical( string $query, string $title, string $body ): float {
		$terms = self::tokens( $query );
		if ( ! $terms ) {
			return 0; }
		$title = mb_strtolower( $title );
		$body  = mb_strtolower( $body );
		$sum   = 0;
		foreach ( $terms as $term ) {
			$sum += str_contains( $title, $term ) ? 1 : ( str_contains( $body, $term ) ? 0.7 : 0 );
		}
		return min( 1, $sum / count( $terms ) );
	}
	/** Shape/size admission shared by math, native cache and compatible provider output. */
	public static function validVector( array $vector ): bool {
		if ( ! $vector || ! array_is_list( $vector ) || count( $vector ) > 4096 ) {
			return false; }
		foreach ( $vector as $value ) {
			if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
				return false; }
		}
		return true;
	}
	public static function cosine( array $a, array $b ): float {
		if ( count( $a ) !== count( $b ) || ! self::validVector( $a ) || ! self::validVector( $b ) ) {
			return 0; }
		$scaleA = 0.0;
		$scaleB = 0.0;
		foreach ( $a as $i => $value ) {
			$scaleA = max( $scaleA, abs( (float) $value ) );
			$scaleB = max( $scaleB, abs( (float) $b[ $i ] ) );
		}
		if ( $scaleA === 0.0 || $scaleB === 0.0 ) {
			return 0; }
		// Scaling each vector separately preserves direction and bounds every intermediate term.
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		foreach ( $a as $i => $value ) {
			$left  = (float) $value / $scaleA;
			$right = (float) $b[ $i ] / $scaleB;
			$dot  += $left * $right;
			$na   += $left * $left;
			$nb   += $right * $right;
		}
		return max( -1, min( 1, $dot / sqrt( $na * $nb ) ) );
	}
	public static function hybrid( float $lexical, float $semantic, bool $solved, int $votes ): float {
		return self::explain( $lexical, $semantic, $solved, $votes )['score'];
	}
	/** Numeric inputs and additive contributions; callers separately enforce debug authorization. */
	public static function explain( float $lexical, float $semantic, bool $solved, int $votes, string $retrieval = 'lexical' ): array {
		$lexical       = is_finite( $lexical ) ? max( 0, min( 1, $lexical ) ) : 0;
		$semantic      = is_finite( $semantic ) ? max( 0, min( 1, $semantic ) ) : 0;
		$contributions = array(
			'lexical'  => 0.65 * $lexical,
			'semantic' => 0.3 * $semantic,
			'solution' => $solved ? 0.025 : 0.0,
			'votes'    => min( 0.025, max( 0, $votes ) * 0.0025 ),
		);
		return array(
			'retrieval'     => $retrieval,
			'coverage'      => $lexical,
			'similarity'    => $semantic,
			'solved'        => $solved,
			'votes'         => $votes,
			'contributions' => $contributions,
			'score'         => array_sum( $contributions ),
		);
	}
	public static function sufficient( array $items, float $threshold = 0.65 ): bool {
		// At least one accepted answer and strong query coverage are required.
		foreach ( $items as $item ) {
			if ( ( $item['coverage'] ?? 0 ) >= $threshold && ! empty( $item['solution'] ) ) {
				return true; }
		}
		return false;
	}
}
