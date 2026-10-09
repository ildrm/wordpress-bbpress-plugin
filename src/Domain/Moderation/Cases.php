<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Moderation;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
final class Cases {
	public const REASONS = array( 'spam', 'harassment', 'abuse', 'misinformation', 'dangerous', 'privacy', 'malicious_links', 'duplicate', 'off_topic', 'copyright', 'other' );
	public function __construct( private Store $store, private Access $access ) {}
	public function report( int $objectId, int $user, string $reason, string $notes ): array {
		$this->access->participate( $objectId, $user );
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			throw new Failure( 'invalid_reason', __( 'Choose a valid report reason.', 'forum-intelligence-for-bbpress' ) ); }
		$this->store->limit( $user, 'report', 5, 300 );
		$this->store->execute( "INSERT IGNORE INTO {$this->store->table('cases')} (object_id,reporter_id,reason,notes,created_at,updated_at) VALUES (%d,%d,%s,%s,%s,%s)", array( $objectId, $user, $reason, mb_substr( sanitize_textarea_field( $notes ), 0, 2000 ), $this->store->now(), $this->store->now() ) );
		$this->store->event( 'report_created', $objectId, $user, array( 'reason' => $reason ) );
		$this->store->metric( 'report_created' );
		return array( 'reported' => true );
	}
	public function listing( int $user, int $page = 1, string $status = 'new' ): array {
		$this->access->moderate( $user );
		$status = in_array( $status, array( 'new', 'triaged', 'investigating', 'actioned', 'dismissed', 'escalated' ), true ) ? $status : 'new';
		$rows   = $this->store->rows( "SELECT * FROM {$this->store->table('cases')} WHERE status=%s ORDER BY priority DESC,id DESC LIMIT 30 OFFSET %d", array( $status, ( max( 1, min( 1000, $page ) ) - 1 ) * 30 ) );
		$items  = array();
		foreach ( $rows as $row ) {
			if ( ! $this->canManage( (int) $row['object_id'], $user ) ) {
				continue; }
			$post           = get_post( $row['object_id'] );
			$row['title']   = $post ? wp_strip_all_tags( $post->post_title ) : __( 'Deleted content', 'forum-intelligence-for-bbpress' );
			$row['content'] = $post ? mb_substr( wp_strip_all_tags( $post->post_content ), 0, 2000 ) : '';
			$row['url']     = $post ? get_permalink( $post ) : '';
			$row['signals'] = $post ? $this->signals( $post->post_content ) : array();
			$items[]        = $row;
		}
		return array(
			'items' => $items,
			'page'  => max( 1, $page ),
		);
	}
	public function resolve( int $caseId, int $user, string $command, string $notes, int $assignee = 0 ): array {
		$this->access->moderate( $user );
		$row = $this->store->row( "SELECT * FROM {$this->store->table('cases')} WHERE id=%d", array( $caseId ) );
		if ( ! $row || ! $this->canManage( (int) $row['object_id'], $user ) ) {
			throw new Failure( 'not_found', __( 'The case is unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
		$objectId = (int) $row['object_id'];
		$post     = get_post( $objectId );
		$status   = 'investigating';
		if ( ! in_array( $command, array( 'dismiss', 'warn', 'pending', 'spam', 'trash', 'restore', 'close', 'open', 'escalate', 'triage', 'assign' ), true ) ) {
			throw new Failure( 'invalid_action', __( 'Invalid moderation action.', 'forum-intelligence-for-bbpress' ) ); }
		if ( $assignee && ! user_can( $assignee, 'moderate' ) ) {
			throw new Failure( 'invalid_assignee', __( 'Choose an authorized moderator.', 'forum-intelligence-for-bbpress' ) ); }
		$old = $post->post_status;
		$this->store->event(
			'moderation_intent',
			$objectId,
			$user,
			array(
				'case'    => $caseId,
				'command' => $command,
				'before'  => $old,
			)
		);
		switch ( $command ) {
			case 'spam':
				$post->post_type === bbp_get_reply_post_type() ? bbp_spam_reply( $objectId ) : bbp_spam_topic( $objectId );
				$status = 'actioned';
				break;
			case 'pending':
				wp_update_post(
					array(
						'ID'          => $objectId,
						'post_status' => 'pending',
					)
				);
				$status = 'actioned';
				break;
			case 'trash':
				if ( ! user_can( $user, 'delete_post', $objectId ) ) {
					throw new Failure( 'forbidden', __( 'Deletion permission is required.', 'forum-intelligence-for-bbpress' ), 403 ); }
				wp_trash_post( $objectId );
				$status = 'actioned';
				break;
			case 'restore':
				if ( $old === 'spam' ) {
					$post->post_type === bbp_get_reply_post_type() ? bbp_unspam_reply( $objectId ) : bbp_unspam_topic( $objectId ); } elseif ( $old === 'trash' ) {
					wp_untrash_post( $objectId ); } else {
						wp_update_post(
							array(
								'ID'          => $objectId,
								'post_status' => 'publish',
							)
						);
					} $status = 'actioned';
				break;
			case 'close':
			case 'open':
				if ( $post->post_type !== bbp_get_topic_post_type() ) {
					throw new Failure( 'invalid_object', __( 'Only topics can be closed or reopened.', 'forum-intelligence-for-bbpress' ) ); }
				$command === 'close' ? bbp_close_topic( $objectId ) : bbp_open_topic( $objectId );
				$status = 'actioned';
				break;
			case 'dismiss':
				$status = 'dismissed';
				break;
			case 'warn':
				$status = 'actioned';
				break;
			case 'escalate':
				$status = 'escalated';
				break;
			case 'triage':
				$status = 'triaged';
				break;
		}
		$expected = array(
			'spam'    => 'spam',
			'pending' => 'pending',
			'trash'   => 'trash',
			'close'   => 'closed',
			'open'    => 'publish',
		);
		if ( isset( $expected[ $command ] ) && get_post_status( $objectId ) !== $expected[ $command ] ) {
			throw new Failure( 'moderation_failed', __( 'The content state could not be changed. Review the recorded action intent and retry.', 'forum-intelligence-for-bbpress' ), 503 ); }
		$this->store->transaction(
			function () use ( $caseId, $objectId, $user, $command, $notes, $assignee, $status, $old ): void {
				$this->store->execute( "UPDATE {$this->store->table('cases')} SET status=%s,assignee_id=%d,updated_at=%s WHERE id=%d", array( $status, $assignee, $this->store->now(), $caseId ) );
				$this->store->event(
					'moderation_' . $command,
					$objectId,
					$user,
					array(
						'case'   => $caseId,
						'before' => $old,
						'after'  => get_post_status( $objectId ),
						'notes'  => mb_substr( sanitize_textarea_field( $notes ), 0, 2000 ),
					)
				);
				$this->store->metric( 'moderation_resolved' );
			}
		);
		do_action( 'fi_moderation_action', $objectId, $command, $user );
		return array( 'status' => $status );
	}
	private function canManage( int $objectId, int $user ): bool {
		$forum = $this->access->forum( $objectId );
		return $forum && user_can( $user, 'edit_post', $objectId ) && bbp_user_can_view_forum(
			array(
				'user_id'  => $user,
				'forum_id' => $forum,
			)
		);
	}
	private function signals( string $text ): array {
		$signals = array();
		if ( preg_match( '/\\b(?:sk-[A-Za-z0-9_-]{12,}|AKIA[A-Z0-9]{16})/', $text ) ) {
			$signals[] = array(
				'type'        => 'possible_secret',
				'explanation' => __( 'A credential-like pattern needs human review.', 'forum-intelligence-for-bbpress' ),
			); }
		if ( preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i', $text ) ) {
			$signals[] = array(
				'type'        => 'possible_pii',
				'explanation' => __( 'An email address may contain personal information.', 'forum-intelligence-for-bbpress' ),
			); }
		return apply_filters( 'fi_moderation_signals', $signals, $text );
	}
}
