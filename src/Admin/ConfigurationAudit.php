<?php
declare(strict_types=1);
namespace ForumIntelligence\Admin;
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Domain\Failure;
/** Configuration metadata only: never record old/new values, credentials or editorial text. */
final class ConfigurationAudit {
	private const FIELDS = array(
		'author_acceptance',
		'multiple_solutions',
		'downvotes',
		'self_voting',
		'ai_enabled',
		'semantic',
		'delete_on_uninstall',
		'text_model',
		'embedding_model',
		'ai_daily_requests',
		'evidence_threshold',
		'vote_weight',
		'downvote_weight',
		'confirmation_weight',
		'solution_weight',
		'reputation_daily_cap',
		'duplicate_threshold',
		'reactions',
		'retention_days',
		'excluded_forums',
		'modules',
	);
	public static function register(): void {
		// Static callback identities keep repeated service registration idempotent.
		add_action( 'updated_option', array( self::class, 'changed' ), 10, 3 );
		add_action( 'added_option', array( self::class, 'added' ), 10, 2 );
	}
	public static function added( string $option, mixed $value ): void {
		self::changed( $option, array(), $value );
	}
	public static function changed( string $option, mixed $old, mixed $value ): void {
		if ( ! in_array( $option, array( 'fi_settings', 'fi_field_definitions', 'fi_templates', 'fi_template_variants' ), true ) || $old === $value ) {
			return;
		}
		$payload = array();
		$kind    = 'settings_changed';
		if ( $option === 'fi_settings' ) {
			$old    = is_array( $old ) ? $old : array();
			$value  = is_array( $value ) ? $value : array();
			$fields = array();
			foreach ( self::FIELDS as $field ) {
				if ( array_key_exists( $field, $old ) !== array_key_exists( $field, $value ) || ( $old[ $field ] ?? null ) !== ( $value[ $field ] ?? null ) ) {
					$fields[] = $field;
				}
			}
			if ( ! $fields ) {
				return;
			}
			sort( $fields );
			$payload['fields'] = $fields;
		} else {
			$kind                        = in_array( $option, array( 'fi_templates', 'fi_template_variants' ), true ) ? 'topic_templates_changed' : 'topic_fields_changed';
			$payload['definition_count'] = is_array( $value ) ? count( $value ) : 0;
		}
		try {
			( new Store() )->event( $kind, 0, get_current_user_id(), $payload );
		} catch ( Failure $failure ) {
			// The native option is already saved; diagnose without leaking its values.
			do_action( 'fi_audit_write_failed', $kind, $failure->reason );
			error_log( 'Forum Intelligence configuration audit write failed.' );
		}
	}
}
