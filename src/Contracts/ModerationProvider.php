<?php
declare(strict_types=1);
namespace ForumIntelligence\Contracts;
/** Returns an advisory answer and source_ids; the domain validates policy scores and never executes decisions. */
interface ModerationProvider {
	public function assess( array $evidence ): array;
}
