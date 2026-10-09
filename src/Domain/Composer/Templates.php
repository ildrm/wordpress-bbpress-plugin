<?php
declare(strict_types=1);
namespace ForumIntelligence\Domain\Composer;
use ForumIntelligence\Domain\Failure;
use ForumIntelligence\Integration\BbPress\Access;
/** Optional plain-text guidance; selection changes no topic classification or content. */
final class Templates {
	public const AXES = array( 'topic_type', 'product', 'category' );
	public function __construct( private Access $access ) {}
	public function configure( int $user, string $json ): array {
		if ( ! user_can( $user, 'manage_options' ) ) {
			throw new Failure( 'forbidden', __( 'Settings permission is required.', 'forum-intelligence-for-bbpress' ), 403 );}
		if ( strlen( $json ) > 1048576 ) {
			throw new Failure( 'too_large', __( 'The configuration is too large.', 'forum-intelligence-for-bbpress' ), 413 );}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! array_is_list( $data ) || count( $data ) > 20 || ! str_starts_with( ltrim( $json ), '[' ) ) {
			throw new Failure( 'invalid_templates', __( 'Provide a JSON list of no more than 20 contextual templates.', 'forum-intelligence-for-bbpress' ) );}
		$rules = array();
		foreach ( $data as $rule ) {
			if ( ! is_array( $rule ) || array_diff( array_keys( $rule ), array( 'id', 'label', 'forum', 'topic_type', 'product', 'category', 'text' ) ) || ! is_string( $rule['id'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9_-]{1,47}$/D', $rule['id'] ) || isset( $rules[ $rule['id'] ] ) ) {
				throw new Failure( 'invalid_template', __( 'Use unique template identifiers and the supported template fields.', 'forum-intelligence-for-bbpress' ) );}
			$forum = $rule['forum'] ?? 0;
			if ( ! is_int( $forum ) || $forum < 0 || ( $forum && get_post_type( $forum ) !== bbp_get_forum_post_type() ) ) {
				throw new Failure( 'invalid_forum', __( 'Choose a forum.', 'forum-intelligence-for-bbpress' ) );}
			if ( $forum ) {
				$this->access->read( $forum, $user );}
			$clean = array(
				'id'    => $rule['id'],
				'forum' => $forum,
			);
			foreach ( array(
				'label'      => 100,
				'text'       => 5000,
				'topic_type' => 80,
				'product'    => 80,
				'category'   => 80,
			) as $key => $limit ) {
				$value = $rule[ $key ] ?? '';
				if ( ! is_string( $value ) || mb_strlen( $value ) > $limit ) {
					throw new Failure( 'invalid_template', __( 'Template labels, criteria and text must be plain text within their limits.', 'forum-intelligence-for-bbpress' ) );}
				$clean[ $key ] = $key === 'text' ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
			if ( trim( $clean['label'] ) === '' || trim( $clean['text'] ) === '' ) {
				throw new Failure( 'invalid_template', __( 'Each template needs a label and nonempty guidance.', 'forum-intelligence-for-bbpress' ) );}
			$rules[ $clean['id'] ] = $clean;
		}
		$definitions = array_values( $rules );
		update_option( 'fi_template_variants', $definitions, false );
		if ( maybe_serialize( get_option( 'fi_template_variants', null ) ) !== maybe_serialize( $definitions ) ) {
			throw new Failure( 'template_save_failed', __( 'The template configuration could not be saved. Please retry.', 'forum-intelligence-for-bbpress' ), 503 );
		}
		return array( 'saved' => true );
	}
	public function view( int $forum, int $user, array $selection = array() ): array {
		$this->access->read( $forum, $user );
		if ( get_post_type( $forum ) !== bbp_get_forum_post_type() ) {
			throw new Failure( 'invalid_forum', __( 'Choose a forum.', 'forum-intelligence-for-bbpress' ) );}
		$selected = array();
		$options  = array();
		foreach ( self::AXES as $axis ) {
			$value = $selection[ $axis ] ?? '';
			if ( ! is_string( $value ) || mb_strlen( $value ) > 80 ) {
				throw new Failure( 'invalid_template_selection', __( 'Choose a valid template context.', 'forum-intelligence-for-bbpress' ) );}
			$selected[ $axis ] = sanitize_text_field( $value );
			$options[ $axis ]  = array();
		}
		$catalog = array();
		$matches = array();
		foreach ( array_slice( (array) get_option( 'fi_template_variants', array() ), 0, 20 ) as $rule ) {
			if ( ! is_array( $rule ) || ! is_int( $rule['forum'] ?? 0 ) || ! is_string( $rule['id'] ?? null ) || ! is_string( $rule['label'] ?? null ) || ! is_string( $rule['text'] ?? null ) || ( (int) ( $rule['forum'] ?? 0 ) !== 0 && (int) $rule['forum'] !== $forum ) ) {
				continue;}
			$criteria         = array();
			$matchesSelection = true;
			$specificity      = 0;
			foreach ( self::AXES as $axis ) {
				$value             = is_string( $rule[ $axis ] ?? null ) ? mb_substr( sanitize_text_field( $rule[ $axis ] ), 0, 80 ) : '';
				$criteria[ $axis ] = $value;
				if ( $value !== '' ) {
					$options[ $axis ][] = $value;
					++$specificity;
					if ( $selected[ $axis ] !== $value ) {
						$matchesSelection = false;}
				}
			}
			$item      = array(
				'id'       => $rule['id'],
				'label'    => mb_substr( sanitize_text_field( $rule['label'] ), 0, 100 ),
				'text'     => mb_substr( sanitize_textarea_field( $rule['text'] ), 0, 5000 ),
				'criteria' => $criteria,
			);
			$catalog[] = $item;
			if ( $matchesSelection ) {
				$matches[] = $item + array(
					'specificity' => $specificity,
					'local'       => (int) ( $rule['forum'] ?? 0 ) === $forum ? 1 : 0,
				);}
		}
		foreach ( self::AXES as $axis ) {
			$options[ $axis ] = array_values( array_unique( $options[ $axis ] ) );
			sort( $options[ $axis ], SORT_NATURAL | SORT_FLAG_CASE );
		}
		usort( $matches, static fn( array $a, array $b ): int => $b['specificity'] <=> $a['specificity'] ?: ( $b['local'] <=> $a['local'] ?: strcmp( $a['id'], $b['id'] ) ) );
		$defaults = (array) get_option( 'fi_templates', array() );
		$chosen   = $matches[0] ?? array(
			'id'    => '',
			'label' => __( 'Forum guidance', 'forum-intelligence-for-bbpress' ),
			'text'  => is_string( $defaults[ $forum ] ?? null ) ? mb_substr( sanitize_textarea_field( $defaults[ $forum ] ), 0, 5000 ) : '',
		);
		return array(
			'object'    => $forum,
			'owner'     => $user,
			'blog'      => get_current_blog_id(),
			'id'        => $chosen['id'],
			'label'     => $chosen['label'],
			'text'      => $chosen['text'],
			'options'   => $options,
			'selection' => $selected,
			'templates' => $catalog,
		);
	}
}
