<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Composer;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;
final class TopicFields {
	public const TYPES = array( 'text', 'textarea', 'number', 'url', 'date', 'select', 'multiselect', 'checkbox', 'radio', 'version', 'taxonomy' );
	public function __construct( private Store $store, private Access $access ) {}
	public function configure( string $json, int $user ): array {
		if ( ! user_can( $user, 'manage_options' ) ) {
			throw new Failure( 'forbidden', __( 'Settings permission is required.', 'forum-intelligence-for-bbpress' ), 403 ); }
		$definitions = json_decode( $json, true );
		if ( ! is_array( $definitions ) || ! array_is_list( $definitions ) || count( $definitions ) > 20 ) {
			throw new Failure( 'invalid_fields', __( 'Provide a JSON list of no more than 20 fields.', 'forum-intelligence-for-bbpress' ) ); }
		$result = array();
		foreach ( $definitions as $field ) {
			if ( ! is_array( $field ) || ! preg_match( '/^[a-z][a-z0-9_]{1,31}$/D', (string) ( $field['key'] ?? '' ) ) || ! in_array( $field['type'] ?? '', self::TYPES, true ) ) {
				throw new Failure( 'invalid_field', __( 'Invalid field identifier or type.', 'forum-intelligence-for-bbpress' ) ); }
			$key = $field['key'];
			if ( isset( $result[ $key ] ) ) {
				throw new Failure( 'duplicate_field', __( 'Field identifiers must be unique.', 'forum-intelligence-for-bbpress' ) ); }
			$visibility     = in_array( $field['visibility'] ?? '', array( 'public', 'members', 'moderators' ), true ) ? $field['visibility'] : 'members';
			$result[ $key ] = array(
				'key'        => $key,
				'label'      => mb_substr( sanitize_text_field( (string) ( $field['label'] ?? $key ) ), 0, 100 ),
				'type'       => $field['type'],
				'required'   => ! empty( $field['required'] ),
				'visibility' => $visibility,
				'searchable' => ! empty( $field['searchable'] ) && $visibility === 'public',
				'ai'         => ! empty( $field['ai'] ) && $visibility === 'public',
				'forums'     => array_slice( array_values( array_filter( array_map( 'absint', (array) ( $field['forums'] ?? array() ) ) ) ), 0, 100 ),
				'options'    => array_slice( array_values( array_unique( array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) ) ) ), 0, 50 ),
			);
		}
		update_option( 'fi_field_definitions', array_values( $result ), false );
		$this->store->event( 'fields_configured', 0, $user, array( 'fields' => array_keys( $result ) ) );
		return array( 'saved' => true );
	}
	public function validate( array $values, int $forum, int $user ): array {
		$data = array();
		foreach ( (array) get_option( 'fi_field_definitions', array() ) as $field ) {
			if ( ! $this->visible( $field, $user ) || ( $field['forums'] && ! in_array( $forum, $field['forums'], true ) ) ) {
				continue; }
			$key   = $field['key'];
			$value = $values[ $key ] ?? '';
			if ( empty( $field['required'] ) && ( $value === '' || $value === array() ) ) {
				continue; }
			if ( ! empty( $field['required'] ) && ( $value === '' || $value === array() ) ) {
				// translators: %s is the field label.
				throw new Failure( 'required_field', sprintf( __( 'Complete the required field: %s', 'forum-intelligence-for-bbpress' ), $field['label'] ) ); }
			if ( is_array( $value ) && ! in_array( $field['type'], array( 'multiselect', 'taxonomy' ), true ) ) {
				throw new Failure( 'invalid_field_value', __( 'Invalid field value.', 'forum-intelligence-for-bbpress' ) ); }
			$valid = true;
			switch ( $field['type'] ) {
				case 'number':
					$valid = is_scalar( $value ) && is_numeric( $value ) && is_finite( (float) $value );
					$value = $valid ? (float) $value : 0;
					break;
				case 'checkbox':
					$value = (int) (bool) $value;
					$valid = empty( $field['required'] ) || $value === 1;
					break;
				case 'url':
					$value = esc_url_raw( (string) $value, array( 'http', 'https' ) );
					$valid = $value !== '';
					break;
				case 'date':
					$valid = (bool) preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/D', (string) $value ) && gmdate( 'Y-m-d', strtotime( (string) $value . ' UTC' ) ?: 0 ) === $value;
					break;
				case 'version':
					$valid = (bool) preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,49}$/D', (string) $value );
					break;
				case 'select':
				case 'radio':
					$valid = in_array( $value, $field['options'], true );
					break;
				case 'multiselect':
					$value = array_values( array_unique( array_map( 'sanitize_text_field', (array) $value ) ) );
					$valid = count( $value ) <= 20 && ! array_diff( $value, $field['options'] );
					break;
				case 'taxonomy':
					$value = array_slice( array_values( array_unique( array_map( 'absint', (array) $value ) ) ), 0, 20 );
					foreach ( $value as $id ) {
						$term = get_term( $id, bbp_get_topic_tag_tax_id() );
						if ( ! $term || is_wp_error( $term ) ) {
							$valid = false;
						}
					} break;
				case 'textarea':
					$value = mb_substr( sanitize_textarea_field( (string) $value ), 0, 2000 );
					break;
				default:
					$value = mb_substr( sanitize_text_field( (string) $value ), 0, 200 );
			}
			if ( ! $valid ) {
				// translators: %s is the field label.
				throw new Failure( 'invalid_field_value', sprintf( __( 'Check the value for: %s', 'forum-intelligence-for-bbpress' ), $field['label'] ) ); }
			$data[ $key ] = $value;
		}
		return $data;
	}
	public function save( int $topic, array $values, int $user ): array {
		$this->access->read( $topic, $user );
		if ( get_post_type( $topic ) !== bbp_get_topic_post_type() || ! user_can( $user, 'edit_post', $topic ) ) {
			throw new Failure( 'forbidden', __( 'Topic editing permission is required.', 'forum-intelligence-for-bbpress' ), 403 ); }
		$data     = $this->validate( $values, $this->access->forum( $topic ), $user );
		$existing = (array) get_post_meta( $topic, '_fi_fields', true );
		foreach ( (array) get_option( 'fi_field_definitions', array() ) as $field ) {
			if ( ! $this->visible( $field, $user ) && array_key_exists( $field['key'], $existing ) ) {
				$data[ $field['key'] ] = $existing[ $field['key'] ]; }
		}
		update_post_meta( $topic, '_fi_fields', $data );
		return array( 'saved' => true );
	}
	public function read( int $topic, int $user ): array {
		$this->access->read( $topic, $user );
		$stored = (array) get_post_meta( $topic, '_fi_fields', true );
		$data   = array();
		foreach ( (array) get_option( 'fi_field_definitions', array() ) as $field ) {
			if ( $this->visible( $field, $user ) && ( ! $field['forums'] || in_array( $this->access->forum( $topic ), $field['forums'], true ) ) && array_key_exists( $field['key'], $stored ) ) {
				$data[ $field['key'] ] = array(
					'label' => $field['label'],
					'value' => $stored[ $field['key'] ],
					'type'  => $field['type'],
				); }
		}
		return $data;
	}
	public function register(): void {
		add_action(
			'bbp_new_topic_pre_extras',
			function ( int $forum ): void {
				try {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- bbPress verifies the topic nonce before invoking these composer integration hooks.
					$this->validate( isset( $_POST['fi_fields'] ) && is_array( $_POST['fi_fields'] ) ? wp_unslash( $_POST['fi_fields'] ) : array(), $forum, get_current_user_id() ); } catch ( Failure $failure ) {
								bbp_add_error( 'fi_fields', $failure->getMessage() ); }
			}
		);
		add_action(
			'bbp_new_topic',
			function ( int $topic ): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- bbPress verifies the topic nonce before invoking these composer integration hooks.
				$values = isset( $_POST['fi_fields'] ) && is_array( $_POST['fi_fields'] ) ? wp_unslash( $_POST['fi_fields'] ) : array();
				update_post_meta( $topic, '_fi_fields', $this->validate( $values, $this->access->forum( $topic ), get_current_user_id() ) );
			},
			30
		);
		add_action(
			'bbp_edit_topic_pre_extras',
			function ( int $topic ): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- bbPress verifies the topic nonce before invoking these composer integration hooks.
				$forum = (int) ( $_POST['bbp_forum_id'] ?? $this->access->forum( $topic ) );
				try {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- bbPress verifies the topic nonce before invoking these composer integration hooks.
					$this->validate( isset( $_POST['fi_fields'] ) && is_array( $_POST['fi_fields'] ) ? wp_unslash( $_POST['fi_fields'] ) : array(), $forum, get_current_user_id() ); } catch ( Failure $failure ) {
								bbp_add_error( 'fi_fields', $failure->getMessage() ); }
			}
		);
		add_action(
			'bbp_edit_topic',
			function ( int $topic ): void {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- bbPress verifies the topic nonce before invoking these composer integration hooks.
				$values = isset( $_POST['fi_fields'] ) && is_array( $_POST['fi_fields'] ) ? wp_unslash( $_POST['fi_fields'] ) : array();
				$this->save( $topic, $values, get_current_user_id() );
			},
			30
		);
		add_action( 'bbp_theme_before_topic_form_submit_wrapper', array( $this, 'render' ) );
	}
	public function render(): void {
		$user  = get_current_user_id();
		$topic = (int) bbp_get_topic_id();
		$forum = (int) bbp_get_forum_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- bbPress verifies the topic nonce before invoking these composer integration hooks.
		$values = isset( $_POST['fi_fields'] ) && is_array( $_POST['fi_fields'] ) ? wp_unslash( $_POST['fi_fields'] ) : (array) get_post_meta( $topic, '_fi_fields', true );
		echo '<fieldset class="fi-panel"><legend>' . esc_html__( 'Topic details', 'forum-intelligence-for-bbpress' ) . '</legend>';
		foreach ( (array) get_option( 'fi_field_definitions', array() ) as $field ) {
			if ( ! $this->visible( $field, $user ) || ( $forum && $field['forums'] && ! in_array( $forum, $field['forums'], true ) ) ) {
				continue; }
			$value    = $values[ $field['key'] ] ?? '';
			$name     = 'fi_fields[' . $field['key'] . ']';
			$required = ! empty( $field['required'] ) ? ' aria-required="true"' : '';
			echo '<label>' . esc_html( $field['label'] ) . ' ';
			if ( $field['type'] === 'textarea' ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The required attribute is a fixed literal chosen from two constants; field values are escaped separately.
				echo '<textarea name="' . esc_attr( $name ) . '" maxlength="2000"' . $required . '>' . esc_textarea( is_scalar( $value ) ? (string) $value : '' ) . '</textarea>'; } elseif ( in_array( $field['type'], array( 'select', 'multiselect', 'radio', 'taxonomy' ), true ) ) {
				$multiple = in_array( $field['type'], array( 'multiselect', 'taxonomy' ), true );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The required attribute is a fixed literal chosen from two constants; field values are escaped separately.
				echo '<select name="' . esc_attr( $name . ( $multiple ? '[]' : '' ) ) . '"' . ( $multiple ? ' multiple' : '' ) . $required . '>';
				if ( ! $multiple ) {
					echo '<option value="">' . esc_html__( 'Choose an option', 'forum-intelligence-for-bbpress' ) . '</option>'; }
				$options = $field['options'];
				if ( $field['type'] === 'taxonomy' ) {
					$terms   = get_terms(
						array(
							'taxonomy'   => bbp_get_topic_tag_tax_id(),
							'hide_empty' => false,
							'number'     => 50,
						)
					);
					$options = array();
					if ( ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term ) {
							$options[ (string) $term->term_id ] = $term->name; }
					}
				}
				foreach ( $options as $key => $option ) {
					$optionValue = $field['type'] === 'taxonomy' ? (string) $key : (string) $option;
					$selected    = $multiple ? in_array( $optionValue, array_map( 'strval', (array) $value ), true ) : $optionValue === (string) $value;
					echo '<option value="' . esc_attr( $optionValue ) . '" ' . selected( $selected, true, false ) . '>' . esc_html( $option ) . '</option>';
				} echo '</select>';
				} else {
					$type = in_array( $field['type'], array( 'number', 'url', 'date', 'checkbox' ), true ) ? $field['type'] : 'text';
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The required attribute is a fixed literal chosen from two constants; field values are escaped separately.
					echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '"' . ( $type === 'checkbox' ? ' value="1" ' . checked( (bool) $value, true, false ) : ' value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '"' ) . $required . '>';
				}
				echo '</label>';
		}
		echo '</fieldset>';
	}
	private function visible( array $field, int $user ): bool {
		return $field['visibility'] === 'public' || ( $field['visibility'] === 'members' && $user > 0 ) || user_can( $user, 'moderate' ); }
}
