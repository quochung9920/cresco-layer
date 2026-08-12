<?php
namespace CrescoLayer\REST;

use CrescoLayer\AI\PackageBuilder;
use CrescoLayer\AI\PatchApplier;
use CrescoLayer\AI\PatchValidator;
use CrescoLayer\Audit\Auditor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Controller {
	public function __construct(
		private PackageBuilder $builder,
		private PatchValidator $validator,
		private PatchApplier $applier,
		private Auditor $auditor
	) {}

	public function register_routes(): void {
		register_rest_route( 'cresco-layer/v1', '/health', [
			'methods' => 'GET',
			'callback' => [ $this, 'health' ],
			'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/export', [
			'methods' => 'GET',
			'callback' => [ $this, 'export' ],
			'permission_callback' => [ $this, 'can_edit' ],
			'args' => [
				'scope' => [ 'default' => 'document', 'sanitize_callback' => 'sanitize_key' ],
				'selected' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/preview', [
			'methods' => 'POST',
			'callback' => [ $this, 'preview' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/apply', [
			'methods' => 'POST',
			'callback' => [ $this, 'apply' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/audit', [
			'methods' => 'GET',
			'callback' => [ $this, 'audit' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
	}

	public function can_edit( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', absint( $request['id'] ) );
	}

	public function health(): WP_REST_Response {
		return new WP_REST_Response( [
			'ok' => true,
			'version' => CRESCO_LAYER_VERSION,
			'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementorPro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'patchSchema' => 'cresco-layer-patch/v1',
		], 200 );
	}

	public function export( WP_REST_Request $request ) {
		try {
			$selected = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $request->get_param( 'selected' ) ) ) ) );
			return new WP_REST_Response( $this->builder->build( absint( $request['id'] ), (string) $request->get_param( 'scope' ), $selected ), 200 );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function preview( WP_REST_Request $request ) {
		try {
			$patch = $this->request_patch( $request );
			return new WP_REST_Response( $this->applier->preview( absint( $request['id'] ), $patch ), 200 );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function apply( WP_REST_Request $request ) {
		try {
			$patch = $this->request_patch( $request );
			return new WP_REST_Response( $this->applier->apply( absint( $request['id'] ), $patch ), 200 );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function audit( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->auditor->audit_post( absint( $request['id'] ) ), 200 );
	}

	private function request_patch( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( isset( $body['patch'] ) && is_array( $body['patch'] ) ) { return $body['patch']; }
		if ( is_array( $body ) ) { return $body; }
		throw new \InvalidArgumentException( 'Request must contain a JSON patch.' );
	}

	private function error( \Throwable $error ): WP_Error {
		$message = $error->getMessage();
		$status = str_contains( strtolower( $message ), 'older elementor document' ) || str_contains( strtolower( $message ), 'checksum' ) ? 409 : ( $error instanceof \InvalidArgumentException ? 400 : 500 );
		return new WP_Error( 'cresco_layer_error', $message, [ 'status' => $status ] );
	}
}
