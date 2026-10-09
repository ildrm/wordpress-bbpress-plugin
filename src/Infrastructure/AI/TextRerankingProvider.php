<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\AI;
use ForumIntelligence\Contracts\RerankingProvider;
use ForumIntelligence\Contracts\TextProvider;
use ForumIntelligence\Domain\Failure;
/** Explicit bridge; untrusted model scores never add sources or change access. */
final class TextRerankingProvider implements RerankingProvider {
	public function __construct( private TextProvider $provider ) {}
	public function rerank( string $query, array $evidence ): array {
		$result = $this->provider->generate( 'Rank relevance to this query using only the supplied evidence. In answer return JSON with scores: a list of objects with integer id and finite score from 0 to 1. Include every supplied ID exactly once. Return the same IDs in source_ids. Query: ' . \ForumIntelligence\Domain\AI\Evidence::text( $query, 500 ), $evidence );
		$ids    = array_column( $evidence, 'id' );
		$cited  = $result['source_ids'] ?? null;
		if ( ! is_string( $result['answer'] ?? null ) || mb_strlen( $result['answer'] ) > 8000 || ! is_array( $cited ) || ! array_is_list( $cited ) || array_filter( $cited, static fn( $id ): bool => ! is_int( $id ) || $id <= 0 ) || count( $cited ) !== count( $ids ) || array_diff( $ids, $cited ) || array_diff( $cited, $ids ) ) {
			throw new Failure( 'invalid_reranking', __( 'The reranking response could not be verified.', 'forum-intelligence-for-bbpress' ), 503 );
		}
		$data   = json_decode( $result['answer'], true );
		$rows   = $data['scores'] ?? null;
		$scores = array();
		if ( ! is_array( $rows ) || ! array_is_list( $rows ) || count( $rows ) !== count( $ids ) ) {
			throw new Failure( 'invalid_reranking', __( 'The reranking response could not be verified.', 'forum-intelligence-for-bbpress' ), 503 );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== 2 ) {
				throw new Failure( 'invalid_reranking', __( 'The reranking response could not be verified.', 'forum-intelligence-for-bbpress' ), 503 );
			}
			$id    = $row['id'] ?? null;
			$score = $row['score'] ?? null;
			if ( ! is_int( $id ) || ! in_array( $id, $ids, true ) || isset( $scores[ $id ] ) || ( ! is_int( $score ) && ! is_float( $score ) ) || ! is_finite( (float) $score ) || $score < 0 || $score > 1 ) {
				throw new Failure( 'invalid_reranking', __( 'The reranking response could not be verified.', 'forum-intelligence-for-bbpress' ), 503 );
			}$scores[ $id ] = (float) $score;}
		return $scores;
	}
}
