<?php
declare(strict_types=1);
namespace ForumIntelligence\Rest;
use ForumIntelligence\Application\Operations;
use ForumIntelligence\Domain\Failure;
use ForumIntelligence\Domain\Solutions\Solutions;
final class Routes {
	private array $queries = array();
	public function __construct( private Operations $operations, private Solutions $solutions ) {}
	public function query( string $name, callable $handler, bool $isPublic = false, array $arguments = array(), string $method = 'GET' ): void {
		$this->queries[ $name ] = array( $handler, $isPublic, $arguments, $method ); }
	public function register(): void {
		add_action(
			'rest_api_init',
			function (): void {
				foreach ( $this->operations->names() as $operation ) {
					register_rest_route(
						'forum-intelligence/v1',
						'/actions/' . $operation,
						array(
							'methods'             => 'POST',
							'permission_callback' => static fn(): bool=>is_user_logged_in(),
							'args'                => array(
								'data' => array(
									'type'              => 'object',
									'required'          => true,
									'validate_callback' => static fn( $data ): bool=>is_array( $data ) && strlen( (string) wp_json_encode( $data ) ) <= Operations::payloadLimit( $operation, get_current_user_id() ),
								),
							),
							'callback'            => fn( \WP_REST_Request $request ): mixed=>$this->respond( fn(): mixed=>$this->operations->execute( $operation, (array) $request->get_param( 'data' ), get_current_user_id() ) ),
						)
					);
				}
				foreach ( $this->queries as $name => [$handler, $isPublic, $arguments, $method] ) {
					register_rest_route(
						'forum-intelligence/v1',
						'/' . $name,
						array(
							'methods'             => $method,
							'permission_callback' => static fn(): bool=>$isPublic || is_user_logged_in(),
							'args'                => $arguments + array(
								'object' => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'page'   => array(
									'type'    => 'integer',
									'minimum' => 1,
									'maximum' => 1000,
								),
							),
							'callback'            => fn( \WP_REST_Request $request ): mixed=>$this->respond(
								function () use ( $request, $handler ): mixed {
								$params = $request->get_params();
								if ( strlen( (string) wp_json_encode( $params ) ) > 16384 ) {
								throw new Failure( 'too_large', __( 'The request is too large.', 'forum-intelligence-for-bbpress' ), 413 );
											} return $handler( $params, get_current_user_id() ); }
							),
						)
					);
				}
				register_rest_route(
					'forum-intelligence/v1',
					'/solutions/(?P<id>\\d+)',
					array(
						'methods'             => 'GET',
						'permission_callback' => '__return_true',
						'args'                => array(
							'id' => array(
								'type'              => 'integer',
								'minimum'           => 1,
								'sanitize_callback' => 'absint',
							),
						),
						'callback'            => fn( \WP_REST_Request $request ): mixed=>$this->respond( fn(): array=>$this->solutions->state( (int) $request['id'], get_current_user_id() ) ),
					)
				);
			}
		);
	}
	public function respond( callable $callback ): mixed {
		try {
			$response = rest_ensure_response( $callback() );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response; } catch ( Failure $failure ) {
			return new \WP_Error( $failure->reason, $failure->getMessage(), array( 'status' => $failure->status ) ); } catch ( \Throwable $failure ) {
				return new \WP_Error( 'operation_failed', __( 'The operation failed. Please retry.', 'forum-intelligence-for-bbpress' ), array( 'status' => 503 ) ); }
	}
}
