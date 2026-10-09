<?php
declare(strict_types=1);
namespace ForumIntelligence\Admin;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Frontend\Frontend;
use ForumIntelligence\Domain\Failure;
final class Tools {
	public function __construct( private Services $services, private Frontend $frontend ) {}
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		( new Onboarding( $this->services, $this->frontend ) )->render();
		$fields = $this->services->get( 'fields' );
		if ( $fields ) {
			echo '<section class="fi-panel"><h2>' . esc_html__( 'Structured topic fields', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Each field uses a stable key, label, type, visibility, required flag, forums and options. Public fields may separately opt into search and AI. Member and moderator fields stay out of both.', 'forum-intelligence-for-bbpress' ) . '</p>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
			echo $this->frontend->form( 'field_config', array(), __( 'Save field definitions', 'forum-intelligence-for-bbpress' ), '<label>' . esc_html__( 'Field definitions, JSON', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[json]" rows="12" maxlength="14000" required>' . esc_textarea( wp_json_encode( get_option( 'fi_field_definitions', array() ), JSON_PRETTY_PRINT ) ) . '</textarea></label>' ) . '</section>';
			echo '<section class="fi-panel"><h2>' . esc_html__( 'Question templates', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Map forum IDs to plain-text templates. Existing discussions are unchanged.', 'forum-intelligence-for-bbpress' ) . '</p>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
			echo $this->frontend->form( 'templates', array(), __( 'Save templates', 'forum-intelligence-for-bbpress' ), '<label>' . esc_html__( 'Templates, JSON object', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[json]" rows="8" maxlength="14000" required>' . esc_textarea( wp_json_encode( (object) get_option( 'fi_templates', array() ), JSON_PRETTY_PRINT ) ) . '</textarea></label>' ) . '</section>';
			$example = array(
				array(
					'id'         => 'support-example',
					'label'      => __( 'Product support', 'forum-intelligence-for-bbpress' ),
					'forum'      => 0,
					'topic_type' => __( 'Support', 'forum-intelligence-for-bbpress' ),
					'product'    => __( 'Example product', 'forum-intelligence-for-bbpress' ),
					'category'   => __( 'Troubleshooting', 'forum-intelligence-for-bbpress' ),
					'text'       => "Problem:\nExpected behavior:\nActual behavior:\nSteps to reproduce:\nRedacted relevant logs:",
				),
			);
			echo '<section class="fi-panel"><h2>' . esc_html__( 'Contextual question templates', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Add up to 20 optional templates by question type, product and category. Each entry needs a unique id, label, integer forum and plain-text guidance. Forum 0 makes it available in every readable forum; a specific forum limits its audience. Criteria are optional and do not classify or change a topic.', 'forum-intelligence-for-bbpress' ) . '</p><p>' . esc_html__( 'Matching uses the most specified criteria, then a forum-specific rule, then the identifier in alphabetical order. Unmatched selections use the existing forum guidance. Labels allow 100 characters, each criterion 80, and guidance 5,000.', 'forum-intelligence-for-bbpress' ) . '</p><details><summary>' . esc_html__( 'Configuration example', 'forum-intelligence-for-bbpress' ) . '</summary><pre>' . esc_html( wp_json_encode( $example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form helper and textarea escaping protect all dynamic values.
			echo $this->frontend->form( 'template_variants', array(), __( 'Save contextual templates', 'forum-intelligence-for-bbpress' ), '<label>' . esc_html__( 'Contextual templates, JSON list', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[json]" rows="12" maxlength="1048576" required>' . esc_textarea( wp_json_encode( (array) get_option( 'fi_template_variants', array() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</textarea></label>' ) . '</section>';
		}
		echo '<section class="fi-panel"><h2>' . esc_html__( 'Configuration transfer', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'This export contains settings, field definitions and templates. API keys, browser subscriptions, messages, files and personal preferences are excluded.', 'forum-intelligence-for-bbpress' ) . '</p><label>' . esc_html__( 'Copy configuration JSON', 'forum-intelligence-for-bbpress' ) . '<textarea readonly rows="10">' . esc_textarea(
			wp_json_encode(
				$this->services->operations->execute( 'config_export', array(), get_current_user_id() ),
				JSON_PRETTY_PRINT
			)
		) . '</textarea></label>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
		echo $this->frontend->form( 'config_import', array(), __( 'Import reviewed configuration', 'forum-intelligence-for-bbpress' ), '<label>' . esc_html__( 'Configuration JSON', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[json]" rows="10" maxlength="1048576" required></textarea></label><label><input type="checkbox" name="data[reviewed]" value="1" required> ' . esc_html__( 'I reviewed the module and AI privacy settings in this configuration.', 'forum-intelligence-for-bbpress' ) . '</label>' ) . '</section>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
		echo '<section class="fi-panel"><h2>' . esc_html__( 'Retention', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Use WordPress Tools to export or erase a member’s personal data. Retention processes operational data in batches and preserves editorial content and reputation ledgers.', 'forum-intelligence-for-bbpress' ) . '</p>' . $this->frontend->form( 'retain', array(), __( 'Run one retention batch', 'forum-intelligence-for-bbpress' ) ) . '</section>';
	}
	public function analytics(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$store = $this->services->store;
		$rows  = $store->rows( "SELECT kind,SUM(count) AS total FROM {$store->table('metrics')} WHERE day>=%s GROUP BY kind", array( gmdate( 'Y-m-d', time() - 30 * 86400 ) ) );
		echo '<section class="fi-panel"><h2>' . esc_html__( 'Community outcomes, last 30 days', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Counts are aggregate. No raw search questions or member-level search histories are collected. API token totals are approximate provider-reported usage; they do not represent a billing invoice.', 'forum-intelligence-for-bbpress' ) . '</p><dl>';
		foreach ( $rows as $row ) {
			echo '<dt>' . esc_html( $row['kind'] ) . '</dt><dd>' . esc_html( number_format_i18n( (int) $row['total'] ) ) . '</dd>'; }
		echo '</dl></section>';
		echo '<section class="fi-panel"><h2>' . esc_html__( 'Documentation opportunities', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Review active discussions that still need a solution. Zero-result search counts and AI abstentions indicate demand without exposing private queries.', 'forum-intelligence-for-bbpress' ) . '</p><ul>';
		$topics = $this->services->get( 'discovery' )->topics( get_current_user_id(), 'trending' )['items'];
		foreach ( $topics as $topic ) {
			if ( ! $topic['solved'] ) {
				echo '<li><a href="' . esc_url( $topic['url'] ) . '">' . esc_html( $topic['title'] ) . '</a></li>'; }
		}
		echo '</ul></section>';
	}
	public function reputation(): void {
		if ( ! current_user_can( 'moderate' ) || ! $this->services->get( 'reputation' ) ) {
			return; }

		$badges = $this->services->get( 'badges' );
		if ( $badges ) {
			$fields = '<label>' . esc_html__( 'Member ID', 'forum-intelligence-for-bbpress' ) . '<input type="number" min="1" name="data[member]" required></label><label>' . esc_html__( 'Reviewed badge', 'forum-intelligence-for-bbpress' ) . '<select name="data[badge]">';
			foreach ( \ForumIntelligence\Domain\Reputation\Badges::catalog() as $key => $badge ) {
				if ( ! $badge['automatic'] ) {
					$fields .= '<option value="' . esc_attr( $key ) . '">' . esc_html( $badge['label'] ) . '</option>';}
			}
			$fields .= '</select></label><label>' . esc_html__( 'Action', 'forum-intelligence-for-bbpress' ) . '<select name="data[active]"><option value="1">' . esc_html__( 'Award', 'forum-intelligence-for-bbpress' ) . '</option><option value="0">' . esc_html__( 'Revoke', 'forum-intelligence-for-bbpress' ) . '</option></select></label><label>' . esc_html__( 'Review explanation', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[reason]" minlength="10" maxlength="1000" required></textarea></label>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form helper escapes dynamic values; field markup escapes at construction.
			echo '<section class="fi-panel"><h2>' . esc_html__( 'Reviewed achievements', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Badges recognize contributions and grant no permissions. Automatic badges depend on current public evidence.', 'forum-intelligence-for-bbpress' ) . '</p>' . $this->frontend->form( 'badge_review', array(), __( 'Save reviewed badge', 'forum-intelligence-for-bbpress' ), $fields ) . '</section>';
		}
		if ( current_user_can( 'manage_options' ) ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form helper escapes its dynamic values.
			echo $this->frontend->form( 'reputation_rebuild', array(), __( 'Reconcile source awards', 'forum-intelligence-for-bbpress' ) );
		}
		echo '<section class="fi-panel"><h2>' . esc_html__( 'Reviewed reputation correction', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'Corrections create an explainable ledger event. Reports and moderation actions do not automatically change reputation.', 'forum-intelligence-for-bbpress' ) . '</p>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
		echo $this->frontend->form( 'reputation', array(), __( 'Record correction', 'forum-intelligence-for-bbpress' ), '<label>' . esc_html__( 'Member ID', 'forum-intelligence-for-bbpress' ) . '<input type="number" min="1" name="data[member]" required></label><label>' . esc_html__( 'Points, −1000 to 1000', 'forum-intelligence-for-bbpress' ) . '<input type="number" min="-1000" max="1000" name="data[points]" required></label><label>' . esc_html__( 'Explanation', 'forum-intelligence-for-bbpress' ) . '<textarea name="data[reason]" minlength="5" maxlength="1000" required></textarea></label>' ) . '</section>';
	}
	public function messageReports(): void {
		if ( ! current_user_can( 'moderate' ) || ! $this->services->get( 'messaging' ) ) {
			return; }
		$rows = $this->services->store->rows( "SELECT id,payload FROM {$this->services->store->table('records')} WHERE kind='message_report' ORDER BY id DESC LIMIT 30" );
		foreach ( $rows as $row ) {
			$data = json_decode( $row['payload'], true );
			if ( ( $data['status'] ?? '' ) !== 'new' ) {
				continue; }
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Audited form/panel HTML: dynamic text, URLs and attributes are escaped where the markup is constructed.
			echo '<section class="fi-panel"><h2>' . esc_html__( 'Reported private message', 'forum-intelligence-for-bbpress' ) . '</h2><p>' . esc_html__( 'A participant explicitly shared this selected message for review.', 'forum-intelligence-for-bbpress' ) . '</p><blockquote>' . esc_html( $data['evidence'] ?? '' ) . '</blockquote>' . $this->frontend->form( 'message_report_resolve', array( 'id' => $row['id'] ), __( 'Close reviewed report', 'forum-intelligence-for-bbpress' ) ) . '</section>';
		}
	}
}
