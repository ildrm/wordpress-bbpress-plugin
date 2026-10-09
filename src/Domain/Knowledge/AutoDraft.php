<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Knowledge;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Queue\Queue;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
/** Local conversion of an existing current summary; generation and publication remain separate. */
final class AutoDraft {
	public function __construct( private Store $store, private Queue $queue, private Access $access, private Knowledge $knowledge ) {}
	public function register(): void {
		$this->queue->handle( 'knowledge_auto_draft', fn( array $data )=>$this->run( (int) ( $data['topic'] ?? 0 ) ) );
		add_action(
			'fi_signal_changed',
			function ( int $objectId, int $user, string $kind ): void {
				if ( $kind === 'confirm' ) {
					$this->consider( $this->access->topic( $objectId ) ); }
			},
			30,
			3
		);
		foreach ( array( 'fi_summary_ready', 'fi_solution_changed', 'fi_discussion_changed' ) as $hook ) {
			add_action( $hook, array( $this, 'consider' ) );}
	}
	public function consider( int $topic ): void {
		$policy = $this->eligible( $topic );
		if ( $policy ) {
			$this->queue->enqueue( 'knowledge_auto_draft', array( 'topic' => $topic ), (string) $topic, max( $policy['debounce'], $this->delay( $topic, $policy ) ) );}
	}
	public function run( int $topic ): void {
		$policy = $this->eligible( $topic );
		if ( ! $policy ) {
			return;}
		$delay = $this->delay( $topic, $policy );
		if ( $delay > 0 ) {
			$this->queue->enqueue( 'knowledge_auto_draft', array( 'topic' => $topic ), (string) $topic, $delay );
			return;}
		if ( ! ( new \ForumIntelligence\Infrastructure\Database\AtomicStorage( $this->store ) )->ready() ) {
			throw new Failure( 'transactional_storage_required', __( 'Automatic drafts require transactional native and plugin storage.', 'forum-intelligence-for-bbpress' ), 503 );
		}
		$createdId = 0;
		try {
			$this->store->transaction(
				function () use ( $topic, $policy, &$createdId ): void {
					if ( ! $this->store->row( "SELECT ID FROM {$this->store->wordpressTable('posts')} WHERE ID=%d FOR UPDATE", array( $topic ) ) ) {
						return;}
					$guard = function ( int $created = 0 ) use ( $topic, $policy, &$createdId ): void {
						if ( $created > 0 ) {
							$createdId = $created;
							$post      = get_post( $created );
							if ( ! $post || $post->post_type !== $policy['target_type'] || $post->post_status !== 'draft' || (int) $post->post_author !== $policy['actor'] || ! user_can( $policy['actor'], 'edit_post', $created ) ) {
								throw new Failure( 'automation_changed', __( 'Automatic article draft permission or source changed.', 'forum-intelligence-for-bbpress' ), 409 );
							}
						}
						$current = $this->eligible( $topic, $created );
						if ( ! $current || $current['target_type'] !== $policy['target_type'] || $current['settings'] !== $policy['settings'] || $current['summary'] !== $policy['summary'] || $this->delay( $topic, $current ) > 0 ) {
							throw new Failure( 'automation_changed', __( 'Automatic article draft permission or source changed.', 'forum-intelligence-for-bbpress' ), 409 );
						}
					};
					$guard();
					$this->knowledge->command( $topic, 'draft', '', $policy['actor'], $guard );
				}
			);
		} catch ( \Throwable $failure ) {
			// The native row transaction has rolled back; discard any inserted post cache.
			$this->clearCreatedCache( $createdId );
			// Native callbacks may cache changes that the transaction just rolled back.
			foreach ( array_keys( $policy['summary']['sources'] ) as $source ) {
				clean_post_cache( (int) $source ); }
			clean_user_cache( $policy['actor'] );
			foreach ( array( 'fi_settings', 'alloptions', 'notoptions' ) as $option ) {
				wp_cache_delete( $option, 'options' ); }
			throw $failure;
		}
	}
	/** The captured insertion ID may be set immediately before a native callback throws. */
	private function clearCreatedCache( int $id ): void {
		if ( $id > 0 ) {
			clean_post_cache( $id ); }
	}
	private function eligible( int $topic, int $created = 0 ): ?array {
		$settings = (array) get_option( 'fi_settings', array() );
		$actor    = absint( $settings['knowledge_auto_draft_actor'] ?? 0 );
		if ( empty( $settings['knowledge_auto_draft_enabled'] ) || isset( $settings['modules']['knowledge'] ) && ! $settings['modules']['knowledge'] || ! $actor || ! $this->actorAllowed( $actor ) || get_post_type( $topic ) !== bbp_get_topic_post_type() || ! $this->access->isPublic( $topic ) || ! $this->access->canRead( $topic, $actor ) || in_array( $this->access->forum( $topic ), array_map( 'absint', (array) ( $settings['excluded_forums'] ?? array() ) ), true ) ) {
			return null;}
		$posts = $this->store->wordpressTable( 'posts' );
		$meta  = $this->store->wordpressTable( 'postmeta' );
		if ( $this->store->row( "SELECT p.ID FROM $posts p WHERE p.ID<>%d AND p.post_type<>'revision' AND EXISTS (SELECT 1 FROM $meta m WHERE m.post_id=p.ID AND m.meta_key='_fi_source_topic' AND m.meta_value=%s) LIMIT 1", array( $created, (string) $topic ) ) ) {
			return null;}
		$state = ( new \ForumIntelligence\Domain\Solutions\Solutions( $this->store, $this->access ) )->state( $topic, $actor );
		if ( ! $state['solved'] || ! $state['answers'] || $state['community_validations'] < min( 1000, max( 0, (int) ( $settings['knowledge_auto_draft_min_confirmations'] ?? 0 ) ) ) ) {
			return null;}
		$minimum = min( 5000, max( 1, (int) ( $settings['knowledge_auto_draft_min_replies'] ?? 3 ) ) );
		$count   = (int) ( $this->store->row( "SELECT COUNT(*) AS n FROM (SELECT ID FROM $posts WHERE post_parent=%d AND post_type=%s AND post_status='publish' LIMIT %d) AS bounded_replies", array( $topic, bbp_get_reply_post_type(), $minimum ) )['n'] ?? 0 );
		if ( $count < $minimum ) {
			return null;}
		$summary = $this->knowledge->summary( $topic, $actor );
		if ( ! $summary || ! empty( $summary['stale'] ) || ! empty( $summary['disabled'] ) || ! empty( $summary['inaccurate'] ) || empty( $summary['human_edited'] ) && empty( $settings['ai_enabled'] ) ) {
			return null;}
		foreach ( $summary['sources'] as $id => $hash ) {
			if ( ! $this->access->isPublic( (int) $id ) || ! $this->access->canRead( (int) $id, $actor ) ) {
				return null;}
		}
		if ( (array) get_option( 'fi_settings', array() ) !== $settings || ! $this->actorAllowed( $actor ) ) {
			return null;}
		return array(
			'actor'       => $actor,
			'target_type' => ( new Targets() )->selected()['type'],
			'settings'    => $settings,
			'summary'     => $summary,
			'debounce'    => min( 86400, max( 60, (int) ( $settings['knowledge_auto_draft_debounce'] ?? 300 ) ) ),
		);
	}
	/** @phpstan-impure Native access and insertion callbacks can revoke current roles. */
	private function actorAllowed( int $actor ): bool {
		try {
			$targets = new Targets();
			return $actor > 0 && user_can( $actor, 'moderate' ) && $targets->canCreate( $targets->selected()['adapter'], $actor );
		} catch ( Failure $failure ) {
			return false;
		} }
	private function delay( int $topic, array $policy ): int {
		$date    = $this->store->record( 'knowledge_source_revision:' . $topic )['updated_at'] ?? null;
		$changed = is_string( $date ) ? (int) ( strtotime( $date . ' UTC' ) ?: 0 ) : 0;
		return max( 0, $changed + $policy['debounce'] - time() );
	}
}
