<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Knowledge;
use ForumIntelligence\Contracts\KnowledgeTarget;
use ForumIntelligence\Domain\Failure;
/** Native private editorial types retain WordPress author, editor and revision ownership. */
final class NativeKnowledgeTarget implements KnowledgeTarget {
	public function __construct( private string $type, private string $name ) {}
	public function postType(): string {
		return $this->type;}
	public function label(): string {
		return $this->name;}
	public function createDraft( array $post ): int {
		$post['post_type'] = $this->type;
		$id                = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $id ) ) {
			throw new Failure( 'draft_failed', __( 'The draft could not be created.', 'forum-intelligence-for-bbpress' ), 503 );}
		return $id;
	}
}
