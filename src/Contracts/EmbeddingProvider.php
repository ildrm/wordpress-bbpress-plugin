<?php
declare(strict_types=1);
namespace ForumIntelligence\Contracts;
interface EmbeddingProvider {
	/** @return list<float> */
	public function embed( string $text ): array;
}
