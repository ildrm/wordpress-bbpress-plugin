<?php
declare(strict_types=1);
namespace ForumIntelligence\Infrastructure\Search;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
/** Bounded local English spelling aid. It never silently replaces a member's question. */
final class Spelling {
	public function __construct( private Store $store, private Access $access ) {}
	public function suggest( string $query, array $filters, int $user ): string {
		$query = strtolower( trim( $query ) );
		if ( strlen( $query ) > 100 || ! preg_match( '/^[a-z]{4,40}(?: [a-z]{4,40}){0,3}$/D', $query ) ) {
			return '';
		}
		$terms     = explode( ' ', $query );
		$prefix    = implode( ' ', array_map( static fn( string $term ): string=>substr( $term, 0, 3 ) . '*', $terms ) );
		$where     = "MATCH(c.content) AGAINST (%s IN BOOLEAN MODE) AND c.source_type IN (%s,%s) AND p.post_status IN ('publish','closed')";
		$arguments = array( $prefix, bbp_get_topic_post_type(), bbp_get_reply_post_type() );
		if ( ! empty( $filters['forum'] ) ) {
			$where      .= ' AND c.forum_id=%d';
			$arguments[] = (int) $filters['forum'];}
		$rows  = $this->store->rows( "SELECT c.source_id FROM {$this->store->table('chunks')} c INNER JOIN {$this->store->wordpressTable('posts')} p ON p.ID=c.source_id WHERE $where ORDER BY c.source_id DESC LIMIT 50", $arguments );
		$words = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['source_id'];
			if ( ! $this->access->canRead( $id, $user ) || ! $this->access->isPublic( $id ) ) {
				continue;
			}$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}preg_match_all( '/[a-z]{4,40}/', strtolower( mb_substr( $post->post_title, 0, 200 ) ), $matches );
			foreach ( array_slice( $matches[0], 0, 20 )as $word ) {
				$words[ $word ] = true;
			}
		}
		$corrected = array();
		$changed   = false;
		foreach ( $terms as $term ) {
			if ( isset( $words[ $term ] ) ) {
				$corrected[] = $term;
				continue;
			}$best     = '';
			$distance  = strlen( $term ) >= 8 ? 2 : 1;
			$ambiguous = false;
			foreach ( array_keys( $words )as $word ) {
				if ( substr( $word, 0, 3 ) !== substr( $term, 0, 3 ) || abs( strlen( $word ) - strlen( $term ) ) > $distance ) {
							continue;
				}$candidate = levenshtein( $term, $word );
				if ( $candidate > $distance ) {
					continue;
				}if ( $candidate < $distance || $best === '' ) {
					$best      = $word;
					$distance  = $candidate;
					$ambiguous = false;
				} elseif ( $word !== $best ) {
					$ambiguous = true;
				}
			}
			if ( $best === '' || $ambiguous ) {
				$corrected[] = $term;
				continue;
			}$corrected[] = $best;
			$changed      = true;
		}
		return $changed ? implode( ' ', $corrected ) : '';
	}
}
