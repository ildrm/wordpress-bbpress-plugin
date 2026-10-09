<?php
declare(strict_types=1);
namespace ForumIntelligence\Health;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Database\Schema;
final class Health {
	public function __construct( private Store $store ) {}
	public function status(): array {
		$settings     = (array) get_option( 'fi_settings', array() );
		$draftStorage = empty( $settings['knowledge_auto_draft_enabled'] ) || isset( $settings['modules']['knowledge'] ) && ! $settings['modules']['knowledge'] ? 'not_required' : ( ( new \ForumIntelligence\Infrastructure\Database\AtomicStorage( $this->store ) )->ready() ? 'ready' : 'requires_innodb' );
		$jobs         = $this->store->rows( "SELECT status,COUNT(*) AS n,MIN(updated_at) AS oldest FROM {$this->store->table('jobs')} GROUP BY status" );
		return array(
			'schema'                  => (int) get_option( 'fi_schema_version', 0 ),
			'expected_schema'         => Schema::VERSION,
			'bbpress'                 => function_exists( 'bbp_get_version' ) ? bbp_get_version() : 'inactive',
			'ai_configured'           => defined( 'FI_AI_API_KEY' ) && ! empty( get_option( 'fi_settings', array() )['ai_enabled'] ),
			'jobs'                    => $jobs,
			'automatic_draft_storage' => $draftStorage,
			'last_queue_tick'         => (int) get_option( 'fi_last_queue_tick', 0 ),
		);
	}
	public function register(): void {
		add_action(
			'fi_queue_tick',
			static function (): void {
				update_option( 'fi_last_queue_tick', time(), false );
			},
			20
		);
		add_action(
			'fi_queue_soon',
			static function (): void {
				update_option( 'fi_last_queue_tick', time(), false );
			},
			20
		);
		add_filter(
			'site_status_tests',
			function ( array $tests ): array {
				$tests['direct']['forum_intelligence'] = array(
					'label' => __( 'Forum Intelligence processing', 'forum-intelligence-for-bbpress' ),
					'test'  => function (): array {
								$status = $this->status();
						$good                   = $status['schema'] === $status['expected_schema'] && $status['automatic_draft_storage'] !== 'requires_innodb';
						foreach ( $status['jobs'] as $job ) {
							if ( in_array( $job['status'], array( 'dead', 'retrying' ), true ) && (int) $job['n'] > 0 ) {
								$good = false; }
						}
						return array(
							'label'       => $good ? __( 'Forum Intelligence is ready to process jobs', 'forum-intelligence-for-bbpress' ) : __( 'Forum Intelligence needs attention', 'forum-intelligence-for-bbpress' ),
							'status'      => $good ? 'good' : 'recommended',
							'badge'       => array(
								'label' => __( 'Forum Intelligence', 'forum-intelligence-for-bbpress' ),
								'color' => 'blue',
							),
							'description' => '<p>' . esc_html__( 'Review schema and failed jobs in Forum Intelligence. Automatic drafts require InnoDB for native and plugin tables. Use a system cron for reliable background work.', 'forum-intelligence-for-bbpress' ) . '</p>',
							'actions'     => '',
							'test'        => 'forum_intelligence',
						);
					},
				);
				return $tests;
			}
		);
	}
}
