<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\AI;
use ForumIntelligence\Domain\Failure;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Infrastructure\Search\Indexer;
use ForumIntelligence\Integration\BbPress\Access;
/** Local feedback on a verified generated response. No question or answer text is retained. */
final class Feedback {
	public function __construct( private Store $store, private Access $access ) {}
	public static function labels(): array {
		return array(
			'helpful'         => __( 'Helpful', 'forum-intelligence-for-bbpress' ),
			'not_helpful'     => __( 'Not helpful', 'forum-intelligence-for-bbpress' ),
			'inaccurate'      => __( 'Inaccurate', 'forum-intelligence-for-bbpress' ),
			'outdated'        => __( 'Outdated', 'forum-intelligence-for-bbpress' ),
			'missing_context' => __( 'Missing context', 'forum-intelligence-for-bbpress' ),
		);
	}
	public static function states(): array {
		return array(
			'open'      => __( 'Needs review', 'forum-intelligence-for-bbpress' ),
			'resolved'  => __( 'Reviewed and resolved', 'forum-intelligence-for-bbpress' ),
			'dismissed' => __( 'Reviewed and dismissed', 'forum-intelligence-for-bbpress' ),
		);
	}
	/** Issue an unpredictable, site/account-bound 15-minute receipt after grounding validation. */
	public function offer( array $result, int $user, Answers $answers ): string {
		if ( ! $answers->canPresent( $result, $user ) ) {
			return '';
		}
		$support = $result['grounding'];
		$topic   = $this->access->topic( $support['source'] );
		$sources = array();
		foreach ( array_unique( array_merge( array( $support['source'], $support['primary'] ), array_column( $result['citations'], 'id' ) ) )as $id ) {
			$sources[] = array(
				'id'   => $id,
				'hash' => Indexer::hash( $id ),
			);
		}
		$receipt = array(
			'version'  => 1,
			'site'     => get_current_blog_id(),
			'user'     => $user,
			'issued'   => time(),
			'topic'    => $topic,
			'source'   => $support['source'],
			'primary'  => $support['primary'],
			'sources'  => $sources,
			'response' => hash( 'sha256', $support['proof'] ),
		);
		$this->current( $receipt, $user, $topic, true );
		$token = bin2hex( random_bytes( 16 ) );
		if ( ! set_transient( $this->key( $user, $token ), $receipt, 900 ) ) {
			return '';
		}
		return $token;
	}
	private function key( int $user, string $token ): string {
		return 'fi_answer_feedback_' . $user . '_' . $token;}
	private function receipt( string $token, int $objectId, int $user ): array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			$this->unavailable();
		}
		$receipt = get_transient( $this->key( $user, $token ) );
		if ( ! is_array( $receipt ) || count( $receipt ) !== 9 || ( $receipt['version'] ?? null ) !== 1 || ( $receipt['site'] ?? null ) !== get_current_blog_id() || ( $receipt['user'] ?? null ) !== $user || ( $receipt['topic'] ?? null ) !== $objectId || ! is_int( $receipt['issued'] ?? null ) || $receipt['issued'] > time() || $receipt['issued'] < time() - 900 || ! $this->shape( $receipt ) ) {
			$this->unavailable();
		}
		return $receipt;
	}
	private function unavailable(): never {
		throw new Failure( 'not_found', __( 'This answer feedback is unavailable. Ask again for a current answer.', 'forum-intelligence-for-bbpress' ), 404 );}
	/** Strictly admit stored source fingerprints; legacy source-only feedback is not relabelled. */
	private function shape( array $data ): bool {
		if ( ( $data['version'] ?? null ) !== 1 || ! is_int( $data['source'] ?? null ) || $data['source'] <= 0 || ! is_int( $data['primary'] ?? null ) || $data['primary'] <= 0 || ! is_string( $data['response'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['response'] ) || ! is_array( $data['sources'] ?? null ) || ! array_is_list( $data['sources'] ) || count( $data['sources'] ) < 2 || count( $data['sources'] ) > Evidence::SOURCES + 2 ) {
			return false;
		}
		$ids = array();
		foreach ( $data['sources']as $source ) {
			if ( ! is_array( $source ) || count( $source ) !== 2 || ! is_int( $source['id'] ?? null ) || $source['id'] <= 0 || isset( $ids[ $source['id'] ] ) || ! is_string( $source['hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $source['hash'] ) ) {
				return false;
			}$ids[ $source['id'] ] = true;}
		return isset( $ids[ $data['source'] ], $ids[ $data['primary'] ] );
	}
	private function current( array $data, int $user, int $topic, bool $exact ): bool {
		if ( ! $this->shape( $data ) || get_post_type( $topic ) !== bbp_get_topic_post_type() || $this->access->topic( $data['source'] ) !== $topic || get_post_type( $data['primary'] ) !== bbp_get_reply_post_type() || $this->access->topic( $data['primary'] ) !== $topic ) {
			$this->unavailable();
		}
		$this->access->read( $topic, $user );
		if ( ! $this->access->isPublic( $topic ) ) {
			$this->unavailable();
		}
		$stale = false;
		foreach ( $data['sources']as $source ) {
			$this->access->read( $source['id'], $user );
			if ( ! $this->access->isPublic( $source['id'] ) ) {
				$this->unavailable();
			}$stale = $stale || ! hash_equals( $source['hash'], Indexer::hash( $source['id'] ) );}
		$solutions = new \ForumIntelligence\Domain\Solutions\Solutions( $this->store, $this->access );
		$stale     = $stale || $solutions->state( $topic, $user )['primary_reply'] !== $data['primary'];
		if ( $exact && $stale ) {
			throw new Failure( 'source_changed', __( 'The supporting evidence changed. Ask again before sending feedback.', 'forum-intelligence-for-bbpress' ), 409 );
		}
		return $stale;
	}
	public function submit( int $objectId, string $value, int $user, string $token ): array {
		if ( ! array_key_exists( $value, self::labels() ) ) {
			throw new Failure( 'invalid_feedback', __( 'Invalid feedback.', 'forum-intelligence-for-bbpress' ) );
		}
		if ( ! $this->access->activeMember( $user ) || ! user_can( $user, 'publish_replies' ) ) {
			throw new Failure( 'forbidden', __( 'You cannot participate in this discussion.', 'forum-intelligence-for-bbpress' ), 403 );
		}
		$receipt = $this->receipt( $token, $objectId, $user );
		$this->current( $receipt, $user, $objectId, true );
		$this->store->limit( $user, 'ai_feedback', 10 );
		return $this->store->transaction(
			function () use ( $objectId, $value, $user, $token ): array {
				$receipt = $this->receipt( $token, $objectId, $user );
				$this->access->participate( $objectId, $user );
				$this->current( $receipt, $user, $objectId, true );
				$key = 'answer_feedback:' . $user . ':' . $receipt['response'];
				$row = $this->store->row( "SELECT * FROM {$this->store->table('records')} WHERE record_key=%s FOR UPDATE", array( $key ) );
				if ( $row && ( $row['kind'] !== 'ai_feedback' || (int) $row['owner_id'] !== $user || (int) $row['object_id'] !== $objectId ) ) {
					$this->unavailable();
				}
				$previous = $row ? json_decode( $row['payload'], true ) : null;
				if ( is_array( $previous ) && ( $previous['value'] ?? null ) === $value && $this->shape( $previous ) ) {
					return array( 'saved' => true );
				}
				$data = array(
					'version'     => 1,
					'value'       => $value,
					'state'       => 'open',
					'response'    => $receipt['response'],
					'source'      => $receipt['source'],
					'primary'     => $receipt['primary'],
					'sources'     => $receipt['sources'],
					'reviewed_at' => null,
				);
				$this->store->saveRecord( 'ai_feedback', $key, $user, $objectId, $data );
				$this->access->participate( $objectId, $user );
				$this->current( $receipt, $user, $objectId, true );
				$this->store->event(
					'ai_feedback_submitted',
					$objectId,
					$user,
					array(
						'value'    => $value,
						'response' => $receipt['response'],
					)
				);
				return array( 'saved' => true );
			}
		);
	}
	/** @phpstan-impure Current native permissions can change during intervening storage callbacks. */
	private function admitted( array $row, int $user ): ?array {
		$data = json_decode( (string) $row['payload'], true );
		if ( $row['kind'] !== 'ai_feedback' || ! is_array( $data ) || count( $data ) !== 8 || ! $this->shape( $data ) || ! is_string( $data['value'] ?? null ) || ! array_key_exists( $data['value'], self::labels() ) || ! is_string( $data['state'] ?? null ) || ! array_key_exists( $data['state'], self::states() ) || (int) $row['owner_id'] <= 0 || $row['record_key'] !== 'answer_feedback:' . $row['owner_id'] . ':' . $data['response'] ) {
			return null;
		}
		try {
			$stale = $this->current( $data, $user, (int) $row['object_id'], false );
		} catch ( Failure $failure ) {
			return null;}
		return array(
			'id'         => (int) $row['id'],
			'version'    => (int) $row['version'],
			'topic'      => (int) $row['object_id'],
			'title'      => wp_strip_all_tags( get_the_title( (int) $row['object_id'] ) ),
			'url'        => get_permalink( (int) $row['object_id'] ),
			'value'      => $data['value'],
			'state'      => $data['state'],
			'stale'      => $stale,
			'updated_at' => $row['updated_at'],
		);
	}
	/** Keyset page. Hidden, erased or malformed records never expose source metadata. */
	public function listing( int $user, int $before = PHP_INT_MAX ): array {
		$this->access->moderate( $user );
		$rows  = $this->store->rows( "SELECT * FROM {$this->store->table('records')} WHERE kind='ai_feedback' AND id<%d ORDER BY id DESC LIMIT 25", array( max( 1, $before ) ) );
		$items = array();
		foreach ( $rows as $row ) {
			$item = $this->admitted( $row, $user );
			if ( $item ) {
				$items[] = $item;
			}
		}
		return array(
			'items' => $items,
			'next'  => count( $rows ) === 25 ? (int) end( $rows )['id'] : null,
		);
	}
	public function review( int $id, string $state, int $user, int $version ): array {
		$this->access->moderate( $user );
		if ( ! array_key_exists( $state, self::states() ) || $version < 0 ) {
			throw new Failure( 'invalid_feedback', __( 'Choose a valid feedback review.', 'forum-intelligence-for-bbpress' ) );
		}
		return $this->store->transaction(
			function () use ( $id, $state, $user, $version ): array {
				$this->access->moderate( $user );
				$row = $this->store->row( "SELECT * FROM {$this->store->table('records')} WHERE id=%d AND kind='ai_feedback' FOR UPDATE", array( $id ) );
				if ( ! $row || ! $this->admitted( $row, $user ) ) {
					$this->unavailable();
				}
				if ( (int) $row['version'] !== $version ) {
					throw new Failure( 'conflict', __( 'Another reviewer changed this feedback. Refresh before trying again.', 'forum-intelligence-for-bbpress' ), 409 );
				}
				$data = json_decode( $row['payload'], true );
				if ( $data['state'] === $state ) {
					return array( 'saved' => true );
				}
				$data['state']       = $state;
				$data['reviewed_at'] = $this->store->now();
				$this->store->saveRecord( 'ai_feedback', $row['record_key'], (int) $row['owner_id'], (int) $row['object_id'], $data );
				$this->access->moderate( $user );
				if ( ! $this->admitted( $row, $user ) ) {
					$this->unavailable();
				}
				$this->store->event(
					'ai_feedback_reviewed',
					(int) $row['object_id'],
					$user,
					array(
						'feedback' => $id,
						'state'    => $state,
					),
					(int) $row['owner_id']
				);
				return array( 'saved' => true );
			}
		);
	}
}
