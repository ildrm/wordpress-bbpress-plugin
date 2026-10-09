<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Search;
use ForumIntelligence\Contracts\SearchEngine;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Solutions\Solutions;
use ForumIntelligence\Domain\Search\Ranking;
final class LexicalSearch implements SearchEngine {
	public function __construct( private Store $store, private Access $access, private Solutions $solutions ) {}
	public function search( string $query, array $filters, int $user ): array {
		$result = $this->retrieve( $query, $filters, $user, 0, true );
		if ( ! $result['items'] && (int) ( $filters['page'] ?? 1 ) === 1 ) {
			$suggestion = ( new Spelling( $this->store, $this->access ) )->suggest( $query, $filters, $user );
			if ( $suggestion !== '' && $this->retrieve( $suggestion, $filters, $user, 0, false )['items'] ) {
				$result['suggested_query'] = $suggestion;
			}
		}
		return $result;
	}
	/** Refresh one current native match without recording another search or using a provider. */
	public function matchingObject( string $query, int $user, int $objectId ): ?array {
		if ( $objectId <= 0 ) {
			return null;}
		return $this->retrieve( $query, array( 'per_page' => 1 ), $user, $objectId, false )['items'][0] ?? null;
	}
	/** Internal exact-source matching cannot be requested through public search filters. */
	public function matchesObject( string $query, array $filters, int $user, int $objectId ): bool {
		return $objectId > 0 && ! empty(
			$this->retrieve(
				$query,
				array_merge(
					$filters,
					array(
						'page'     => 1,
						'per_page' => 1,
					)
				),
				$user,
				$objectId,
				false
			)['items']
		);
	}
	private function retrieve( string $query, array $filters, int $user, int $objectId, bool $metrics ): array {
		$dates   = \ForumIntelligence\Domain\Search\DateRange::validate( $filters );
		$query   = mb_substr( sanitize_text_field( $query ), 0, 500 );
		$page    = max( 1, min( 25, (int) ( $filters['page'] ?? 1 ) ) );
		$perPage = max( 1, min( 30, (int) ( $filters['per_page'] ?? 10 ) ) );
		if ( mb_strlen( $query ) < 3 ) {
			return array(
				'items'    => array(),
				'has_more' => false,
				'page'     => $page,
			); }
		$types = array( bbp_get_topic_post_type(), bbp_get_reply_post_type() );
		if ( ( $filters['type'] ?? '' ) === 'forum' ) {
			$types = array( bbp_get_forum_post_type() ); } elseif ( ( $filters['type'] ?? '' ) === 'topic' ) {
			$types = array( bbp_get_topic_post_type() ); } elseif ( ( $filters['type'] ?? '' ) === 'reply' ) {
				$types = array( bbp_get_reply_post_type() ); }
			$args = array(
				'post_type'        => $types,
				'post_status'      => array( 'publish', 'closed' ),
				's'                => $query,
				'posts_per_page'   => 500,
				'no_found_rows'    => true,
				'orderby'          => 'relevance',
				'suppress_filters' => false,
			);
			if ( ! empty( $filters['author'] ) ) {
				$args['author'] = (int) $filters['author']; }
			if ( $dates ) {
				$args['date_query'] = array(
					array(
						'column'    => 'post_date_gmt',
						'inclusive' => true,
					) + $dates,
				);}
			if ( $objectId > 0 ) {
				$args['post__in'] = array( $objectId );}
			$where   = array( 'MATCH(c.content) AGAINST (%s IN NATURAL LANGUAGE MODE)' );
			$values  = array( $query );
			$where[] = 'c.source_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$values  = array( ...$values, ...$types );
			if ( ! empty( $filters['forum'] ) ) {
				$where[]  = 'c.forum_id=%d';
				$values[] = (int) $filters['forum'];
				if ( $types === array( bbp_get_forum_post_type() ) ) {
					$args['post__in'] = array( (int) $filters['forum'] );
				} else {
					$args['meta_query'] = array(
						array(
							'key'     => '_bbp_forum_id',
							'value'   => (int) $filters['forum'],
							'compare' => '=',
							'type'    => 'NUMERIC',
						),
					);
				}
			}
			if ( ! empty( $filters['author'] ) ) {
				$where[]  = 'p.post_author=%d';
				$values[] = (int) $filters['author'];
			}
			foreach ( $dates as $key => $date ) {
				$where[]  = $key === 'after' ? 'p.post_date_gmt>=%s' : 'p.post_date_gmt<=%s';
				$values[] = $date . ( $key === 'after' ? ' 00:00:00' : ' 23:59:59' );
			}
			if ( ! empty( $filters['tag'] ) ) {
				$where[] = "EXISTS (SELECT 1 FROM {$this->store->wordpressTable('term_relationships')} tr INNER JOIN {$this->store->wordpressTable('term_taxonomy')} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$this->store->wordpressTable('terms')} t ON t.term_id=tt.term_id WHERE tr.object_id=IF(c.source_type=%s,c.source_id,COALESCE((SELECT pm.meta_value FROM {$this->store->wordpressTable('postmeta')} pm WHERE pm.post_id=c.source_id AND pm.meta_key='_bbp_topic_id' LIMIT 1),0)) AND tt.taxonomy=%s AND t.slug=%s)";
				$values  = array( ...$values, bbp_get_topic_post_type(), bbp_get_topic_tag_tax_id(), sanitize_title( $filters['tag'] ) );
			}
			if ( $objectId > 0 ) {
				$where[]          = 'c.source_id=%d';
				$values[]         = $objectId;
				$args['post__in'] = array( $objectId );}
			$conditions = implode( ' AND ', $where );
			$candidates = $this->store->rows( "SELECT c.source_id FROM {$this->store->table('chunks')} c INNER JOIN {$this->store->wordpressTable('posts')} p ON p.ID=c.source_id WHERE $conditions ORDER BY MATCH(c.content) AGAINST (%s IN NATURAL LANGUAGE MODE) DESC,c.source_id DESC LIMIT 500", array( ...$values, $query ) );
			if ( $candidates ) {
				$args['post__in'] = array_map( 'intval', array_column( $candidates, 'source_id' ) );
				unset( $args['s'] );
				$args['orderby'] = 'post__in'; }
			$posts = ( new \WP_Query( $args ) )->posts;
			$this->solutions->prime( array_values( array_filter( array_map( fn( $post ): int=>$this->access->topic( (int) $post->ID ), $posts ) ) ) );
			$ids   = array_map( static fn( \WP_Post $post ): int=>$post->ID, $posts );
			$votes = array();
			if ( $ids ) {
				$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$rows  = $this->store->rows( "SELECT object_id,SUM(IF(kind='vote',value,0)) AS votes,SUM(IF(kind='confirm',value,0)) AS confirmations FROM {$this->store->table('signals')} WHERE object_id IN ($marks) GROUP BY object_id", $ids );
				foreach ( $rows as $row ) {
					$votes[ (int) $row['object_id'] ] = array(
						'votes'         => (int) $row['votes'],
						'confirmations' => (int) $row['confirmations'],
					); }
			}
			$items = array();
			foreach ( $posts as $post ) {
				$id = (int) $post->ID;
				if ( ! $this->access->canRead( $id, $user ) ) {
					continue; }
				$forum = $this->access->forum( $id );
				if ( ! empty( $filters['forum'] ) && (int) $filters['forum'] !== $forum ) {
					continue; }
				$topic = $this->access->topic( $id );
				if ( ! empty( $filters['tag'] ) && ( ! $topic || ! has_term( sanitize_title( $filters['tag'] ), bbp_get_topic_tag_tax_id(), $topic ) ) ) {
					continue;}
				$state = $topic ? $this->solutions->state( $topic, $user ) : array(
					'solved'        => false,
					'primary_reply' => 0,
					'answers'       => array(),
				);
				if ( isset( $filters['solved'] ) && $filters['solved'] !== '' && (bool) (int) $filters['solved'] !== $state['solved'] ) {
					continue; }
				if ( ! empty( $filters['unanswered'] ) && ( ! $topic || (int) get_post_meta( $topic, '_bbp_reply_count', true ) > 0 ) ) {
					continue; }
				if ( ! \ForumIntelligence\Domain\Search\Metadata::matches( $id, (array) ( $filters['fields'] ?? array() ) ) ) {
					continue; }
				$coverage = Ranking::lexical( $query, $post->post_title, wp_strip_all_tags( $post->post_content ) . "\n" . \ForumIntelligence\Domain\Search\Metadata::text( $id ) );
				if ( $coverage <= 0 ) {
					continue; }
				if ( isset( $filters['min_votes'] ) && ( $votes[ $id ]['votes'] ?? 0 ) < (int) $filters['min_votes'] ) {
					continue; }
				$solution = $state['primary_reply'];
				$items[]  = array(
					'id'               => $id,
					'topic'            => $topic,
					'forum'            => $forum,
					'title'            => wp_strip_all_tags( $post->post_title ),
					'snippet'          => mb_substr( wp_strip_all_tags( $post->post_content ), 0, 240 ),
					'url'              => get_permalink( $id ),
					'coverage'         => $coverage,
					'score'            => Ranking::hybrid( $coverage, 0, $state['solved'], $votes[ $id ]['votes'] ?? 0 ),
					'solved'           => $state['solved'],
					'solution'         => $solution,
					'source_proof'     => Presentation::proof( $id, $solution, $user ),
					'solution_url'     => $solution ? bbp_get_reply_url( $solution ) : null,
					'solution_preview' => $solution ? mb_substr( wp_strip_all_tags( (string) get_post_field( 'post_content', $solution ) ), 0, 240 ) : '',
					'votes'            => $votes[ $id ]['votes'] ?? 0,
					'confirmations'    => $votes[ $id ]['confirmations'] ?? 0,
					'date'             => $post->post_date_gmt,
				);
			}
			if ( ! empty( $filters['explain'] ) && user_can( $user, 'manage_options' ) ) {
				foreach ( $items as &$item ) {
					$item['ranking'] = Ranking::explain( $item['coverage'], 0, $item['solved'], $item['votes'], $candidates ? 'fulltext' : 'native' );
				}
				unset( $item );
			}
			$sort = $filters['sort'] ?? 'relevance';
			usort( $items, static fn( array $a, array $b ): int => $sort === 'recent' ? strcmp( $b['date'], $a['date'] ) : ( $b['score'] <=> $a['score'] ?: $b['id'] <=> $a['id'] ) );
			$start = ( $page - 1 ) * $perPage;
			if ( $metrics ) {
				$this->store->metric( 'search_performed' );
				if ( ! $items ) {
					$this->store->metric( 'search_zero_result' );}
			}
			return array(
				'items'    => array_slice( $items, $start, $perPage ),
				'has_more' => count( $items ) > $start + $perPage,
				'page'     => $page,
			);
	}
}
