<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Storage;
use ForumIntelligence\Contracts\AttachmentStorage;
use ForumIntelligence\Domain\Failure;
final class PublicMediaStorage implements AttachmentStorage {
	public function store( string $temporary, string $name ): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$file   = array(
			'name'     => $name,
			'tmp_name' => $temporary,
			'error'    => 0,
			'size'     => filesize( $temporary ),
		);
		$upload = wp_handle_sideload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg' => 'image/jpeg',
					'png'      => 'image/png',
					'webp'     => 'image/webp',
					'pdf'      => 'application/pdf',
					'txt'      => 'text/plain',
				),
			)
		);
		if ( isset( $upload['error'] ) ) {
			throw new Failure( 'upload_failed', __( 'The public file could not be stored.', 'forum-intelligence-for-bbpress' ), 503 ); }
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $upload['type'],
				'post_title'     => sanitize_file_name( $name ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $upload['file'] );
			throw new Failure( 'upload_failed', __( 'The file record could not be saved.', 'forum-intelligence-for-bbpress' ), 503 ); }
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		return (string) $id;
	}
	public function path( string $key ): string {
		if ( ! ctype_digit( $key ) || get_post_type( (int) $key ) !== 'attachment' ) {
			throw new Failure( 'not_found', __( 'Attachment unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
		$path = get_attached_file( (int) $key );
		if ( ! $path || ! is_file( $path ) ) {
			throw new Failure( 'not_found', __( 'Attachment unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
		return $path;
	}
	public function delete( string $key ): void {
		if ( ! ctype_digit( $key ) ) {
			throw new \InvalidArgumentException( 'Invalid media ID' ); }
		wp_delete_attachment( (int) $key, true );
	}
}
