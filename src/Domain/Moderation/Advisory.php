<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Moderation;
use ForumIntelligence\Contracts\TextProvider;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Queue\Queue;
use ForumIntelligence\Infrastructure\Search\Indexer;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
/** Optional explicit human-requested analysis; never invokes moderation state changes. */
final class Advisory {
	private \ForumIntelligence\Contracts\ModerationProvider $provider;
	public function __construct( private Store $store, private Queue $queue, private Access $access, \ForumIntelligence\Contracts\ModerationProvider|TextProvider $provider ) {
		$this->provider = $provider instanceof \ForumIntelligence\Contracts\ModerationProvider ? $provider : new \ForumIntelligence\Infrastructure\AI\TextModerationProvider( $provider );
	}
	public function register(): void {
		$this->queue->handle( 'moderation_advisory', fn( array $data )=>$this->generate( (int) $data['case'], (int) $data['actor'] ) );}
	public function request( int $caseId, int $actor ): array {
		$object = $this->source( $caseId, $actor );
		$this->store->limit( $actor, 'moderation_advisory', 10, 3600 );
		$this->queue->enqueue(
			'moderation_advisory',
			array(
				'case'  => $caseId,
				'actor' => $actor,
			),
			$caseId . ':' . Indexer::hash( $object )
		);
		$this->store->event( 'moderation_advisory_requested', $object, $actor, array( 'case' => $caseId ) );
		return array(
			'queued'        => true,
			'advisory_only' => true,
		);
	}
	public function view( int $caseId, int $actor ): ?array {
		$this->access->moderate( $actor );
		$row = $this->store->row( "SELECT object_id FROM {$this->store->table('cases')} WHERE id=%d", array( $caseId ) );
		if ( ! $row ) {
			throw new Failure( 'not_found', __( 'The case is unavailable.', 'forum-intelligence-for-bbpress' ), 404 );}
		$this->access->read( (int) $row['object_id'], $actor );
		$result = $this->store->record( 'moderation_advisory:' . $caseId )['data'] ?? null;
		if ( ! $result || ! is_int( $result['source'] ?? null ) || $result['source'] !== (int) $row['object_id'] || ! is_string( $result['hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $result['hash'] ) ) {
			return null; }
		$result['stale'] = $result['hash'] !== Indexer::hash( (int) $row['object_id'] );
		return $result;
	}
	public function generate( int $caseId, int $actor ): void {
		$object = $this->source( $caseId, $actor );
		$post   = get_post( $object );
		$hash   = Indexer::hash( $object );
		$result = $this->provider->assess(
			array(
				array(
					'id'   => $object,
					'text' => \ForumIntelligence\Domain\AI\Evidence::text( $post->post_title . "\n" . $post->post_content . "\n" . \ForumIntelligence\Domain\Search\Metadata::text( $object, true ) ),
				),
			)
		);
		if ( ! is_string( $result['answer'] ?? null ) || mb_strlen( $result['answer'] ) > 20000 || ( $result['source_ids'] ?? null ) !== array( $object ) ) {
			throw new Failure( 'invalid_advisory', __( 'The advisory response could not be verified.', 'forum-intelligence-for-bbpress' ), 503 ); }
		$data = json_decode( $result['answer'], true );
		if ( ! is_array( $data ) || $result['source_ids'] !== array( $object ) || ! is_string( $data['explanation'] ?? null ) || ! in_array( $data['recommendation'] ?? '', array( 'human_review', 'no_flag' ), true ) ) {
			throw new Failure( 'invalid_advisory', __( 'The advisory response could not be verified.', 'forum-intelligence-for-bbpress' ), 503 );}
		foreach ( array( 'spam', 'toxicity', 'dangerous', 'pii', 'confidence' ) as $key ) {
			$value = $data[ $key ] ?? null;
			if ( ! is_int( $value ) && ! is_float( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 || (float) $value > 1 ) {
				throw new Failure( 'invalid_advisory', __( 'The advisory response could not be verified.', 'forum-intelligence-for-bbpress' ), 503 );}
		}
		if ( $this->source( $caseId, $actor ) !== $object || Indexer::hash( $object ) !== $hash ) {
			throw new Failure( 'source_changed', __( 'The source changed; request a new review.', 'forum-intelligence-for-bbpress' ), 409 );}
		$clean                  = array_intersect_key( $data, array_flip( array( 'spam', 'toxicity', 'dangerous', 'pii', 'confidence', 'recommendation' ) ) );
		$clean['explanation']   = mb_substr( sanitize_textarea_field( $data['explanation'] ), 0, 1000 );
		$clean['hash']          = $hash;
		$clean['generated_at']  = $this->store->now();
		$clean['source']        = $object;
		$clean['requested_by']  = $actor;
		$clean['model']         = get_option( 'fi_settings', array() )['text_model'] ?? 'configured';
		$clean['advisory_only'] = true;
		$this->store->saveRecord( 'moderation_advisory', 'moderation_advisory:' . $caseId, 0, $object, $clean );
		$this->store->event(
			'moderation_advisory_generated',
			$object,
			$actor,
			array(
				'case'          => $caseId,
				'advisory_only' => true,
			)
		);
	}
	private function source( int $caseId, int $actor ): int {
		$this->access->moderate( $actor );
		$row    = $this->store->row( "SELECT object_id FROM {$this->store->table('cases')} WHERE id=%d", array( $caseId ) );
		$object = (int) ( $row['object_id'] ?? 0 );
		if ( ! $object || ! user_can( $actor, 'edit_post', $object ) ) {
			throw new Failure( 'not_found', __( 'The case is unavailable.', 'forum-intelligence-for-bbpress' ), 404 );}
		$this->access->read( $object, $actor );
		if ( ! $this->access->isPublic( $object ) ) {
			throw new Failure( 'restricted_ai', __( 'Restricted discussions cannot be processed externally.', 'forum-intelligence-for-bbpress' ), 403 );}
		return $object;
	}
}
