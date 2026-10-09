<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\AI;
use ForumIntelligence\Contracts\TextProvider;
use ForumIntelligence\Contracts\EmbeddingProvider;
use ForumIntelligence\Domain\Failure;
final class NullProvider implements TextProvider, EmbeddingProvider, \ForumIntelligence\Contracts\ModerationProvider, \ForumIntelligence\Contracts\RerankingProvider {
	public function generate( string $instruction, array $evidence ): array {
		throw new Failure( 'ai_unavailable', __( 'AI is not configured. Community search remains available.', 'forum-intelligence-for-bbpress' ), 503 ); }
	public function assess( array $evidence ): array {
		return $this->generate( '', $evidence );
	}
	public function rerank( string $query, array $evidence ): array {
		return array();
	}
	public function embed( string $text ): array {
		return array(); }
}
