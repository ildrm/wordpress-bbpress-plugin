<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Knowledge;
use ForumIntelligence\Domain\Failure;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
/** Private human review queue with bounded native provenance and indexed keyset pagination. */
final class Recommendations {
	private const ACTIVE    = 'knowledge_recommendation';
	private const WITHDRAWN = 'knowledge_recommendation_withdrawn';
	private const DRAFTED   = 'knowledge_recommendation_drafted';
	public function __construct( private Store $store, private Access $access ) {}
	public function register(): void {
		add_action(
			'before_delete_post',
			function ( int $id ): void {
				if ( get_post_type( $id ) === bbp_get_topic_post_type() ) {
					$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE record_key=%s AND kind IN (%s,%s,%s)", array( $this->key( $id ), self::ACTIVE, self::WITHDRAWN, self::DRAFTED ) );
				}
			},
			35
		);
	}
	public function recommend( int $topic, int $user, string $reason, array $plan, callable $renew ): void {
		$this->policy( $user );
		if ( ! $plan['solved'] || ! $plan['accepted'] ) {
			throw new Failure( 'solution_required', __( 'Recommend a solved discussion with a readable accepted answer.', 'forum-intelligence-for-bbpress' ), 409 );}
		$reason = sanitize_textarea_field( $reason );
		if ( mb_strlen( $reason ) > 2000 ) {
			throw new Failure( 'too_large', __( 'Recommendation notes are limited to 2,000 characters.', 'forum-intelligence-for-bbpress' ), 413 );}
		$data = array(
			'reason'              => $reason,
			'sources'             => $plan['hashes'],
			'discussion_revision' => $plan['revision'],
			'solution_version'    => $plan['solution_version'],
			'recommended_by'      => $user,
		);
		if ( ! $this->visible( $topic, $data, $user ) ) {
			throw new Failure( 'not_found', __( 'Content is unavailable.', 'forum-intelligence-for-bbpress' ), 404 );}
		$existing = $this->store->record( $this->key( $topic ) );
		if ( $existing && ! in_array( $existing['kind'], array( self::ACTIVE, self::WITHDRAWN, self::DRAFTED ), true ) ) {
			throw new Failure( 'recommendation_changed', __( 'The recommendation could not be changed. Reopen the review queue.', 'forum-intelligence-for-bbpress' ), 409 );
		}
		if ( $existing && ! $this->visible( $topic, $existing['data'], $user ) ) {
			throw new Failure( 'not_found', __( 'Content is unavailable.', 'forum-intelligence-for-bbpress' ), 404 );
		}
		$this->write( $topic, $user, self::ACTIVE, $data, 'knowledge_recommend', $renew, $existing );
	}
	public function withdraw( int $topic, int $user ): void {
		$this->policy( $user );
		$row = $this->store->record( $this->key( $topic ) );
		if ( ! $row || $row['kind'] !== self::ACTIVE ) {
			return;}
		if ( ! $this->visible( $topic, $row['data'], $user ) ) {
			throw new Failure( 'not_found', __( 'Content is unavailable.', 'forum-intelligence-for-bbpress' ), 404 );}
		$data  = $row['data'];
		$renew = function () use ( $topic, $data, $user ): void {
			$this->policy( $user );
			if ( ! $this->visible( $topic, $data, $user ) ) {
				throw new Failure( 'not_found', __( 'Content is unavailable.', 'forum-intelligence-for-bbpress' ), 404 );
			}};
		$this->write( $topic, $user, self::WITHDRAWN, $data, 'knowledge_unrecommend', $renew, $row );
	}
	public function converted( int $topic, int $user, int $article ): void {
		$row = $this->store->record( $this->key( $topic ) );
		if ( ! $row || $row['kind'] !== self::ACTIVE || ! $this->visible( $topic, $row['data'], $user ) ) {
			return;}
		$data               = $row['data'];
		$data['article_id'] = $article;
		$this->write(
			$topic,
			$user,
			self::DRAFTED,
			$data,
			'knowledge_recommendation_converted',
			function () use ( $topic, $data, $user ): void {
				$this->policy( $user );
				if ( ! $this->visible( $topic, $data, $user ) ) {
							throw new Failure( 'not_found', __( 'Content is unavailable.', 'forum-intelligence-for-bbpress' ), 404 );
				}},
			$row
		);
	}
	public function page( int $user, int $before = PHP_INT_MAX ): array {
		$this->policy( $user );
		$rows   = $this->store->rows( "SELECT * FROM {$this->store->table('records')} WHERE kind=%s AND id<%d ORDER BY id DESC LIMIT 26", array( self::ACTIVE, max( 1, $before ) ) );
		$items  = array();
		$proofs = array();
		$next   = count( $rows ) > 25 ? (int) $rows[24]['id'] : null;
		foreach ( array_slice( $rows, 0, 25 )as $row ) {
			$topic = (int) $row['object_id'];
			$data  = json_decode( $row['payload'], true );
			if ( ! is_array( $data ) || ! $this->visible( $topic, $data, $user ) || ! is_string( $data['reason'] ?? null ) || mb_strlen( $data['reason'] ) > 2000 ) {
				continue;}
			$url = get_permalink( $topic );
			if ( ! $url ) {
				continue;}
			$revision                   = ( new SourceRevision( $this->store ) )->version( $topic );
			$solution                   = (int) ( $this->store->row( "SELECT version FROM {$this->store->table('solutions')} WHERE topic_id=%d", array( $topic ) )['version'] ?? 0 );
			$items[]                    = array(
				'id'             => (int) $row['id'],
				'topic'          => $topic,
				'url'            => $url,
				'reason'         => $data['reason'],
				'recommended_at' => $row['updated_at'],
				'stale'          => $revision !== (int) ( $data['discussion_revision'] ?? 0 ) || $solution !== (int) ( $data['solution_version'] ?? 0 ),
			);
			$proofs[ (int) $row['id'] ] = $row;
		}
		foreach ( $items as $index => &$item ) {
			$current = $this->store->record( $this->key( $item['topic'] ) );
			if ( ! $current || $current['kind'] !== self::ACTIVE || (int) $current['version'] !== (int) $proofs[ $item['id'] ]['version'] || ! $this->visible( $item['topic'], $current['data'], $user ) ) {
				unset( $items[ $index ] );
				continue;}
			$revision      = ( new SourceRevision( $this->store ) )->version( $item['topic'] );
			$solution      = (int) ( $this->store->row( "SELECT version FROM {$this->store->table('solutions')} WHERE topic_id=%d", array( $item['topic'] ) )['version'] ?? 0 );
			$item['stale'] = $revision !== (int) ( $current['data']['discussion_revision'] ?? 0 ) || $solution !== (int) ( $current['data']['solution_version'] ?? 0 );
		}unset( $item );
		$items = array_values( $items );
		$this->policy( $user );
		return array(
			'items' => $items,
			'next'  => $next,
		);
	}
	private function visible( int $topic, mixed $data, int $user ): bool {
		if ( ! is_array( $data ) ) {
			return false;
		}
		$sources = $data['sources'] ?? null;
		if ( ! is_int( $data['recommended_by'] ?? null ) || $data['recommended_by'] <= 0 || ! is_int( $data['discussion_revision'] ?? null ) || $data['discussion_revision'] < 0 || ! is_int( $data['solution_version'] ?? null ) || $data['solution_version'] <= 0 || get_post_type( $topic ) !== bbp_get_topic_post_type() || ! is_array( $sources ) || ! $sources || count( $sources ) > 64 || ! array_key_exists( $topic, $sources ) ) {
			return false;}
		foreach ( $sources as $source => $hash ) {
			if ( ! is_int( $source ) || $source <= 0 || ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ! $this->access->canRead( $source, $user ) ) {
				return false;}
		}
		return true;
	}
	private function policy( int $user ): void {
		$this->access->moderate( $user );
		$settings = (array) get_option( 'fi_settings', array() );
		if ( isset( $settings['modules']['knowledge'] ) && ! $settings['modules']['knowledge'] ) {
			throw new Failure( 'module_disabled', __( 'Enable community knowledge to use its review queue.', 'forum-intelligence-for-bbpress' ), 503 );}
	}
	private function key( int $topic ): string {
		return 'knowledge_recommendation:' . $topic;}
	private function write( int $topic, int $user, string $kind, array $data, string $event, callable $renew, ?array $expected ): void {
		try {
			$this->store->transaction(
				function () use ( $topic, $user, $kind, $data, $event, $renew, $expected ): void {
					$current = $this->store->row( "SELECT id,kind,version FROM {$this->store->table('records')} WHERE record_key=%s FOR UPDATE", array( $this->key( $topic ) ) );
					if ( $expected ? ( ! $current || (int) $current['id'] !== (int) $expected['id'] || (int) $current['version'] !== (int) $expected['version'] || $current['kind'] !== $expected['kind'] ) : (bool) $current ) {
						throw new Failure( 'recommendation_changed', __( 'The recommendation could not be changed. Reopen the review queue.', 'forum-intelligence-for-bbpress' ), 409 );
					}
					if ( $expected && ! $this->visible( $topic, $expected['data'], $user ) ) {
						throw new Failure( 'not_found', __( 'Content is unavailable.', 'forum-intelligence-for-bbpress' ), 404 );
					}
					$renew();
					$this->policy( $user );
					$owner = $data['recommended_by'];
					$this->store->saveRecord( $kind, $this->key( $topic ), $owner, $topic, $data );
					$this->store->execute( "UPDATE {$this->store->table('records')} SET kind=%s,owner_id=%d WHERE record_key=%s AND kind IN (%s,%s,%s)", array( $kind, $owner, $this->key( $topic ), self::ACTIVE, self::WITHDRAWN, self::DRAFTED ) );
					$this->store->event( $event, $topic, $user, array( 'article' => $data['article_id'] ?? 0 ) );
					if ( $expected && ! $this->visible( $topic, $expected['data'], $user ) ) {
						throw new Failure( 'not_found', __( 'Content is unavailable.', 'forum-intelligence-for-bbpress' ), 404 );
					}
					$renew();
					$this->policy( $user );
				}
			);
		} catch ( \Throwable $failure ) {
			$previous = is_array( $expected['data'] ?? null ) && is_array( $expected['data']['sources'] ?? null ) ? $expected['data']['sources'] : array();
			foreach ( array_unique( array_merge( array_keys( $data['sources'] ), array_keys( $previous ) ) )as $source ) {
				clean_post_cache( (int) $source );}
			clean_user_cache( $user );
			foreach ( array( 'fi_settings', 'alloptions', 'notoptions' )as $option ) {
				wp_cache_delete( $option, 'options' );}
			throw $failure;
		}
	}
}
