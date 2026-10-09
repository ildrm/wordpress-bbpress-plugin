<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Search;
use ForumIntelligence\Contracts\SearchEngine;
use ForumIntelligence\Contracts\EmbeddingProvider;
use ForumIntelligence\Contracts\VectorStore;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Search\Ranking;
use ForumIntelligence\Domain\Solutions\Solutions;
use ForumIntelligence\Infrastructure\Database\Store;
final class HybridSearch implements SearchEngine {
	public function __construct( private SearchEngine $lexical, private EmbeddingProvider $provider, private VectorStore $vectors, private Access $access, private Solutions $solutions, private ?Store $store = null ) {}
	public function search( string $query, array $filters, int $user ): array {
		$result   = $this->lexical->search( $query, $filters, $user );
		$advanced = ! empty( $filters['author'] ) || ! empty( $filters['tag'] ) || ! empty( $filters['after'] ) || ! empty( $filters['before'] ) || ! empty( $filters['unanswered'] ) || ! empty( $filters['type'] ) || ! empty( $filters['fields'] ) || isset( $filters['min_votes'] );
		if ( ( ! empty( $filters['forum'] ) && ! $this->access->isPublic( (int) $filters['forum'] ) ) || ( get_option( 'fi_settings', array() )['semantic'] ?? null ) !== true || mb_strlen( $query ) < 3 || (int) ( $filters['page'] ?? 1 ) > 1 || $advanced ) {
			return $result; }
		try {
			$candidates = $this->vectors->nearest( $this->provider->embed( \ForumIntelligence\Domain\AI\Evidence::text( $query, 500 ) ), 50 ); } catch ( \Throwable $failure ) {
			return $result; }
			$candidates = array_slice( $candidates, 0, 50, true );
			$ids        = array_values( array_filter( array_map( 'absint', array_keys( $candidates ) ) ) );
			$votes      = array();
			if ( $ids ) {
				$store = $this->store ?? new Store();
				$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				foreach ( $store->rows( "SELECT object_id,SUM(value) AS votes FROM {$store->table('signals')} WHERE kind='vote' AND object_id IN ($marks) GROUP BY object_id", $ids ) as $row ) {
					$votes[ (int) $row['object_id'] ] = (int) $row['votes'];
				}
			}
			$items = array();
			foreach ( $result['items'] as $item ) {
				$items[ $item['id'] ] = $item; }
			foreach ( $candidates as $id => $similarity ) {
				$id = (int) $id;
				if ( ( ! is_int( $similarity ) && ! is_float( $similarity ) ) || ! is_finite( (float) $similarity ) || $similarity < 0.5 || $similarity > 1 || ! $this->access->canRead( $id, $user ) ) {
					continue; }
				$post  = get_post( $id );
				$forum = $this->access->forum( $id );
				$topic = $this->access->topic( $id );
				if ( ! $post || ! $topic || ( ! empty( $filters['forum'] ) && (int) $filters['forum'] !== $forum ) ) {
					continue; }
				$state = $this->solutions->state( $topic, $user );
				if ( isset( $filters['solved'] ) && $filters['solved'] !== '' && (bool) (int) $filters['solved'] !== $state['solved'] ) {
					continue; }
				$coverage     = Ranking::lexical( $query, $post->post_title, wp_strip_all_tags( $post->post_content ) . "\n" . \ForumIntelligence\Domain\Search\Metadata::text( $id ) );
				$solution     = $state['primary_reply'];
				$items[ $id ] = array(
					'id'               => $id,
					'topic'            => $topic,
					'forum'            => $forum,
					'title'            => wp_strip_all_tags( $post->post_title ),
					'snippet'          => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 240 ),
					'url'              => get_permalink( $id ),
					'coverage'         => $coverage,
					'score'            => Ranking::hybrid( $coverage, (float) $similarity, $state['solved'], $votes[ $id ] ?? 0 ),
					'solved'           => $state['solved'],
					'solution'         => $solution,
					'source_proof'     => Presentation::proof( $id, $solution, $user ),
					'solution_url'     => $solution ? bbp_get_reply_url( $solution ) : null,
					'solution_preview' => $solution ? mb_substr( wp_strip_all_tags( (string) get_post_field( 'post_content', $solution ) ), 0, 240 ) : '',
					'votes'            => $votes[ $id ] ?? 0,
					'date'             => $post->post_date_gmt,
				);
				if ( ! empty( $filters['explain'] ) && user_can( $user, 'manage_options' ) ) {
					$items[ $id ]['ranking'] = Ranking::explain( $coverage, (float) $similarity, $state['solved'], $votes[ $id ] ?? 0, 'semantic' );
				}
			}
			usort( $items, static fn( array $a, array $b ): int => ( $filters['sort'] ?? '' ) === 'recent' ? strcmp( $b['date'], $a['date'] ) : $b['score'] <=> $a['score'] );
			$limit              = min( 30, max( 1, (int) ( $filters['per_page'] ?? 10 ) ) );
			$result['items']    = array_slice( $items, 0, $limit );
			$result['has_more'] = $result['has_more'] || count( $items ) > $limit;
			return $result;
	}
}
