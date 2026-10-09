<?php
declare(strict_types=1);
namespace ForumIntelligence\Integration\BbPress;
use ForumIntelligence\Infrastructure\Database\Store;
final class TopicFilters {
	public function __construct( private Store $store ) {}
	public function register(): void {
		add_filter(
			'bbp_has_topics_query',
			function ( array $args ): array {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only native listing filter; bbPress retains its visibility constraints.
				$value = $_GET['fi_solved'] ?? '';
				if ( is_string( $value ) && in_array( $value, array( '0', '1' ), true ) ) {
					$args['fi_solved_filter'] = $value;}
				return $args;
			}
		);
		add_filter(
			'posts_where',
			function ( string $where, \WP_Query $query ): string {
				$state = $query->get( 'fi_solved_filter' );
				if ( ! in_array( $state, array( '0', '1' ), true ) ) {
					return $where;}
				$posts     = $this->store->wordpressTable( 'posts' );
				$solutions = $this->store->table( 'solutions' );
				return $where . ( $state === '0' ? ' AND NOT EXISTS ' : ' AND EXISTS ' ) . " (SELECT 1 FROM $solutions AS fi_state WHERE fi_state.topic_id=$posts.ID AND fi_state.solved=1)";
			},
			20,
			2
		);
		add_action(
			'bbp_template_before_topics_loop',
			function (): void {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only native listing filter; does not alter content.
				$value = is_string( $_GET['fi_solved'] ?? null ) ? $_GET['fi_solved'] : '';
				echo '<form class="fi-panel" method="get">';
				foreach ( array( 'page_id', 'forum' ) as $key ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve whitelisted routing parameters in the read-only filter.
					if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value, sanitized and escaped without content mutation.
						echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) . '">';}
				}
				echo '<label>' . esc_html__( 'Solution status', 'forum-intelligence-for-bbpress' ) . '<select name="fi_solved"><option value="">' . esc_html__( 'All discussions', 'forum-intelligence-for-bbpress' ) . '</option><option value="1" ' . selected( $value, '1', false ) . '>' . esc_html__( 'Solved', 'forum-intelligence-for-bbpress' ) . '</option><option value="0" ' . selected( $value, '0', false ) . '>' . esc_html__( 'Unsolved', 'forum-intelligence-for-bbpress' ) . '</option></select></label><button type="submit">' . esc_html__( 'Filter topics', 'forum-intelligence-for-bbpress' ) . '</button></form>';
			}
		);
	}
}
