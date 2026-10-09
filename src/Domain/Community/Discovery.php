<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Community;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Reputation\Reputation;
use ForumIntelligence\Domain\Solutions\Solutions;
final class Discovery {
	public function __construct( private Store $store, private Access $access, private Following $following, private Solutions $solutions, private ?Reputation $reputation ) {}
	public function topics( int $user, string $mode = 'trending', int $page = 1 ): array {
		$recent     = gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 );
		$posts      = ( new \WP_Query(
			array(
				'post_type'      => bbp_get_topic_post_type(),
				'post_status'    => array( 'publish', 'closed' ),
				'posts_per_page' => 100,
				'paged'          => max( 1, min( 100, $page ) ),
				'orderby'        => 'date',
				'no_found_rows'  => true,
			)
		) )->posts;
		$topicMutes = $this->following->mutedMany( $user, array_map( static fn( \WP_Post $post ): int => $post->ID, $posts ) );
		$posts      = array_values( array_filter( $posts, fn( \WP_Post $post ): bool => $this->access->canRead( $post->ID, $user ) && ! isset( $topicMutes[ $post->ID ] ) ) );
		$ids        = array_map( static fn( \WP_Post $post ): int => $post->ID, $posts );
		$activity   = array();
		$replies    = array();
		if ( $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// Raw evidence has a fixed recent-ID window, never a private helpfulness rank.
			$signals   = $this->store->rows(
				"SELECT s.object_id,s.user_id,s.kind,s.value,p.post_parent,p.post_type FROM {$this->store->table('signals')} s INNER JOIN {$this->store->wordpressTable('posts')} p ON p.ID=s.object_id WHERE s.updated_at>%s AND p.post_status IN ('publish','closed') AND (p.ID IN ($placeholders) OR (p.post_type=%s AND p.post_parent IN ($placeholders))) ORDER BY s.id DESC LIMIT 2000",
				array( $recent, ...$ids, bbp_get_reply_post_type(), ...$ids )
			);
			$replyRows = $this->store->rows(
				"SELECT ID,post_parent FROM {$this->store->wordpressTable('posts')} WHERE post_type=%s AND post_status='publish' AND post_parent IN ($placeholders) ORDER BY ID DESC LIMIT 2000",
				array( bbp_get_reply_post_type(), ...$ids )
			);
			$sources   = array_values( array_unique( array_merge( array_map( 'intval', array_column( $signals, 'object_id' ) ), array_map( 'intval', array_column( $replyRows, 'ID' ) ) ) ) );
			if ( $sources ) {
				_prime_post_caches( $sources, false, true );
			}
			$muted   = $this->following->mutedMany( $user, $sources );
			$members = array_values( array_unique( array_map( 'intval', array_column( $signals, 'user_id' ) ) ) );
			if ( $members ) {
				cache_users( $members );
				update_meta_cache( 'user', $members );
			}
			$allowed       = array();
			$sourceAllowed = function ( int $source ) use ( &$allowed, $muted, $user ): bool {
				return $allowed[ $source ] ??= ! isset( $muted[ $source ] ) && $this->access->canRead( $source, $user );
			};
			$positiveKinds = array( 'vote', 'confirm', ...array_map( static fn( string $reaction ): string => 'reaction_' . $reaction, \ForumIntelligence\Domain\Engagement\Engagement::REACTIONS ) );
			$participants  = array();
			$active        = array();
			foreach ( $signals as $signal ) {
				$member              = (int) $signal['user_id'];
				$active[ $member ] ??= $this->access->activeMember( $member );
				if ( ! $active[ $member ] || ! $sourceAllowed( (int) $signal['object_id'] ) ) {
					continue;
				}
				$topic = $signal['post_type'] === bbp_get_reply_post_type() ? (int) $signal['post_parent'] : (int) $signal['object_id'];
				if ( ! in_array( $topic, $ids, true ) || (int) $signal['value'] !== 1 || ! in_array( $signal['kind'], $positiveKinds, true ) ) {
					continue;
				}
				$participants[ $topic ][ $member ] = true;
				if ( $signal['kind'] === 'confirm' ) {
					$activity[ $topic ] = ( $activity[ $topic ] ?? 0 ) + 3;
				} elseif ( $signal['kind'] === 'vote' ) {
					$activity[ $topic ] = ( $activity[ $topic ] ?? 0 ) + 1;
				}
			}
			foreach ( $participants as $topic => $people ) {
				$activity[ $topic ] = ( $activity[ $topic ] ?? 0 ) + count( $people );
			}
			foreach ( $replyRows as $reply ) {
				if ( $sourceAllowed( (int) $reply['ID'] ) ) {
					$topic             = (int) $reply['post_parent'];
					$replies[ $topic ] = ( $replies[ $topic ] ?? 0 ) + 1;
				}
			}
		}

