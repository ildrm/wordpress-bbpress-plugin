<?php
declare(strict_types=1);
namespace ForumIntelligence\Frontend;
use ForumIntelligence\Domain\Attachments\Attachments;
use ForumIntelligence\Domain\Failure;
final class Downloads {
	public function __construct( private Attachments $attachments ) {}
	public function register(): void {
		add_action( 'admin_post_fi_download', array( $this, 'send' ) );
		add_action( 'admin_post_nopriv_fi_download', array( $this, 'send' ) );
	}
	public function send(): void {
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
			$file = $this->attachments->download( (int) ( $_GET['id'] ?? 0 ), get_current_user_id() ); } catch ( Failure $failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Domain failure text is escaped; the WordPress response status is a typed integer.
			wp_die( esc_html( $failure->getMessage() ), '', array( 'response' => $failure->status ) ); }
			if ( headers_sent() ) {
				wp_die( esc_html__( 'The download could not be started.', 'forum-intelligence-for-bbpress' ), '', 503 ); }
			nocache_headers();
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Type: ' . $file['mime'] );
			header( "Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode( $file['name'] ) );
			header( 'Content-Length: ' . filesize( $file['path'] ) );
			$stream = fopen( $file['path'], 'rb' );
			if ( ! $stream ) {
				exit; }
			while ( ! feof( $stream ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Domain failure text is escaped; the WordPress response status is a typed integer.
				echo fread( $stream, 65536 ); }
			fclose( $stream );
			exit;
	}
}
