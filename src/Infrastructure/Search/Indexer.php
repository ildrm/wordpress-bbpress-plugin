<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Search;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Queue\Queue;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Contracts\EmbeddingProvider;
use ForumIntelligence\Contracts\VectorStore;
final class Indexer {
	/** Native aggregate/activity bookkeeping changes no indexed text, hierarchy or access policy. */
	private const FORUM_BOOKKEEPING = array(
		'_bbp_topic_count',
		'_bbp_total_topic_count',
		'_bbp_reply_count',
		'_bbp_total_reply_count',
		'_bbp_topic_count_hidden',
		'_bbp_reply_count_hidden',
		'_bbp_total_topic_count_hidden',
		'_bbp_total_reply_count_hidden',
		'_bbp_forum_subforum_count',
		'_bbp_last_active_id',
		'_bbp_last_active_time',
		'_bbp_last_topic_id',
		'_bbp_last_reply_id',
	);
	public function __construct( private Store $store, private Queue $queue, private Access $access, private EmbeddingProvider $provider, private VectorStore $vectors ) {}
	public function register(): void {
		$this->queue->handle( 'index', fn( array $data )=>$this->index( (int) $data['id'] ) );
		$this->queue->handle( 'rebuild', fn( array $data )=>$this->batch( (int) ( $data['cursor'] ?? 0 ), (string) $data['run'] ) );
		foreach ( array( 'bbp_new_topic', 'bbp_edit_topic', 'bbp_new_reply', 'bbp_edit_reply', 'bbp_insert_topic', 'bbp_insert_reply' ) as $hook ) {
			add_action( $hook, array( $this, 'enqueue' ) ); }
		add_action( 'save_post', array( $this, 'enqueue' ), 40 );
		add_action( 'set_object_terms', array( $this, 'enqueue' ), 20 );
		add_action( 'fi_solution_changed', array( $this, 'enqueue' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'metadata' ), 20, 3 );
		}
		add_action(
			'before_delete_post',
			function ( int $id ): void {
				$this->vectors->remove( $id );
			}
		);
	}
	/** WordPress deletion supplies an array of metadata IDs; only the source/key matter here. */
	public function metadata( mixed $meta, int $id, string $key ): void {
		if ( ! str_starts_with( $key, '_bbp_' ) && ! str_starts_with( $key, '_fi_' ) ) {
			return;}
		if ( get_post_type( $id ) === bbp_get_forum_post_type() && in_array( $key, self::FORUM_BOOKKEEPING, true ) ) {
			return;}
		$this->enqueue( $id );
	}
	public function enqueue( int $id ): void {
		if ( ! in_array( get_post_type( $id ), array( bbp_get_topic_post_type(), bbp_get_reply_post_type(), bbp_get_forum_post_type() ), true ) ) {
			return; }
		$this->queue->enqueue( 'index', array( 'id' => $id ), (string) $id, 5 );
		$topic = $this->access->topic( $id );
		if ( $topic ) {
			$this->store->execute( "UPDATE {$this->store->table('records')} SET version=version+1 WHERE kind='summary' AND object_id=%d", array( $topic ) ); }
		if ( get_post_type( $id ) === bbp_get_forum_post_type() ) {
			$this->rebuild(); }
	}
	public function rebuild(): void {
		$run = bin2hex( random_bytes( 8 ) );
		$this->queue->enqueue(
			'rebuild',
			array(
				'cursor' => 0,
				'run'    => $run,
			),
			$run . ':0'
		);
	}
	public function batch( int $cursor, string $run ): void {
		$rows = $this->store->rows( "SELECT ID FROM {$this->store->wordpressTable('posts')} WHERE ID>%d AND post_type IN (%s,%s) AND (post_status IN ('publish','closed') OR (post_status='pending' AND %d=1)) ORDER BY ID LIMIT 50", array( $cursor, bbp_get_topic_post_type(), bbp_get_reply_post_type(), ( get_option( 'fi_settings', array() )['index_pending'] ?? null ) === true ? 1 : 0 ) );
		foreach ( $rows as $row ) {
			$this->queue->enqueue( 'index', array( 'id' => (int) $row['ID'] ), (string) $row['ID'] ); }
		if ( count( $rows ) === 50 ) {
			$next = (int) end( $rows )['ID'];
			$this->queue->enqueue(
				'rebuild',
				array(
					'cursor' => $next,
					'run'    => $run,
				),
				$run . ':' . $next
			); }
	}
	public static function hash( int $id ): string {
		$post = get_post( $id );
		if ( ! $post ) {
			return ''; }
		$access = new Access();
		return hash( 'sha256', $post->post_title . ':' . $post->post_content . ':' . \ForumIntelligence\Domain\Search\Metadata::text( $id ) . ':' . \ForumIntelligence\Domain\Search\Metadata::text( $id, true ) . ':' . $access->forum( $id ) . ':' . ( $access->isPublic( $id ) ? 'public' : 'restricted' ) . ':' . $post->post_modified_gmt . ':' . (string) ( get_option( 'fi_settings', array() )['embedding_model'] ?? 'text-embedding-3-small' ) );
	}
	public function index( int $id ): void {
		$post     = get_post( $id );
		$table    = $this->store->table( 'chunks' );
		$forum    = $this->access->forum( $id );
		$excluded = array_map( 'intval', get_option( 'fi_settings', array() )['excluded_forums'] ?? array() );
		if ( ! $post || ! $forum || in_array( $forum, $excluded, true ) || ( ! in_array( $post->post_status, array( 'publish', 'closed' ), true ) && ! ( $post->post_status === 'pending' && ( get_option( 'fi_settings', array() )['index_pending'] ?? null ) === true ) ) ) {
			$this->vectors->remove( $id );
			return; }
		$base         = wp_strip_all_tags( $post->post_title . "\n" . $post->post_content );
		$text         = mb_substr( $base . "\n" . \ForumIntelligence\Domain\Search\Metadata::text( $id ), 0, 12000 );
		$externalText = mb_substr( $base . "\n" . \ForumIntelligence\Domain\Search\Metadata::text( $id, true ), 0, 12000 );
		$visibility   = $this->access->isPublic( $id ) ? 'public' : 'restricted';
		$hash         = self::hash( $id );
		$old          = $this->store->row( "SELECT content_hash,vector,model FROM $table WHERE source_id=%d", array( $id ) );
		$semantic     = ( get_option( 'fi_settings', array() )['semantic'] ?? null ) === true;
		$validCache   = false;
		if ( $old && $semantic && $visibility === 'public' && $old['model'] === (string) ( get_option( 'fi_settings', array() )['embedding_model'] ?? 'text-embedding-3-small' ) ) {
			$cached     = json_decode( $old['vector'], true );
			$validCache = is_array( $cached ) && \ForumIntelligence\Domain\Search\Ranking::validVector( $cached );
		}
		if ( $old && $old['content_hash'] === $hash && ( ! $semantic || $visibility !== 'public' || $validCache ) ) {
			return; }
		$this->store->execute( "INSERT INTO $table (source_id,forum_id,source_type,content_hash,visibility,content,vector,updated_at) VALUES (%d,%d,%s,%s,%s,%s,'[]',%s) ON DUPLICATE KEY UPDATE forum_id=VALUES(forum_id),source_type=VALUES(source_type),content_hash=VALUES(content_hash),visibility=VALUES(visibility),content=VALUES(content),vector='[]',vector_binary=NULL,updated_at=VALUES(updated_at)", array( $id, $forum, $post->post_type, $hash, $visibility, $text, $this->store->now() ) );
		if ( $visibility === 'public' && $semantic ) {
			$vector = $this->provider->embed( \ForumIntelligence\Domain\AI\Evidence::text( $externalText, \ForumIntelligence\Domain\AI\Evidence::EMBEDDING_LENGTH ) );
			if ( $vector ) {
				$this->vectors->put( $id, $hash, (string) ( get_option( 'fi_settings', array() )['embedding_model'] ?? 'text-embedding-3-small' ), $vector ); }
		}
	}
}
