<?php
declare(strict_types=1);
namespace ForumIntelligence\Frontend;
use ForumIntelligence\Application\Operations;
use ForumIntelligence\Domain\Solutions\Solutions;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
final class Frontend {
	public function __construct( private Operations $operations, private Solutions $solutions, private Access $access, private string $file ) {}
	public function register(): void {
		add_action( 'admin_post_fi_action', array( $this, 'submit' ) );
		add_action( 'bbp_theme_after_reply_content', array( $this, 'reply' ) );
		add_action( 'bbp_template_before_single_topic', array( $this, 'topic' ) );
		add_filter( 'bbp_get_topic_title', array( $this, 'title' ), 20, 2 );
		add_action(
			'wp_enqueue_scripts',
			function (): void {
				$content = (string) get_post_field( 'post_content', get_queried_object_id() );
				if ( ( function_exists( 'is_bbpress' ) && is_bbpress() ) || has_shortcode( $content, 'forum_intelligence_search' ) || has_shortcode( $content, 'forum_intelligence_ask' ) || has_shortcode( $content, 'forum_intelligence_hub' ) || has_shortcode( $content, 'forum_intelligence_notifications' ) || has_shortcode( $content, 'forum_intelligence_unread' ) || has_shortcode( $content, 'forum_intelligence_messages' ) || has_shortcode( $content, 'forum_intelligence_discovery' ) || has_shortcode( $content, 'forum_intelligence_experts' ) || has_shortcode( $content, 'forum_intelligence_knowledge' ) ) {
					$this->assets(); }
			}
		);
	}
	public function assets(): void {
		wp_enqueue_style( 'forum-intelligence', plugins_url( 'assets/build/frontend.css', $this->file ), array(), '0.1.0' );
		wp_enqueue_script( 'forum-intelligence', plugins_url( 'assets/build/frontend.js', $this->file ), array(), '0.1.0', true );
		wp_add_inline_script(
			'forum-intelligence',
			'window.fiConfig=' . wp_json_encode(
				array(
					'rest'                => rest_url( 'forum-intelligence/v1/' ),
					'nonce'               => wp_create_nonce( 'wp_rest' ),
					'loggedIn'            => is_user_logged_in(),
					'notifications'       => __( 'Notifications', 'forum-intelligence-for-bbpress' ),
					'mentionsEnabled'     => (bool) ( get_option( 'fi_settings', array() )['modules']['notifications'] ?? true ),
					'mentionsManaged'     => \ForumIntelligence\Domain\Notifications\Mentions::managed(),
					'mentionMember'       => __( 'Find a member to mention', 'forum-intelligence-for-bbpress' ),
					'mentionLoading'      => __( 'Finding members…', 'forum-intelligence-for-bbpress' ),
					'mentionHelp'         => __( 'Type at least two username characters. Choose a member to insert @username into your text.', 'forum-intelligence-for-bbpress' ),
					'mentionEmpty'        => __( 'No matching members are available for this discussion.', 'forum-intelligence-for-bbpress' ),
					'mentionUnavailable'  => __( 'Member suggestions are unavailable. You can type a known @username and continue posting.', 'forum-intelligence-for-bbpress' ),
					'mentionAdded'        => __( 'Mention inserted. Your existing text was kept.', 'forum-intelligence-for-bbpress' ),
					'pwa'                 => (bool) ( get_option( 'fi_settings', array() )['modules']['pwa'] ?? false ),
					'workerConflict'      => __( 'Another application manages offline access on this site. Contact the administrator to integrate browser notifications.', 'forum-intelligence-for-bbpress' ),
					'drafts'              => (bool) ( get_option( 'fi_settings', array() )['modules']['composer'] ?? true ),
					'draftOwner'          => get_current_user_id(),
					'draftBlog'           => get_current_blog_id(),
					'offlineDraftModule'  => plugins_url( 'assets/build/offline-drafts.js', $this->file ),
					'composerToolsModule' => plugins_url( 'assets/build/composer-tools.js', $this->file ),
					'composerToolsLabels' => array(
						'toolbar'         => __( 'Formatting', 'forum-intelligence-for-bbpress' ),
						'bold'            => __( 'Bold', 'forum-intelligence-for-bbpress' ),
						'italic'          => __( 'Italic', 'forum-intelligence-for-bbpress' ),
						'code'            => __( 'Code', 'forum-intelligence-for-bbpress' ),
						'quote'           => __( 'Quote', 'forum-intelligence-for-bbpress' ),
						'preview'         => __( 'Preview discussion', 'forum-intelligence-for-bbpress' ),
						'close'           => __( 'Close preview', 'forum-intelligence-for-bbpress' ),
						'placeholder'     => __( 'text', 'forum-intelligence-for-bbpress' ),
						'previewTitle'    => __( 'Discussion preview', 'forum-intelligence-for-bbpress' ),
						'help'            => __( 'Review formatting before posting. Links and media are inactive in preview. Your editor text is kept.', 'forum-intelligence-for-bbpress' ),
						'loading'         => __( 'Preparing preview…', 'forum-intelligence-for-bbpress' ),
						'ready'           => __( 'Preview ready. Your text has not been posted.', 'forum-intelligence-for-bbpress' ),
						'changed'         => __( 'Your text changed. Preview again to review the latest version.', 'forum-intelligence-for-bbpress' ),
						'unavailable'     => __( 'Preview is unavailable. Keep editing or copy your text before reloading.', 'forum-intelligence-for-bbpress' ),
						'formatted'       => __( 'Formatting applied to the selection.', 'forum-intelligence-for-bbpress' ),
						'tooLarge'        => __( 'Preview supports up to 200 title characters and 12,000 content characters.', 'forum-intelligence-for-bbpress' ),
						'denied'          => __( 'Preview is unavailable for this account or discussion. Copy your text and reload before continuing.', 'forum-intelligence-for-bbpress' ),
						'quota'           => __( 'Preview limit reached. Please try again in a minute.', 'forum-intelligence-for-bbpress' ),
						'editUnavailable' => __( 'Formatting is unavailable. You can keep editing in the native composer.', 'forum-intelligence-for-bbpress' ),
					),
					'offlineDraftLabels'  => array(
						'heading'          => __( 'Encrypted browser copy', 'forum-intelligence-for-bbpress' ),
						'help'             => __( 'Optional: save an encrypted copy in this browser for 24 hours. Use a unique passphrase of 16–128 characters. Keep this page open to save while offline; reconnect to recover. Forgetting the passphrase makes recovery impossible. Site drafts still save separately.', 'forum-intelligence-for-bbpress' ),
						'passphrase'       => __( 'Passphrase for this browser copy', 'forum-intelligence-for-bbpress' ),
						'unlock'           => __( 'Enable or unlock', 'forum-intelligence-for-bbpress' ),
						'save'             => __( 'Save encrypted copy', 'forum-intelligence-for-bbpress' ),
						'recover'          => __( 'Recover encrypted copy', 'forum-intelligence-for-bbpress' ),
						'remove'           => __( 'Remove encrypted copy', 'forum-intelligence-for-bbpress' ),
						'lock'             => __( 'Lock browser copy', 'forum-intelligence-for-bbpress' ),
						'ready'            => __( 'Browser copy unlocked for this page. Save a copy when needed.', 'forum-intelligence-for-bbpress' ),
						'saved'            => __( 'Encrypted browser copy saved for 24 hours.', 'forum-intelligence-for-bbpress' ),
						'recovered'        => __( 'Browser copy recovered after current permission checks.', 'forum-intelligence-for-bbpress' ),
						'removed'          => __( 'Encrypted browser copy removed.', 'forum-intelligence-for-bbpress' ),
						'locked'           => __( 'Browser copy locked. Enter the passphrase to unlock it again.', 'forum-intelligence-for-bbpress' ),
						'passphrase_error' => __( 'Use a passphrase of 16–128 characters.', 'forum-intelligence-for-bbpress' ),
						'invalid'          => __( 'The encrypted copy could not be opened. Check the passphrase; the copy may be damaged.', 'forum-intelligence-for-bbpress' ),
						'expired'          => __( 'This encrypted copy expired. Remove it to start a new copy.', 'forum-intelligence-for-bbpress' ),
						'conflict'         => __( 'Another tab changed this encrypted copy. Lock and unlock again before saving.', 'forum-intelligence-for-bbpress' ),
						'missing'          => __( 'No encrypted copy exists for this discussion.', 'forum-intelligence-for-bbpress' ),
						'limit'            => __( 'This account has ten browser copies. Remove an older copy before saving another.', 'forum-intelligence-for-bbpress' ),
						'too_large'        => __( 'Browser copies support up to 200 title characters and 12,000 content characters.', 'forum-intelligence-for-bbpress' ),
						'occupied'         => __( 'Your current text was kept. Clear the editor before recovering a browser copy.', 'forum-intelligence-for-bbpress' ),
						'unavailable'      => __( 'Browser copies are unavailable. Keep this page open or copy your text. Recovery requires an online permission check.', 'forum-intelligence-for-bbpress' ),
					),

					'duplicateLabels'     => array(
						'dismiss'   => __( 'None of these discussions answer my question', 'forum-intelligence-for-bbpress' ),
						'dismissed' => __( 'Suggestions dismissed. Your text is kept and you can continue posting.', 'forum-intelligence-for-bbpress' ),
						'score'     => __( 'Relevance score', 'forum-intelligence-for-bbpress' ),
						'scoreHelp' => __( 'This is a relevance score, not a probability that a discussion answers your question.', 'forum-intelligence-for-bbpress' ),
						'newTab'    => __( 'Opens in a new tab', 'forum-intelligence-for-bbpress' ),
					),
					'minimum'             => __( 'Type at least 12 characters to find existing discussions.', 'forum-intelligence-for-bbpress' ),
					'loading'             => __( 'Finding related discussions…', 'forum-intelligence-for-bbpress' ),
					'matches'             => __( 'These discussions may help. You can continue posting.', 'forum-intelligence-for-bbpress' ),
					'empty'               => __( 'No matching discussions found. You can continue posting.', 'forum-intelligence-for-bbpress' ),
					'unavailable'         => __( 'Suggestions are unavailable. You can continue posting.', 'forum-intelligence-for-bbpress' ),
					'solved'              => __( 'Solved', 'forum-intelligence-for-bbpress' ),
					'useTemplate'         => __( 'Use this question template', 'forum-intelligence-for-bbpress' ),
					'templateLabels'      => array(
						'heading'     => __( 'Optional template context', 'forum-intelligence-for-bbpress' ),
						'topic_type'  => __( 'Question type', 'forum-intelligence-for-bbpress' ),
						'product'     => __( 'Product', 'forum-intelligence-for-bbpress' ),
						'category'    => __( 'Category', 'forum-intelligence-for-bbpress' ),
						'any'         => __( 'Any', 'forum-intelligence-for-bbpress' ),
						'preview'     => __( 'Suggested question template', 'forum-intelligence-for-bbpress' ),
						'catalog'     => __( 'Browse applicable templates', 'forum-intelligence-for-bbpress' ),
						'help'        => __( 'Optional guidance: copy the sections you need. Choosing guidance never changes your topic classification or existing text.', 'forum-intelligence-for-bbpress' ),
						'loading'     => __( 'Finding template guidance…', 'forum-intelligence-for-bbpress' ),
						'unavailable' => __( 'Template guidance is unavailable. Your current text has been kept.', 'forum-intelligence-for-bbpress' ),
						'empty'       => __( 'No template matches these choices. You can continue writing.', 'forum-intelligence-for-bbpress' ),
					),
					'templateUsed'        => __( 'Question template added. Replace each section with your details.', 'forum-intelligence-for-bbpress' ),
					'templateOccupied'    => __( 'Your existing text was kept. Copy the suggested template if you need it.', 'forum-intelligence-for-bbpress' ),
					'draftSaved'          => __( 'Draft saved on this site.', 'forum-intelligence-for-bbpress' ),
					'draftConflict'       => __( 'A newer draft was saved in another tab. Copy your current text, then recover the newer draft.', 'forum-intelligence-for-bbpress' ),
					'draftOccupied'       => __( 'Your current text was kept. Clear the editor before recovering the saved draft.', 'forum-intelligence-for-bbpress' ),
					'draftUnavailable'    => __( 'Draft could not be saved. Keep this page open or copy your text.', 'forum-intelligence-for-bbpress' ),
					'recover'             => __( 'Recover saved draft', 'forum-intelligence-for-bbpress' ),
					'recovered'           => __( 'Saved draft recovered.', 'forum-intelligence-for-bbpress' ),
					'vapid'               => defined( 'FI_VAPID_PUBLIC_KEY' ) ? (string) constant( 'FI_VAPID_PUBLIC_KEY' ) : '',
					'worker'              => add_query_arg( 'fi_worker', '1', home_url( '/' ) ),
					'scope'               => wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
					'pushDisabled'        => __( 'Push notifications disabled for this browser.', 'forum-intelligence-for-bbpress' ),
					'pushDenied'          => __( 'Push permission was not granted.', 'forum-intelligence-for-bbpress' ),
					'pushEnabled'         => __( 'Push notifications enabled for this browser.', 'forum-intelligence-for-bbpress' ),
				),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);
	}
	public function form( string $operation, array $data, string $label, string $fields = '' ): string {
		$html  = '<form class="fi-action" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="fi_action"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		$html .= wp_nonce_field( 'fi_action', '_fi_nonce', true, false );
		foreach ( $data as $key => $value ) {
			$html .= '<input type="hidden" name="data[' . esc_attr( (string) $key ) . ']" value="' . esc_attr( (string) $value ) . '">'; }
		return $html . $fields . '<button type="submit">' . esc_html( $label ) . '</button></form>';
	}
	public function submit(): void {
		if ( ! is_user_logged_in() || ! isset( $_POST['_fi_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_fi_nonce'] ) ), 'fi_action' ) ) {
			wp_die( esc_html__( 'The request could not be verified.', 'forum-intelligence-for-bbpress' ), '', 403 );
		}
		try {
			$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
			if ( in_array( $operation, array( 'offline_restore', 'composer_preview' ), true ) ) {
				throw new Failure( 'unsupported_transport', __( 'Use the preview or recovery controls on the discussion page.', 'forum-intelligence-for-bbpress' ), 400 );
			}
			$data = isset( $_POST['data'] ) && is_array( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : array();
			if ( strlen( (string) wp_json_encode( $data ) ) > Operations::payloadLimit( $operation, get_current_user_id() ) ) {
				throw new Failure( 'too_large', __( 'The request is too large.', 'forum-intelligence-for-bbpress' ), 413 ); }
			if ( sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) ) === 'attachment' ) {
				$data['upload'] = $_FILES['attachment'] ?? array(); }
			$result = $this->operations->execute( sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) ), $data, get_current_user_id() );
			$token  = bin2hex( random_bytes( 12 ) );
			set_transient( 'fi_result_' . get_current_user_id() . '_' . $token, $result, 300 );
			wp_safe_redirect( add_query_arg( 'fi_result', $token, wp_get_referer() ?: home_url( '/' ) ) );
			exit;
		} catch ( Failure $failure ) {
			wp_die(
				esc_html( $failure->getMessage() ),
				'',
				array(
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Domain failure text is escaped; the WordPress response status is a typed integer.
					'response'  => $failure->status,
					'back_link' => true,
				)
			); } catch ( \Throwable $failure ) {
			wp_die( esc_html__( 'The operation failed. Please retry.', 'forum-intelligence-for-bbpress' ), '', 503 ); }
	}
	public function reply(): void {
		$reply = (int) bbp_get_reply_id();
		if ( get_post_type( $reply ) !== bbp_get_reply_post_type() ) {
			return;
		} $topic = $this->access->topic( $reply );
		$user    = get_current_user_id();
		if ( ! $topic || ! $this->access->canRead( $reply, $user ) ) {
			return; }
		$state = $this->solutions->state( $topic, $user );
		echo '<div class="fi-controls">';
		if ( in_array( $reply, $state['answers'], true ) ) {
			echo '<strong>' . esc_html__( 'Accepted solution', 'forum-intelligence-for-bbpress' ) . '</strong>'; }
		try {
			$this->access->solve( $topic, $user );
			$accepted = in_array( $reply, $state['answers'], true );
			if ( $accepted && $state['primary_reply'] !== $reply ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form escapes its dynamic values.
				$primaryForm = $this->form(
					'solution',
					array(
						'topic'   => $topic,
						'reply'   => $reply,
						'version' => $state['version'],
						'command' => 'primary',
					),
					__( 'Use as primary solution', 'forum-intelligence-for-bbpress' )
				);
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form helper escapes dynamic values at construction.
				echo $primaryForm;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
			echo $this->form(
				'solution',
				array(
					'topic'   => $topic,
					'reply'   => $reply,
					'version' => $state['version'],
					'command' => $accepted ? 'remove' : 'accept',
				),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				$accepted ? __( 'Remove solution', 'forum-intelligence-for-bbpress' ) : __( 'Accept solution', 'forum-intelligence-for-bbpress' )
			);
		} catch ( Failure $failure ) {
			/* Read-only visitors see state without mutation controls. */ }
		echo '</div>';
	}
	public function topic(): void {
		$topic = (int) bbp_get_topic_id();
		$user  = get_current_user_id();
		if ( ! $this->access->canRead( $topic, $user ) ) {
			return; }
		$state = $this->solutions->state( $topic, $user );
		echo '<section class="fi-panel" aria-label="' . esc_attr__( 'Solution status', 'forum-intelligence-for-bbpress' ) . '"><p>';
		echo esc_html( $state['solved'] ? __( 'Solved', 'forum-intelligence-for-bbpress' ) : __( 'Awaiting a solution', 'forum-intelligence-for-bbpress' ) );
		if ( $state['primary_reply'] ) {
			echo ' <a href="' . esc_url( bbp_get_reply_url( $state['primary_reply'] ) ) . '">' . esc_html__( 'Go to accepted solution', 'forum-intelligence-for-bbpress' ) . '</a>'; }
		echo '</p>';
		if ( $state['updated_at'] ) {
			echo '<p>' . esc_html__( 'Last solution update', 'forum-intelligence-for-bbpress' ) . ': ' . esc_html( $state['updated_at'] ) . ' UTC';
			$actor = get_userdata( $state['accepted_by'] );
			if ( $actor ) {
				echo ' — ' . esc_html( $actor->display_name );}
			echo '</p>';
		}
		if ( $state['community_validations'] ) {
			// translators: %d is the number of member confirmations across accepted replies.
			echo '<p>' . esc_html( sprintf( _n( '%d member confirmation', '%d member confirmations', $state['community_validations'], 'forum-intelligence-for-bbpress' ), $state['community_validations'] ) ) . '</p>';
		}
		$history = $this->solutions->history( $topic, $user );
		if ( $history['items'] ) {
			echo '<details><summary>' . esc_html__( 'Solution history', 'forum-intelligence-for-bbpress' ) . '</summary><ul>';
			foreach ( $history['items'] as $event ) {
				$labels = array(
					'solution_accept'      => __( 'Accepted answer', 'forum-intelligence-for-bbpress' ),
					'solution_remove'      => __( 'Removed answer', 'forum-intelligence-for-bbpress' ),
					'solution_primary'     => __( 'Changed primary answer', 'forum-intelligence-for-bbpress' ),
					'solution_solve'       => __( 'Marked solved', 'forum-intelligence-for-bbpress' ),
					'solution_reopen'      => __( 'Reopened', 'forum-intelligence-for-bbpress' ),
					'solution_invalidated' => __( 'Source invalidated', 'forum-intelligence-for-bbpress' ),
				);
				echo '<li>' . esc_html( $labels[ $event['kind'] ] ?? __( 'Solution updated', 'forum-intelligence-for-bbpress' ) ) . ' — ' . esc_html( $event['created_at'] ) . ' UTC</li>';
			}echo '</ul></details>';
		}
		try {
			$this->access->solve( $topic, $user );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
			echo $this->form(
				'solution',
				array(
					'topic'   => $topic,
					'version' => $state['version'],
					'command' => $state['solved'] ? 'reopen' : 'solve',
				),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				$state['solved'] ? __( 'Reopen topic', 'forum-intelligence-for-bbpress' ) : __( 'Mark solved', 'forum-intelligence-for-bbpress' )
			);
		} catch ( Failure $failure ) {
		}
		echo '</section>';
	}
	public function title( string $title, int $topic ): string {
		if ( ! $this->access->canRead( $topic, get_current_user_id() ) ) {
			return $title; }
		$state = $this->solutions->state( $topic, get_current_user_id() );
		// translators: %s is the topic title.
		return $state['solved'] ? sprintf( __( '[Solved] %s', 'forum-intelligence-for-bbpress' ), $title ) : $title;
	}
}
