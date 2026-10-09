<?php
declare(strict_types=1);
namespace ForumIntelligence\Cli;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Health\Health;
final class Commands {
	public function __construct( private Services $services ) {}
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! constant( 'WP_CLI' ) ) {
			return; }
		\WP_CLI::add_command(
			'forum-intelligence config export',
			function (): void {
				try {
					\WP_CLI::line( wp_json_encode( $this->services->operations->execute( 'config_export', array(), get_current_user_id() ), JSON_PRETTY_PRINT ) );
				} catch ( \ForumIntelligence\Domain\Failure $failure ) {
					\WP_CLI::error( $failure->getMessage() . ' Use --user=ADMIN_LOGIN.' );}
			}
		);
		\WP_CLI::add_command(
			'forum-intelligence config import',
			function ( array $args, array $options ): void {
				$path = realpath( (string) ( $args[0] ?? '' ) );
				if ( ! $path || ! is_file( $path ) || filesize( $path ) > 1048576 ) {
					\WP_CLI::error( 'Provide a configuration JSON file no larger than 1 MB.' );}
				if ( ( $options['reviewed'] ?? false ) !== true ) {
					\WP_CLI::error( 'Review module and AI privacy settings, then add --reviewed.' );}
				try {
					$this->services->operations->execute(
						'config_import',
						array(
							'json'     => file_get_contents( $path ),
							'reviewed' => 1,
						),
						get_current_user_id()
					);
					\WP_CLI::success( 'Reviewed configuration imported.' );
				} catch ( \ForumIntelligence\Domain\Failure $failure ) {
					\WP_CLI::error( $failure->getMessage() );}
			}
		);
		\WP_CLI::add_command(
			'forum-intelligence queue retry',
			function ( array $args ): void {
				try {
					$this->services->queue->retry( (int) ( $args[0] ?? 0 ), get_current_user_id() );
					\WP_CLI::success( 'Eligible failed job requeued.' );
				} catch ( \ForumIntelligence\Domain\Failure $failure ) {
					\WP_CLI::error( $failure->getMessage() );}
			}
		);
		\WP_CLI::add_command(
			'forum-intelligence health',
			function (): void {
				\WP_CLI::line( wp_json_encode( ( new Health( $this->services->store ) )->status(), JSON_PRETTY_PRINT ) );
			}
		);
		\WP_CLI::add_command(
			'forum-intelligence reindex',
			function (): void {
				$this->services->get( 'indexer' )->rebuild();
				\WP_CLI::success( 'Index rebuild queued.' );
			}
		);
		\WP_CLI::add_command(
			'forum-intelligence queue run',
			function ( array $args, array $options ): void {
				\WP_CLI::line( wp_json_encode( $this->services->queue->run( (int) ( $options['limit'] ?? 20 ) ) ) );
			}
		);
		\WP_CLI::add_command(
			'forum-intelligence privacy retain',
			function (): void {
				( new \ForumIntelligence\Privacy\Privacy( $this->services->store ) )->retain();
				\WP_CLI::success( 'One bounded retention batch processed.' );
			}
		);
		\WP_CLI::add_command(
			'forum-intelligence reputation rebuild',
			function (): void {
				$this->services->get( 'reputation' )->rebuild( get_current_user_id() );
				\WP_CLI::success( 'Reputation source reconciliation queued.' );
			}
		);
		\WP_CLI::add_command(
			'forum-intelligence reputation view',
			function ( array $args ): void {
				$member = (int) ( $args[0] ?? 0 );
				\WP_CLI::line( wp_json_encode( $this->services->get( 'reputation' )->profile( $member, get_current_user_id() ), JSON_PRETTY_PRINT ) );
			}
		);
	}
}
