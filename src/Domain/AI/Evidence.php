<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\AI;
use ForumIntelligence\Domain\Search\Ranking;
/** Shared transfer limits and redaction, applied before grounding and for every text adapter. */
final class Evidence {
	public const SOURCES          = 12;
	public const TEXT_LENGTH      = 3000;
	public const EMBEDDING_LENGTH = 12000;
	public static function redact( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted email]', $text ) ?? '';
		return preg_replace( '/\b(?:sk-[A-Za-z0-9_-]{12,}|AKIA[A-Z0-9]{16}|(?:password|api[_ -]?key|secret)\s*[:=]\s*[^\s]{4,})/i', '[redacted secret]', $text ) ?? '';
	}
	public static function text( string $text, int $limit = self::TEXT_LENGTH ): string {
		$limit = max( 1, min( self::EMBEDDING_LENGTH, $limit ) );
		return mb_substr( self::redact( mb_substr( wp_strip_all_tags( $text ), 0, $limit ) ), 0, $limit );
	}
	public static function coverage( string $question, string $text ): float {
		// Neither full nor clipped redaction markers can manufacture a query match.
		$text  = preg_replace( '/\[redacted[^\]\n]*(?:\]|$)/i', '', $text ) ?? '';
		$parts = explode( "\n", $text, 2 );
		return Ranking::lexical( $question, $parts[0], $parts[1] ?? '' );
	}
}
