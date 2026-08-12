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
			'packageSchema' => 'cresco-layer-ai-package/v2',
			'patchSchema' => 'cresco-layer-patch/v1',
			'scopedExchange' => true,
		], 200 );
	}

	public function export( WP_REST_Request $request ) {
		try {
			$selected = array_values( array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'selected' ) ) ) ) );
			return new WP_REST_Response( $this->builder->build( absint( $request['id'] ), (string) $request->get_param( 'scope' ), $selected ), 200 );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function preview( WP_REST_Request $request ) {
		try {
			$body = $this->request_body( $request );
			return new WP_REST_Response(
				$this->applier->preview( absint( $request['id'] ), $this->request_patch_from_body( $body ), $this->expected_scope( $body ) ),
				200
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function apply( WP_REST_Request $request ) {
		try {
			$body = $this->request_body( $request );
			return new WP_REST_Response(
				$this->applier->apply( absint( $request['id'] ), $this->request_patch_from_body( $body ), $this->expected_scope( $body ) ),
				200
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	public function audit( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->auditor->audit_post( absint( $request['id'] ) ), 200 );
	}

	private function request_body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { throw new \InvalidArgumentException( 'Request must contain JSON.' ); }
		return $body;
	}

	private function request_patch_from_body( array $body ): array {
		if ( isset( $body['patch'] ) && is_array( $body['patch'] ) ) { return $body['patch']; }
		if ( isset( $body['schema'] ) ) { return $body; }
		throw new \InvalidArgumentException( 'Request must contain a JSON patch.' );
	}

	private function expected_scope( array $body ): ?array {
		if ( ! isset( $body['expectedScope'] ) ) { return null; }
		if ( ! is_array( $body['expectedScope'] ) ) { throw new \InvalidArgumentException( 'expectedScope must be an object.' ); }
		$mode = sanitize_key( (string) ( $body['expectedScope']['mode'] ?? '' ) );
		$root = trim( (string) ( $body['expectedScope']['rootElementId'] ?? '' ) );
		if ( ! in_array( $mode, [ 'widget', 'subtree', 'selection', 'document' ], true ) ) { throw new \InvalidArgumentException( 'expectedScope mode is invalid.' ); }
		if ( 'document' !== $mode && ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $root ) ) { throw new \InvalidArgumentException( 'expectedScope rootElementId is invalid.' ); }
		return [ 'mode' => $mode, 'rootElementId' => $root ];
	}

	private function error( \Throwable $error ): WP_Error {
		$message = $error->getMessage();
		$lower = strtolower( $message );
		$status = str_contains( $lower, 'older elementor document' ) || str_contains( $lower, 'checksum' ) || str_contains( $lower, 'changed after ai export' )
			? 409
			: ( $error instanceof \InvalidArgumentException ? 400 : 500 );
		return new WP_Error( 'cresco_layer_error', $message, [ 'status' => $status ] );
	}
}
