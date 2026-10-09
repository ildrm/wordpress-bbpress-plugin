<?php
declare(strict_types=1);
namespace ForumIntelligence\Application;
use ForumIntelligence\Domain\Failure;
final class Operations {
	/** @var array<string,callable> */
	private array $handlers = array();
	/** Bound encoded request data independently from each domain's character limits. */
	public static function payloadLimit( string $operation, int $user ): int {
		if ( in_array( $operation, array( 'config_import', 'template_variants' ), true ) && user_can( $user, 'manage_options' ) ) {
			return 1048576; }
		if ( in_array( $operation, array( 'draft', 'offline_restore', 'composer_preview' ), true ) && $user ) {
			return 196608; }
		return 16384;
	}
	public function add( string $name, callable $handler ): void {
		$this->handlers[ $name ] = $handler; }
	public function names(): array {
		return array_keys( $this->handlers ); }
	public function execute( string $name, array $data, int $user ): mixed {
		if ( ! isset( $this->handlers[ $name ] ) ) {
			throw new Failure( 'unknown_operation', __( 'This feature is unavailable.', 'forum-intelligence-for-bbpress' ), 404 ); }
		if ( count( $data ) > 50 ) {
			throw new Failure( 'invalid_input', __( 'Too many request fields.', 'forum-intelligence-for-bbpress' ) ); }
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z_]{1,40}$/D', $key ) ) {
				throw new Failure( 'invalid_input', __( 'Invalid request field.', 'forum-intelligence-for-bbpress' ) ); }
			if ( is_array( $value ) && ! in_array( $key, array( 'choices', 'muted_categories', 'fields', 'upload', 'subscription', 'filters' ), true ) ) {
				throw new Failure( 'invalid_input', __( 'A scalar request value is required.', 'forum-intelligence-for-bbpress' ) ); }
			if ( ! is_array( $value ) && ! is_scalar( $value ) && $value !== null ) {
				throw new Failure( 'invalid_input', __( 'Invalid request value.', 'forum-intelligence-for-bbpress' ) ); }
		}
		return ( $this->handlers[ $name ] )( $data, $user );
	}
}
