<?php
declare(strict_types=1);
namespace ForumIntelligence\Frontend;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Domain\Failure;
final class Extras {
	public function __construct( private Services $services, private Frontend $frontend ) {}
	public function register(): void {
		$drafts = $this->services->get( 'drafts' );
		if ( $drafts ) {
			( new ComposerDrafts( $drafts ) )->register(); }

		add_action( 'bbp_template_before_single_topic', array( $this, 'topic' ), 40 );
		add_action(
			'template_redirect',
			function (): void {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only own messaging view; access checks occur in the service.
				if ( ! isset( $_GET['fi_messages'] ) || (string) $_GET['fi_messages'] !== '1' || ! $this->services->get( 'messaging' ) ) {
					return;}
				if ( ! is_user_logged_in() ) {
					auth_redirect();
					exit;}
				nocache_headers();
				$this->frontend->assets();
				get_header();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited messages renderer escapes dynamic values at construction.
				echo '<main id="fi-messages">' . $this->messages() . '</main>';
				get_footer();
				exit;
			},
			-10
		);
		add_shortcode( 'forum_intelligence_messages', fn(): string=>$this->messages() );
		add_action(
			'bbp_template_before_user_profile',
			function (): void {
				$push = $this->services->get( 'push' );
				if ( ! $push || ! $push->available() || ! is_user_logged_in() ) {
					return; }
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				echo '<section class="fi-panel"><h2>' . esc_html__( 'Browser notifications', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Enable only on a device you trust. Notifications contain a generic activity message.', 'forum-intelligence-for-bbpress' ) . '</p><button type="button" data-fi-push>' . esc_html__( 'Enable for this browser', 'forum-intelligence-for-bbpress' ) . '</button><p role="status" aria-live="polite"></p>' . $this->frontend->form( 'push_unsubscribe', array(), __( 'Unsubscribe all my browsers', 'forum-intelligence-for-bbpress' ) ) . '</section>';
			}
		);
		$templates = $this->services->get( 'templates' );
		if ( $templates ) {
			( new ComposerTemplates( $templates ) )->register();}
		add_action(
			'show_user_profile',
			function ( \WP_User $user ): void {
				$notifications = $this->services->get( 'notifications' );
				if ( ! $notifications || $user->ID !== get_current_user_id() ) {
					return; }
				$prefs = $notifications->preferences( $user->ID );
				echo '<h2 id="fi_preferences">' . esc_html__( 'Community notification preferences', 'forum-intelligence-for-bbpress' ) . '</h2><label>' . esc_html__( 'Email delivery', 'forum-intelligence-for-bbpress' ) . '<select name="fi_email_mode">';
				foreach ( array( 'off', 'immediate', 'hourly', 'daily', 'weekly', 'important' ) as $mode ) {
					echo '<option value="' . esc_attr( $mode ) . '" ' . selected( $prefs['email_mode'], $mode, false ) . '>' . esc_html( $mode ) . '</option>'; }
				echo '</select></label>';
			}
		);
		add_action(
			'personal_options_update',
			function ( int $user ): void {
				$notifications = $this->services->get( 'notifications' );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress verifies the profile update nonce before personal_options_update.
				if ( $notifications && $user === get_current_user_id() && isset( $_POST['fi_email_mode'] ) ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress verifies the profile update nonce before personal_options_update.
					$notifications->savePreferences( $user, sanitize_key( wp_unslash( $_POST['fi_email_mode'] ) ), $notifications->preferences( $user )['muted_categories'] ); }
			}
		);
	}
	public function topic(): void {
		$topic = (int) bbp_get_topic_id();
		$user  = get_current_user_id();
		if ( ! $this->services->access->canRead( $topic, $user ) ) {
			return; }
		$fields = $this->services->get( 'fields' );
		if ( $fields ) {
			$data = $fields->read( $topic, $user );
			if ( $data ) {
				echo '<dl class="fi-panel">';
				foreach ( $data as $field ) {
					echo '<dt>' . esc_html( $field['label'] ) . '</dt><dd>' . esc_html( is_array( $field['value'] ) ? implode( ', ', $field['value'] ) : (string) $field['value'] ) . '</dd>';
				} echo '</dl>'; }
		}
		$attachments = $this->services->get( 'attachments' );
		if ( $attachments ) {
			echo '<section class="fi-panel"><h2>' . esc_html__( 'Attachments', 'forum-intelligence-for-bbpress' ) . '</h2><ul>';
			foreach ( $attachments->listing( $topic, $user ) as $file ) {
				echo '<li><a href="' . esc_url( $file['url'] ) . '">' . esc_html( $file['name'] ) . '</a> — ' . esc_html( $file['protected'] ? __( 'Protected download', 'forum-intelligence-for-bbpress' ) : __( 'Public file; its URL remains accessible', 'forum-intelligence-for-bbpress' ) ) . '</li>'; }
			echo '</ul>';
			if ( $user && user_can( $user, 'upload_files' ) ) {
				$fields = '<label>' . esc_html__( 'File, up to 5 MB; JPEG, PNG, WebP, PDF or plain text', 'forum-intelligence-for-bbpress' ) . '<input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf,.txt" required></label>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				echo $this->frontend->form( 'attachment', array( 'object' => $topic ), __( 'Upload attachment', 'forum-intelligence-for-bbpress' ), $fields );
			}
			echo '</section>';
		}
		try {
			$this->services->access->solve( $topic, $user );
			$polls = $this->services->get( 'polls' );
			if ( $polls && ! $polls->view( $topic, $user ) ) {
				$fields = '<label>' . esc_html__( 'Poll choices, one per line', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[choices]" rows="4" required></textarea></label><label>' . esc_html__( 'Maximum selections', 'forum-intelligence-for-bbpress' ) . '<input type="number" name="data[maximum]" min="1" max="10" value="1" required></label><label>' . esc_html__( 'Closing time, UTC', 'forum-intelligence-for-bbpress' ) . '<input type="datetime-local" name="data[ends]" required></label><label>' . esc_html__( 'Starting time, UTC; leave empty to start now', 'forum-intelligence-for-bbpress' ) . '<input type="datetime-local" name="data[starts]"></label><label>' . esc_html__( 'Results visible to', 'forum-intelligence-for-bbpress' ) . '<select name="data[visibility]"><option value="voters">' . esc_html__( 'Members who voted', 'forum-intelligence-for-bbpress' ) . '</option><option value="public">' . esc_html__( 'Everyone who can read this topic', 'forum-intelligence-for-bbpress' ) . '</option><option value="closed">' . esc_html__( 'After the poll closes', 'forum-intelligence-for-bbpress' ) . '</option></select></label><label><input type="checkbox" name="data[change]" value="1"> ' . esc_html__( 'Allow changing votes', 'forum-intelligence-for-bbpress' ) . '</label>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				echo '<details class="fi-panel"><summary>' . esc_html__( 'Create a topic poll', 'forum-intelligence-for-bbpress' ) . '</summary>' . $this->frontend->form(
					'poll_create',
					array(

						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
						'topic' => $topic,
					),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
					__( 'Create poll', 'forum-intelligence-for-bbpress' ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
					$fields
				) . '</details>';
			}
			if ( $this->services->get( 'drafts' ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				echo '<details class="fi-panel"><summary>' . esc_html__( 'Schedule this topic', 'forum-intelligence-for-bbpress' ) . '</summary><p>' . esc_html__( 'Scheduling removes this topic from view until its future publication time.', 'forum-intelligence-for-bbpress' ) . '</p>' . $this->frontend->form( 'schedule', array( 'topic' => $topic ), __( 'Schedule publication', 'forum-intelligence-for-bbpress' ), '<label>' . esc_html__( 'Publication time, UTC', 'forum-intelligence-for-bbpress' ) . '<input type="datetime-local" name="data[date]" required></label>' ) . '</details>'; }
		} catch ( Failure $failure ) {
		}
	}
	public function messages(): string {
		$service = $this->services->get( 'messaging' );
		if ( ! $service || ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Private messaging is unavailable.', 'forum-intelligence-for-bbpress' ) . '</p>'; }
		$user  = get_current_user_id();
		$html  = '<section class="fi-panel"><h2>' . esc_html__( 'Private conversations', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Messages are stored on this site. Reporting shares a selected message with moderators. Leaving removes your access; other participants retain their copies.', 'forum-intelligence-for-bbpress' ) . '</p>';
		$html .= $this->frontend->form( 'message_start', array(), __( 'Start conversation', 'forum-intelligence-for-bbpress' ), '<label>' . esc_html__( 'Recipient member ID', 'forum-intelligence-for-bbpress' ) . '<input name="data[recipient]" type="number" min="1" required></label><label>' . esc_html__( 'Message', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[text]" maxlength="5000" required></textarea></label>' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination; every selected conversation rechecks membership.
		$selected = max( 0, (int) ( $_GET['fi_conversation'] ?? 0 ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only own history cursor.
		$after = max( 0, (int) ( $_GET['fi_after'] ?? 0 ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only own conversation page.
		$page          = max( 1, min( 1000, (int) ( $_GET['fi_messages_page'] ?? 1 ) ) );
		$conversations = $service->listing( $user, $page )['items'];
		if ( $selected && ! in_array( $selected, array_column( $conversations, 'conversation' ), true ) ) {
			try {
				$service->messages( $selected, $user, $after );
				$conversations[] = array(
					'conversation' => $selected,
					'last_read'    => 0,
				);
			} catch ( Failure $failure ) {
				$html .= '<p>' . esc_html__( 'Conversation unavailable.', 'forum-intelligence-for-bbpress' ) . '</p>'; }
		}
		foreach ( $conversations as $conversation ) {
			$id       = $conversation['conversation'];
			$messages = $service->messages( $id, $user, $selected === $id ? $after : 0 );
			// translators: %s is the conversation identifier.
			$html .= '<details data-fi-conversation="' . esc_attr( (string) $id ) . '"><summary>' . sprintf( esc_html__( 'Conversation %s', 'forum-intelligence-for-bbpress' ), esc_html( (string) $id ) ) . '</summary>';
			foreach ( $messages['items'] as $message ) {
				$html .= '<p data-fi-message="' . esc_attr( (string) $message['id'] ) . '">' . esc_html( $message['text'] ) . '</p>' . $this->frontend->form(
					'conversation',
					array(
						'conversation' => $id,
						'command'      => 'report',
						'message'      => $message['id'],
					),
					__( 'Report this message', 'forum-intelligence-for-bbpress' )
				);
			}
			if ( $messages['has_more'] ) {
				$cursor = (int) end( $messages['items'] )['id'];
				$html  .= '<p><a href="' . esc_url(
					add_query_arg(
						array(
							'fi_conversation' => $id,
							'fi_after'        => $cursor,
						)
					)
				) . '">' . esc_html__( 'Next messages', 'forum-intelligence-for-bbpress' ) . '</a></p>';
			}
			if ( $selected === $id && $after ) {
				$html .= '<p><a href="' . esc_url(
					add_query_arg(
						array(
							'fi_conversation' => $id,
							'fi_after'        => 0,
						)
					)
				) . '">' . esc_html__( 'First messages', 'forum-intelligence-for-bbpress' ) . '</a></p>';
			}
			$html .= $this->frontend->form( 'message_send', array( 'conversation' => $id ), __( 'Send reply', 'forum-intelligence-for-bbpress' ), '<label>' . esc_html__( 'Reply', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[text]" maxlength="5000" required></textarea></label>' );
			$last  = $messages['items'] ? end( $messages['items'] )['id'] : 0;
			$html .= $this->frontend->form(
				'conversation',
				array(
					'conversation' => $id,
					'command'      => 'read',
					'message'      => $last,
				),
				__( 'Mark read', 'forum-intelligence-for-bbpress' )
			);
			$html .= $this->frontend->form(
				'conversation',
				array(
					'conversation' => $id,
					'command'      => 'leave',
				),
				__( 'Leave conversation', 'forum-intelligence-for-bbpress' )
			);
			$html .= '</details>';
		}
		$html .= '<nav aria-label="' . esc_attr__( 'Conversation pages', 'forum-intelligence-for-bbpress' ) . '">';
		if ( $page > 1 ) {
			$html .= '<a href="' . esc_url(
				add_query_arg(
					array(
						'fi_messages_page' => $page - 1,
						'fi_after'         => 0,
					)
				)
			) . '">' . esc_html__( 'Previous conversations', 'forum-intelligence-for-bbpress' ) . '</a> ';}
		if ( count( $conversations ) >= 20 ) {
			$html .= '<a href="' . esc_url(
				add_query_arg(
					array(
						'fi_messages_page' => $page + 1,
						'fi_after'         => 0,
					)
				)
			) . '">' . esc_html__( 'Older conversations', 'forum-intelligence-for-bbpress' ) . '</a>';}
		$html .= '</nav>';
		$html .= '<h3>' . esc_html__( 'Block a member', 'forum-intelligence-for-bbpress' ) . '</h3>' . $this->frontend->form(
			'follow',
			array(
				'type'    => 'user',
				'mode'    => 'block',
				'enabled' => 1,
			),
			__( 'Block', 'forum-intelligence-for-bbpress' ),
			'<label>' . esc_html__( 'Member ID', 'forum-intelligence-for-bbpress' ) . '<input type="number" name="data[target]" min="1" required></label>'
		);
		return $html . '</section>';
	}
}
