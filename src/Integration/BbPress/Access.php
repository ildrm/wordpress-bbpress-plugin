<?php
declare(strict_types=1);
namespace ForumIntelligence\Integration\BbPress;
use ForumIntelligence\Domain\Failure;
final class Access {
	public function topic( int $objectId ): int {
		$post = get_post( $objectId );
		if ( ! $post ) {
			return 0; }
		if ( $post->post_type === bbp_get_topic_post_type() ) {
			return $objectId; }
		return $post->post_type === bbp_get_reply_post_type() ? (int) bbp_get_reply_topic_id( $objectId ) : 0;
	}
	public function forum( int $objectId ): int {
		if ( get_post_type( $objectId ) === bbp_get_forum_post_type() ) {
			return $objectId; }
		$topic = $this->topic( $objectId );
		return $topic ? (int) bbp_get_topic_forum_id( $topic ) : 0;
	}
	public function activeMember( int $user ): bool {
		return $user > 0 && (bool) get_userdata( $user ) && ! bbp_is_user_inactive( $user ) && bbp_get_user_role( $user ) !== bbp_get_blocked_role();
	}
	public function canRead( int $objectId, int $user ): bool {
		if ( $user && ! $this->activeMember( $user ) ) {
			return false; }
		$post = get_post( $objectId );
		if ( ! $post || ! in_array( $post->post_type, array( bbp_get_topic_post_type(), bbp_get_reply_post_type(), bbp_get_forum_post_type() ), true ) ) {
			return false; }
		$forum = $this->forum( $objectId );
		if ( ! $forum || ! bbp_user_can_view_forum(
			array(
				'user_id'         => $user,
				'forum_id'        => $forum,
				'check_ancestors' => true,
			)
		) ) {
			return false; }
		foreach ( array_unique( array_filter( array( $objectId, $this->topic( $objectId ), $forum, ...get_post_ancestors( $forum ) ) ) ) as $id ) {
			$parent = get_post( $id );
			if ( ! $parent || ( $parent->post_password !== '' && ( $user !== get_current_user_id() || post_password_required( $parent ) ) ) || ! in_array( $parent->post_status, array( 'publish', 'closed', 'private', 'hidden' ), true ) ) {
				return false; }
		}
		return (bool) apply_filters( 'fi_can_read_object', true, $objectId, $user );
	}
	public function isPublic( int $objectId ): bool {
		$forum = $this->forum( $objectId );
		if ( function_exists( 'bbp_get_forum_group_ids' ) && bbp_get_forum_group_ids( $forum ) ) {
			return false; }
		if ( ! $forum || ! bbp_is_forum_public( $forum, true ) ) {
			return false; }
		foreach ( array_unique( array_filter( array( $objectId, $this->topic( $objectId ), $forum, ...get_post_ancestors( $forum ) ) ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_password !== '' || ! in_array( $post->post_status, array( 'publish', 'closed' ), true ) ) {
				return false; }
		}
		return (bool) apply_filters( 'fi_is_public_object', true, $objectId );
	}
	public function read( int $objectId, int $user ): void {
		if ( ! $this->canRead( $objectId, $user ) ) {
			throw new Failure( 'not_found', __( 'Content is unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
	}
	public function participate( int $objectId, int $user ): void {
		$this->read( $objectId, $user );
		if ( ! $user || bbp_is_user_inactive( $user ) || ! user_can( $user, 'publish_replies' ) ) {
			throw new Failure( 'forbidden', __( 'You cannot participate in this discussion.', 'forum-intelligence-for-bbpress' ), 403 ); }
	}
	public function moderate( int $user ): void {
		if ( ! $user || ! user_can( $user, 'moderate' ) ) {
			throw new Failure( 'forbidden', __( 'Moderation permission is required.', 'forum-intelligence-for-bbpress' ), 403 ); }
	}
	public function solve( int $topic, int $user ): void {
		$this->participate( $topic, $user );
		$author = (bool) ( get_option( 'fi_settings', array() )['author_acceptance'] ?? true );
		if ( ! ( user_can( $user, 'moderate' ) || ( (int) get_post_field( 'post_author', $topic ) === $user && $author ) ) || ! apply_filters( 'fi_can_accept_solution', true, $topic, $user ) ) {
			throw new Failure( 'forbidden', __( 'You cannot change this solution.', 'forum-intelligence-for-bbpress' ), 403 );
		}
	}
}
