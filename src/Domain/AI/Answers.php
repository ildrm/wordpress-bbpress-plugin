<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\AI;
use ForumIntelligence\Contracts\SearchEngine;
use ForumIntelligence\Contracts\TextProvider;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Search\Ranking;
use ForumIntelligence\Domain\Failure;
final class Answers {
	public function __construct( private SearchEngine $search, private TextProvider $provider, private Access $access, private Store $store ) {}
	public function ask( string $question, int $user ): array {
		if ( ! $user || ! user_can( $user, 'publish_topics' ) ) {
			throw new Failure( 'forbidden', __( 'Sign in with posting permission to ask the community.', 'forum-intelligence-for-bbpress' ), 403 ); }
		$question = mb_substr( sanitize_textarea_field( $question ), 0, 500 );
		$this->store->limit( $user, 'ask', 5, 300 );
		$found     = $this->search->search( $question, array( 'per_page' => Evidence::SOURCES ), $user );
		$items     = ( new \ForumIntelligence\Infrastructure\Search\Presentation( $this->store, $this->access ) )->refresh( array_slice( $found['items'], 0, Evidence::SOURCES ), $question, $user );
		$threshold = (float) ( get_option( 'fi_settings', array() )['evidence_threshold'] ?? 0.7 );
		if ( ! is_finite( $threshold ) ) {
			return $this->abstain( $question, $items, $user ); }
		$threshold = max( 0.5, min( 1, $threshold ) );
		$evidence  = array();
		foreach ( $items as $item ) {
			foreach ( array_filter( array( $item['id'], $item['solution'] ) ) as $id ) {
				$id = (int) $id;
				if ( isset( $evidence[ $id ] ) || ! $this->access->canRead( $id, $user ) || ! $this->access->isPublic( $id ) ) {
					continue; }
				$post            = get_post( $id );
				$evidence[ $id ] = array(
					'id'   => $id,
					'text' => Evidence::text( $post->post_title . "\n" . $post->post_content . "\n" . \ForumIntelligence\Domain\Search\Metadata::text( $id, true ) ),
					'hash' => \ForumIntelligence\Infrastructure\Search\Indexer::hash( $id ),
				);
			}
		}
		$grounding = array();
		foreach ( $items as $item ) {
			$id      = (int) $item['id'];
			$primary = (int) $item['solution'];
			if ( isset( $evidence[ $id ], $evidence[ $primary ] ) && Ranking::sufficient(
				array(
					array(
						'coverage' => Evidence::coverage( $question, $evidence[ $id ]['text'] ),
						'solution' => $primary,
					),
				),
				$threshold
			) ) {
				$grounding = array(
					'id'      => $id,
					'primary' => $primary,
				);
				break;
			}
		}
		if ( ! $grounding ) {
			return $this->abstain( $question, $items, $user ); }
		// Reserve the qualifying source and accepted primary before allocating the remaining budget.
		$selected = array();
		foreach ( array_unique( array( $grounding['id'], $grounding['primary'] ) ) as $id ) {
			$selected[ $id ] = $evidence[ $id ]; }
		foreach ( $evidence as $id => $source ) {
			if ( count( $selected ) >= Evidence::SOURCES ) {
				break; }
			$selected[ $id ] = $source;
		}
		$evidence = $selected;
		try {
			$generated = $this->provider->generate( mb_substr( Evidence::redact( $question ), 0, 1000 ), array_values( $evidence ) ); } catch ( \Throwable $failure ) {
			return $this->abstain( $question, $items, $user, 'provider_unavailable' ); }
			$citations = array();
			$answer    = is_string( $generated['answer'] ?? null ) ? mb_substr( wp_strip_all_tags( $generated['answer'] ), 0, 8000 ) : '';
			if ( ! trim( $answer ) || ! is_array( $generated['source_ids'] ?? null ) || ! array_is_list( $generated['source_ids'] ) || ! $generated['source_ids'] || count( $generated['source_ids'] ) > Evidence::SOURCES || array_filter( $generated['source_ids'], static fn( $id ): bool => ! is_int( $id ) || $id <= 0 ) ) {
				return $this->abstain( $question, $items, $user ); }
			if ( ! $this->currentGrounding( $grounding, $evidence, $user ) ) {
				return $this->abstain( $question, $items, $user, 'evidence_changed' ); }
			foreach ( array_unique( $generated['source_ids'] ) as $id ) {
				$id = (int) $id;
				if ( ! isset( $evidence[ $id ] ) || ! $this->access->canRead( $id, $user ) || ! $this->access->isPublic( $id ) ) {
					return $this->abstain( $question, $items, $user, 'evidence_changed' ); }
				$post = get_post( $id );
				if ( \ForumIntelligence\Infrastructure\Search\Indexer::hash( $post->ID ) !== $evidence[ $id ]['hash'] ) {
					return $this->abstain( $question, $items, $user, 'evidence_changed' ); }
				$citations[] = array(
					'id'    => $id,
					'title' => wp_strip_all_tags( $post->post_title ),
					'hash'  => $evidence[ $id ]['hash'],
					'url'   => $post->post_type === bbp_get_reply_post_type() ? bbp_get_reply_url( $id ) : get_permalink( $id ),
				);
			}
			$this->store->metric( 'ask_answer_generated' );
			$result                   = array(
				'state'     => 'answered',
				'answer'    => $answer,
				'citations' => $citations,
				'generated' => true,
			);
			$result['grounding']      = array(
				'source'  => $grounding['id'],
				'primary' => $grounding['primary'],
				'proof'   => $this->groundingProof( $grounding, $evidence, $result, $user ),
			);
			$result['feedback_token'] = ( new Feedback( $this->store, $this->access ) )->offer( $result, $user, $this );
			return $result;
	}
	/** Read-only validation of an owner/site-bound generated response; no provider, metrics or refresh. */
	public function canPresent( array $result, int $user ): bool {
		$support = $result['grounding'] ?? null;
		if ( $user <= 0 || ( $result['state'] ?? '' ) !== 'answered' || ( $result['generated'] ?? null ) !== true || ! is_array( $support ) || ! is_int( $support['source'] ?? null ) || $support['source'] <= 0 || ! is_int( $support['primary'] ?? null ) || $support['primary'] <= 0 || ! is_string( $support['proof'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $support['proof'] ) || ! is_string( $result['answer'] ?? null ) || mb_strlen( $result['answer'] ) > 8000 || ! trim( $result['answer'] ) || ! is_array( $result['citations'] ?? null ) || ! array_is_list( $result['citations'] ) || ! $result['citations'] || count( $result['citations'] ) > Evidence::SOURCES ) {
			return false; }
		$grounding = array(
			'id'      => $support['source'],
			'primary' => $support['primary'],
		);
		$evidence  = array();
		foreach ( array_unique( array( $grounding['id'], $grounding['primary'] ) ) as $id ) {
			if ( ! $this->access->canRead( $id, $user ) || ! $this->access->isPublic( $id ) ) {
				return false; }
			$evidence[ $id ] = array( 'hash' => \ForumIntelligence\Infrastructure\Search\Indexer::hash( $id ) );
		}
		foreach ( $result['citations'] as $citation ) {
			if ( ! is_array( $citation ) || ! is_int( $citation['id'] ?? null ) || $citation['id'] <= 0 || ! is_string( $citation['hash'] ?? null ) || ! $this->access->canRead( $citation['id'], $user ) || ! $this->access->isPublic( $citation['id'] ) || ! hash_equals( $citation['hash'], \ForumIntelligence\Infrastructure\Search\Indexer::hash( $citation['id'] ) ) ) {
				return false; }
		}
		return $this->currentGrounding( $grounding, $evidence, $user ) && hash_equals( $support['proof'], $this->groundingProof( $grounding, $evidence, $result, $user ) );
	}
	/** Bind the original support hashes, rendered answer and citations to this site and reader. */
	private function groundingProof( array $grounding, array $evidence, array $result, int $user ): string {
		return hash_hmac( 'sha256', (string) wp_json_encode( array( get_current_blog_id(), $user, $grounding['id'], $evidence[ $grounding['id'] ]['hash'], $grounding['primary'], $evidence[ $grounding['primary'] ]['hash'], $result['answer'], $result['citations'] ) ), wp_salt( 'nonce' ) );
	}
	/** Recheck even uncited qualifying evidence and current primary selection after provider work. */
	private function currentGrounding( array $grounding, array $evidence, int $user ): bool {
		foreach ( array_unique( array( $grounding['id'], $grounding['primary'] ) ) as $id ) {
			if ( ! $this->access->canRead( $id, $user ) || ! $this->access->isPublic( $id ) || \ForumIntelligence\Infrastructure\Search\Indexer::hash( $id ) !== $evidence[ $id ]['hash'] ) {
				return false; }
		}
		$topic = $this->access->topic( $grounding['id'] );
		if ( ! $topic || ! $this->access->canRead( $topic, $user ) ) {
			return false; }
		$solutions = new \ForumIntelligence\Domain\Solutions\Solutions( $this->store, $this->access );
		return $solutions->state( $topic, $user )['primary_reply'] === $grounding['primary'];
	}
	private function abstain( string $question, array $items, int $user, string $reason = 'insufficient_evidence' ): array {
		$this->store->metric( 'ask_abstained' );
		return array(
			'state'        => 'abstained',
			'reason'       => $reason,
			'answer'       => __( 'We could not find a sufficiently supported answer in this community.', 'forum-intelligence-for-bbpress' ),
			'citations'    => array(),
			'topics'       => ( new \ForumIntelligence\Infrastructure\Search\Presentation( $this->store, $this->access ) )->refresh( $items, $question, $user ),
			'composer_url' => add_query_arg( 'fi_question', $question, bbp_get_forums_url() ),
		);
	}
	public function feedback( int $objectId, string $value, int $user, string $token = '' ): array {
		return ( new Feedback( $this->store, $this->access ) )->submit( $objectId, $value, $user, $token );
	}
}
