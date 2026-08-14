<?php
namespace CrescoLayer\LocalAI;

use WP_REST_Request;
use WP_REST_Response;

final class RESTController {
	public function __construct( private Manager $manager, private ?Analyzer $analyzer = null ) {}

	public function register_routes(): void {
		$permission = [ $this, 'can_manage' ];
		register_rest_route( 'cresco-layer/v1', '/local-ai', [ 'methods' => 'GET', 'callback' => [ $this, 'summary' ], 'permission_callback' => $permission ] );
		register_rest_route( 'cresco-layer/v1', '/local-ai/settings', [ 'methods' => 'POST', 'callback' => [ $this, 'save' ], 'permission_callback' => $permission ] );
		register_rest_route( 'cresco-layer/v1', '/local-ai/test', [ 'methods' => 'POST', 'callback' => [ $this, 'test' ], 'permission_callback' => $permission ] );
		register_rest_route( 'cresco-layer/v1', '/local-ai/models', [ 'methods' => 'GET', 'callback' => [ $this, 'models' ], 'permission_callback' => $permission ] );
		register_rest_route( 'cresco-layer/v1', '/local-ai/diagnostics', [ 'methods' => 'POST', 'callback' => [ $this, 'diagnostics' ], 'permission_callback' => $permission ] );
		register_rest_route( 'cresco-layer/v1', '/local-ai/contract', [ 'methods' => 'GET', 'callback' => [ $this, 'contract' ], 'permission_callback' => $permission ] );

		$document_permission = [ $this, 'can_use_document_ai' ];
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/local-ai/(?P<element>[A-Za-z0-9_-]+)/context', [ 'methods' => 'POST', 'callback' => [ $this, 'semantic_context' ], 'permission_callback' => $document_permission ] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/local-ai/(?P<element>[A-Za-z0-9_-]+)/analyze', [ 'methods' => 'POST', 'callback' => [ $this, 'analyze' ], 'permission_callback' => $document_permission ] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/local-ai/(?P<element>[A-Za-z0-9_-]+)/validate', [ 'methods' => 'POST', 'callback' => [ $this, 'validate_plan' ], 'permission_callback' => $document_permission ] );
	}

	public function can_manage(): bool { return current_user_can( 'manage_options' ); }
	public function can_use_document_ai( WP_REST_Request $request ): bool { return current_user_can( 'edit_post', absint( $request['id'] ) ); }
	public function summary(): WP_REST_Response { return $this->response( fn() => $this->manager->summary() ); }
	public function test(): WP_REST_Response { return $this->response( fn() => $this->manager->test() ); }
	public function models(): WP_REST_Response { return $this->response( fn() => $this->manager->models() ); }
	public function diagnostics(): WP_REST_Response { return $this->response( fn() => $this->manager->diagnostics() ); }
	public function contract(): WP_REST_Response { return $this->response( fn() => $this->manager->contract() ); }

	public function save( WP_REST_Request $request ): WP_REST_Response {
		return $this->response( function () use ( $request ): array {
			$body = $request->get_json_params();
			if ( ! is_array( $body ) ) { throw new \InvalidArgumentException( 'Local AI settings request must contain JSON.' ); }
			return $this->manager->save( $body );
		} );
	}

	public function semantic_context( WP_REST_Request $request ): WP_REST_Response {
		return $this->response( function () use ( $request ): array {
			$input = $this->body( $request );
			return [ 'schema' => ContextCompiler::SCHEMA, 'context' => $this->analyzer()->prepare( absint( $request['id'] ), (string) $request['element'], $input )['context'] ];
		} );
	}

	public function analyze( WP_REST_Request $request ): WP_REST_Response {
		return $this->response( fn() => $this->analyzer()->analyze( absint( $request['id'] ), (string) $request['element'], $this->body( $request ) ) );
	}

	public function validate_plan( WP_REST_Request $request ): WP_REST_Response {
		return $this->response( fn() => $this->analyzer()->validate_external_plan( absint( $request['id'] ), (string) $request['element'], $this->body( $request ) ) );
	}

	private function analyzer(): Analyzer {
		if ( ! $this->analyzer ) { throw new \RuntimeException( 'Local AI semantic analyzer is unavailable.' ); }
		return $this->analyzer;
	}

	private function body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { throw new \InvalidArgumentException( 'Local AI request must contain JSON.' ); }
		return $body;
	}

	private function response( callable $callback ): WP_REST_Response {
		try { return new WP_REST_Response( $callback(), 200 ); }
		catch ( \Throwable $error ) {
			$status = $error instanceof \InvalidArgumentException ? 400 : 500;
			return new WP_REST_Response( [ 'code' => 'cresco_layer_local_ai_error', 'message' => $error->getMessage() ], $status );
		}
	}
}
