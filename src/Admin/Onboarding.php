<?php
declare(strict_types=1);
namespace ForumIntelligence\Admin;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Frontend\Frontend;
use ForumIntelligence\Domain\Failure;
use ForumIntelligence\Health\Health;
/** Explicit guided setup; visiting it never changes settings or contacts a provider. */
final class Onboarding {
	private static ?array $pendingDefaults = null;
	public static function preservingDefaults( mixed $input ): bool {
		return self::$pendingDefaults !== null && self::$pendingDefaults === $input;
	}
	private const MODULES = array( 'engagement', 'moderation', 'knowledge', 'relationships', 'notifications', 'reputation', 'polls', 'composer', 'attachments', 'messaging', 'pwa', 'push', 'badges' );
	public function __construct( private Services $services, private Frontend $frontend ) {}
	private function authorize( int $actor ): void {
		if ( ! user_can( $actor, 'manage_options' ) ) {
			throw new Failure( 'forbidden', __( 'Settings permission is required.', 'forum-intelligence-for-bbpress' ), 403 );
		}
	}
	public function status( int $actor ): array {
		$this->authorize( $actor );
		$health       = ( new Health( $this->services->store ) )->status();
		$environment  = array(
			'php'        => version_compare( PHP_VERSION, '8.2', '>=' ),
			'wordpress'  => version_compare( get_bloginfo( 'version' ), '6.6', '>=' ),
			'extensions' => extension_loaded( 'mbstring' ) && extension_loaded( 'mysqli' ) && extension_loaded( 'json' ),
			'bbpress'    => function_exists( 'bbp_get_version' ),
			'schema'     => $health['schema'] === $health['expected_schema'],
		);
		$integrations = array();
		foreach ( array( 'activity', 'groups', 'notifications', 'messages' ) as $component ) {
			$integrations[ $component ] = function_exists( 'bp_is_active' ) && bp_is_active( $component );
		}
		$settings = (array) get_option( 'fi_settings', array() );
		return array(
			'ready'           => ! in_array( false, $environment, true ),
			'environment'     => $environment,
			'integrations'    => $integrations,
			'ai_enabled'      => ( $settings['ai_enabled'] ?? null ) === true,
			'semantic'        => ( $settings['semantic'] ?? null ) === true,
			'queue_scheduled' => (bool) wp_next_scheduled( 'fi_queue_tick' ),
			'last_queue_tick' => $health['last_queue_tick'],
			'jobs'            => $health['jobs'],
		);
	}
	private function ready( int $actor ): void {
		if ( ! $this->status( $actor )['ready'] ) {
			throw new Failure( 'environment_unavailable', __( 'Resolve the environment checks before continuing setup.', 'forum-intelligence-for-bbpress' ), 503 );
		}
	}
	public function register(): void {
		$this->services->operations->add( 'setup_defaults', array( $this, 'defaults' ) );
		$this->services->operations->add(
			'setup_index',
			function ( array $data, int $actor ): array {
				$this->ready( $actor );
				if ( ! in_array( $data['reviewed'] ?? null, array( true, 1, '1' ), true ) ) {
					throw new Failure( 'review_required', __( 'Review the indexing and AI privacy settings before building the index.', 'forum-intelligence-for-bbpress' ), 400 );
				}
				$this->services->store->limit( $actor, 'setup_index', 5, 300 );
				return $this->services->operations->execute( 'reindex', array(), $actor );
			}
		);
	}
	public function defaults( array $data, int $actor ): array {
		$this->ready( $actor );
		$this->services->store->limit( $actor, 'setup_defaults', 5, 300 );
		$defaults            = Admin::defaultSettings();
		$defaults['modules'] = array();
		foreach ( self::MODULES as $module ) {
			$defaults['modules'][ $module ] = $this->services->enabled( $module );
		}
		$current               = (array) get_option( 'fi_settings', array() );
		$merged                = array_replace( $defaults, $current );
		$merged['modules']     = array_replace( $defaults['modules'], (array) ( $current['modules'] ?? array() ) );
		$previous              = self::$pendingDefaults;
		self::$pendingDefaults = $merged;
		try {
			update_option( 'fi_settings', $merged, false );
		} finally {
			self::$pendingDefaults = $previous;
		}
		if ( maybe_serialize( get_option( 'fi_settings', array() ) ) !== maybe_serialize( $merged ) ) {
			throw new Failure( 'save_failed', __( 'The defaults could not be saved. Please retry.', 'forum-intelligence-for-bbpress' ), 503 );
		}
		return array( 'defaults_saved' => true );
	}
	private function link( string $tab, string $label ): string {
		return '<a href="' . esc_url(
			add_query_arg(
				array(
					'page' => 'forum-intelligence',
					'tab'  => $tab,
				),
				admin_url( 'admin.php' )
			)
		) . '">' . esc_html( $label ) . '</a>';
	}
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = $this->status( get_current_user_id() );
		echo '<section class="fi-panel" aria-labelledby="fi-setup-title"><h2 id="fi-setup-title">' . esc_html__( 'Getting started', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Follow these steps at your own pace. Accepted solutions and local search work without an AI account.', 'forum-intelligence-for-bbpress' ) . '</p><ol class="fi-setup-steps"><li><h3>' . esc_html__( 'Check the environment', 'forum-intelligence-for-bbpress' ) . '</h3><dl>';
		$labels = array(
			'php'        => __( 'PHP 8.2 or later', 'forum-intelligence-for-bbpress' ),
			'wordpress'  => __( 'WordPress 6.6 or later', 'forum-intelligence-for-bbpress' ),
			'extensions' => __( 'Required PHP extensions', 'forum-intelligence-for-bbpress' ),
			'bbpress'    => __( 'bbPress is active', 'forum-intelligence-for-bbpress' ),
			'schema'     => __( 'Database schema is current', 'forum-intelligence-for-bbpress' ),
		);
		foreach ( $labels as $key => $label ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $status['environment'][ $key ] ? __( 'Passed', 'forum-intelligence-for-bbpress' ) : __( 'Needs attention', 'forum-intelligence-for-bbpress' ) ) . '</dd>';
		}
		echo '</dl></li><li><h3>' . esc_html__( 'Review community integrations', 'forum-intelligence-for-bbpress' ) . '</h3><dl>';
		$labels = array(
			'activity'      => __( 'BuddyPress activity and mentions', 'forum-intelligence-for-bbpress' ),
			'groups'        => __( 'BuddyPress group forums', 'forum-intelligence-for-bbpress' ),
			'notifications' => __( 'BuddyPress notifications', 'forum-intelligence-for-bbpress' ),
			'messages'      => __( 'BuddyPress messaging', 'forum-intelligence-for-bbpress' ),
		);
		foreach ( $labels as $key => $label ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $status['integrations'][ $key ] ? __( 'Active', 'forum-intelligence-for-bbpress' ) : __( 'Not active', 'forum-intelligence-for-bbpress' ) ) . '</dd>';
		}
		echo '</dl><p>' . esc_html__( 'Active community integrations retain their native ownership. Check their own delivery settings before sending member alerts.', 'forum-intelligence-for-bbpress' ) . '</p></li><li><h3>' . esc_html__( 'Save essential defaults', 'forum-intelligence-for-bbpress' ) . '</h3><p>' . esc_html__( 'Fill missing settings with conservative defaults: author acceptance enabled, AI and semantic search disabled, and content retained on uninstall. Existing choices are preserved.', 'forum-intelligence-for-bbpress' ) . '</p>';
		if ( $status['ready'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form helper escapes all dynamic values.
			echo $this->frontend->form( 'setup_defaults', array(), __( 'Save missing defaults', 'forum-intelligence-for-bbpress' ) );
		}
		echo '</li><li><h3>' . esc_html__( 'Choose optional modules', 'forum-intelligence-for-bbpress' ) . '</h3><p>' . esc_html__( 'Engagement, moderation, knowledge, relationships, notifications, reputation, polls and the composer provide local features. Attachments, private messaging, achievements, push and the PWA are separate choices. Protected files need private storage; push needs browser consent and server keys.', 'forum-intelligence-for-bbpress' ) . '</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Link helper escapes URL and label.
		echo $this->link( 'settings', __( 'Review settings and modules', 'forum-intelligence-for-bbpress' ) );
		echo '</li><li><h3>' . esc_html__( 'Review AI privacy and indexing', 'forum-intelligence-for-bbpress' ) . '</h3><p>' . esc_html__( 'Local indexing keeps search data on this site. Semantic indexing and AI answers can send permitted public content to your configured provider. Private, hidden, password-protected and group-forum content stays out of the bundled external adapter. Redaction is heuristic; review provider retention, region and contractual terms. Keep API keys in server configuration.', 'forum-intelligence-for-bbpress' ) . '</p><p>' . esc_html( $status['ai_enabled'] ? __( 'AI is currently enabled. Review its privacy settings before indexing.', 'forum-intelligence-for-bbpress' ) : __( 'AI is currently disabled. You can build the local index now.', 'forum-intelligence-for-bbpress' ) ) . '</p><p>' . esc_html( $status['semantic'] ? __( 'Semantic indexing is currently enabled and may contact the configured provider.', 'forum-intelligence-for-bbpress' ) : __( 'Semantic indexing is currently disabled.', 'forum-intelligence-for-bbpress' ) ) . '</p><p>' . esc_html__( 'Indexing runs in bounded background batches. Configure a system cron for reliable processing, then monitor Jobs and indexing.', 'forum-intelligence-for-bbpress' ) . '</p>';
		if ( $status['ready'] ) {
			$review = '<label><input type="checkbox" name="data[reviewed]" value="1" required> ' . esc_html__( 'I reviewed the current indexing and AI privacy settings.', 'forum-intelligence-for-bbpress' ) . '</label>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form helper and review field escape dynamic values at construction.
			echo $this->frontend->form( 'setup_index', array(), __( 'Queue initial index build', 'forum-intelligence-for-bbpress' ), $review );
		}
		echo '</li><li><h3>' . esc_html__( 'Check background processing', 'forum-intelligence-for-bbpress' ) . '</h3><dl><dt>' . esc_html__( 'Recurring queue schedule', 'forum-intelligence-for-bbpress' ) . '</dt><dd>' . esc_html( $status['queue_scheduled'] ? __( 'Scheduled', 'forum-intelligence-for-bbpress' ) : __( 'Needs attention', 'forum-intelligence-for-bbpress' ) ) . '</dd><dt>' . esc_html__( 'Last queue run', 'forum-intelligence-for-bbpress' ) . '</dt><dd>' . esc_html( $status['last_queue_tick'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $status['last_queue_tick'] ) : __( 'Not recorded yet', 'forum-intelligence-for-bbpress' ) ) . '</dd></dl>';
		foreach ( $status['jobs'] as $job ) {
			if ( in_array( $job['status'], array( 'dead', 'retrying' ), true ) && (int) $job['n'] > 0 ) {
				echo '<p>' . esc_html__( 'Some background jobs need attention. Review their status and retry eligible jobs.', 'forum-intelligence-for-bbpress' ) . '</p>';
				break;
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Link helper escapes URL and label.
		echo '<p>' . $this->link( 'jobs', __( 'Open jobs and indexing', 'forum-intelligence-for-bbpress' ) ) . '</p><p><a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Run WordPress Site Health checks', 'forum-intelligence-for-bbpress' ) . '</a></p></li></ol></section>';
	}
}
