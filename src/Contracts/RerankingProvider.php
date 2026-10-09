<?php
declare(strict_types=1);
namespace ForumIntelligence\Contracts;
/** Scores only the supplied source IDs with finite values from zero to one. */
interface RerankingProvider {
	public function rerank( string $query, array $evidence ): array;
}
