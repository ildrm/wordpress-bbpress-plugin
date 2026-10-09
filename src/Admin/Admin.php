<?php
declare(strict_types=1);
namespace ForumIntelligence\Admin;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Frontend\Frontend;
final class Admin {
	public function __construct( private Services $services, private Frontend $frontend ) {}
	public function register(): void {
		ConfigurationAudit::register();
		( new Onboarding( $this->services, $this->frontend ) )->register();
		$this->services->operations->add(
			'retain',
			function ( array $data, int $user ): array {
				if ( ! user_can( $user, 'manage_options' ) ) {
					throw new \ForumIntelligence\Domain\Failure( 'forbidden', __( 'Settings permission is required.', 'forum-intelligence-for-bbpress' ), 403 ); }
				( new \ForumIntelligence\Privacy\Privacy( $this->services->store ) )->retain();
				return array( 'processed' => true );
			}
		);
		$this->services->operations->add( 'config_export', fn( array $data, int $user ): array=>$this->exportConfiguration( $user ) );
		$this->services->operations->add(
			'config_import',
			function ( array $data, int $user ): array {
				if ( ! user_can( $user, 'manage_options' ) ) {
					throw new \ForumIntelligence\Domain\Failure( 'forbidden', __( 'Settings permission is required.', 'forum-intelligence-for-bbpress' ), 403 ); }
				if ( strlen( (string) ( $data['json'] ?? '' ) ) > 1048576 ) {
					throw new \ForumIntelligence\Domain\Failure( 'too_large', __( 'The configuration is too large.', 'forum-intelligence-for-bbpress' ), 413 );}
				$decoded = json_decode( (string) ( $data['json'] ?? '' ), true );
				if ( ! in_array( $data['reviewed'] ?? null, array( true, 1, '1' ), true ) || ! is_array( $decoded ) || ( $decoded['version'] ?? 0 ) !== 1 || ! is_array( $decoded['settings'] ?? null ) ) {
					throw new \ForumIntelligence\Domain\Failure( 'invalid_configuration', __( 'Review a valid version 1 configuration.', 'forum-intelligence-for-bbpress' ) ); }
				try {
					$this->services->store->transaction(
						function () use ( $decoded, $user ): void {
							$settings                    = $decoded['settings'];
							$settings['excluded_forums'] = implode( ',', array_map( 'absint', (array) ( $settings['excluded_forums'] ?? array() ) ) );
							if ( isset( $settings['knowledge_target'] ) && ( ! is_string( $settings['knowledge_target'] ) || ! array_key_exists( $settings['knowledge_target'], ( new \ForumIntelligence\Domain\Knowledge\Targets() )->options() ) ) ) {
								throw new \ForumIntelligence\Domain\Failure( 'invalid_configuration', __( 'Select an available compatible editorial target before importing configuration.', 'forum-intelligence-for-bbpress' ) );
							}
							$clean  = $this->sanitize( $settings );
							$fields = $this->services->get( 'fields' );
							$drafts = $this->services->get( 'drafts' );
							if ( isset( $decoded['fields'] ) ) {
								if ( ! $fields ) {
									throw new \ForumIntelligence\Domain\Failure( 'module_disabled', __( 'Enable composer before importing fields.', 'forum-intelligence-for-bbpress' ) );
								}$fields->configure( wp_json_encode( $decoded['fields'] ), $user );}
							if ( isset( $decoded['templates'] ) ) {
								if ( ! $drafts ) {
									throw new \ForumIntelligence\Domain\Failure( 'module_disabled', __( 'Enable composer before importing templates.', 'forum-intelligence-for-bbpress' ) );
								}$drafts->templates( $user, wp_json_encode( (object) $decoded['templates'] ) );}
							if ( isset( $decoded['template_variants'] ) ) {
								$templates = $this->services->get( 'templates' );
								if ( ! $templates ) {
									throw new \ForumIntelligence\Domain\Failure( 'module_disabled', __( 'Enable composer before importing templates.', 'forum-intelligence-for-bbpress' ) );}
								$templates->configure( $user, wp_json_encode( $decoded['template_variants'] ) );
							}
							update_option( 'fi_settings', $clean, false );
							$this->services->store->event( 'configuration_imported', 0, $user );
						}
					);
				} catch ( \Throwable $failure ) {
					foreach ( array( 'fi_settings', 'fi_field_definitions', 'fi_templates', 'fi_template_variants', 'alloptions' ) as $key ) {
						wp_cache_delete( $key, 'options' );
					}wp_cache_delete( 'notoptions', 'options' );
					throw $failure;
				}
				return array( 'imported' => true );
			}
		);
		$this->services->operations->add(
			'message_report_resolve',
			function ( array $data, int $user ): array {
				$this->services->access->moderate( $user );
				$id  = (int) ( $data['id'] ?? 0 );
				$row = $this->services->store->row( "SELECT * FROM {$this->services->store->table('records')} WHERE id=%d AND kind='message_report'", array( $id ) );
				if ( ! $row ) {
					throw new \ForumIntelligence\Domain\Failure( 'not_found', __( 'Report unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
				$payload           = json_decode( $row['payload'], true );
				$payload['status'] = 'reviewed';
				$this->services->store->saveRecord( 'message_report', $row['record_key'], (int) $row['owner_id'], (int) $row['object_id'], $payload );
				$this->services->store->event( 'message_report_reviewed', 0, $user, array( 'report' => $id ) );
				return array( 'reviewed' => true );
			}
		);
		add_action(
			'admin_menu',
			function (): void {
				add_menu_page( __( 'Forum Intelligence', 'forum-intelligence-for-bbpress' ), __( 'Forum Intelligence', 'forum-intelligence-for-bbpress' ), 'moderate', 'forum-intelligence', array( $this, 'render' ), 'dashicons-lightbulb', 58 );
			}
		);
		add_action(
			'admin_init',
			function (): void {
				register_setting(
					'fi_settings_group',
					'fi_settings',
					array(
						'type'              => 'array',
						'sanitize_callback' => array( $this, 'sanitize' ),
						'default'           => array(),
					)
				);
			}
		);
		add_action(
			'admin_enqueue_scripts',
			function ( string $screen ): void {
				if ( str_contains( $screen, 'forum-intelligence' ) ) {
					$this->frontend->assets();
				} }
		);
	}
	public static function defaultSettings(): array {
		return array(
			'author_acceptance'                      => true,
			'multiple_solutions'                     => false,
			'downvotes'                              => false,
			'self_voting'                            => false,
			'ai_enabled'                             => false,
			'semantic'                               => false,
			'summary_auto_enabled'                   => false,
			'knowledge_review_notifications'         => false,
			'knowledge_target'                       => 'fi_knowledge',
			'knowledge_auto_draft_enabled'           => false,
			'knowledge_auto_draft_actor'             => 0,
			'knowledge_auto_draft_min_replies'       => 3,
			'knowledge_auto_draft_min_confirmations' => 0,
			'knowledge_auto_draft_debounce'          => 300,
			'summary_auto_actor'                     => 0,
			'summary_min_replies'                    => 20,
			'summary_debounce_seconds'               => 600,
			'summary_min_interval'                   => 3600,
			'delete_on_uninstall'                    => false,
			'text_model'                             => 'gpt-4o-mini',
			'embedding_model'                        => 'text-embedding-3-small',
			'ai_daily_requests'                      => 200,
			'ai_answers'                             => true,
			'ai_moderation'                          => true,
			'reranking'                              => false,
			'index_pending'                          => false,
			'summary_model'                          => '',
			'moderation_model'                       => '',
			'reranking_model'                        => '',
			'ai_timeout_seconds'                     => 15,
			'ai_completion_tokens'                   => 1200,
			'ai_retry_seconds'                       => 60,
			'ai_logging'                             => 'usage',
			'evidence_threshold'                     => 0.7,
			'vote_weight'                            => 2,
			'downvote_weight'                        => 2,
			'confirmation_weight'                    => 3,
			'solution_weight'                        => 10,
			'reputation_daily_cap'                   => 100,
			'duplicate_threshold'                    => 0.25,
			'reactions'                              => \ForumIntelligence\Domain\Engagement\Engagement::REACTIONS,
			'retention_days'                         => 90,
			'excluded_forums'                        => array(),
		);
	}
	public function exportConfiguration( int $actor ): array {
		if ( ! user_can( $actor, 'manage_options' ) ) {
			throw new \ForumIntelligence\Domain\Failure( 'forbidden', __( 'Settings permission is required.', 'forum-intelligence-for-bbpress' ), 403 );}
		$defaults            = self::defaultSettings();
		$settings            = array_replace( $defaults, array_intersect_key( (array) get_option( 'fi_settings', array() ), $defaults ) );
		$settings['modules'] = array();
		foreach ( array( 'engagement', 'moderation', 'knowledge', 'relationships', 'notifications', 'reputation', 'polls', 'composer', 'attachments', 'messaging', 'pwa', 'push', 'badges' ) as $module ) {
			$settings['modules'][ $module ] = $this->services->enabled( $module );
		}
		$this->services->store->event( 'configuration_exported', 0, $actor );
		return array(
			'version'           => 1,
			'settings'          => $settings,
			'fields'            => get_option( 'fi_field_definitions', array() ),
			'templates'         => (object) get_option( 'fi_templates', array() ),
			'template_variants' => (array) get_option( 'fi_template_variants', array() ),
		);
	}
	public function sanitize( mixed $input ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return (array) get_option( 'fi_settings', array() ); }
		// Setup writes combine server-owned defaults with already persisted choices.
		if ( Onboarding::preservingDefaults( $input ) ) {
			return $input;
		}
		$input = is_array( $input ) ? $input : array();
		$data  = array();
		foreach ( array( 'author_acceptance', 'multiple_solutions', 'downvotes', 'self_voting', 'ai_enabled', 'semantic', 'ai_answers', 'ai_moderation', 'reranking', 'index_pending', 'summary_auto_enabled', 'knowledge_review_notifications', 'knowledge_auto_draft_enabled', 'delete_on_uninstall' ) as $key ) {
			$data[ $key ] = in_array( $input[ $key ] ?? null, array( true, 1, '1' ), true ); }
		$data['text_model']        = substr( sanitize_text_field( (string) ( $input['text_model'] ?? 'gpt-4o-mini' ) ), 0, 100 );
		$data['embedding_model']   = substr( sanitize_text_field( (string) ( $input['embedding_model'] ?? 'text-embedding-3-small' ) ), 0, 100 );
		$data['ai_daily_requests'] = min( 10000, max( 1, (int) ( $input['ai_daily_requests'] ?? 200 ) ) );
		foreach ( array( 'summary_model', 'moderation_model', 'reranking_model' ) as $modelKey ) {
			$data[ $modelKey ] = is_string( $input[ $modelKey ] ?? null ) ? substr( sanitize_text_field( $input[ $modelKey ] ), 0, 100 ) : '';
		}
		$data['ai_timeout_seconds']       = min( 30, max( 1, (int) ( $input['ai_timeout_seconds'] ?? 15 ) ) );
		$data['ai_completion_tokens']     = min( 4096, max( 128, (int) ( $input['ai_completion_tokens'] ?? 1200 ) ) );
		$data['ai_retry_seconds']         = min( 3600, max( 10, (int) ( $input['ai_retry_seconds'] ?? 60 ) ) );
		$data['ai_logging']               = in_array( $input['ai_logging'] ?? 'usage', array( 'off', 'errors', 'usage' ), true ) ? ( $input['ai_logging'] ?? 'usage' ) : 'off';
		$data['summary_auto_actor']       = absint( $input['summary_auto_actor'] ?? 0 );
		$data['summary_min_replies']      = min( 5000, max( 3, (int) ( $input['summary_min_replies'] ?? 20 ) ) );
		$data['summary_debounce_seconds'] = min( 86400, max( 60, (int) ( $input['summary_debounce_seconds'] ?? 600 ) ) );
		$data['summary_min_interval']     = min( 604800, max( 300, (int) ( $input['summary_min_interval'] ?? 3600 ) ) );
		if ( ! $data['ai_enabled'] || ! $data['summary_auto_actor'] || ! user_can( $data['summary_auto_actor'], 'moderate' ) ) {
			$data['summary_auto_enabled'] = false;}

		$storedTarget             = (array) get_option( 'fi_settings', array() );
		$targetInput              = $input['knowledge_target'] ?? ( $storedTarget['knowledge_target'] ?? 'fi_knowledge' );
		$data['knowledge_target'] = is_string( $targetInput ) && preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $targetInput ) ? $targetInput : ( is_string( $storedTarget['knowledge_target'] ?? null ) ? $storedTarget['knowledge_target'] : 'fi_knowledge' );
		$targets                  = new \ForumIntelligence\Domain\Knowledge\Targets();
		$targetAdapter            = $targets->registry()[ $data['knowledge_target'] ] ?? null;
		if ( ! array_key_exists( $data['knowledge_target'], $targets->options() ) ) {
			add_settings_error( 'fi_settings', 'knowledge_target', __( 'The selected editorial target is unavailable. Select a compatible target before conversion.', 'forum-intelligence-for-bbpress' ) );
		}
		$data['knowledge_auto_draft_actor']             = absint( $input['knowledge_auto_draft_actor'] ?? 0 );
		$data['knowledge_auto_draft_min_replies']       = min( 5000, max( 1, (int) ( $input['knowledge_auto_draft_min_replies'] ?? 3 ) ) );
		$data['knowledge_auto_draft_min_confirmations'] = min( 1000, max( 0, (int) ( $input['knowledge_auto_draft_min_confirmations'] ?? 0 ) ) );
		$data['knowledge_auto_draft_debounce']          = min( 86400, max( 60, (int) ( $input['knowledge_auto_draft_debounce'] ?? 300 ) ) );
		if ( ! $data['knowledge_auto_draft_actor'] || ! user_can( $data['knowledge_auto_draft_actor'], 'moderate' ) || ( ! $targetAdapter || ! $targets->canCreate( $targetAdapter, $data['knowledge_auto_draft_actor'] ) ) ) {
			$data['knowledge_auto_draft_enabled'] = false;}
		$data['evidence_threshold'] = min( 1, max( 0.5, (float) ( $input['evidence_threshold'] ?? 0.7 ) ) );
		foreach ( array(
			'vote_weight'          => 2,
			'downvote_weight'      => 2,
			'confirmation_weight'  => 3,
			'solution_weight'      => 10,
			'reputation_daily_cap' => 100,
		) as $key => $default ) {
			$data[ $key ] = min( $key === 'reputation_daily_cap' ? 1000 : 100, max( $key === 'reputation_daily_cap' ? 1 : 0, (int) ( $input[ $key ] ?? $default ) ) );}
		$data['duplicate_threshold'] = min( 1, max( 0.1, (float) ( $input['duplicate_threshold'] ?? 0.25 ) ) );
		$data['reactions']           = array_key_exists( 'reactions', $input ) ? \ForumIntelligence\Domain\Engagement\Engagement::normalizeReactions( $input['reactions'] ) : ( ! empty( $input['reactions_present'] ) ? array() : \ForumIntelligence\Domain\Engagement\Engagement::enabledReactions() );
		$data['retention_days']      = min( 3650, max( 7, (int) ( $input['retention_days'] ?? 90 ) ) );
		$excluded                    = $input['excluded_forums'] ?? '';
		$excluded                    = is_array( $excluded ) ? $excluded : ( preg_split( '/[,\\s]+/', (string) $excluded ) ?: array() );
		$data['excluded_forums']     = array_values( array_filter( array_map( 'absint', $excluded ) ) );
		$data['modules']             = array();
		foreach ( array( 'engagement', 'moderation', 'knowledge', 'relationships', 'notifications', 'reputation', 'polls', 'composer', 'attachments', 'messaging', 'pwa', 'push', 'badges' ) as $module ) {
			$data['modules'][ $module ] = in_array( $input['modules'][ $module ] ?? null, array( true, 1, '1' ), true ); }
		if ( $data['semantic'] && ! $data['ai_enabled'] ) {
			$data['semantic'] = false; }
		return $data;
	}
	public function render(): void {
		if ( ! current_user_can( 'moderate' ) ) {
			return; }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$tabs = array(
			'dashboard'  => __( 'Dashboard', 'forum-intelligence-for-bbpress' ),
			'moderation' => __( 'Moderation', 'forum-intelligence-for-bbpress' ),
			'knowledge'  => __( 'Knowledge', 'forum-intelligence-for-bbpress' ),
			'feedback'   => __( 'AI answer feedback', 'forum-intelligence-for-bbpress' ),
			'jobs'       => __( 'Jobs and indexing', 'forum-intelligence-for-bbpress' ),
			'settings'   => __( 'Settings and modules', 'forum-intelligence-for-bbpress' ),
			'audit'      => __( 'Audit', 'forum-intelligence-for-bbpress' ),
			'tools'      => __( 'Setup and tools', 'forum-intelligence-for-bbpress' ),
			'analytics'  => __( 'Analytics', 'forum-intelligence-for-bbpress' ),
			'reputation' => __( 'Reputation', 'forum-intelligence-for-bbpress' ),
			'messages'   => __( 'Message reports', 'forum-intelligence-for-bbpress' ),
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Forum Intelligence', 'forum-intelligence-for-bbpress' ) . '</h1><nav aria-label="' . esc_attr__( 'Administration sections', 'forum-intelligence-for-bbpress' ) . '">';
		foreach ( $tabs as $id => $label ) {
			echo '<a class="button" href="' . esc_url(
				add_query_arg(
					array(
						'page' => 'forum-intelligence',
						'tab'  => $id,
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html( $label ) . '</a> '; }
		echo '</nav>';
		$tools = new Tools( $this->services, $this->frontend );
		if ( $tab === 'tools' ) {
			$tools->render(); } elseif ( $tab === 'analytics' ) {
			$tools->analytics(); } elseif ( $tab === 'reputation' ) {
				$tools->reputation(); } elseif ( $tab === 'messages' ) {
				$tools->messageReports(); } elseif ( $tab === 'moderation' ) {
					$this->moderation(); } elseif ( $tab === 'knowledge' ) {
					$this->knowledge(); } elseif ( $tab === 'feedback' ) {
						$this->answerFeedback(); } elseif ( $tab === 'settings' ) {
						$this->settings(); } elseif ( $tab === 'jobs' && current_user_can( 'manage_options' ) ) {
							$this->jobs(); } elseif ( $tab === 'audit' && current_user_can( 'manage_options' ) ) {
							$this->audit(); } else {
								$this->dashboard(); }
							echo '</div>';
	}
	private function answerFeedback(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cursor; the service renews moderation and source access.
		$before = isset( $_GET['fi_feedback_before'] ) ? max( 1, (int) $_GET['fi_feedback_before'] ) : PHP_INT_MAX;
		$page   = $this->services->get( 'answer_feedback' )->listing( get_current_user_id(), $before );
		echo '<section class="fi-panel" data-fi-feedback-review><h2>' . esc_html__( 'Review answer feedback', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Review the current source discussions to identify missing documentation, inaccurate advice and outdated solutions. Resolving a report records your review; it does not change a discussion, regenerate an answer or train an external model.', 'forum-intelligence-for-bbpress' ) . '</p>';
		if ( ! $page['items'] ) {
			echo '<p>' . esc_html__( 'No accessible answer feedback on this page.', 'forum-intelligence-for-bbpress' ) . '</p>';
		}
		$labels = \ForumIntelligence\Domain\AI\Feedback::labels();
		$states = \ForumIntelligence\Domain\AI\Feedback::states();
		foreach ( $page['items'] as $item ) {
			echo '<article class="fi-panel" data-fi-feedback-id="' . esc_attr( (string) $item['id'] ) . '"><h3><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['title'] ) . '</a></h3><p>' . esc_html( $labels[ $item['value'] ] ) . ' · ' . esc_html( $states[ $item['state'] ] ) . '</p><p>' . esc_html__( 'Updated (UTC):', 'forum-intelligence-for-bbpress' ) . ' ' . esc_html( $item['updated_at'] ) . '</p>';
			if ( $item['stale'] ) {
				echo '<p>' . esc_html__( 'The supporting evidence has changed since this answer. Review the current discussion.', 'forum-intelligence-for-bbpress' ) . '</p>';
			}
			$fields = '<label>' . esc_html__( 'Review status', 'forum-intelligence-for-bbpress' ) . '<select name="data[state]">';
			foreach ( $states as $state => $label ) {
				$fields .= '<option value="' . esc_attr( $state ) . '" ' . selected( $item['state'], $state, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$fields    .= '</select></label>';
			$reviewForm = $this->frontend->form(
				'ai_feedback_review',
				array(
					'id'      => $item['id'],
					'version' => $item['version'],
				),
				__( 'Save feedback review', 'forum-intelligence-for-bbpress' ),
				$fields
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The form helper escapes dynamic attributes and labels; fields are escaped above.
			echo $reviewForm;
			echo '</article>';
		}
		if ( $page['next'] !== null ) {
			echo '<a href="' . esc_url(
				add_query_arg(
					array(
						'page'               => 'forum-intelligence',
						'tab'                => 'feedback',
						'fi_feedback_before' => $page['next'],
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( 'Older feedback', 'forum-intelligence-for-bbpress' ) . '</a>';
		}
		echo '</section>';
	}
	private function dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>' . esc_html__( 'Use Moderation to review reports and Knowledge to review summaries.', 'forum-intelligence-for-bbpress' ) . '</p>';
			return; }
		echo '<p><a href="' . esc_url(
			add_query_arg(
				array(
					'page' => 'forum-intelligence',
					'tab'  => 'tools',
				),
				admin_url( 'admin.php' )
			)
		) . '">' . esc_html__( 'Get started with guided setup', 'forum-intelligence-for-bbpress' ) . '</a></p>';
		$rows = $this->services->store->rows( "SELECT kind,SUM(count) AS total FROM {$this->services->store->table('metrics')} WHERE day>=%s GROUP BY kind", array( gmdate( 'Y-m-d', time() - 30 * 86400 ) ) );
		echo '<section class="fi-panel"><h2>' . esc_html__( 'Last 30 days', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'No raw search questions are stored. AI is optional; configure it only after reviewing the site privacy policy.', 'forum-intelligence-for-bbpress' ) . '</p><dl>';
		foreach ( $rows as $row ) {
			echo '<dt>' . esc_html( $row['kind'] ) . '</dt><dd>' . esc_html( number_format_i18n( (int) $row['total'] ) ) . '</dd>'; }
		echo '</dl></section>';
	}
	private function moderation(): void {
		$service = $this->services->get( 'cases' );
		if ( ! $service ) {
			return; }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? 'new' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
		$page = max( 1, (int) ( $_GET['fi_page'] ?? 1 ) );
		echo '<form method="get"><input type="hidden" name="page" value="forum-intelligence"><input type="hidden" name="tab" value="moderation"><label>' . esc_html__( 'Case status', 'forum-intelligence-for-bbpress' ) . '<select name="status">';
		foreach ( array( 'new', 'triaged', 'investigating', 'actioned', 'dismissed', 'escalated' ) as $value ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $value ) . '</option>'; }
		echo '</select></label><button>' . esc_html__( 'Filter cases', 'forum-intelligence-for-bbpress' ) . '</button></form>';
		$items = $service->listing( get_current_user_id(), $page, $status )['items'];
		foreach ( $items as $item ) {
			echo '<section class="fi-panel"><h2>' . esc_html( $item['title'] ) . '</h2><p>' . esc_html( $item['reason'] ) . '</p><p>' . esc_html( $item['notes'] ) . '</p><blockquote>' . esc_html( $item['content'] ) . '</blockquote>';
			foreach ( $item['signals'] as $signal ) {
				echo '<p>' . esc_html( $signal['explanation'] ) . '</p>'; }
			$advisory = $this->services->get( 'moderation_advisory' );
			if ( $advisory ) {
				if ( $this->services->access->isPublic( (int) $item['object_id'] ) ) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form helper escapes its dynamic values.
					echo $this->frontend->form( 'moderation_advisory', array( 'case' => $item['id'] ), __( 'Request AI advisory review', 'forum-intelligence-for-bbpress' ) );
				}
				try {
					$analysis = $advisory->view( (int) $item['id'], get_current_user_id() );
				} catch ( \ForumIntelligence\Domain\Failure $failure ) {
					$analysis = null;}
				if ( $analysis ) {
					echo '<details><summary>' . esc_html__( 'AI advisory: human review required', 'forum-intelligence-for-bbpress' ) . '</summary><p>' . esc_html( $analysis['explanation'] ) . '</p><dl>';
					$labels = array(
						'spam'       => __( 'Possible spam', 'forum-intelligence-for-bbpress' ),
						'toxicity'   => __( 'Possible toxicity', 'forum-intelligence-for-bbpress' ),
						'dangerous'  => __( 'Possible dangerous advice', 'forum-intelligence-for-bbpress' ),
						'pii'        => __( 'Possible personal information', 'forum-intelligence-for-bbpress' ),
						'confidence' => __( 'Provider confidence', 'forum-intelligence-for-bbpress' ),
					);
					foreach ( $labels as $key => $label ) {
						echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( number_format_i18n( (float) $analysis[ $key ] * 100, 0 ) ) . '%</dd>';}
					echo '</dl><p>' . esc_html( $analysis['stale'] ? __( 'Source changed; regenerate before using this advisory.', 'forum-intelligence-for-bbpress' ) : __( 'No moderation action was taken by AI.', 'forum-intelligence-for-bbpress' ) ) . '</p></details>';
				}
			}
			$fields = '<label>' . esc_html__( 'Action', 'forum-intelligence-for-bbpress' ) . '<select name="data[command]">';
			foreach ( array( 'triage', 'dismiss', 'warn', 'pending', 'spam', 'trash', 'restore', 'close', 'open', 'escalate', 'assign' ) as $command ) {
				$fields .= '<option value="' . esc_attr( $command ) . '">' . esc_html( $command ) . '</option>'; }
			$fields .= '</select></label><label>' . esc_html__( 'Moderator notes', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[notes]" maxlength="2000"></textarea></label><label>' . esc_html__( 'Assign moderator user ID', 'forum-intelligence-for-bbpress' ) . '<input type="number" name="data[assignee]" min="0" value="' . esc_attr( $item['assignee_id'] ) . '"></label>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
			echo $this->frontend->form( 'moderate', array( 'case' => $item['id'] ), __( 'Apply reviewed action', 'forum-intelligence-for-bbpress' ), $fields ) . '</section>';
		}
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'No cases in this view.', 'forum-intelligence-for-bbpress' ) . '</p>'; }
		echo '<p><a href="' . esc_url( add_query_arg( 'fi_page', $page + 1 ) ) . '">' . esc_html__( 'Older cases', 'forum-intelligence-for-bbpress' ) . '</a></p>';
	}
	private function knowledge(): void {
		$knowledge = $this->services->get( 'knowledge' );
		if ( ! $knowledge ) {
			echo '<p>' . esc_html__( 'Community knowledge is disabled.', 'forum-intelligence-for-bbpress' ) . '</p>';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only keyset cursor; the queue rechecks current moderator and recorded source permissions.
		$before = isset( $_GET['fi_knowledge_before'] ) ? max( 1, (int) $_GET['fi_knowledge_before'] ) : PHP_INT_MAX;
		echo '<section class="fi-panel" data-fi-knowledge-recommendations><h2>' . esc_html__( 'Moderator recommendations', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Review recommended solved discussions, prepare a current summary, then create an editorial draft.', 'forum-intelligence-for-bbpress' ) . '</p>';
		try {
			$reviews = $knowledge->recommendations( get_current_user_id(), $before );
		} catch ( \ForumIntelligence\Domain\Failure $failure ) {
			$reviews = array(
				'items' => array(),
				'next'  => null,
			);
			echo '<p>' . esc_html__( 'The recommendation queue is unavailable. Check your current access and reopen this page.', 'forum-intelligence-for-bbpress' ) . '</p>';
		}
		if ( ! $reviews['items'] ) {
			echo '<p>' . esc_html__( 'No readable recommendations in this page.', 'forum-intelligence-for-bbpress' ) . '</p>';
		}
		foreach ( $reviews['items'] as $review ) {
			try {
				$summary = $knowledge->summary( $review['topic'], get_current_user_id() );
				$this->services->access->moderate( get_current_user_id() );
			} catch ( \ForumIntelligence\Domain\Failure $failure ) {
				continue;
			}
			/* translators: %d: Native source topic ID. */
			echo '<article data-fi-recommendation="' . esc_attr( (string) $review['topic'] ) . '"><h3><a href="' . esc_url( $review['url'] ) . '">' . esc_html( sprintf( __( 'Topic #%d', 'forum-intelligence-for-bbpress' ), $review['topic'] ) ) . '</a></h3>';
			echo '<p>' . esc_html( $review['reason'] ) . '</p><p>' . esc_html( $review['stale'] ? __( 'The source changed after this recommendation. Review the current discussion.', 'forum-intelligence-for-bbpress' ) : __( 'Ready for human review.', 'forum-intelligence-for-bbpress' ) ) . '</p>';
			if ( $summary && empty( $summary['stale'] ) && empty( $summary['inaccurate'] ) && $knowledge->canDraft( get_current_user_id() ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited native action form; current command authorization runs again on submission.
				$draftForm = $this->frontend->form(
					'knowledge',
					array(
						'topic'   => $review['topic'],
						'command' => 'draft',
					),
					__( 'Create editorial draft', 'forum-intelligence-for-bbpress' )
				);
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited native form escapes its operation, hidden values, action URL and button label.
				echo $draftForm;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited native action form; withdrawal renews current moderator/source access.
			$withdrawForm = $this->frontend->form(
				'knowledge',
				array(
					'topic'   => $review['topic'],
					'command' => 'unrecommend',
				),
				__( 'Withdraw recommendation', 'forum-intelligence-for-bbpress' )
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited native form escapes all dynamic inputs and renews command permissions on submission.
			echo $withdrawForm;
			echo '</article>';
		}
		if ( $reviews['next'] ) {
			$url = add_query_arg(
				array(
					'page'                => 'forum-intelligence',
					'tab'                 => 'knowledge',
					'fi_knowledge_before' => $reviews['next'],
				),
				admin_url( 'admin.php' )
			);
			echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Older recommendations', 'forum-intelligence-for-bbpress' ) . '</a></p>';
		}
		echo '</section>';
		$fields = '<p><label for="fi-knowledge-topic">' . esc_html__( 'Topic ID', 'forum-intelligence-for-bbpress' ) . '</label><br><input id="fi-knowledge-topic" type="number" name="data[topic]" min="1" required></p><p><label for="fi-knowledge-action">' . esc_html__( 'Action', 'forum-intelligence-for-bbpress' ) . '</label><br><select id="fi-knowledge-action" name="data[command]">';
		foreach ( array(
			'regenerate'  => __( 'Generate a summary', 'forum-intelligence-for-bbpress' ),
			'edit'        => __( 'Save reviewed summary', 'forum-intelligence-for-bbpress' ),
			'unlock'      => __( 'Unlock summary', 'forum-intelligence-for-bbpress' ),
			'disable'     => __( 'Disable summary', 'forum-intelligence-for-bbpress' ),
			'inaccurate'  => __( 'Flag inaccurate summary', 'forum-intelligence-for-bbpress' ),
			'draft'       => __( 'Create editorial draft', 'forum-intelligence-for-bbpress' ),
			'recommend'   => __( 'Recommend solved discussion', 'forum-intelligence-for-bbpress' ),
			'unrecommend' => __( 'Withdraw recommendation', 'forum-intelligence-for-bbpress' ),
		) as $command => $label ) {
			$fields .= '<option value="' . esc_attr( $command ) . '">' . esc_html( $label ) . '</option>'; }
		$fields .= '</select></p><p><label for="fi-knowledge-text">' . esc_html__( 'Reviewed summary text or recommendation note', 'forum-intelligence-for-bbpress' ) . '</label><br><textarea id="fi-knowledge-text" class="large-text" name="data[text]" rows="8" maxlength="20000"></textarea></p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
		echo '<section class="fi-panel" data-fi-knowledge-controls><h2>' . esc_html__( 'Review community knowledge', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Manual edits lock summaries. AI regeneration is queued. Articles are always created as editorial drafts.', 'forum-intelligence-for-bbpress' ) . '</p>' . $this->frontend->form( 'knowledge', array(), __( 'Apply', 'forum-intelligence-for-bbpress' ), $fields ) . '</section>';
		if ( $this->services->get( 'relationships' ) ) {
			$fields = '<label>' . esc_html__( 'Source topic ID', 'forum-intelligence-for-bbpress' ) . '<input type="number" min="1" name="data[source]" required></label><label>' . esc_html__( 'Target topic ID', 'forum-intelligence-for-bbpress' ) . '<input type="number" min="1" name="data[target]" required></label><label>' . esc_html__( 'Relationship', 'forum-intelligence-for-bbpress' ) . '<select name="data[type]">';
			foreach ( \ForumIntelligence\Domain\Relationships\Relationships::TYPES as $type ) {
				$fields .= '<option value="' . esc_attr( $type ) . '">' . esc_html( $type ) . '</option>'; }
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
			echo $this->frontend->form( 'relationship', array( 'status' => 'approved' ), __( 'Approve relationship', 'forum-intelligence-for-bbpress' ), $fields . '</select></label>' );
		}
	}
	private function settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$data = (array) get_option( 'fi_settings', array() );
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '" class="fi-panel">';
		settings_fields( 'fi_settings_group' );
		echo '<h2>' . esc_html__( 'Core behavior', 'forum-intelligence-for-bbpress' ) . '</h2>';
		foreach ( array(
			'author_acceptance'              => __( 'Topic authors can accept solutions', 'forum-intelligence-for-bbpress' ),
			'multiple_solutions'             => __( 'Allow multiple accepted answers', 'forum-intelligence-for-bbpress' ),
			'downvotes'                      => __( 'Enable downvotes', 'forum-intelligence-for-bbpress' ),
			'self_voting'                    => __( 'Allow self-votes', 'forum-intelligence-for-bbpress' ),
			'knowledge_review_notifications' => __( 'Notify responsible article editors when sources change', 'forum-intelligence-for-bbpress' ),
		) as $key => $label ) {
			$this->checkbox( $key, $label, (bool) ( $data[ $key ] ?? $key === 'author_acceptance' ) ); }
		echo '<fieldset><legend>' . esc_html__( 'Enabled reactions', 'forum-intelligence-for-bbpress' ) . '</legend><p>' . esc_html__( 'Reactions express appreciation and do not award reputation points. Helpful votes remain separate. Disabled reactions are retained and members can remove their own previous reactions.', 'forum-intelligence-for-bbpress' ) . '</p><input type="hidden" name="fi_settings[reactions_present]" value="1">';
		$enabledReactions = \ForumIntelligence\Domain\Engagement\Engagement::enabledReactions();
		foreach ( \ForumIntelligence\Domain\Engagement\Engagement::reactionLabels() as $id => $label ) {
			echo '<p><label for="fi-reaction-' . esc_attr( $id ) . '"><input type="checkbox" id="fi-reaction-' . esc_attr( $id ) . '" name="fi_settings[reactions][]" value="' . esc_attr( $id ) . '" ' . checked( in_array( $id, $enabledReactions, true ), true, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		echo '</fieldset>';
		echo '<h2>' . esc_html__( 'Optional modules', 'forum-intelligence-for-bbpress' ) . '</h2>';
		foreach ( array( 'engagement', 'moderation', 'knowledge', 'relationships', 'notifications', 'reputation', 'polls', 'composer', 'attachments', 'messaging', 'pwa', 'push', 'badges' ) as $module ) {
			$moduleLabels = array(
				'engagement'    => __( 'Votes and reactions', 'forum-intelligence-for-bbpress' ),
				'moderation'    => __( 'Reports and moderation', 'forum-intelligence-for-bbpress' ),
				'knowledge'     => __( 'Community knowledge', 'forum-intelligence-for-bbpress' ),
				'relationships' => __( 'Related discussions', 'forum-intelligence-for-bbpress' ),
				'notifications' => __( 'Notifications and follows', 'forum-intelligence-for-bbpress' ),
				'reputation'    => __( 'Reputation and expertise', 'forum-intelligence-for-bbpress' ),
				'badges'        => __( 'Achievements and reviewed badges', 'forum-intelligence-for-bbpress' ),
				'polls'         => __( 'Topic polls', 'forum-intelligence-for-bbpress' ),
				'composer'      => __( 'Composer fields and drafts', 'forum-intelligence-for-bbpress' ),
				'attachments'   => __( 'Attachments', 'forum-intelligence-for-bbpress' ),
				'messaging'     => __( 'Private messaging', 'forum-intelligence-for-bbpress' ),
				'pwa'           => __( 'Installable community app', 'forum-intelligence-for-bbpress' ),
				'push'          => __( 'Browser push notifications', 'forum-intelligence-for-bbpress' ),
			);
			$this->checkbox( 'modules][' . $module, $moduleLabels[ $module ], $this->services->enabled( $module ) ); }
		echo '<h2>' . esc_html__( 'AI and privacy', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Enabling AI permits public discussion excerpts and redacted questions to leave this site. Restricted content is excluded. Set FI_AI_API_KEY in server configuration; it is never stored or displayed here. Review provider terms and update your privacy policy before enabling.', 'forum-intelligence-for-bbpress' ) . '</p>';
		$this->checkbox( 'ai_enabled', __( 'Enable external AI processing of public content', 'forum-intelligence-for-bbpress' ), ( $data['ai_enabled'] ?? null ) === true );
		$this->checkbox( 'ai_answers', __( 'Allow generated Ask the Community answers when AI is enabled', 'forum-intelligence-for-bbpress' ), ( $data['ai_answers'] ?? true ) === true );
		$this->checkbox( 'ai_moderation', __( 'Allow human-requested moderation advice when AI is enabled', 'forum-intelligence-for-bbpress' ), ( $data['ai_moderation'] ?? true ) === true );
		$this->checkbox( 'reranking', __( 'Enable optional AI reranking of public search results', 'forum-intelligence-for-bbpress' ), ( $data['reranking'] ?? null ) === true );
		echo '<p>' . esc_html__( 'Reranking sends at most twelve public excerpts and the redacted search query. It is off by default. Separate feature models may be left blank to use the text model. Provider failure keeps community search available.', 'forum-intelligence-for-bbpress' ) . '</p>';
		$this->checkbox( 'index_pending', __( 'Prepare pending content in the local index; retrieval waits for publication', 'forum-intelligence-for-bbpress' ), ( $data['index_pending'] ?? null ) === true );
		$this->checkbox( 'semantic', __( 'Enable semantic search', 'forum-intelligence-for-bbpress' ), ( $data['semantic'] ?? null ) === true );
		echo '<h3>' . esc_html__( 'Automatic long-discussion summaries', 'forum-intelligence-for-bbpress' ) . '</h3><p>' . esc_html__( 'Automatic summaries require external AI opt-in and a current moderator. Work waits for a quiet discussion and respects the minimum interval. Human locks, disabled summaries and restricted sources are preserved. Articles are never automatically published.', 'forum-intelligence-for-bbpress' ) . '</p>';
		$this->checkbox( 'summary_auto_enabled', __( 'Enable automatic long-discussion summaries', 'forum-intelligence-for-bbpress' ), ! empty( $data['summary_auto_enabled'] ) );
		foreach ( array(
			'summary_auto_actor'       => array( __( 'Responsible moderator user ID', 'forum-intelligence-for-bbpress' ), 0, 0, 2147483647 ),
			'summary_min_replies'      => array( __( 'Minimum published replies for automatic summaries', 'forum-intelligence-for-bbpress' ), 20, 3, 5000 ),
			'summary_debounce_seconds' => array( __( 'Wait after discussion changes, in seconds', 'forum-intelligence-for-bbpress' ), 600, 60, 86400 ),
			'summary_min_interval'     => array( __( 'Minimum seconds between generated summaries', 'forum-intelligence-for-bbpress' ), 3600, 300, 604800 ),
		) as $key => $definition ) {
			echo '<label>' . esc_html( $definition[0] ) . '<input type="number" name="fi_settings[' . esc_attr( $key ) . ']" min="' . esc_attr( (string) $definition[2] ) . '" max="' . esc_attr( (string) $definition[3] ) . '" value="' . esc_attr( (string) ( $data[ $key ] ?? $definition[1] ) ) . '"></label>';
		}

		echo '<h3>' . esc_html__( 'Knowledge article target', 'forum-intelligence-for-bbpress' ) . '</h3><p>' . esc_html__( 'Choose the registered editorial integration for new drafts. Existing human articles retain their sources and review controls. Unavailable integrations stop conversion.', 'forum-intelligence-for-bbpress' ) . '</p><label for="fi-knowledge-target">' . esc_html__( 'Editorial target', 'forum-intelligence-for-bbpress' ) . '</label><select id="fi-knowledge-target" name="fi_settings[knowledge_target]">';
		$targets = ( new \ForumIntelligence\Domain\Knowledge\Targets() )->options();
		$chosen  = is_string( $data['knowledge_target'] ?? null ) ? $data['knowledge_target'] : 'fi_knowledge';
		if ( ! array_key_exists( $chosen, $targets ) ) {
			echo '<option value="' . esc_attr( $chosen ) . '" selected>' . esc_html__( 'Unavailable editorial target', 'forum-intelligence-for-bbpress' ) . '</option>';
		}
		foreach ( $targets as $id => $label ) {
			echo '<option value="' . esc_attr( $id ) . '" ' . selected( $chosen, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<h3>' . esc_html__( 'Automatic knowledge drafts', 'forum-intelligence-for-bbpress' ) . '</h3><p>' . esc_html__( 'Create a draft from a current summary of a public solved discussion that meets the configured criteria. Existing articles, including trashed articles, are preserved. A current moderator with article editing rights owns the draft. This local conversion does not generate content; AI summaries require current AI consent. Human publication is required.', 'forum-intelligence-for-bbpress' ) . '</p>';
		$this->checkbox( 'knowledge_auto_draft_enabled', __( 'Enable automatic knowledge article drafts', 'forum-intelligence-for-bbpress' ), ! empty( $data['knowledge_auto_draft_enabled'] ) );
		foreach ( array(
			'knowledge_auto_draft_actor'             => array( __( 'Responsible article moderator user ID', 'forum-intelligence-for-bbpress' ), 0, 0, 2147483647 ),
			'knowledge_auto_draft_min_replies'       => array( __( 'Minimum published replies for article drafts', 'forum-intelligence-for-bbpress' ), 3, 1, 5000 ),
			'knowledge_auto_draft_min_confirmations' => array( __( 'Minimum accepted-answer confirmations for article drafts', 'forum-intelligence-for-bbpress' ), 0, 0, 1000 ),
			'knowledge_auto_draft_debounce'          => array( __( 'Wait before article conversion, in seconds', 'forum-intelligence-for-bbpress' ), 300, 60, 86400 ),
		) as $key => $definition ) {
			echo '<label>' . esc_html( $definition[0] ) . '<input type="number" name="fi_settings[' . esc_attr( $key ) . ']" min="' . esc_attr( (string) $definition[2] ) . '" max="' . esc_attr( (string) $definition[3] ) . '" value="' . esc_attr( (string) ( $data[ $key ] ?? $definition[1] ) ) . '"></label>';
		}
		foreach ( array(
			'text_model'           => 'gpt-4o-mini',
			'summary_model'        => '',
			'moderation_model'     => '',
			'reranking_model'      => '',
			'ai_timeout_seconds'   => 15,
			'ai_completion_tokens' => 1200,
			'ai_retry_seconds'     => 60,
			'embedding_model'      => 'text-embedding-3-small',
			'ai_daily_requests'    => 200,
			'evidence_threshold'   => 0.7,
			'retention_days'       => 90,
			'vote_weight'          => 2,
			'downvote_weight'      => 2,
			'confirmation_weight'  => 3,
			'solution_weight'      => 10,
			'reputation_daily_cap' => 100,
			'duplicate_threshold'  => 0.25,
		) as $key => $default ) {
			$labels = array(
				'text_model'           => __( 'Text generation model', 'forum-intelligence-for-bbpress' ),
				'summary_model'        => __( 'Summary model (blank uses text model)', 'forum-intelligence-for-bbpress' ),
				'moderation_model'     => __( 'Moderation model (blank uses text model)', 'forum-intelligence-for-bbpress' ),
				'reranking_model'      => __( 'Reranking model (blank uses text model)', 'forum-intelligence-for-bbpress' ),
				'ai_timeout_seconds'   => __( 'AI request timeout, 1–30 seconds', 'forum-intelligence-for-bbpress' ),
				'ai_completion_tokens' => __( 'Maximum generated tokens, 128–4096', 'forum-intelligence-for-bbpress' ),
				'ai_retry_seconds'     => __( 'Retry pause after provider failure, 10–3600 seconds', 'forum-intelligence-for-bbpress' ),
				'embedding_model'      => __( 'Embedding model', 'forum-intelligence-for-bbpress' ),
				'ai_daily_requests'    => __( 'Maximum daily AI requests', 'forum-intelligence-for-bbpress' ),
				'evidence_threshold'   => __( 'Minimum evidence coverage, 0.5–1', 'forum-intelligence-for-bbpress' ),
				'retention_days'       => __( 'Retention in days', 'forum-intelligence-for-bbpress' ),
				'vote_weight'          => __( 'Upvote reputation points', 'forum-intelligence-for-bbpress' ),
				'downvote_weight'      => __( 'Downvote reputation deduction', 'forum-intelligence-for-bbpress' ),
				'confirmation_weight'  => __( 'Solution confirmation points', 'forum-intelligence-for-bbpress' ),
				'solution_weight'      => __( 'Accepted solution points', 'forum-intelligence-for-bbpress' ),
				'reputation_daily_cap' => __( 'Maximum daily reputation gain', 'forum-intelligence-for-bbpress' ),
				'duplicate_threshold'  => __( 'Minimum duplicate relevance, 0.1–1', 'forum-intelligence-for-bbpress' ),
			);
			echo '<label>' . esc_html( $labels[ $key ] ) . '<input name="fi_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) ( $data[ $key ] ?? $default ) ) . '"></label>'; }
		echo '<label>' . esc_html__( 'AI logging', 'forum-intelligence-for-bbpress' ) . '<select name="fi_settings[ai_logging]">';
		foreach ( array(
			'off'    => __( 'Off', 'forum-intelligence-for-bbpress' ),
			'errors' => __( 'Aggregate failures only', 'forum-intelligence-for-bbpress' ),
			'usage'  => __( 'Aggregate usage and failures', 'forum-intelligence-for-bbpress' ),
		) as $mode => $label ) {
			echo '<option value="' . esc_attr( $mode ) . '" ' . selected( $data['ai_logging'] ?? 'usage', $mode, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><p>' . esc_html__( 'No logging mode stores questions, evidence, generated text or secrets. Request limits and security audits remain active.', 'forum-intelligence-for-bbpress' ) . '</p>';
		echo '<label>' . esc_html__( 'Excluded forum IDs, separated by commas', 'forum-intelligence-for-bbpress' ) . '<input name="fi_settings[excluded_forums]" value="' . esc_attr( implode( ',', $data['excluded_forums'] ?? array() ) ) . '"></label><h2>' . esc_html__( 'Data retention', 'forum-intelligence-for-bbpress' ) . '</h2>';
		$this->checkbox( 'delete_on_uninstall', __( 'Delete plugin data on uninstall; forum posts remain', 'forum-intelligence-for-bbpress' ), ! empty( $data['delete_on_uninstall'] ) );
		submit_button();
		echo '</form>';
	}
	private function checkbox( string $key, string $label, bool $enabled ): void {
		$name = 'fi_settings[' . $key . ']';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $enabled, true, false ) . '> ' . esc_html( $label ) . '</label>';
	}
	private function jobs(): void {
		$rows = $this->services->store->rows( "SELECT status,COUNT(*) AS total FROM {$this->services->store->table('jobs')} GROUP BY status" );
		echo '<section class="fi-panel"><h2>' . esc_html__( 'Background processing', 'forum-intelligence-for-bbpress' ) . '</h2><dl>';
		foreach ( $rows as $row ) {
			echo '<dt>' . esc_html( $row['status'] ) . '</dt><dd>' . esc_html( $row['total'] ) . '</dd>'; }
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
		echo '</dl>' . $this->frontend->form( 'reindex', array(), __( 'Queue index rebuild', 'forum-intelligence-for-bbpress' ) ) . '</section>';
		$failed = $this->services->store->rows( "SELECT id,kind,error_code FROM {$this->services->store->table('jobs')} WHERE status IN ('dead','retrying') ORDER BY id DESC LIMIT 30" );
		foreach ( $failed as $job ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
			echo '<p>' . esc_html( $job['kind'] ) . ' — ' . esc_html( $job['error_code'] ) . '</p>' . $this->frontend->form( 'retry_job', array( 'id' => $job['id'] ), __( 'Retry job', 'forum-intelligence-for-bbpress' ) ); }
	}
	private function audit(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
		$page   = max( 1, min( 1000, (int) ( $_GET['fi_page'] ?? 1 ) ) );
		$labels = array(
			'settings_changed'        => __( 'Settings changed', 'forum-intelligence-for-bbpress' ),
			'configuration_exported'  => __( 'Configuration exported', 'forum-intelligence-for-bbpress' ),
			'configuration_imported'  => __( 'Reviewed configuration imported', 'forum-intelligence-for-bbpress' ),
			'topic_templates_changed' => __( 'Topic templates changed', 'forum-intelligence-for-bbpress' ),
			'topic_fields_changed'    => __( 'Topic field definitions changed', 'forum-intelligence-for-bbpress' ),
		);
		$rows   = $this->services->store->rows( "SELECT kind,object_id,actor_id,created_at FROM {$this->services->store->table('events')} ORDER BY id DESC LIMIT 50 OFFSET %d", array( ( $page - 1 ) * 50 ) );
		echo '<section class="fi-panel"><h2>' . esc_html__( 'Audit events', 'forum-intelligence-for-bbpress' ) . '</h2><ul>';
		foreach ( $rows as $row ) {
			echo '<li>' . esc_html( $row['created_at'] . ' ' . ( $labels[ $row['kind'] ] ?? $row['kind'] ) . ' #' . $row['object_id'] . ' (' . $row['actor_id'] . ')' ) . '</li>'; }
		echo '</ul><a href="' . esc_url( add_query_arg( 'fi_page', $page + 1 ) ) . '">' . esc_html__( 'Older events', 'forum-intelligence-for-bbpress' ) . '</a></section>';
	}
}
