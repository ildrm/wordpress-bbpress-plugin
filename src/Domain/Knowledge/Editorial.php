<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Knowledge;
use ForumIntelligence\Domain\Failure;
/** Explicit review lives inside the existing native editor form and its capability model. */
final class Editorial {
	private ?int $failed = null;
	public function __construct( private Knowledge $knowledge ) {}
	public function register(): void {
		add_action(
			'add_meta_boxes',
			function ( string $type, \WP_Post $post ): void {
				if ( $this->knowledge->isArticle( $post->ID ) ) {
					add_meta_box( 'fi-knowledge-review', __( 'Source review', 'forum-intelligence-for-bbpress' ), array( $this, 'box' ), $type, 'side', 'high' );
				}
			},
			10,
			2
		);
		add_action( 'save_post', array( $this, 'save' ), 30, 3 );
		add_filter(
			'redirect_post_location',
			function ( string $location, int $id ): string {
				return $this->failed === $id ? add_query_arg( 'fi_knowledge_review', 'unavailable', $location ) : $location;
			},
			20,
			2
		);
		add_action(
			'admin_notices',
			function (): void {
				$screen = get_current_screen();
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, fixed generic feedback; source verification itself checks its native nonce and capabilities.
				if ( $screen && in_array( $screen->post_type, ( new Targets() )->types(), true ) && isset( $_GET['fi_knowledge_review'] ) && $_GET['fi_knowledge_review'] === 'unavailable' ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Source verification was not recorded. Reopen the source review and check your current access.', 'forum-intelligence-for-bbpress' ) . '</p></div>';
				}
			}
		);
	}
	public function box( \WP_Post $post ): void {
		try {
			$state = $this->knowledge->reviewState( $post->ID, get_current_user_id() );
		} catch ( Failure $failure ) {
			echo '<p>' . esc_html__( 'Source review is unavailable.', 'forum-intelligence-for-bbpress' ) . '</p>';
			return;}
		echo '<p data-fi-review-status>' . esc_html( $state['stale'] ? __( 'The source discussion has changed. Review the article before verifying it.', 'forum-intelligence-for-bbpress' ) : __( 'The source discussion matches this article’s recorded provenance.', 'forum-intelligence-for-bbpress' ) ) . '</p>';
		echo '<p><a href="' . esc_url( get_permalink( $state['topic'] ) ) . '">' . esc_html__( 'Review source discussion', 'forum-intelligence-for-bbpress' ) . '</a></p>';
		if ( user_can( get_current_user_id(), 'edit_post', $state['topic'] ) ) {
			echo '<p><a href="' . esc_url( get_edit_post_link( $state['topic'], 'raw' ) ) . '">' . esc_html__( 'Open source editor and revisions', 'forum-intelligence-for-bbpress' ) . '</a></p>';
		}
		if ( $state['verified_at'] ) {
			echo '<p>' . esc_html__( 'Last verified (UTC):', 'forum-intelligence-for-bbpress' ) . ' <time datetime="' . esc_attr( str_replace( ' ', 'T', $state['verified_at'] ) . 'Z' ) . '">' . esc_html( $state['verified_at'] ) . '</time></p>';
		} else {
			echo '<p>' . esc_html__( 'No human verification has been recorded.', 'forum-intelligence-for-bbpress' ) . '</p>';}
		if ( ! $state['review_available'] ) {
			echo '<p>' . esc_html__( 'Some current sources are unavailable. Verification cannot be recorded.', 'forum-intelligence-for-bbpress' ) . '</p>';
			return;}
		try {
			$comparison = $this->knowledge->comparison( $post->ID, get_current_user_id() );
		} catch ( Failure $failure ) {
			echo '<p>' . esc_html__( 'Source comparison is unavailable. Reopen this review and check your current access.', 'forum-intelligence-for-bbpress' ) . '</p>';
			return;
		}
		echo '<details data-fi-source-comparison><summary>' . esc_html__( 'Source comparison', 'forum-intelligence-for-bbpress' ) . '</summary>';
		if ( $comparison['solution_changed'] ) {
			echo '<p>' . esc_html__( 'The accepted solution selection has changed.', 'forum-intelligence-for-bbpress' ) . '</p>';
		}
		if ( $comparison['outside_window'] ) {
			echo '<p>' . esc_html__( 'The discussion changed outside the recorded source window. Review the full source discussion.', 'forum-intelligence-for-bbpress' ) . '</p>';
		} elseif ( ! $comparison['changes'] && ! $comparison['solution_changed'] ) {
			echo '<p>' . esc_html__( 'No current source differences were found.', 'forum-intelligence-for-bbpress' ) . '</p>';
		}
		$labels = array(
			'added'            => __( 'Added to the source window', 'forum-intelligence-for-bbpress' ),
			'left_window'      => __( 'Left the current source window', 'forum-intelligence-for-bbpress' ),
			'changed'          => __( 'Changed; exact text baseline unavailable', 'forum-intelligence-for-bbpress' ),
			'metadata_changed' => __( 'Source metadata changed', 'forum-intelligence-for-bbpress' ),
			'text_changed'     => __( 'Source text changed', 'forum-intelligence-for-bbpress' ),
		);
		if ( $comparison['changes'] ) {
			echo '<ul>';
			foreach ( $comparison['changes'] as $change ) {
				/* translators: %d: Native source post ID. */
				echo '<li><a href="' . esc_url( $change['source_url'] ) . '">' . esc_html( sprintf( __( 'Source #%d', 'forum-intelligence-for-bbpress' ), $change['source_id'] ) ) . '</a>: ' . esc_html( $labels[ $change['kind'] ] );
				if ( $change['revision_url'] ) {
					echo ' <a href="' . esc_url( $change['revision_url'] ) . '">' . esc_html__( 'Compare recorded and current text', 'forum-intelligence-for-bbpress' ) . '</a>';
				} elseif ( $change['kind'] === 'text_changed' ) {
					echo ' ' . esc_html__( 'An exact revision comparison is unavailable. Review the current source.', 'forum-intelligence-for-bbpress' );
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		if ( $comparison['remaining'] ) {
			echo '<p>' . esc_html__( 'More sources changed. Review the full source discussion.', 'forum-intelligence-for-bbpress' ) . '</p>';
		}
		echo '</details>';
		wp_nonce_field( 'fi_knowledge_review_' . $post->ID, 'fi_knowledge_review_nonce' );
		echo '<p><label><input type="checkbox" name="fi_knowledge_reviewed" value="1"> ' . esc_html__( 'I reviewed the source discussion and verified this article.', 'forum-intelligence-for-bbpress' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Save the article to record this review. Human article text and summary locks are preserved.', 'forum-intelligence-for-bbpress' ) . '</p>';
	}
	public function save( int $id, \WP_Post $post, bool $update ): void {
		if ( ! $update || ! $this->knowledge->isArticle( $id ) || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;}
		if ( ! isset( $_POST['fi_knowledge_reviewed'] ) || ! is_string( $_POST['fi_knowledge_reviewed'] ) || wp_unslash( $_POST['fi_knowledge_reviewed'] ) !== '1' ) {
			return;}
		if ( ! isset( $_POST['fi_knowledge_review_nonce'] ) || ! is_string( $_POST['fi_knowledge_review_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fi_knowledge_review_nonce'] ) ), 'fi_knowledge_review_' . $id ) ) {
			$this->failed = $id;
			return;}
		try {
			$this->knowledge->verifyArticle( $id, get_current_user_id() );
		} catch ( Failure $failure ) {
			$this->failed = $id;}
	}
}
