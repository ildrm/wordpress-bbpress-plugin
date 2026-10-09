<?php
declare(strict_types=1);
namespace ForumIntelligence\Contracts;
interface TextProvider {
	public function generate( string $instruction, array $evidence ): array;
}
