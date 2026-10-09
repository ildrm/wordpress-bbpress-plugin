<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Knowledge;
use ForumIntelligence\Domain\Notifications\Notifications;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Queue\Queue;
/** Current native authors receive only optional generic, source-bound editorial alerts. */
final class ReviewNotifications {
	public function __construct( private Store $store, private Queue $queue, private Notifications $notifications ) {}
	public function register(): void {
		$this->queue->handle( 'knowledge_review', fn( array $data )=>$this->fanout( (int) ( $data['topic'] ?? 0 ), (int) ( $data['cursor'] ?? 0 ) ) );
		add_action( 'fi_discussion_changed', array( $this, 'request' ) );
		add_action( 'fi_solution_changed', array( $this, 'request' ) );
	}
	/** @phpstan-impure Native callbacks can change the current site policy. */
	private function enabled(): bool {
		$settings = (array) get_option( 'fi_settings', array() );
		return ! empty( $settings['knowledge_review_notifications'] ) && ( $settings['modules']['knowledge'] ?? true ) && ( $settings['modules']['notifications'] ?? true );
	}
	public function request( int $topic ): void {
		if ( ! $this->enabled() || $topic <= 0 || get_post_type( $topic ) !== bbp_get_topic_post_type() ) {
			return;}
		$this->queue->enqueue(
			'knowledge_review',
			array(
				'topic'  => $topic,
				'cursor' => 0,
			),
			$topic . ':0',
			120
		);
	}
	private function fanout( int $topic, int $cursor ): void {
		if ( ! $this->enabled() || $topic <= 0 || get_post_type( $topic ) !== bbp_get_topic_post_type() ) {
			return;}
		$posts = $this->store->wordpressTable( 'posts' );
		$meta  = $this->store->wordpressTable( 'postmeta' );
		$types = ( new Targets() )->types();
		if ( ! $types ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$rows         = $this->store->rows( "SELECT p.ID FROM $posts p WHERE p.ID>%d AND p.post_type IN ($placeholders) AND p.post_status IN ('draft','pending','future','private','publish') AND EXISTS (SELECT 1 FROM $meta m WHERE m.post_id=p.ID AND m.meta_key='_fi_source_topic' AND m.meta_value=%s) ORDER BY p.ID LIMIT 25", array( max( 0, $cursor ), ...$types, (string) $topic ) );
		$ids          = array_map( 'intval', array_column( $rows, 'ID' ) );
		if ( $ids ) {
			get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => array( 'draft', 'pending', 'future', 'private', 'publish' ),
					'post__in'       => $ids,
					'posts_per_page' => 25,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);}
		$revision = ( new SourceRevision( $this->store ) )->version( $topic );
		$solution = (int) ( $this->store->row( "SELECT version FROM {$this->store->table('solutions')} WHERE topic_id=%d", array( $topic ) )['version'] ?? 0 );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! ( new Targets() )->availableArticle( $post ) || (int) get_post_meta( $id, '_fi_source_topic', true ) !== $topic ) {
				continue;}
			$data        = get_post_meta( $id, '_fi_provenance', true );
			$fingerprint = is_array( $data ) && is_string( $data['fingerprint'] ?? null ) ? $data['fingerprint'] : '';
			$key         = 'knowledge_review:' . $id . ':' . hash( 'sha256', $revision . ':' . $solution . ':' . $fingerprint . ':' . $post->post_author );
			$this->notifications->deliver( (int) $post->post_author, $id, 0, 'knowledge_review', $key );
		}
		if ( count( $ids ) === 25 && $this->enabled() ) {
			$next = (int) end( $ids );
			$this->queue->enqueue(
				'knowledge_review',
				array(
					'topic'  => $topic,
					'cursor' => $next,
				),
				$topic . ':' . $next
			);
		}
	}
}
