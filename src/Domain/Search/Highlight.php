<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Search;
final class Highlight {
	public static function text( string $text, string $query ): string {
		$tokens = array_slice( Ranking::tokens( $query ), 0, 12 );
		if ( ! $tokens ) {
			return esc_html( $text ); }
		$pattern = '/(' . implode( '|', array_map( static fn( string $token ): string=>preg_quote( $token, '/' ), $tokens ) ) . ')/iu';
		$parts   = preg_split( $pattern, mb_substr( $text, 0, 500 ), -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return esc_html( $text ); }
		$html = '';
		foreach ( $parts as $index => $part ) {
			$html .= $index % 2 ? '<mark>' . esc_html( $part ) . '</mark>' : esc_html( $part ); }
		return $html;
	}
}
