<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Community;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
final class Following {
	public function __construct( private Store $store, private Access $access ) {}
	public function set( int $user, int $target, string $type, string $mode, bool $enabled ): array {
		if ( ! $user || ! user_can( $user, 'publish_replies' ) ) {
			throw new Failure( 'forbidden', __( 'Participation permission is required.', 'forum-intelligence-for-bbpress' ), 403 ); }
		if ( ! in_array( $type, array( 'topic', 'forum', 'tag', 'user' ), true ) || ! in_array( $mode, array( 'follow', 'mute', 'block' ), true ) || ( $mode === 'block' && $type !== 'user' ) ) {
			throw new Failure( 'invalid_follow', __( 'Invalid follow preference.', 'forum-intelligence-for-bbpress' ) ); }
		if ( in_array( $type, array( 'topic', 'forum' ), true ) ) {
			$this->access->read( $target, $user );
			if ( get_post_type( $target ) !== ( $type === 'topic' ? bbp_get_topic_post_type() : bbp_get_forum_post_type() ) ) {
				throw new Failure( 'invalid_target', __( 'Invalid follow target.', 'forum-intelligence-for-bbpress' ) ); }
		} elseif ( $type === 'user' ) {
			if ( ! get_userdata( $target ) || $target === $user ) {
				throw new Failure( 'invalid_target', __( 'Choose another member.', 'forum-intelligence-for-bbpress' ) ); }
		} elseif ( ! get_term( $target, bbp_get_topic_tag_tax_id() ) || is_wp_error( get_term( $target, bbp_get_topic_tag_tax_id() ) ) ) {
			throw new Failure( 'invalid_target', __( 'Invalid tag.', 'forum-intelligence-for-bbpress' ) ); }
		$this->store->limit( $user, 'follow', 30 );
		$kind = $mode . '_' . $type;
		if ( $enabled ) {
			$this->store->execute( "INSERT IGNORE INTO {$this->store->table('links')} (user_id,target_id,kind,payload,created_at) VALUES (%d,%d,%s,'{}',%s)", array( $user, $target, $kind, $this->store->now() ) );
		} else {
			$this->store->execute( "DELETE FROM {$this->store->table('links')} WHERE user_id=%d AND target_id=%d AND kind=%s", array( $user, $target, $kind ) ); }
		return array( 'saved' => true );
	}
	public function muted( int $user, int $objectId ): bool {
		$topic  = $this->access->topic( $objectId );
		$forum  = $this->access->forum( $objectId );
		$author = (int) get_post_field( 'post_author', $objectId );
		$terms  = $topic ? get_the_terms( $topic, bbp_get_topic_tag_tax_id() ) : array();
		$tags   = is_array( $terms ) ? array_map( static fn( \WP_Term $term ): int=> (int) $term->term_id, $terms ) : array();
		if ( $tags ) {
			$placeholders = implode( ',', array_fill( 0, count( $tags ), '%d' ) );
			if ( $this->store->row( "SELECT id FROM {$this->store->table('links')} WHERE user_id=%d AND kind='mute_tag' AND target_id IN ($placeholders) LIMIT 1", array( $user, ...$tags ) ) ) {
				return true; }
		}
		return (bool) $this->store->row( "SELECT id FROM {$this->store->table('links')} WHERE user_id=%d AND ((kind='mute_topic' AND target_id=%d) OR (kind='mute_forum' AND target_id=%d) OR (kind IN ('mute_user','block_user') AND target_id=%d)) LIMIT 1", array( $user, $topic, $forum, $author ) );
	}

