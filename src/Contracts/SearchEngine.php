<?php
declare(strict_types=1);
namespace ForumIntelligence\Contracts;
interface SearchEngine {
	/** @return array{items: array, has_more: bool, page: int} */
	public function search( string $query, array $filters, int $user ): array;
}
