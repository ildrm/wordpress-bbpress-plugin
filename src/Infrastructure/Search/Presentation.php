<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Search;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Solutions\Solutions;
use ForumIntelligence\Domain\Search\Ranking;
/** Reauthorize delayed results and reconstruct their display fields from native content. */
final class Presentation {
	public function __construct( private Store $store, private Access $access ) {}
	/** Opaque owner/site proof; never expose a raw fingerprint of restricted source text. */
	public static function proof( int $id, int $solution, int $user ): string {
		return hash_hmac( 'sha256', (string) wp_json_encode( array( get_current_blog_id(), $user, $id, Indexer::hash( $id ), $solution, $solution ? Indexer::hash( $solution ) : '' ) ), wp_salt( 'nonce' ) );
	}
	public function refresh( array $items, string $query, int $user ): array {
		// A fresh solution reader avoids request-local state retained across a provider call.
		$solutions = new Solutions( $this->store, $this->access );
		$lexical   = new LexicalSearch( $this->store, $this->access, $solutions );
		$result    = array();
		$seen      = array();
		foreach ( array_slice( $items, 0, 30 ) as $item ) {
			$id = is_array( $item ) ? (int) ( $item['id'] ?? 0 ) : 0;
			if ( $id <= 0 || isset( $seen[ $id ] ) || ! $this->access->canRead( $id, $user ) ) {
				continue;}
			$seen[ $id ] = true;
			$post        = get_post( $id );
			if ( ! $post || ! in_array( $post->post_type, array( bbp_get_topic_post_type(), bbp_get_reply_post_type() ), true ) || ! in_array( $post->post_status, array( 'publish', 'closed' ), true ) ) {
				continue;}
			$topic = $this->access->topic( $id );
			if ( ! $topic || ! $this->access->canRead( $topic, $user ) ) {
				continue;}
			$state    = $solutions->state( $topic, $user );
			$solution = $state['primary_reply'];
			$proof    = self::proof( $id, $solution, $user );
			if ( ! is_string( $item['source_proof'] ?? null ) || ! hash_equals( $proof, $item['source_proof'] ) ) {
				// Changed or legacy candidates must still match locally; stale semantic admission is discarded.
				$current = $lexical->matchingObject( $query, $user, $id );
				if ( $current ) {
					$result[] = $current;}
				continue;
			}
			$signals  = $this->store->row( "SELECT SUM(IF(kind='vote',value,0)) AS votes,SUM(IF(kind='confirm',value,0)) AS confirmations FROM {$this->store->table('signals')} WHERE object_id=%d", array( $id ) );
			$result[] = array(
				'id'               => $id,
				'topic'            => $topic,
				'forum'            => $this->access->forum( $id ),
				'title'            => wp_strip_all_tags( $post->post_title ),
				'snippet'          => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 240 ),
				'url'              => get_permalink( $id ),
				'coverage'         => Ranking::lexical( $query, $post->post_title, wp_strip_all_tags( $post->post_content ) . "\n" . \ForumIntelligence\Domain\Search\Metadata::text( $id ) ),
				'score'            => (float) ( $item['score'] ?? 0 ),
				'solved'           => $state['solved'],
				'solution'         => $solution,
				'source_proof'     => $proof,
				'solution_url'     => $solution ? bbp_get_reply_url( $solution ) : null,
				'solution_preview' => $solution ? mb_substr( wp_strip_all_tags( (string) get_post_field( 'post_content', $solution ) ), 0, 240 ) : '',
				'votes'            => (int) ( $signals['votes'] ?? 0 ),
				'confirmations'    => (int) ( $signals['confirmations'] ?? 0 ),
				'date'             => $post->post_date_gmt,
			);
		}
		return $result;
	}
}
