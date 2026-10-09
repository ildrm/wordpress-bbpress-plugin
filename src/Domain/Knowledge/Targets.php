<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Knowledge;
use ForumIntelligence\Contracts\KnowledgeTarget;
use ForumIntelligence\Domain\Failure;
/** Resolve current-site registered native adapters without granting native capabilities. */
final class Targets {
	public function registry(): array {
		$default    = new NativeKnowledgeTarget( 'fi_knowledge', __( 'Community Knowledge', 'forum-intelligence-for-bbpress' ) );
		$registered = apply_filters( 'fi_knowledge_targets', array( 'fi_knowledge' => $default ) );
		$targets    = array( 'fi_knowledge' => $default );
		$types      = array( 'fi_knowledge' );
		if ( ! is_array( $registered ) ) {
			return $targets;}
		foreach ( array_slice( $registered, 0, 20, true )as $id => $target ) {
			if ( $id === 'fi_knowledge' || ! is_string( $id ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id ) || ! $target instanceof KnowledgeTarget ) {
				continue;}
			$type = $target->postType();
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,19}$/D', $type ) || in_array( $type, $types, true ) || ! $this->compatible( $target ) ) {
				continue;}
			$targets[ $id ] = $target;
			$types[]        = $type;
		}
		return $targets;
	}
	/** @phpstan-impure Native adapter and capability callbacks can change current policy. */
	public function selected(): array {
		$settings = (array) get_option( 'fi_settings', array() );
		$id       = $settings['knowledge_target'] ?? 'fi_knowledge';
		$registry = $this->registry();
		$target   = is_string( $id ) ? ( $registry[ $id ] ?? null ) : null;
		if ( ! $target || ! $this->compatible( $target ) ) {
			throw new Failure( 'target_unavailable', __( 'The selected editorial target is unavailable or incompatible. Check its configuration.', 'forum-intelligence-for-bbpress' ), 409 );}
		return array(
			'id'      => $id,
			'adapter' => $target,
			'type'    => $target->postType(),
		);
	}
	/** Private native editorial types keep raw public feeds/REST outside this integration. */
	public function compatible( KnowledgeTarget $target ): bool {
		$type = get_post_type_object( $target->postType() );
		if ( ! $type || $type->public || $type->publicly_queryable || $type->show_in_rest || $type->has_archive || ! $type->exclude_from_search || ! $type->show_ui || ! $type->map_meta_cap ) {
			return false;}
		foreach ( array( 'title', 'editor', 'revisions', 'author' )as $support ) {
			if ( ! post_type_supports( $type->name, $support ) ) {
				return false;}
		}
		return true;
	}
	/** @phpstan-impure Current native capabilities and post type registration are renewed. */
	public function canCreate( KnowledgeTarget $target, int $user ): bool {
		$type = get_post_type_object( $target->postType() );
		if ( $user <= 0 || ! $type || ! $this->compatible( $target ) || ! user_can( $user, $type->cap->edit_posts ) ) {
			return false;}
		// Native Add New is deliberately disabled on the plugin's built-in type.
		return $type->name === 'fi_knowledge' || user_can( $user, $type->cap->create_posts );
	}
	public function options(): array {
		$options = array();
		foreach ( $this->registry()as $id => $target ) {
			if ( $id === 'fi_knowledge' || $this->compatible( $target ) ) {
				$options[ $id ] = mb_substr( sanitize_text_field( $target->label() ), 0, 120 );}
		}
		return $options;
	}
	public function types(): array {
		$types = array();
		foreach ( $this->registry()as $target ) {
			if ( $this->compatible( $target ) ) {
				$types[] = $target->postType();}
		}
		return $types;
	}
	/** Owned metadata preserves source guarding even if a prior adapter disappears. */
	public function bound( \WP_Post $post ): bool {
		if ( in_array( $post->post_type, array( bbp_get_topic_post_type(), bbp_get_reply_post_type(), bbp_get_forum_post_type(), 'revision' ), true ) ) {
			return false;}
		return $post->post_type === 'fi_knowledge' || metadata_exists( 'post', $post->ID, '_fi_knowledge_target' ) || metadata_exists( 'post', $post->ID, '_fi_source_topic' ) || metadata_exists( 'post', $post->ID, '_fi_provenance' );
	}
	public function availableArticle( \WP_Post $post ): bool {
		if ( ! $this->bound( $post ) ) {
			return false;}
		$id = get_post_meta( $post->ID, '_fi_knowledge_target', true );
		if ( $id === '' && $post->post_type === 'fi_knowledge' ) {
			$id = 'fi_knowledge';}
		$target = is_string( $id ) ? ( $this->registry()[ $id ] ?? null ) : null;
		return $target && $target->postType() === $post->post_type && $this->compatible( $target );
	}
}
