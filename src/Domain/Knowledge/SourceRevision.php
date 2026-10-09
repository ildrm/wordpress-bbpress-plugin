<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Knowledge;
use ForumIntelligence\Infrastructure\Database\Store;
/** Monotonic native discussion changes; no copied content, provider work or generation jobs. */
final class SourceRevision {
	public function __construct( private Store $store ) {}
	public function register(): void {
		add_action(
			'save_post',
			function ( int $id, \WP_Post $post, bool $update ): void {
				if ( ! $update ) {
							$this->changed( $post );
				}},
			35,
			3
		);
		add_action(
			'post_updated',
			function ( int $id, \WP_Post $after, \WP_Post $before ): void {
				foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_password', 'post_parent', 'post_date_gmt' ) as $field ) {
					if ( $after->$field !== $before->$field ) {
						$this->changed( $before );
						$this->changed( $after );
						break;}
				}
			},
			35,
			3
		);
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action(
				$hook,
				function ( mixed $meta, int $id, string $key ): void {
					if ( in_array( $key, array( '_fi_fields', '_bbp_topic_id', '_bbp_forum_id' ), true ) ) {
						$post = get_post( $id );
						if ( $post ) {
										$this->changed( $post );}
					}
				},
				35,
				3
			);
		}
		add_action(
			'set_object_terms',
			function ( int $id ): void {
				$post = get_post( $id );
				if ( $post ) {
							$this->changed( $post );
				}},
			35
		);
		add_action(
			'before_delete_post',
			function ( int $id ): void {
				$post = get_post( $id );
				if ( ! $post ) {
					return;}
				if ( $post->post_type === bbp_get_topic_post_type() ) {
					$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE kind='knowledge_source_revision' AND record_key=%s", array( $this->key( $id ) ) );
				} else {
					$this->changed( $post );}
			},
			35
		);
		add_action(
			'deleted_post',
			function ( int $id, \WP_Post $post ): void {
				if ( $post->post_type === bbp_get_topic_post_type() ) {
					$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE kind='knowledge_source_revision' AND record_key=%s", array( $this->key( $id ) ) );
				}
			},
			35,
			2
		);
	}
	public function version( int $topic ): int {
		return (int) ( $this->store->row( "SELECT version FROM {$this->store->table('records')} WHERE kind='knowledge_source_revision' AND record_key=%s", array( $this->key( $topic ) ) )['version'] ?? 0 );
	}
	private function key( int $topic ): string {
		return 'knowledge_source_revision:' . $topic;}
	private function changed( \WP_Post $post ): void {
		if ( $post->post_type === bbp_get_topic_post_type() ) {
			$topics = array( $post->ID );
		} elseif ( $post->post_type === bbp_get_reply_post_type() ) {
			$topics = array( $post->post_parent, (int) get_post_meta( $post->ID, '_bbp_topic_id', true ) );
		} else {
			return;}
		foreach ( array_unique( $topics ) as $topic ) {
			if ( $topic <= 0 || get_post_type( $topic ) !== bbp_get_topic_post_type() ) {
				continue;}
			$now = $this->store->now();
			$this->store->execute( "INSERT INTO {$this->store->table('records')} (kind,record_key,owner_id,object_id,payload,version,created_at,updated_at) VALUES ('knowledge_source_revision',%s,0,%d,'{}',1,%s,%s) ON DUPLICATE KEY UPDATE version=version+1,updated_at=VALUES(updated_at)", array( $this->key( $topic ), $topic, $now, $now ) );
			do_action( 'fi_discussion_changed', (int) $topic );
		}
	}
}
