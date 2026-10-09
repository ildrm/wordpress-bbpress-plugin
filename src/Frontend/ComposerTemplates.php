<?php
declare(strict_types=1);
namespace ForumIntelligence\Frontend;
use ForumIntelligence\Domain\Composer\Templates;
use ForumIntelligence\Domain\Failure;
/** Plain guidance remains available inside the native composer without submitting or rewriting it. */
final class ComposerTemplates {
	public function __construct( private Templates $templates ) {}
	public function register(): void {
		add_action( 'bbp_theme_before_topic_form_content', array( $this, 'render' ) );
	}
	public function render(): void {
		if ( bbp_is_topic_edit() || bbp_is_reply_edit() ) {
			return;}
		$forum = (int) ( bbp_get_form_topic_forum() ?: bbp_get_forum_id() );
		if ( ! $forum ) {
			return;}
		try {
			$view = $this->templates->view( $forum, get_current_user_id() );
		} catch ( Failure $failure ) {
			return;}
		if ( ! $view['text'] && ! $view['templates'] ) {
			return;}
		echo '<details class="fi-panel" data-fi-template-guidance><summary>' . esc_html__( 'Suggested question template', 'forum-intelligence-for-bbpress' ) . '</summary><p>' . esc_html__( 'Optional guidance: copy the sections you need. Choosing guidance never changes your topic classification or existing text.', 'forum-intelligence-for-bbpress' ) . '</p>';
		if ( $view['text'] ) {
			echo '<p><strong>' . esc_html( $view['label'] ) . '</strong></p><pre>' . esc_html( $view['text'] ) . '</pre>';}
		if ( $view['templates'] ) {
			echo '<details><summary>' . esc_html__( 'Browse applicable templates', 'forum-intelligence-for-bbpress' ) . '</summary>';
			$labels = array(
				'topic_type' => __( 'Question type', 'forum-intelligence-for-bbpress' ),
				'product'    => __( 'Product', 'forum-intelligence-for-bbpress' ),
				'category'   => __( 'Category', 'forum-intelligence-for-bbpress' ),
			);
			foreach ( $view['templates'] as $template ) {
				echo '<details><summary>' . esc_html( $template['label'] ) . '</summary><dl>';
				foreach ( $labels as $axis => $label ) {
					if ( $template['criteria'][ $axis ] !== '' ) {
						echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $template['criteria'][ $axis ] ) . '</dd>';}
				}
				echo '</dl><pre>' . esc_html( $template['text'] ) . '</pre></details>';
			}
			echo '</details>';
		}
		echo '</details>';
	}
}
