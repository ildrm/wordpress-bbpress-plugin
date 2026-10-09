<?php
declare(strict_types=1);
namespace ForumIntelligence\Frontend;
use ForumIntelligence\Domain\Composer\Drafts;
use ForumIntelligence\Domain\Failure;
/** Draft buttons live inside the native composer and intercept before native publication. */
final class ComposerDrafts {
	public function __construct( private Drafts $drafts ) {}
	public function register(): void {
		add_action(
			'template_redirect',
			static function (): void {
				if ( is_user_logged_in() && is_bbpress() ) {
					nocache_headers(); }
			},
			-15
		);
		add_action( 'bbp_theme_before_topic_form_submit_button', fn() => $this->controls( 'topic' ) );
		add_action( 'bbp_theme_before_reply_form_submit_button', fn() => $this->controls( 'reply' ) );
		add_action( 'bbp_post_request', array( $this, 'submit' ), 5 );
		add_filter( 'bbp_get_form_topic_title', fn( string $value ): string => $this->recover( $value, 'topic', 'title' ) );
		add_filter( 'bbp_get_form_topic_content', fn( string $value ): string => $this->recover( $value, 'topic', 'body' ) );
		add_filter( 'bbp_get_form_reply_content', fn( string $value ): string => $this->recover( $value, 'reply', 'body' ) );
	}
	private function target( string $type ): int {
		return $type === 'reply' ? (int) bbp_get_topic_id() : (int) bbp_get_forum_id();
	}
	private function editing(): bool {
		return bbp_is_topic_edit() || bbp_is_reply_edit();
	}
	public function controls( string $type ): void {
		if ( ! is_user_logged_in() || $this->editing() || ! $this->target( $type ) ) {
			return; }
		try {
			$draft = $this->drafts->read( $this->target( $type ), get_current_user_id(), $type );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core nonce helper escapes attributes.
			echo wp_nonce_field( 'fi_native_draft', '_fi_draft_nonce', false, false );
			echo '<input type="hidden" name="fi_draft_type" value="' . esc_attr( $type ) . '"><input type="hidden" name="fi_draft_version" value="' . esc_attr( (string) ( $draft['version'] ?? 0 ) ) . '">';
			echo '<p>' . esc_html__( 'Drafts are private to your account. Recovery replaces unsaved composer text; copy it first if needed.', 'forum-intelligence-for-bbpress' ) . '</p>';
			echo '<span class="fi-controls" data-fi-draft-controls><button type="submit" name="fi_draft_command" value="save" formnovalidate>' . esc_html__( 'Save draft', 'forum-intelligence-for-bbpress' ) . '</button>';
			if ( $draft ) {
				echo '<button type="submit" name="fi_draft_command" value="recover" formnovalidate>' . esc_html__( 'Recover saved draft', 'forum-intelligence-for-bbpress' ) . '</button><button type="submit" name="fi_draft_command" value="discard" formnovalidate>' . esc_html__( 'Discard saved draft', 'forum-intelligence-for-bbpress' ) . '</button>';
			}
			echo '</span>';
		} catch ( Failure $failure ) {
			// Native posting still works if draft storage is unavailable.
		}
	}
	public function submit(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Own nonce is verified immediately below before reading or mutating a draft.
		if ( ! isset( $_POST['fi_draft_command'] ) ) {
			return; }
		if ( ! is_user_logged_in() || ! isset( $_POST['_fi_draft_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_fi_draft_nonce'] ) ), 'fi_native_draft' ) ) {
			bbp_add_error( 'fi_draft_nonce', __( 'The draft request could not be verified.', 'forum-intelligence-for-bbpress' ) );
			return; }
		try {
			$type    = sanitize_key( wp_unslash( $_POST['fi_draft_type'] ?? '' ) );
			$command = sanitize_key( wp_unslash( $_POST['fi_draft_command'] ) );
			$action  = sanitize_key( wp_unslash( $_POST['action'] ?? '' ) );
			if ( ! in_array( $type, array( 'topic', 'reply' ), true ) || $action !== 'bbp-new-' . $type || ! in_array( $command, array( 'save', 'discard', 'recover' ), true ) ) {
				throw new Failure( 'invalid_draft', __( 'Use the new-topic or reply composer to manage drafts.', 'forum-intelligence-for-bbpress' ) ); }
			$target  = absint( $_POST[ $type === 'reply' ? 'bbp_topic_id' : 'bbp_forum_id' ] ?? 0 );
			$user    = get_current_user_id();
			$version = isset( $_POST['fi_draft_version'] ) ? sanitize_text_field( wp_unslash( $_POST['fi_draft_version'] ) ) : '0';
			if ( $command === 'save' ) {
				$title = $type === 'topic' ? (string) wp_unslash( $_POST['bbp_topic_title'] ?? '' ) : '';
				$body  = (string) wp_unslash( $_POST[ $type === 'reply' ? 'bbp_reply_content' : 'bbp_topic_content' ] ?? '' );
				$this->drafts->save( $target, $user, $title, $body, $type, $version );
			} elseif ( $command === 'discard' ) {
				$this->drafts->discard( $target, $user, $type, $version );
			} else {
				$this->drafts->read( $target, $user, $type );
			}
			$url = $type === 'reply' ? get_permalink( $target ) : bbp_get_forum_permalink( $target );
			wp_safe_redirect( add_query_arg( array( 'fi_recover_draft' => $command === 'discard' ? null : $type ), $url ) . ( $type === 'reply' ? '#new-post' : '#new-topic-0' ) );
			exit;
		} catch ( Failure $failure ) {
			bbp_add_error( 'fi_draft_failure', $failure->getMessage() );
		} catch ( \Throwable $failure ) {
			bbp_add_error( 'fi_draft_failure', __( 'The draft could not be saved. Your current text has been kept.', 'forum-intelligence-for-bbpress' ) );
		}
	}
	public function recover( string $value, string $type, string $field ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only own recovery; current source and ownership are checked by Drafts.
		if ( $value !== '' || ! is_user_logged_in() || $this->editing() || ( $_GET['fi_recover_draft'] ?? '' ) !== $type || ! $this->target( $type ) ) {
			return $value; }
		try {
			$draft = $this->drafts->read( $this->target( $type ), get_current_user_id(), $type );
			nocache_headers();
			return (string) ( $draft[ $field ] ?? $value );
		} catch ( Failure $failure ) {
			return $value; }
	}
}
