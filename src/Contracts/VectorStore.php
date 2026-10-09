<?php
declare(strict_types=1);
namespace ForumIntelligence\Contracts;
interface VectorStore {
	public function put( int $source, string $hash, string $model, array $vector ): void;
	public function remove( int $source ): void;
	/** Candidate IDs and scores; callers must authorize source objects. */
	public function nearest( array $vector, int $limit ): array;
}
