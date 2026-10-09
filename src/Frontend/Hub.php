<?php
declare(strict_types=1);
namespace ForumIntelligence\Frontend;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Domain\Failure;
final class Hub {
	public function __construct( private Services $services, private Frontend $frontend ) {}
	public function register(): void {
		add_shortcode( 'forum_intelligence_search', fn(): string=>$this->search() );
		add_shortcode( 'forum_intelligence_ask', fn(): string=>$this->ask() );
		add_shortcode( 'forum_intelligence_hub', fn(): string=>$this->hub() );
		( new NotificationCenter( $this->services, $this->frontend ) )->register();
		add_shortcode( 'forum_intelligence_discovery', fn(): string=>$this->discovery() );
		add_shortcode( 'forum_intelligence_experts', fn(): string=>$this->experts() );
		add_action(
			'bbp_theme_after_topic_form_title',
			function (): void {
				echo '<div class="fi-panel" data-fi-duplicates><p role="status" aria-live="polite">' . esc_html__( 'Existing discussions will appear here as you compose your question.', 'forum-intelligence-for-bbpress' ) . '</p><ul></ul></div>';
			}
		);
		add_action( 'bbp_template_before_single_topic', array( $this, 'topic' ), 30 );
		add_action( 'bbp_theme_after_reply_content', array( $this, 'engagement' ), 30 );
		add_action(
			'template_redirect',
			function (): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
				if ( is_user_logged_in() && ( isset( $_GET['fi_result'] ) || ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'forum_intelligence_hub' ) ) ) ) {
					nocache_headers(); }
			}
		);
		add_filter(
			'bbp_get_form_topic_title',
			function ( string $title ): string {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
				return $title === '' && isset( $_GET['fi_question'] ) ? sanitize_text_field( wp_unslash( $_GET['fi_question'] ) ) : $title;
			}
		);
	}
	private function panel( string $title, string $content ): string {
		return '<section class="fi-panel"><h2>' . esc_html( $title ) . '</h2>' . $content . '</section>';
	}
	public function results( array $items ): string {
		if ( ! $items ) {
			return '<p>' . esc_html__( 'No matching discussions found. Try fewer words or a different term.', 'forum-intelligence-for-bbpress' ) . '</p>'; }
		$html = '<ul>';
		foreach ( $items as $item ) {
			$html .= '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['title'] ) . '</a>';
			if ( ! empty( $item['solved'] ) ) {
				$html .= ' <strong>' . esc_html__( 'Solved', 'forum-intelligence-for-bbpress' ) . '</strong>'; }
			if ( ! empty( $item['snippet'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only highlighting of a query; source results are separately authorized.
				$html .= '<p>' . \ForumIntelligence\Domain\Search\Highlight::text( $item['snippet'], is_string( $_GET['fi_q'] ?? null ) ? wp_unslash( $_GET['fi_q'] ) : '' ) . '</p>'; }
			if ( ! empty( $item['solution_preview'] ) ) {
				$html .= '<p><a href="' . esc_url( $item['solution_url'] ) . '">' . esc_html__( 'Accepted solution', 'forum-intelligence-for-bbpress' ) . '</a>: ' . esc_html( $item['solution_preview'] ) . '</p>'; }
			if ( current_user_can( 'manage_options' ) && is_array( $item['ranking'] ?? null ) && isset( $item['ranking']['contributions'] ) && is_array( $item['ranking']['contributions'] ) ) {
				$trace  = $item['ranking'];
				$html  .= '<details class="fi-ranking"><summary>' . esc_html__( 'Ranking explanation', 'forum-intelligence-for-bbpress' ) . '</summary><dl>';
				$labels = array(
					'lexical'  => __( 'Lexical contribution', 'forum-intelligence-for-bbpress' ),
					'semantic' => __( 'Semantic contribution', 'forum-intelligence-for-bbpress' ),
					'solution' => __( 'Solved-topic contribution', 'forum-intelligence-for-bbpress' ),
					'votes'    => __( 'Vote contribution', 'forum-intelligence-for-bbpress' ),
				);
				foreach ( $labels as $key => $label ) {
					$value = $trace['contributions'][ $key ] ?? null;
					if ( is_numeric( $value ) && is_finite( (float) $value ) ) {
						$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( number_format_i18n( (float) $value, 4 ) ) . '</dd>';
					}
				}
				$methods  = array(
					'fulltext' => __( 'Full-text index', 'forum-intelligence-for-bbpress' ),
					'native'   => __( 'Native WordPress search', 'forum-intelligence-for-bbpress' ),
					'semantic' => __( 'Semantic candidate with current lexical coverage', 'forum-intelligence-for-bbpress' ),
				);
				$methodId = is_string( $trace['retrieval'] ?? null ) ? $trace['retrieval'] : '';
				$method   = $methods[ $methodId ] ?? '';
				if ( $method ) {
					$html .= '<dt>' . esc_html__( 'Retrieval path', 'forum-intelligence-for-bbpress' ) . '</dt><dd>' . esc_html( $method ) . '</dd>';
				}
				$html .= '</dl><p>' . esc_html__( 'The score combines lexical coverage (65%), semantic similarity (30%), solved-topic status (up to 2.5%) and positive net votes (up to 2.5%). It is a relevance score, not a probability of correctness.', 'forum-intelligence-for-bbpress' ) . '</p></details>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}
	public function search(): string {
		$this->frontend->assets();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
		$query = isset( $_GET['fi_q'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['fi_q'] ) ), 0, 500 ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
		$solved = isset( $_GET['fi_solved'] ) ? sanitize_text_field( wp_unslash( $_GET['fi_solved'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
		$sort    = isset( $_GET['fi_sort'] ) && $_GET['fi_sort'] === 'recent' ? 'recent' : 'relevance';
		$filters = array(
			'solved' => $solved,
			'sort'   => $sort,
		);
		foreach ( array( 'forum', 'author' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
			$filters[ $key ] = absint( $_GET[ 'fi_' . $key ] ?? 0 ); }
		foreach ( array( 'after', 'before', 'tag', 'type' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
			$raw             = $_GET[ 'fi_' . $key ] ?? '';
			$filters[ $key ] = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : ''; }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
		$filters['unanswered'] = ! empty( $_GET['fi_unanswered'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Administrator-only read-only ranking detail; search still authorizes each source.
		$filters['explain'] = current_user_can( 'manage_options' ) && ( $_GET['fi_explain'] ?? '' ) === '1';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded vote filter.
		$minimum = $_GET['fi_min_votes'] ?? '';
		if ( is_scalar( $minimum ) && preg_match( '/^-?[0-9]{1,6}$/D', (string) $minimum ) ) {
			$filters['min_votes'] = min( 100000, max( -1000, (int) $minimum ) );}
		$hidden   = is_page() ? '<input type="hidden" name="page_id" value="' . esc_attr( (string) get_queried_object_id() ) . '">' : '';
		$html     = '<form method="get">' . $hidden . '<label>' . esc_html__( 'Search the community', 'forum-intelligence-for-bbpress' ) . '<input type="search" name="fi_q" maxlength="500" value="' . esc_attr( $query ) . '" required></label><label>' . esc_html__( 'Solution status', 'forum-intelligence-for-bbpress' ) . '<select name="fi_solved"><option value="">' . esc_html__( 'All discussions', 'forum-intelligence-for-bbpress' ) . '</option><option value="1" ' . selected( $solved, '1', false ) . '>' . esc_html__( 'Solved', 'forum-intelligence-for-bbpress' ) . '</option><option value="0" ' . selected( $solved, '0', false ) . '>' . esc_html__( 'Unsolved', 'forum-intelligence-for-bbpress' ) . '</option></select></label><label>' . esc_html__( 'Sort', 'forum-intelligence-for-bbpress' ) . '<select name="fi_sort"><option value="relevance">' . esc_html__( 'Relevance', 'forum-intelligence-for-bbpress' ) . '</option><option value="recent" ' . selected( $sort, 'recent', false ) . '>' . esc_html__( 'Recent', 'forum-intelligence-for-bbpress' ) . '</option></select></label><button type="submit">' . esc_html__( 'Search', 'forum-intelligence-for-bbpress' ) . '</button></form>';
		$advanced = '<details><summary>' . esc_html__( 'Advanced search', 'forum-intelligence-for-bbpress' ) . '</summary>';
		foreach ( array(
			'forum'     => __( 'Forum ID', 'forum-intelligence-for-bbpress' ),
			'author'    => __( 'Author ID', 'forum-intelligence-for-bbpress' ),
			'tag'       => __( 'Topic tag slug', 'forum-intelligence-for-bbpress' ),
			'min_votes' => __( 'Minimum net votes', 'forum-intelligence-for-bbpress' ),
			'after'     => __( 'Published on or after (UTC)', 'forum-intelligence-for-bbpress' ),
			'before'    => __( 'Published on or before (UTC)', 'forum-intelligence-for-bbpress' ),
		) as $key => $label ) {
			$advanced .= '<label>' . esc_html( $label ) . '<input type="' . esc_attr( in_array( $key, array( 'after', 'before' ), true ) ? 'date' : ( in_array( $key, array( 'forum', 'author', 'min_votes' ), true ) ? 'number' : 'text' ) ) . '" name="fi_' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $filters[ $key ] ?? '' ) ) . '"></label>'; }
		$advanced .= '<label>' . esc_html__( 'Content type', 'forum-intelligence-for-bbpress' ) . '<select name="fi_type">';
		foreach ( array(
			''      => __( 'Topics and replies', 'forum-intelligence-for-bbpress' ),
			'topic' => __( 'Topics', 'forum-intelligence-for-bbpress' ),
			'reply' => __( 'Replies', 'forum-intelligence-for-bbpress' ),
			'forum' => __( 'Forums', 'forum-intelligence-for-bbpress' ),
		) as $value => $label ) {
			$advanced .= '<option value="' . esc_attr( $value ) . '" ' . selected( $filters['type'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		$advanced .= '</select></label><label><input type="checkbox" name="fi_unanswered" value="1" ' . checked( $filters['unanswered'], true, false ) . '> ' . esc_html__( 'Unanswered topics only', 'forum-intelligence-for-bbpress' ) . '</label></details>';
		if ( current_user_can( 'manage_options' ) ) {
			$advanced = str_replace( '</details>', '<label><input type="checkbox" name="fi_explain" value="1" ' . checked( $filters['explain'], true, false ) . '> ' . esc_html__( 'Show ranking explanations', 'forum-intelligence-for-bbpress' ) . '</label></details>', $advanced );
		}
		$html = str_replace( '<button type="submit">', $advanced . '<button type="submit">', $html );
		if ( $query ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
			$page = max( 1, (int) ( $_GET['fi_page'] ?? 1 ) );
			try {
				$result = $this->services->get( 'search' )->search( $query, $filters + array( 'page' => $page ), get_current_user_id() );
			} catch ( Failure $failure ) {
				$html .= '<p role="alert">' . esc_html( $failure->getMessage() ) . '</p>';
				return $this->panel( __( 'Community search', 'forum-intelligence-for-bbpress' ), $html );
			}
			if ( ! empty( $result['suggested_query'] ) ) {
				$html .= '<p>' . esc_html__( 'Try this spelling:', 'forum-intelligence-for-bbpress' ) . ' <a href="' . esc_url(
					add_query_arg(
						array(
							'fi_q'    => $result['suggested_query'],
							'fi_page' => 1,
						)
					)
				) . '">' . esc_html( $result['suggested_query'] ) . '</a></p>';
			}
			$html .= $this->results( $result['items'] );
			if ( $page > 1 ) {
				$html .= '<a href="' . esc_url( add_query_arg( 'fi_page', $page - 1 ) ) . '">' . esc_html__( 'Previous results', 'forum-intelligence-for-bbpress' ) . '</a> '; }
			if ( $result['has_more'] ) {
				$html .= '<a href="' . esc_url( add_query_arg( 'fi_page', $page + 1 ) ) . '">' . esc_html__( 'Next results', 'forum-intelligence-for-bbpress' ) . '</a>'; }
		}
		return $this->panel( __( 'Community search', 'forum-intelligence-for-bbpress' ), $html );
	}
	public function ask(): string {
		$html = $this->flash();
		if ( ! is_user_logged_in() ) {
			return $this->panel( __( 'Ask the Community', 'forum-intelligence-for-bbpress' ), '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Sign in to ask', 'forum-intelligence-for-bbpress' ) . '</a>' ); }
		$html .= '<p>' . esc_html__( 'Answers use community evidence and may be incomplete. Check the linked sources.', 'forum-intelligence-for-bbpress' ) . '</p>';
		$html .= $this->frontend->form( 'ask', array(), __( 'Ask', 'forum-intelligence-for-bbpress' ), '<label>' . esc_html__( 'Your question', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[question]" minlength="12" maxlength="500" rows="4" required></textarea></label>' );
		return $this->panel( __( 'Ask the Community', 'forum-intelligence-for-bbpress' ), $html );
	}
	private function flash(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
		$token = isset( $_GET['fi_result'] ) ? sanitize_key( wp_unslash( $_GET['fi_result'] ) ) : '';
		if ( ! $token || ! is_user_logged_in() ) {
			return ''; }
		$result = get_transient( 'fi_result_' . get_current_user_id() . '_' . $token );
		if ( ! is_array( $result ) ) {
			return ''; }
		if ( ( ( $result['state'] ?? '' ) === 'answered' || ! empty( $result['generated'] ) ) && ! $this->services->get( 'answers' )->canPresent( $result, get_current_user_id() ) ) {
			return '<div role="status"><p>' . esc_html__( 'This saved answer is no longer supported by current community evidence. Ask again for an updated result.', 'forum-intelligence-for-bbpress' ) . '</p></div>'; }
		foreach ( $result['citations'] ?? array() as $citation ) {
			$post = get_post( (int) $citation['id'] );
			if ( ! $post || ! $this->services->access->canRead( $post->ID, get_current_user_id() ) || ( isset( $citation['hash'] ) && \ForumIntelligence\Infrastructure\Search\Indexer::hash( $post->ID ) !== $citation['hash'] ) ) {
				return ''; }
		}
		if ( isset( $result['topics'] ) ) {
			$query = array();
			wp_parse_str( (string) wp_parse_url( (string) ( $result['composer_url'] ?? '' ), PHP_URL_QUERY ), $query );
			$question         = is_string( $query['fi_question'] ?? null ) ? mb_substr( sanitize_textarea_field( $query['fi_question'] ), 0, 500 ) : '';
			$result['topics'] = ( new \ForumIntelligence\Infrastructure\Search\Presentation( $this->services->store, $this->services->access ) )->refresh( (array) $result['topics'], $question, get_current_user_id() );
		}
		$html = '<div role="status"><p>' . esc_html( $result['answer'] ?? __( 'Your change was saved.', 'forum-intelligence-for-bbpress' ) ) . '</p></div>';
		foreach ( $result['citations'] ?? array() as $citation ) {
			if ( $this->services->access->canRead( (int) $citation['id'], get_current_user_id() ) ) {
				$html .= '<p><a href="' . esc_url( $citation['url'] ) . '">' . esc_html( $citation['title'] ) . '</a></p>'; }
		}
		if ( ( $result['state'] ?? '' ) === 'answered' && preg_match( '/^[a-f0-9]{32}$/D', (string) ( $result['feedback_token'] ?? '' ) ) ) {
			$fields = '<label>' . esc_html__( 'How useful was this generated answer?', 'forum-intelligence-for-bbpress' ) . '<br><select name="data[value]" required><option value="">' . esc_html__( 'Choose feedback', 'forum-intelligence-for-bbpress' ) . '</option>';
			foreach ( \ForumIntelligence\Domain\AI\Feedback::labels() as $value => $label ) {
				$fields .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			}
			$fields .= '</select></label>';
			$html   .= '<section class="fi-panel" data-fi-answer-feedback><h3>' . esc_html__( 'Feedback on this answer', 'forum-intelligence-for-bbpress' ) . '</h3><p>' . esc_html__( 'Your selection helps moderators improve community documentation. Feedback does not train external models.', 'forum-intelligence-for-bbpress' ) . '</p>' . $this->frontend->form(
				'ai_feedback',
				array(
					'object' => $this->services->access->topic( $result['grounding']['source'] ),
					'token'  => $result['feedback_token'],
				),
				__( 'Send answer feedback', 'forum-intelligence-for-bbpress' ),
				$fields
			) . '</section>';
		}
		if ( ( $result['state'] ?? '' ) === 'abstained' ) {
			$html .= $this->results( $result['topics'] ?? array() );
			$html .= '<p><a href="' . esc_url( $result['composer_url'] ) . '">' . esc_html__( 'Create a new topic', 'forum-intelligence-for-bbpress' ) . '</a></p>';
		}
		return $html;
	}
	public function hub(): string {
		if ( ! is_user_logged_in() ) {
			return $this->panel( __( 'Community hub', 'forum-intelligence-for-bbpress' ), '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Sign in', 'forum-intelligence-for-bbpress' ) . '</a>' ); }
		$user = get_current_user_id();
		if ( ! $this->services->access->activeMember( $user ) ) {
			return $this->panel( __( 'Community hub', 'forum-intelligence-for-bbpress' ), '<p>' . esc_html__( 'Community access is unavailable for this account.', 'forum-intelligence-for-bbpress' ) . '</p>' );}

		$html          = $this->flash();
		$notifications = $this->services->get( 'notifications' );
		if ( $notifications ) {
			$prefs  = $notifications->preferences( $user );
			$fields = '<label>' . esc_html__( 'Email delivery', 'forum-intelligence-for-bbpress' ) . '<select name="data[email_mode]">';
			foreach ( array(
				'off'       => __( 'Off', 'forum-intelligence-for-bbpress' ),
				'immediate' => __( 'Immediate', 'forum-intelligence-for-bbpress' ),
				'hourly'    => __( 'Hourly digest', 'forum-intelligence-for-bbpress' ),
				'daily'     => __( 'Daily digest', 'forum-intelligence-for-bbpress' ),
				'weekly'    => __( 'Weekly digest', 'forum-intelligence-for-bbpress' ),
				'important' => __( 'Important activity only', 'forum-intelligence-for-bbpress' ),
			) as $value => $label ) {
				$fields .= '<option value="' . esc_attr( $value ) . '" ' . selected( $prefs['email_mode'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
			$fields .= '</select></label><fieldset><legend>' . esc_html__( 'Mute notification categories', 'forum-intelligence-for-bbpress' ) . '</legend>';
			foreach ( \ForumIntelligence\Domain\Notifications\Notifications::categories() as $category => $label ) {
				$fields .= '<label><input type="checkbox" name="data[muted_categories][]" value="' . esc_attr( $category ) . '" ' . checked( in_array( $category, $prefs['muted_categories'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
			$fields .= '</fieldset>';
			$html   .= $this->panel( __( 'Notification preferences', 'forum-intelligence-for-bbpress' ), $this->frontend->form( 'preferences', array(), __( 'Save preferences', 'forum-intelligence-for-bbpress' ), $fields ) );
			$html   .= ( new NotificationCenter( $this->services, $this->frontend ) )->render();
			$html   .= $this->panel( __( 'Followed discussions', 'forum-intelligence-for-bbpress' ), $this->results( $this->services->get( 'following' )->feed( $user )['items'] ) );
		}
		$saved = $this->services->get( 'saved_searches' );
		if ( $saved && user_can( $user, 'publish_replies' ) ) {
			$content  = '<p>' . esc_html__( 'Save up to five local keyword searches. Saved queries belong to your account and are never included in aggregate analytics or sent externally. Matching alerts are off until you enable them for each search; they cover new public topics and replies.', 'forum-intelligence-for-bbpress' ) . '</p>';
			$content .= $this->frontend->form( 'saved_search', array(), __( 'Save search', 'forum-intelligence-for-bbpress' ), '<label>' . esc_html__( 'Keywords', 'forum-intelligence-for-bbpress' ) . '<input name="data[query]" maxlength="100" required></label>' );
			foreach ( $saved->listing( $user ) as $entry ) {
				$content .= '<p>' . esc_html( $entry['query'] ) . '</p>' . $this->frontend->form(
					'saved_search_alerts',
					array(
						'id'      => $entry['id'],
						'enabled' => $entry['alerts'] ? 0 : 1,
					),
					$entry['alerts'] ? __( 'Disable matching alerts', 'forum-intelligence-for-bbpress' ) : __( 'Enable matching alerts', 'forum-intelligence-for-bbpress' )
				) . $this->frontend->form( 'saved_search_remove', array( 'id' => $entry['id'] ), __( 'Remove saved search', 'forum-intelligence-for-bbpress' ) );}
			try {
				$content .= $this->results( $saved->feed( $user )['items'] );
			} catch ( Failure $failure ) {
				$content .= '<p role="status">' . esc_html( $failure->status === 429 ? __( 'Saved-search results are temporarily paused. Your preferences are saved; try viewing results again shortly.', 'forum-intelligence-for-bbpress' ) : __( 'Saved-search results are unavailable. Try again shortly.', 'forum-intelligence-for-bbpress' ) ) . '</p>';
			}
			$html .= $this->panel( __( 'Saved searches', 'forum-intelligence-for-bbpress' ), $content );
		}
		$reputation = $this->services->get( 'reputation' );
		if ( $reputation ) {
			$profile = $reputation->profile( $user, $user );
			// translators: %s is the formatted reputation score.
			$content = '<p>' . sprintf( esc_html__( 'Your visible reputation across up to 1,000 source posts: %s', 'forum-intelligence-for-bbpress' ), esc_html( number_format_i18n( $profile['score'] ) ) ) . '</p><ul>';
			foreach ( $profile['expertise'] as $tag => $score ) {
				$content .= '<li>' . esc_html( $tag ) . ': ' . esc_html( number_format_i18n( $score ) ) . '</li>'; }
			$content .= '</ul><details><summary>' . esc_html__( 'Score explanation', 'forum-intelligence-for-bbpress' ) . '</summary><ul>';
			foreach ( array_slice( $profile['ledger'], 0, 30 ) as $event ) {
				$content .= '<li>' . esc_html( $event['kind'] ) . ': ' . esc_html( (string) $event['points'] ) . '</li>'; }
			$html .= $this->panel( __( 'Contribution profile', 'forum-intelligence-for-bbpress' ), $content . '</ul></details>' );
		}

		$badges = $this->services->get( 'badges' );
		if ( $badges ) {
			$optedOut = (bool) get_user_meta( $user, \ForumIntelligence\Domain\Reputation\Badges::preferenceKey(), true );
			$content  = $this->frontend->form( 'badge_preferences', array( 'enabled' => $optedOut ? 1 : 0 ), $optedOut ? __( 'Enable achievements', 'forum-intelligence-for-bbpress' ) : __( 'Disable and erase achievements', 'forum-intelligence-for-bbpress' ) );
			$content .= '<ul>';
			foreach ( $badges->listing( $user, $user, true )['items'] as $badge ) {
				$content .= '<li><strong>' . esc_html( $badge['label'] ) . '</strong> — ' . esc_html( $badge['active'] ? __( 'Active', 'forum-intelligence-for-bbpress' ) : __( 'Inactive', 'forum-intelligence-for-bbpress' ) ) . '<p>' . esc_html( $badge['rule'] ) . '</p><details><summary>' . esc_html__( 'Award history', 'forum-intelligence-for-bbpress' ) . '</summary><ul>';
				foreach ( array_reverse( $badge['history'] ) as $entry ) {
					$content .= '<li>' . esc_html( $entry['active'] ? __( 'Awarded', 'forum-intelligence-for-bbpress' ) : __( 'Revoked', 'forum-intelligence-for-bbpress' ) ) . ' ' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $entry['date'] . ' UTC' ) ) ) . ' — ' . esc_html( $entry['reason'] ) . '</li>';
				}
				$content .= '</ul></details></li>';
			}
			$html .= $this->panel( __( 'Your achievements', 'forum-intelligence-for-bbpress' ), $content . '</ul>' );
		}
		$push = $this->services->get( 'push' );
		if ( $push && $push->available() ) {
			$html .= $this->panel( __( 'Browser notifications', 'forum-intelligence-for-bbpress' ), '<button type="button" data-fi-push>' . esc_html__( 'Enable for this browser', 'forum-intelligence-for-bbpress' ) . '</button><button type="button" data-fi-unsubscribe>' . esc_html__( 'Disable for this browser', 'forum-intelligence-for-bbpress' ) . '</button><p role="status" aria-live="polite"></p>' ); }
		return $html;
	}
	public function discovery(): string {
		$this->frontend->assets();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
		$mode = sanitize_key( wp_unslash( $_GET['fi_mode'] ?? 'trending' ) );
		if ( ! in_array( $mode, array( 'trending', 'help', 'solved', 'recent' ), true ) ) {
			$mode = 'trending'; }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameters; authorization is checked before restricted data is rendered.
		$data = $this->services->get( 'discovery' )->topics( get_current_user_id(), $mode, max( 1, (int) ( $_GET['fi_page'] ?? 1 ) ) );
		$html = '<nav aria-label="' . esc_attr__( 'Discovery views', 'forum-intelligence-for-bbpress' ) . '">';
		foreach ( array(
			'trending' => __( 'Trending', 'forum-intelligence-for-bbpress' ),
			'help'     => __( 'Questions you can help with', 'forum-intelligence-for-bbpress' ),
			'solved'   => __( 'Solved discussions', 'forum-intelligence-for-bbpress' ),
			'recent'   => __( 'Recent discussions', 'forum-intelligence-for-bbpress' ),
		) as $key => $label ) {
			$html .= '<a href="' . esc_url(
				add_query_arg(
					array(
						'fi_mode' => $key,
						'fi_page' => 1,
					)
				)
			) . '">' . esc_html( $label ) . '</a> '; }
		return $this->panel( __( 'Discover discussions', 'forum-intelligence-for-bbpress' ), $html . '</nav>' . $this->results( $data['items'] ) );
	}
	public function experts(): string {
		$this->frontend->assets();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only tag selection; all contribution evidence is independently authorized.
		$raw  = $_GET['fi_expertise_tag'] ?? '';
		$tag  = is_string( $raw ) ? mb_substr( sanitize_text_field( wp_unslash( $raw ) ), 0, 100 ) : '';
		$html = '<p>' . esc_html__( 'Discover members with helpful public contributions in a topic tag. Results require at least three distinct sources; reputation and badges grant no permissions.', 'forum-intelligence-for-bbpress' ) . '</p><form method="get">';
		if ( is_page() ) {
			$html .= '<input type="hidden" name="page_id" value="' . esc_attr( (string) get_queried_object_id() ) . '">';}
		$html .= '<label>' . esc_html__( 'Topic tag', 'forum-intelligence-for-bbpress' ) . '<input name="fi_expertise_tag" maxlength="100" value="' . esc_attr( $tag ) . '" required></label><button type="submit">' . esc_html__( 'Find contributors', 'forum-intelligence-for-bbpress' ) . '</button></form>';
		if ( $tag !== '' ) {
			$results = $this->services->get( 'discovery' )->experts( get_current_user_id(), $tag );
			$html   .= '<ul>';
			foreach ( $results['items'] as $item ) {
				// translators: 1: formatted expertise points, 2: number of distinct public sources.
				$evidence = sprintf( __( 'Expertise points: %1$s from %2$s public sources.', 'forum-intelligence-for-bbpress' ), number_format_i18n( $item['points'] ), number_format_i18n( $item['samples'] ) );
				$html    .= '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a><p>' . esc_html( $evidence ) . '</p></li>';
			}
			$html .= '</ul>';
			if ( ! $results['items'] ) {
				$html .= '<p>' . esc_html__( 'No contributors meet the public evidence threshold for this tag.', 'forum-intelligence-for-bbpress' ) . '</p>';}
		}
		return $this->panel( __( 'Community expertise', 'forum-intelligence-for-bbpress' ), $html );
	}
	public function engagement(): void {
		$objectId = (int) bbp_get_reply_id();
		$user     = get_current_user_id();
		$service  = $this->services->get( 'engagement' );
		if ( ! $service || ! $this->services->access->canRead( $objectId, $user ) ) {
			return; }
		$signals     = $service->counts( $objectId, $user );
		$content     = '';
		$enabled     = \ForumIntelligence\Domain\Engagement\Engagement::enabledReactions();
		$labels      = \ForumIntelligence\Domain\Engagement\Engagement::reactionLabels();
		$removals    = \ForumIntelligence\Domain\Engagement\Engagement::removalLabels();
		$countLabels = array(
			'vote'    => __( 'Helpful votes', 'forum-intelligence-for-bbpress' ),
			'confirm' => __( 'Solution confirmations', 'forum-intelligence-for-bbpress' ),
		);
		foreach ( $enabled as $id ) {
			$countLabels[ 'reaction_' . $id ] = $labels[ $id ];
		}
		foreach ( $signals['counts'] as $count ) {
			if ( isset( $countLabels[ $count['kind'] ] ) && (int) $count['participants'] > 0 ) {
				$content .= '<span>' . esc_html( $countLabels[ $count['kind'] ] ) . ': ' . esc_html( number_format_i18n( (int) $count['score'] ) ) . '</span> ';
			}
		}
		try {
			$this->services->access->participate( $objectId, $user );
			$mayAdd = (int) get_post_field( 'post_author', $objectId, 'raw' ) !== $user || ! empty( ( (array) get_option( 'fi_settings', array() ) )['self_voting'] );
			if ( $mayAdd || $signals['mine'] ) {
				$mine = array();
				foreach ( $signals['mine'] as $signal ) {
					$mine[ $signal['kind'] ] = (int) $signal['value']; }
				if ( $mayAdd || ( $mine['vote'] ?? 0 ) === 1 ) {
					$content .= $this->frontend->form(
						'signal',
						array(
							'object' => $objectId,
							'kind'   => 'vote',
							'value'  => ( $mine['vote'] ?? 0 ) === 1 ? 0 : 1,
						),
						__( 'Helpful', 'forum-intelligence-for-bbpress' )
					);
				}
				if ( ( $mayAdd && ! empty( ( (array) get_option( 'fi_settings', array() ) )['downvotes'] ) ) || ( $mine['vote'] ?? 0 ) === -1 ) {
					$content .= $this->frontend->form(
						'signal',
						array(
							'object' => $objectId,
							'kind'   => 'vote',
							'value'  => ( $mine['vote'] ?? 0 ) === -1 ? 0 : -1,
						),
						__( 'Not helpful', 'forum-intelligence-for-bbpress' )
					); }
				$state = $this->services->solutions->state( $this->services->access->topic( $objectId ), $user );
				if ( in_array( $objectId, $state['answers'], true ) && ( $mayAdd || ( $mine['confirm'] ?? 0 ) === 1 ) ) {
					$content .= $this->frontend->form(
						'signal',
						array(
							'object' => $objectId,
							'kind'   => 'confirm',
							'value'  => ( $mine['confirm'] ?? 0 ) ? 0 : 1,
						),
						__( 'This solved my problem', 'forum-intelligence-for-bbpress' )
					); }
				foreach ( $labels as $reaction => $label ) {
					$kind   = 'reaction_' . $reaction;
					$active = ( $mine[ $kind ] ?? 0 ) === 1;
					if ( ! $active && ( ! $mayAdd || ! in_array( $reaction, $enabled, true ) ) ) {
						continue;
					}
					$content .= $this->frontend->form(
						'signal',
						array(
							'object' => $objectId,
							'kind'   => $kind,
							'value'  => $active ? 0 : 1,
						),
						$active ? $removals[ $reaction ] : $label
					);
				}
			}
		} catch ( Failure $failure ) {
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
		echo '<div class="fi-controls">' . $content . '</div>';
	}
	public function topic(): void {
		$topic = (int) bbp_get_topic_id();
		$user  = get_current_user_id();
		if ( ! $this->services->access->canRead( $topic, $user ) ) {
			return; }
		$knowledge = $this->services->get( 'knowledge' );
		if ( $knowledge ) {
			$summary = $knowledge->summary( $topic, $user );
			if ( $summary ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				echo $this->panel( __( 'Discussion summary', 'forum-intelligence-for-bbpress' ), '<p>' . esc_html( $summary['answer'] ?? '' ) . '</p><p>' . esc_html( ! empty( $summary['stale'] ) ? __( 'Sources have changed; review this summary.', 'forum-intelligence-for-bbpress' ) : __( 'Community summary; verify against the discussion.', 'forum-intelligence-for-bbpress' ) ) . '</p>' ); }
		}
		$relations = $this->services->get( 'relationships' );
		if ( $relations ) {
			$items = $relations->listing( $topic, $user );
			if ( $items ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				echo $this->panel( __( 'Related knowledge', 'forum-intelligence-for-bbpress' ), $this->results( $items ) ); }
		}
		if ( ! $user ) {
			return; }
		$following = $this->services->get( 'following' );
		if ( $following ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
			echo $this->panel(
				__( 'Discussion preferences', 'forum-intelligence-for-bbpress' ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				$this->frontend->form(
					'follow',
					array(
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
						'target'  => $topic,
						'type'    => 'topic',
						'mode'    => 'follow',
						'enabled' => 1,
					),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
					__( 'Follow topic', 'forum-intelligence-for-bbpress' )
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				) . $this->frontend->form(
					'follow',
					array(
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
						'target'  => $topic,
						'type'    => 'topic',
						'mode'    => 'follow',
						'enabled' => 0,
					),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
					__( 'Unfollow', 'forum-intelligence-for-bbpress' )
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				) . $this->frontend->form(
					'follow',
					array(
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
						'target'  => $topic,
						'type'    => 'topic',
						'mode'    => 'mute',
						'enabled' => 1,
					),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
					__( 'Mute topic', 'forum-intelligence-for-bbpress' )
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				) . $this->frontend->form(
					'follow',
					array(
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
						'target'  => $topic,
						'type'    => 'topic',
						'mode'    => 'mute',
						'enabled' => 0,
					),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
					__( 'Unmute', 'forum-intelligence-for-bbpress' )
				)
			);
		}
		if ( $this->services->get( 'cases' ) ) {
			$fields = '<label>' . esc_html__( 'Report reason', 'forum-intelligence-for-bbpress' ) . '<select name="data[reason]">';
			foreach ( \ForumIntelligence\Domain\Moderation\Cases::REASONS as $reason ) {
				$fields .= '<option value="' . esc_attr( $reason ) . '">' . esc_html( $reason ) . '</option>'; }
			$fields .= '</select></label><label>' . esc_html__( 'Notes for moderators', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[notes]" maxlength="2000"></textarea></label>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
			echo $this->panel( __( 'Report discussion', 'forum-intelligence-for-bbpress' ), $this->frontend->form( 'report', array( 'object' => $topic ), __( 'Send report', 'forum-intelligence-for-bbpress' ), $fields ) );
		}
		$polls = $this->services->get( 'polls' );
		if ( $polls ) {
			$poll = $polls->view( $topic, $user );
			if ( $poll ) {
				$fields = '<fieldset><legend>' . esc_html__( 'Poll choices', 'forum-intelligence-for-bbpress' ) . '</legend>';
				foreach ( $poll['choices'] as $id => $choice ) {
					$fields .= '<label><input type="checkbox" name="data[choices][]" value="' . esc_attr( (string) $id ) . '" ' . checked( in_array( $id, $poll['my_choices'], true ), true, false ) . '> ' . esc_html( $choice ) . ( isset( $poll['counts'] ) ? ' (' . esc_html( (string) $poll['counts'][ $id ] ) . ')' : '' ) . '</label>'; }
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
				echo $this->panel( __( 'Topic poll', 'forum-intelligence-for-bbpress' ), $this->frontend->form( 'poll_vote', array( 'topic' => $topic ), __( 'Submit vote', 'forum-intelligence-for-bbpress' ), $fields . '</fieldset>' ) );
			}
		}
	}
}
