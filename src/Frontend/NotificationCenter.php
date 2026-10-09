<?php
declare(strict_types=1);
namespace ForumIntelligence\Frontend;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Domain\Notifications\Notifications;
/** Progressive, owned notification views and an opt-in asynchronous unread indicator. */
final class NotificationCenter {
	public function __construct( private Services $services, private Frontend $frontend ) {}
	public function register(): void {
		add_shortcode( 'forum_intelligence_notifications', fn(): string=>$this->render() );
		add_shortcode( 'forum_intelligence_unread', fn( array $attributes ): string=>$this->indicator( $attributes ) );
		add_action(
			'template_redirect',
			static function (): void {
				if ( ! is_user_logged_in() || ! is_singular() ) {
					return;
				}
				$content = (string) get_post_field( 'post_content', get_queried_object_id() );
				if ( has_shortcode( $content, 'forum_intelligence_notifications' ) || has_shortcode( $content, 'forum_intelligence_unread' ) ) {
					nocache_headers();
				}
			}
		);
	}
	public function indicator( array $attributes = array() ): string {
		if ( ! is_user_logged_in() || ! $this->services->get( 'notifications' ) || ! $this->services->access->activeMember( get_current_user_id() ) ) {
			return '';
		}
		$this->frontend->assets();
		$url        = is_string( $attributes['url'] ?? null ) ? $attributes['url'] : '';
		$attributes = shortcode_atts( array( 'url' => '' ), array( 'url' => $url ), 'forum_intelligence_unread' );
		$url        = wp_validate_redirect( $attributes['url'], '' );
		$label      = '<span data-fi-unread-label>' . esc_html__( 'Notifications', 'forum-intelligence-for-bbpress' ) . '</span>';
		if ( $url !== '' ) {
			$label = '<a href="' . esc_url( $url ) . '">' . $label . '</a>';
		}
		return '<span class="fi-unread" role="status" aria-live="polite">' . $label . '</span>';
	}
	public function render(): string {
		if ( ! is_user_logged_in() ) {
			return '<p><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Sign in to view notifications', 'forum-intelligence-for-bbpress' ) . '</a></p>';
		}
		$user = get_current_user_id();
		if ( ! $this->services->access->activeMember( $user ) ) {
			return '<p>' . esc_html__( 'Community access is unavailable for this account.', 'forum-intelligence-for-bbpress' ) . '</p>';
		}
		$notifications = $this->services->get( 'notifications' );
		if ( ! $notifications ) {
			return '';
		}
		$this->frontend->assets();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters; every returned source is currently authorized.
		$parameters = wp_unslash( $_GET );
		$state      = is_string( $parameters['fi_inbox_state'] ?? null ) && in_array( $parameters['fi_inbox_state'], array( 'all', 'read', 'unread' ), true ) ? $parameters['fi_inbox_state'] : 'all';
		$categories = Notifications::categories();
		$kind       = is_string( $parameters['fi_inbox_kind'] ?? null ) && isset( $categories[ $parameters['fi_inbox_kind'] ] ) ? $parameters['fi_inbox_kind'] : '';
		$page       = is_scalar( $parameters['fi_inbox_page'] ?? null ) ? max( 1, min( 1000, (int) $parameters['fi_inbox_page'] ) ) : 1;
		$inbox      = $notifications->inbox( $user, $page, false, $kind, $state );
		$html       = '<section class="fi-panel" data-fi-center><h2>' . esc_html__( 'Notifications', 'forum-intelligence-for-bbpress' ) . '</h2>';
		$html      .= '<form method="get" action="' . esc_url( get_permalink() ?: home_url( '/' ) ) . '">';
		if ( ! get_option( 'permalink_structure' ) && get_queried_object_id() ) {
			$html .= '<input type="hidden" name="' . esc_attr( is_page() ? 'page_id' : 'p' ) . '" value="' . esc_attr( (string) get_queried_object_id() ) . '">';
		}
		$html .= '<label>' . esc_html__( 'Notification status', 'forum-intelligence-for-bbpress' ) . '<select name="fi_inbox_state">';
		foreach ( array(
			'all'    => __( 'All', 'forum-intelligence-for-bbpress' ),
			'unread' => __( 'Unread', 'forum-intelligence-for-bbpress' ),
			'read'   => __( 'Read', 'forum-intelligence-for-bbpress' ),
		) as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '" ' . selected( $state, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select></label><label>' . esc_html__( 'Notification category', 'forum-intelligence-for-bbpress' ) . '<select name="fi_inbox_kind"><option value="">' . esc_html__( 'All categories', 'forum-intelligence-for-bbpress' ) . '</option>';
		foreach ( $categories as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '" ' . selected( $kind, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select></label><button type="submit">' . esc_html__( 'Filter notifications', 'forum-intelligence-for-bbpress' ) . '</button></form>';
		$html .= $this->frontend->form( 'notification', array( 'command' => 'all_read' ), __( 'Mark all read', 'forum-intelligence-for-bbpress' ) );
		$day   = '';
		foreach ( $inbox['items'] as $item ) {
			$nextDay = substr( $item['created_at'], 0, 10 );
			if ( $nextDay !== $day ) {
				if ( $day !== '' ) {
					$html .= '</ul>';
				}
				$day   = $nextDay;
				$html .= '<h3>' . esc_html( $day . ' UTC' ) . '</h3><ul>';
			}
			$html .= '<li><p><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['title'] ) . '</a> — ' . esc_html( $categories[ $item['kind'] ] ?? __( 'Community activity', 'forum-intelligence-for-bbpress' ) ) . ' — <strong>' . esc_html( $item['is_read'] ? __( 'Read', 'forum-intelligence-for-bbpress' ) : __( 'Unread', 'forum-intelligence-for-bbpress' ) ) . '</strong></p><p><time datetime="' . esc_attr( str_replace( ' ', 'T', $item['created_at'] ) . 'Z' ) . '">' . esc_html( $item['created_at'] . ' UTC' ) . '</time></p>';
			$html .= $this->frontend->form(
				'notification',
				array(
					'id'      => $item['id'],
					'command' => $item['is_read'] ? 'unread' : 'read',
				),
				__( 'Change read status', 'forum-intelligence-for-bbpress' )
			);
			$html .= $this->frontend->form(
				'notification',
				array(
					'id'      => $item['id'],
					'command' => 'delete',
				),
				__( 'Delete notification', 'forum-intelligence-for-bbpress' )
			) . '</li>';
		}
		if ( $day !== '' ) {
			$html .= '</ul>';
		} else {
			$html .= '<p>' . esc_html__( 'No notifications to show. Change the filters or check another page.', 'forum-intelligence-for-bbpress' ) . '</p>';
		}
		$html .= '<nav aria-label="' . esc_attr__( 'Notification pages', 'forum-intelligence-for-bbpress' ) . '">';
		$base  = remove_query_arg( 'fi_result' );
		if ( $page > 1 ) {
			$html .= '<a href="' . esc_url(
				add_query_arg(
					array(
						'fi_inbox_page'  => $page - 1,
						'fi_inbox_state' => $state,
						'fi_inbox_kind'  => $kind,
					),
					$base
				)
			) . '">' . esc_html__( 'Newer notifications', 'forum-intelligence-for-bbpress' ) . '</a> ';
		}
		if ( $page < 1000 ) {
			$html .= '<a href="' . esc_url(
				add_query_arg(
					array(
						'fi_inbox_page'  => $page + 1,
						'fi_inbox_state' => $state,
						'fi_inbox_kind'  => $kind,
					),
					$base
				)
			) . '">' . esc_html__( 'Older notifications', 'forum-intelligence-for-bbpress' ) . '</a>';
		}
		return $html . '</nav></section>';
	}
}
