<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Attachments;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Contracts\AttachmentStorage;
use ForumIntelligence\Domain\Failure;
final class Attachments {
	public function __construct( private Store $store, private Access $access, private AttachmentStorage $storage, private bool $isProtected ) {}
	public function upload( int $objectId, int $user, array $file ): array {
		$this->access->participate( $objectId, $user );
		if ( ! user_can( $user, 'upload_files' ) || ! $this->access->topic( $objectId ) ) {
			throw new Failure( 'forbidden', __( 'Attachment upload permission is required.', 'forum-intelligence-for-bbpress' ), 403 ); }
		if ( ! $this->isProtected && ! $this->access->isPublic( $objectId ) ) {
			throw new Failure( 'protected_storage_required', __( 'Uploads to restricted discussions require protected storage.', 'forum-intelligence-for-bbpress' ), 403 ); }
		$this->store->limit( $user, 'attachment', 5, 300 );
		$maximum = 5 * 1024 * 1024;
		if ( ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) || filesize( $file['tmp_name'] ) > $maximum || filesize( $file['tmp_name'] ) < 1 ) {
			throw new Failure( 'invalid_upload', __( 'Upload a valid file no larger than 5 MB.', 'forum-intelligence-for-bbpress' ) ); }
		$name      = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$allowed   = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
			'pdf'  => 'application/pdf',
			'txt'  => 'text/plain',
		);
		$mime      = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $file['tmp_name'] );
		if ( ! isset( $allowed[ $extension ] ) || $allowed[ $extension ] !== $mime ) {
			throw new Failure( 'invalid_file_type', __( 'The actual file type does not match an allowed extension.', 'forum-intelligence-for-bbpress' ) ); }
		$head = file_get_contents( $file['tmp_name'], false, null, 0, 4096 );
		if ( $head !== false && ( stripos( $head, '<?php' ) !== false || str_contains( $head, '<script' ) ) ) {
			throw new Failure( 'unsafe_file', __( 'Executable content is not allowed.', 'forum-intelligence-for-bbpress' ) ); }
		if ( ! apply_filters( 'fi_scan_attachment', true, $file['tmp_name'], $mime ) ) {
			throw new Failure( 'unsafe_file', __( 'The upload was rejected by the file scanner.', 'forum-intelligence-for-bbpress' ) ); }
		$size = filesize( $file['tmp_name'] );
		$key  = $this->storage->store( $file['tmp_name'], $name );
		try {
			$this->store->transaction(
				function () use ( $objectId, $user, $name, $mime, $key, $size ): void {
					// Serialize uploads for this post; the unique record and row lock bound concurrent files.
					$table = $this->store->table( 'records' );
					$this->store->execute( "INSERT IGNORE INTO $table (kind,record_key,object_id,payload,created_at,updated_at) VALUES ('attachment_lock',%s,%d,'{}',%s,%s)", array( 'attachment_lock:' . $objectId, $objectId, $this->store->now(), $this->store->now() ) );
					$this->store->row( "SELECT id FROM $table WHERE record_key=%s FOR UPDATE", array( 'attachment_lock:' . $objectId ) );
					$this->access->participate( $objectId, $user );
					if ( ! $this->isProtected && ! $this->access->isPublic( $objectId ) ) {
						throw new Failure( 'protected_storage_required', __( 'Uploads to restricted discussions require protected storage.', 'forum-intelligence-for-bbpress' ), 403 );}
					$count = $this->store->row( "SELECT COUNT(*) AS n FROM $table WHERE kind='attachment' AND object_id=%d", array( $objectId ) );
					if ( (int) $count['n'] >= 5 ) {
						throw new Failure( 'attachment_limit', __( 'This post already has five attachments.', 'forum-intelligence-for-bbpress' ) );}
					$this->store->saveRecord(
						'attachment',
						'attachment:' . bin2hex( random_bytes( 16 ) ),
						$user,
						$objectId,
						array(
							'key'       => $key,
							'name'      => $name,
							'mime'      => $mime,
							'size'      => $size,
							'protected' => $this->isProtected,
						)
					);
				}
			);
		} catch ( \Throwable $failure ) {
			$this->storage->delete( $key );
			throw $failure;
		}
		return array(
			'uploaded'  => true,
			'protected' => $this->isProtected,
		);
	}

	public function listing( int $objectId, int $user ): array {
		$this->access->read( $objectId, $user );
		$rows = $this->store->rows( "SELECT id,payload FROM {$this->store->table('records')} WHERE kind='attachment' AND object_id=%d ORDER BY id LIMIT 5", array( $objectId ) );
		return array_map(
			static function ( array $row ): array {
				$data = json_decode( $row['payload'], true );
				return array(
					'id'        => (int) $row['id'],
					'name'      => $data['name'],
					'size'      => $data['size'],
					'protected' => $data['protected'],
					'url'       => add_query_arg(
						array(
							'action' => 'fi_download',
							'id'     => $row['id'],
						),
						admin_url( 'admin-post.php' )
					),
				);
			},
			$rows
		);
	}
	public function download( int $id, int $user ): array {
		$row = $this->store->row( "SELECT * FROM {$this->store->table('records')} WHERE kind='attachment' AND id=%d", array( $id ) );
		if ( ! $row ) {
			throw new Failure( 'not_found', __( 'Attachment unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
		$this->access->read( (int) $row['object_id'], $user );
		$data = json_decode( $row['payload'], true );
		if ( (bool) $data['protected'] !== $this->isProtected ) {
			throw new Failure( 'storage_changed', __( 'The original storage backend must be restored to access this file.', 'forum-intelligence-for-bbpress' ), 503 ); }
		return array(
			'path' => $this->storage->path( $data['key'] ),
			'name' => $data['name'],
			'mime' => $data['mime'],
		);
	}
}