	/** Evaluate a bounded candidate list against current preferences without per-source SQL. */
	public function mutedMany( int $user, array $objectIds ): array {
		$objectIds = array_values( array_unique( array_filter( array_map( 'absint', $objectIds ) ) ) );
		if ( ! $user || ! $objectIds ) {
			return array(); }
		$result = array();
		foreach ( array_chunk( $objectIds, 1000 ) as $batch ) {
			$context = array();
			$targets = array();
			$topics  = array();
			foreach ( $batch as $objectId ) {
				$topic                = $this->access->topic( $objectId );
				$forum                = $this->access->forum( $objectId );
				$author               = (int) get_post_field( 'post_author', $objectId );
				$context[ $objectId ] = array( $topic, $forum, $author );
				foreach ( array( $topic, $forum, $author ) as $target ) {
					if ( $target ) {
						$targets[ $target ] = true; }
				}
				if ( $topic ) {
					$topics[ $topic ] = true; }
			}
			$topicTags = array();
			$terms     = $topics ? wp_get_object_terms( array_keys( $topics ), bbp_get_topic_tag_tax_id(), array( 'fields' => 'all_with_object_id' ) ) : array();
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$topicId                         = (int) ( get_object_vars( $term )['object_id'] ?? 0 );
					$topicTags[ $topicId ][]         = (int) $term->term_id;
					$targets[ (int) $term->term_id ] = true;
				}
			}
			if ( ! $targets ) {
				continue; }
			$ids          = array_keys( $targets );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows         = $this->store->rows( "SELECT kind,target_id FROM {$this->store->table('links')} WHERE user_id=%d AND kind IN ('mute_topic','mute_forum','mute_user','block_user','mute_tag') AND target_id IN ($placeholders)", array( $user, ...$ids ) );
			$preferences  = array();
			foreach ( $rows as $row ) {
				$preferences[ $row['kind'] ][ (int) $row['target_id'] ] = true; }
			foreach ( $context as $objectId => [ $topic, $forum, $author ] ) {
				$muted = isset( $preferences['mute_topic'][ $topic ] ) || isset( $preferences['mute_forum'][ $forum ] ) || isset( $preferences['mute_user'][ $author ] ) || isset( $preferences['block_user'][ $author ] );
				foreach ( $topicTags[ $topic ] ?? array() as $tag ) {
					$muted = $muted || isset( $preferences['mute_tag'][ $tag ] ); }
				if ( $muted ) {
					$result[ $objectId ] = true; }
			}
		}
		return $result;
	}
	public function blocked( int $sender, int $recipient ): bool {
		return (bool) $this->store->row( "SELECT id FROM {$this->store->table('links')} WHERE kind='block_user' AND ((user_id=%d AND target_id=%d) OR (user_id=%d AND target_id=%d)) LIMIT 1", array( $sender, $recipient, $recipient, $sender ) );
	}
	public function feed( int $user, int $page = 1, string $mode = 'relevant' ): array {
		if ( ! $user ) {
			throw new Failure( 'forbidden', __( 'Sign in to view your feed.', 'forum-intelligence-for-bbpress' ), 403 ); }
		$follows = $this->store->rows( "SELECT kind,target_id FROM {$this->store->table('links')} WHERE user_id=%d AND kind LIKE 'follow_%' LIMIT 200", array( $user ) );
		$topics  = ( new \WP_Query(
			array(
				'post_type'      => bbp_get_topic_post_type(),
				'post_status'    => array( 'publish', 'closed' ),
				'posts_per_page' => 100,
				'paged'          => max( 1, min( 100, $page ) ),
				'orderby'        => 'date',
				'no_found_rows'  => true,
			)
		) )->posts;
		$items   = array();
		foreach ( $topics as $post ) {
			if ( ! $this->access->canRead( $post->ID, $user ) || $this->muted( $user, $post->ID ) ) {
				continue; }
			$reasons = array();
			$tags    = wp_get_object_terms( $post->ID, bbp_get_topic_tag_tax_id(), array( 'fields' => 'ids' ) );
			$tags    = is_wp_error( $tags ) ? array() : $tags;
			foreach ( $follows as $follow ) {
				$target = (int) $follow['target_id'];
				if ( ( $follow['kind'] === 'follow_forum' && $target === $this->access->forum( $post->ID ) ) || ( $follow['kind'] === 'follow_user' && $target === (int) $post->post_author ) || ( $follow['kind'] === 'follow_topic' && $target === $post->ID ) || ( $follow['kind'] === 'follow_tag' && in_array( $target, $tags, true ) ) ) {
					$reasons[] = $follow['kind']; }
			}
			if ( $mode === 'relevant' && ! $reasons ) {
				continue; }
			$items[] = array(
				'id'      => $post->ID,
				'title'   => wp_strip_all_tags( $post->post_title ),
				'url'     => get_permalink( $post ),
				'reasons' => $reasons,
				'date'    => $post->post_date_gmt,
			);
			if ( count( $items ) >= 20 ) {
				break; }
		}
		return array(
			'items' => $items,
			'page'  => $page,
		);
	}
}
