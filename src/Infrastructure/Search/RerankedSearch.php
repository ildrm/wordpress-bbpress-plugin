<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Search;
use ForumIntelligence\Contracts\SearchEngine;
use ForumIntelligence\Contracts\RerankingProvider;
use ForumIntelligence\Domain\AI\Evidence;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
/** Optional bounded public-source reranking. Failures retain current authorized local results. */
final class RerankedSearch implements SearchEngine {
	public function __construct( private SearchEngine $search, private RerankingProvider $provider, private Store $store, private Access $access ) {}
	public function search( string $query, array $filters, int $user ): array {
		$result   = $this->search->search( $query, $filters, $user );
		$settings = get_option( 'fi_settings', array() );
		if ( ( $settings['ai_enabled'] ?? null ) !== true || ( $settings['reranking'] ?? null ) !== true || ! in_array( $filters['sort'] ?? 'relevance', array( '', 'relevance' ), true ) || (int) ( $filters['page'] ?? 1 ) > 1 || count( $result['items'] ) < 2 || count( $result['items'] ) > Evidence::SOURCES ) {
			return $result;
		}
		$presentation    = new Presentation( $this->store, $this->access );
		$result['items'] = $presentation->refresh( $result['items'], $query, $user );
		$evidence        = array();
		$hashes          = array();
		$proofs          = array();
		$positions       = array();
		foreach ( $result['items']as $position => $item ) {
			$id = $item['id'];
			if ( ! $this->access->isPublic( $id ) ) {
				return $result;
			}$post = get_post( $id );
			if ( ! $post ) {
				return $result;
			}$evidence[]      = array(
				'id'   => $id,
				'text' => Evidence::text( $post->post_title . "\n" . $post->post_content . "\n" . \ForumIntelligence\Domain\Search\Metadata::text( $id, true ) ),
			);
			$hashes[ $id ]    = Indexer::hash( $id );
			$proofs[ $id ]    = $item['source_proof'];
			$positions[ $id ] = $position;}
		if ( count( $evidence ) < 2 ) {
			return $result;
		}
		$current = get_option( 'fi_settings', array() );
		if ( ( $current['ai_enabled'] ?? null ) !== true || ( $current['reranking'] ?? null ) !== true ) {
			$result['items'] = $presentation->refresh( $result['items'], $query, $user );
			return $result;
		}
		try {
			$scores          = $this->provider->rerank( Evidence::text( $query, 500 ), $evidence );
			$result['items'] = $presentation->refresh( $result['items'], $query, $user );
			$current         = get_option( 'fi_settings', array() );
			if ( ( $current['ai_enabled'] ?? null ) !== true || ( $current['reranking'] ?? null ) !== true || count( $scores ) !== count( $evidence ) || count( $result['items'] ) !== count( $evidence ) ) {
				return $result;
			}
			foreach ( $result['items']as $item ) {
				$id    = $item['id'];
				$score = $scores[ $id ] ?? null;
				if ( ! isset( $hashes[ $id ] ) || ! $this->access->isPublic( $id ) || ! hash_equals( $hashes[ $id ], Indexer::hash( $id ) ) || ! hash_equals( $proofs[ $id ], $item['source_proof'] ) || ( ! is_int( $score ) && ! is_float( $score ) ) || ! is_finite( (float) $score ) || $score < 0 || $score > 1 ) {
							return $result;
				}
			}
			usort( $result['items'], static fn( array $a, array $b ): int=>$scores[ $b['id'] ] <=> $scores[ $a['id'] ] ?: $positions[ $a['id'] ] <=> $positions[ $b['id'] ] );
			foreach ( $result['items']as &$item ) {
				$item['rerank_score'] = (float) $scores[ $item['id'] ];
				if ( ! empty( $filters['explain'] ) && user_can( $user, 'manage_options' ) ) {
							$item['ranking']['reranking'] = (float) $scores[ $item['id'] ];
				}
			}unset( $item );
		} catch ( \Throwable $failure ) {
			$result['items'] = $presentation->refresh( $result['items'], $query, $user );}
		return $result;
	}
}