		$this->solutions->prime( array_map( static fn( \WP_Post $p ): int=>$p->ID, $posts ) );
		$profile = $user && $this->reputation ? $this->reputation->profile( $user, $user ) : array( 'expertise' => array() );
		$items   = array();
		foreach ( $posts as $post ) {
			if ( ! $this->access->canRead( $post->ID, $user ) || isset( $topicMutes[ $post->ID ] ) ) {
				continue; }
			$state   = $this->solutions->state( $post->ID, $user );
			$reasons = array();
			$tags    = wp_get_object_terms( $post->ID, bbp_get_topic_tag_tax_id(), array( 'fields' => 'names' ) );
			$tags    = is_wp_error( $tags ) ? array() : $tags;
			if ( $mode === 'help' ) {
				if ( $state['solved'] || ! array_intersect( $tags, array_keys( $profile['expertise'] ) ) ) {
					continue; }
				$reasons[] = 'matches_demonstrated_expertise';
			} elseif ( $mode === 'solved' ) {
				if ( ! $state['solved'] ) {
					continue;
				} $reasons[] = 'solved_discussion'; }
			$replyCount = $replies[ $post->ID ] ?? 0;
			$age        = max( 0, ( time() - ( strtotime( $post->post_date_gmt . ' UTC' ) ?: time() ) ) / 86400 );
			$score      = ( log1p( $replyCount ) + log1p( $activity[ $post->ID ] ?? 0 ) ) / ( 1 + $age / 7 );
			$score      = (float) apply_filters( 'fi_trending_score', $score, $post->ID, $age, $replyCount, $activity[ $post->ID ] ?? 0 );
			if ( $mode === 'trending' ) {
				$reasons[] = 'recent_helpful_activity'; }
			$items[] = array(
				'id'      => $post->ID,
				'title'   => wp_strip_all_tags( $post->post_title ),
				'url'     => get_permalink( $post ),
				'score'   => $score,
				'date'    => $post->post_date_gmt,
				'solved'  => $state['solved'],
				'reasons' => $reasons,
			);
		}
		usort( $items, static fn( array $x, array $y ): int=>$mode === 'recent' ? strcmp( $y['date'], $x['date'] ) : $y['score'] <=> $x['score'] );
		return array(
			'items'            => array_slice( $items, 0, 20 ),
			'page'             => $page,
			'mode'             => $mode,
			'candidate_window' => 100,
			'evidence_window'  => 2000,
		);
	}
	public function experts( int $viewer, string $tag ): array {
		if ( ! $this->reputation || mb_strlen( $tag ) > 100 ) {
			return array( 'items' => array() ); }
		// Group only matching domain sources; never compute fifty complete profiles on a public request.
		$rows    = $this->store->rows( "SELECT e.subject_id,e.object_id,SUM(e.points) AS points,p.post_parent,p.post_type FROM {$this->store->table('events')} e INNER JOIN {$this->store->wordpressTable('posts')} p ON p.ID=e.object_id INNER JOIN {$this->store->wordpressTable('term_relationships')} tr ON tr.object_id=IF(p.post_type=%s,p.post_parent,p.ID) INNER JOIN {$this->store->wordpressTable('term_taxonomy')} tx ON tx.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$this->store->wordpressTable('terms')} term ON term.term_id=tx.term_id WHERE e.subject_id>0 AND p.post_author=e.subject_id AND e.points<>0 AND p.post_type IN (%s,%s) AND tx.taxonomy=%s AND term.name=%s GROUP BY e.subject_id,e.object_id,p.post_parent,p.post_type HAVING SUM(e.points)>0 ORDER BY e.object_id DESC LIMIT 1000", array( bbp_get_reply_post_type(), bbp_get_topic_post_type(), bbp_get_reply_post_type(), bbp_get_topic_tag_tax_id(), $tag ) );
		$ids     = array_values( array_unique( array_map( 'intval', array_column( $rows, 'object_id' ) ) ) );
		$parents = array_values( array_unique( array_filter( array_map( 'intval', array_column( $rows, 'post_parent' ) ) ) ) );
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );}
		if ( $parents ) {
			_prime_post_caches( $parents, false, true );}
		$muted   = $this->following->mutedMany( $viewer, $ids );
		$members = array();
		foreach ( $rows as $row ) {
			$source = (int) $row['object_id'];
			$member = (int) $row['subject_id'];
			if ( ! $this->access->isPublic( $source ) || ! $this->access->canRead( $source, 0 ) || isset( $muted[ $source ] ) ) {
				continue;}
			$members[ $member ]['points']             = ( $members[ $member ]['points'] ?? 0 ) + (int) $row['points'];
			$members[ $member ]['sources'][ $source ] = true;
		}
		$eligible = array_keys( array_filter( $members, static fn( array $evidence ): bool => count( $evidence['sources'] ) >= 3 ) );
		if ( $eligible ) {
			cache_users( $eligible );
			update_meta_cache( 'user', $eligible ); }
		$items = array();
		foreach ( $members as $member => $evidence ) {
			$samples = count( $evidence['sources'] );
			if ( $samples < 3 ) {
				continue; }
			$user = get_userdata( $member );
			if ( ! $user || ! $this->access->activeMember( $member ) ) {
				continue;}
			$items[] = array(
				'member'  => $member,
				'name'    => $user->display_name,
				'tag'     => $tag,
				'points'  => $evidence['points'],
				'samples' => $samples,
				'url'     => bbp_get_user_profile_url( $member ),
			);
		}
		usort( $items, static fn( array $x, array $y ): int=>$y['points'] <=> $x['points'] );
		return array(
			'items'             => array_slice( $items, 0, 10 ),
			'minimum_samples'   => 3,
			'candidate_sources' => 1000,
		);
	}
}
