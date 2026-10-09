<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\AI;
use ForumIntelligence\Contracts\ModerationProvider;
use ForumIntelligence\Contracts\TextProvider;
/** Explicit bridge for a text backend; independent moderation adapters need no text contract. */
final class TextModerationProvider implements ModerationProvider {
	public function __construct( private TextProvider $provider ) {}
	public function assess( array $evidence ): array {
		return $this->provider->generate( 'Provide advisory policy triage only. In answer return a JSON object with scores spam, toxicity, dangerous and pii, each a finite number from 0 to 1; confidence from 0 to 1; explanation plain text; recommendation human_review or no_flag. Do not act, punish or infer guilt. Quote no secrets. Return source_ids containing only this source.', $evidence );}
}
