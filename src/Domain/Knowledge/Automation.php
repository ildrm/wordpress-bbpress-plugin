<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Knowledge;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Queue\Queue;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
/** Explicitly opted-in, coalesced long-thread generation with current native authority. */
final class Automation {
	public function __construct( private Store $store, private Queue $queue, private Access $access, private Knowledge $knowledge ) {}
	public function register(): void {
		$this->queue->handle( 'summary_auto', fn( array $data )=>$this->run( (int) ( $data['topic'] ?? 0 ) ) );
		add_action( 'fi_discussion_changed', array( $this, 'consider' ) );
		add_action( 'fi_solution_changed', array( $this, 'consider' ) );
	}
	public function consider( int $topic ): void {
		$policy = $this->eligible( $topic );
		if ( ! $policy ) {
			return;}
		$this->queue->enqueue( 'summary_auto', array( 'topic' => $topic ), (string) $topic, $this->delay( $topic, $policy, true ) );
	}
	public function run( int $topic ): void {
		$policy = $this->eligible( $topic );
		if ( ! $policy ) {
			return;}
		$delay = $this->delay( $topic, $policy, false );
		if ( $delay > 0 ) {
			$this->queue->enqueue( 'summary_auto', array( 'topic' => $topic ), (string) $topic, $delay );
			return;
		}
		$summary = $this->knowledge->summary( $topic, $policy['actor'] );
		if ( $summary && ! $summary['stale'] ) {
			return;}

		$this->knowledge->generate(
			$topic,
			$policy['actor'],
			function () use ( $topic, $policy ): void {
				$current = $this->eligible( $topic );
				if ( ! $current || $current['actor'] !== $policy['actor'] || $this->delay( $topic, $current, false ) > 0 ) {
					throw new Failure( 'automation_changed', __( 'Automatic summary permission or timing changed.', 'forum-intelligence-for-bbpress' ), 409 );
				}
			}
		);
	}
	private function eligible( int $topic ): ?array {
		$settings = (array) get_option( 'fi_settings', array() );
		if ( empty( $settings['summary_auto_enabled'] ) || empty( $settings['ai_enabled'] ) || isset( $settings['modules']['knowledge'] ) && ! $settings['modules']['knowledge'] ) {
			return null;}
		$actor = absint( $settings['summary_auto_actor'] ?? 0 );
		if ( ! $actor || ! user_can( $actor, 'moderate' ) || get_post_type( $topic ) !== bbp_get_topic_post_type() || ! $this->access->isPublic( $topic ) || ! $this->access->canRead( $topic, $actor ) || in_array( $this->access->forum( $topic ), array_map( 'absint', (array) ( $settings['excluded_forums'] ?? array() ) ), true ) ) {
			return null;}
		$summary = $this->store->record( 'summary:' . $topic )['data'] ?? array();
		if ( ! empty( $summary['manual_lock'] ) || ! empty( $summary['disabled'] ) || ! empty( $summary['inaccurate'] ) ) {
			return null;}
		$threshold = min( 5000, max( 3, (int) ( $settings['summary_min_replies'] ?? 20 ) ) );
		$posts     = $this->store->wordpressTable( 'posts' );
		$count     = (int) ( $this->store->row( "SELECT COUNT(*) AS n FROM (SELECT ID FROM $posts WHERE post_parent=%d AND post_type=%s AND post_status='publish' LIMIT %d) AS bounded_replies", array( $topic, bbp_get_reply_post_type(), $threshold ) )['n'] ?? 0 );
		if ( $count < $threshold ) {
			return null;}
		if ( (array) get_option( 'fi_settings', array() ) !== $settings ) {
			return null; }
		return array(
			'actor'    => $actor,
			'debounce' => min( 86400, max( 60, (int) ( $settings['summary_debounce_seconds'] ?? 600 ) ) ),
			'interval' => min( 604800, max( 300, (int) ( $settings['summary_min_interval'] ?? 3600 ) ) ),
			'summary'  => $summary,
		);
	}
	private function delay( int $topic, array $policy, bool $scheduling ): int {
		$now      = time();
		$last     = $this->timestamp( $policy['summary']['generated_at'] ?? null );
		$revision = $this->store->record( 'knowledge_source_revision:' . $topic );
		$changed  = $this->timestamp( $revision['updated_at'] ?? null );
		$quiet    = $changed ? $changed + $policy['debounce'] : $now;
		if ( $scheduling ) {
			$quiet = max( $quiet, $now + $policy['debounce'] );}
		return max( 0, $quiet - $now, $last ? $last + $policy['interval'] - $now : 0 );
	}
	private function timestamp( mixed $date ): int {
		if ( ! is_string( $date ) || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/D', $date ) ) {
			return 0;}
		return (int) ( strtotime( $date . ' UTC' ) ?: 0 );
	}
}
