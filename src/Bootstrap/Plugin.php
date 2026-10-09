<?php
declare(strict_types=1);
namespace ForumIntelligence\Bootstrap;
use ForumIntelligence\Infrastructure\Database\Schema;
final class Plugin {
	public function __construct( private string $file ) {}
	public function register(): void {
		add_action(
			'init',
			function (): void {
				if ( ! function_exists( 'bbp_get_version' ) || version_compare( bbp_get_version(), '2.6.0', '<' ) ) {
					add_action( 'admin_notices', array( $this, 'dependencyNotice' ) );
					return; }
				if ( (int) get_option( 'fi_schema_version', 0 ) !== Schema::VERSION ) {
					try {
						Lifecycle::activate();
					} catch ( \Throwable $failure ) {
								add_action( 'admin_notices', array( $this, 'storageNotice' ) );
								return; }
				}
				if ( ! wp_next_scheduled( 'fi_queue_tick' ) ) {
					wp_schedule_event( time() + 60, 'hourly', 'fi_queue_tick' ); }
				if ( ! wp_next_scheduled( 'fi_retention_tick' ) ) {
					wp_schedule_event( time() + 3600, 'daily', 'fi_retention_tick' ); }
				$this->boot();
			},
			20
		);
	}
	public function dependencyNotice(): void {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Forum Intelligence requires active bbPress 2.6 or later. Install or activate bbPress.', 'forum-intelligence-for-bbpress' ) . '</p></div>'; }
	}
	public function storageNotice(): void {
		if ( current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Forum Intelligence could not upgrade its database. Check database privileges and retry activation.', 'forum-intelligence-for-bbpress' ) . '</p></div>'; }
	}
	private function boot(): void {
		( new \ForumIntelligence\Integration\BuddyPress\Compatibility() )->register();
		$services = new Services();
		$services->register();
		$store     = $services->store;
		$access    = $services->access;
		$solutions = $services->solutions;
		$frontend  = new \ForumIntelligence\Frontend\Frontend( $services->operations, $solutions, $access, $this->file );
		$frontend->register();
		( new \ForumIntelligence\Integration\BbPress\TopicFilters( $store ) )->register();
		( new \ForumIntelligence\Frontend\Hub( $services, $frontend ) )->register();
		( new \ForumIntelligence\Frontend\Extras( $services, $frontend ) )->register();
		if ( $services->enabled( 'pwa' ) || $services->enabled( 'push' ) ) {
			( new \ForumIntelligence\Frontend\Pwa( $frontend, $this->file, $services->enabled( 'pwa' ) ) )->register(); }
		( new \ForumIntelligence\Admin\Admin( $services, $frontend ) )->register();
		add_action( 'before_delete_post', array( $solutions, 'invalidate' ) );
		add_action(
			'updated_post_meta',
			static function ( int $meta, int $objectId, string $key ) use ( $solutions ): void {
				if ( $key === '_bbp_topic_id' ) {
					$solutions->invalidate( $objectId );
				} },
			10,
			3
		);
		add_filter(
			'the_posts',
			static function ( array $posts ) use ( $solutions, $services ): array {
				$topics = array();
				foreach ( $posts as $post ) {
					if ( $post->post_type === bbp_get_topic_post_type() ) {
						$topics[] = $post->ID;
					}
				} $solutions->prime( $topics );
				$engagement = $services->get( 'engagement' );
				if ( $engagement ) {
					$engagement->prime( array_column( $posts, 'ID' ), get_current_user_id() );}
				return $posts;
			}
		);
		add_action(
			'transition_post_status',
			static function ( string $newStatus, string $old, \WP_Post $post ) use ( $solutions ): void {
				if ( $newStatus !== $old && ! in_array( $newStatus, array( 'publish', 'closed' ), true ) ) {
					$solutions->invalidate( $post->ID ); }
			},
			10,
			3
		);
		do_action( 'fi_booted', $store, $access, $solutions );
	}
}
