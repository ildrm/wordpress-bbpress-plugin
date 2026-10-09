<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Composer;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
final class Drafts {
	public function __construct( private Store $store, private Access $access ) {}
	private function context( int $target, int $user, string $type, bool $posting = false ): void {
		$this->access->read( $target, $user );
		if ( ! in_array( $type, array( 'topic', 'reply' ), true ) || get_post_type( $target ) !== ( $type === 'reply' ? bbp_get_topic_post_type() : bbp_get_forum_post_type() ) ) {
			throw new Failure( 'invalid_draft_target', __( 'Choose a forum for a topic draft or a topic for a reply draft.', 'forum-intelligence-for-bbpress' ) ); }
		if ( ! $user || bbp_is_user_inactive( $user ) ) {
			throw new Failure( 'forbidden', __( 'Sign in to manage your drafts.', 'forum-intelligence-for-bbpress' ), 403 ); }
		$forum = $this->access->forum( $target );
		if ( $posting && ( ! user_can( $user, $type === 'reply' ? 'publish_replies' : 'publish_topics' ) || bbp_is_forum_category( $forum ) || ( ! user_can( $user, 'moderate' ) && ( bbp_is_forum_closed( $forum, true ) || ( $type === 'reply' && bbp_is_topic_closed( $target ) ) ) ) ) ) {
			throw new Failure( 'forbidden', __( 'Posting is unavailable in this discussion.', 'forum-intelligence-for-bbpress' ), 403 ); }
	}
	private function key( int $target, int $user, string $type ): string {
		return ( $type === 'reply' ? 'draft_reply:' : 'draft:' ) . $user . ':' . $target;
	}
	private function lockUser( int $user ): void {
		$this->store->row( "SELECT ID FROM {$this->store->wordpressTable('users')} WHERE ID=%d FOR UPDATE", array( $user ) );
	}
	private function token( ?array $record ): string {
		return $record ? $record['id'] . ':' . $record['version'] : '0';
	}
	private function version( ?array $record, ?string $expected ): void {
		if ( $expected !== null && $expected !== $this->token( $record ) ) {
			throw new Failure( 'draft_conflict', __( 'A newer draft was saved in another tab. Recover it before saving again; your current text has been kept.', 'forum-intelligence-for-bbpress' ), 409 ); }
	}
	public function save( int $target, int $user, string $title, string $body, string $type = 'topic', ?string $expected = null ): array {
		$this->context( $target, $user, $type, true );
		if ( mb_strlen( $title ) > 200 || mb_strlen( $body ) > 12000 ) {
			throw new Failure( 'too_large', __( 'Drafts support up to 200 title characters and 12,000 content characters.', 'forum-intelligence-for-bbpress' ), 413 ); }
		$this->store->limit( $user, 'draft', 30 );
		return $this->store->transaction(
			function () use ( $target, $user, $title, $body, $type, $expected ): array {
				$this->lockUser( $user );
				$key = $this->key( $target, $user, $type );
				$this->version( $this->store->record( $key ), $expected );
				$this->store->saveRecord(
					'draft',
					$key,
					$user,
					$target,
					array(
						'title' => $type === 'reply' ? '' : sanitize_text_field( $title ),
						'body'  => wp_kses_post( $body ),
						'type'  => $type,
					)
				);
				$record = $this->store->record( $key );
				return array(
					'saved'    => true,
					'saved_at' => $record['updated_at'],
					'version'  => $this->token( $record ),
				);
			}
		);
	}
	/** Shared native composer validation; no draft/content persistence or provider calls. */
	private function prepare( int $target, int $user, string $title, string $body, string $type, int $owner, int $blog, string $bucket ): array {
		if ( ! $user || $owner !== $user || $blog !== get_current_blog_id() ) {
			throw new Failure( 'draft_identity_changed', __( 'Your signed-in account or site changed. Reload this discussion before continuing.', 'forum-intelligence-for-bbpress' ), 403 );
		}
		$this->context( $target, $user, $type, true );
		if ( mb_strlen( $title ) > 200 || mb_strlen( $body ) > 12000 ) {
			throw new Failure( 'too_large', __( 'Drafts support up to 200 title characters and 12,000 content characters.', 'forum-intelligence-for-bbpress' ), 413 );
		}
		$this->store->limit( $user, $bucket, 20 );
		return array(
			'title'  => $type === 'reply' ? '' : sanitize_text_field( $title ),
			'body'   => wp_kses_post( $body ),
			'type'   => $type,
			'object' => $target,
			'owner'  => $user,
			'blog'   => get_current_blog_id(),
		);
	}
	public function offlineRestore( int $target, int $user, string $title, string $body, string $type, int $owner, int $blog ): array {
		return $this->prepare( $target, $user, $title, $body, $type, $owner, $blog, 'offline_restore' );
	}
	public function preview( int $target, int $user, string $title, string $body, string $type, int $owner, int $blog ): array {
		$content = $this->prepare( $target, $user, $title, $body, $type, $owner, $blog, 'composer_preview' );
		// Preview is inert: preserve formatting but remove links, media, embeds, forms and all attributes.
		$allowed = array_fill_keys( array( 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'a', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'hr', 'div', 'span' ), array() );
		return $content + array( 'html' => wp_kses( wpautop( $content['body'] ), $allowed ) );
	}
	public function read( int $target, int $user, string $type = 'topic' ): ?array {
		$this->context( $target, $user, $type );
		$record = $this->store->record( $this->key( $target, $user, $type ) );
		return $record ? $record['data'] + array(
			'version'  => $this->token( $record ),
			'saved_at' => $record['updated_at'],
			'type'     => $type,
		) : null;
	}
	public function discard( int $target, int $user, string $type = 'topic', ?string $expected = null ): array {
		$this->context( $target, $user, $type );
		$this->store->transaction(
			function () use ( $target, $user, $type, $expected ): void {
				$this->lockUser( $user );
				$key = $this->key( $target, $user, $type );
				$this->version( $this->store->record( $key ), $expected );
				$this->store->execute( "DELETE FROM {$this->store->table('records')} WHERE record_key=%s AND owner_id=%d AND kind='draft'", array( $key, $user ) );
			}
		);
		return array( 'saved' => true );
	}
	public function schedule( int $topic, int $user, string $date ): array {
		$this->access->read( $topic, $user );
		if ( get_post_type( $topic ) !== bbp_get_topic_post_type() || ! user_can( $user, 'edit_post', $topic ) || ! user_can( $user, 'publish_topics' ) ) {
			throw new Failure( 'forbidden', __( 'Topic editing and publication permissions are required.', 'forum-intelligence-for-bbpress' ), 403 ); }
		$timestamp = strtotime( $date . ' UTC' );
		if ( ! $timestamp || $timestamp < time() + 60 || $timestamp > time() + 366 * 86400 ) {
			throw new Failure( 'invalid_date', __( 'Choose a future UTC publication time within one year.', 'forum-intelligence-for-bbpress' ) ); }
		$result = wp_update_post(
			array(
				'ID'            => $topic,
				'post_status'   => 'future',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new Failure( 'schedule_failed', __( 'The topic could not be scheduled.', 'forum-intelligence-for-bbpress' ), 503 ); }
		$this->store->event( 'topic_scheduled', $topic, $user, array( 'date' => gmdate( 'c', $timestamp ) ) );
		return array( 'scheduled' => true );
	}
	public function template( int $forum, int $user, array $selection = array() ): array {
		return ( new Templates( $this->access ) )->view( $forum, $user, $selection );
	}
	public function templates( int $user, string $json ): array {
		if ( ! user_can( $user, 'manage_options' ) ) {
			throw new Failure( 'forbidden', __( 'Settings permission is required.', 'forum-intelligence-for-bbpress' ), 403 ); }
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || count( $data ) > 100 ) {
			throw new Failure( 'invalid_templates', __( 'Provide a JSON object mapping forum IDs to template text.', 'forum-intelligence-for-bbpress' ) ); }
		$templates = array();
		foreach ( $data as $forum => $text ) {
			if ( ! is_string( $text ) || get_post_type( (int) $forum ) !== bbp_get_forum_post_type() ) {
				throw new Failure( 'invalid_template', __( 'Invalid template or forum.', 'forum-intelligence-for-bbpress' ) );
			} $templates[ (int) $forum ] = mb_substr( sanitize_textarea_field( $text ), 0, 5000 ); }
		update_option( 'fi_templates', $templates, false );
		return array( 'saved' => true );
	}
}
