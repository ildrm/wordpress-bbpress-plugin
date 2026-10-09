<?php
declare(strict_types=1);
namespace ForumIntelligence\Contracts;
/** Trusted native editorial adapter; central conversion still enforces source and draft policy. */
interface KnowledgeTarget {
	public function postType(): string;
	public function label(): string;
	/** Insert a new native draft, preserving the supplied creation marker. */
	public function createDraft( array $post ): int;
}
