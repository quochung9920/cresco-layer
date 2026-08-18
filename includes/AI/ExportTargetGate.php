<?php
namespace CrescoLayer\AI;

use CrescoLayer\Diagnostics\ExportDiagnostics;
use WP_Error;
use WP_REST_Request;

/**
 * Server-side fail-closed gate for scoped export targets.
 *
 * The browser preflight remains the primary UX, but it must not be the only safety boundary. Any
 * direct/programmatic /export request is checked again after REST permissions pass and immediately
 * before the route callback so a stale or not-yet-synchronized Elementor target never falls
 * through to PackageBuilder as a generic 500.
 */
final class ExportTargetGate {
	public function __construct( private ExportTargetResolver $resolver ) {}

	public function register_hooks(): void {
		// Run after route matching + permission checks, but before the actual export callback. This
		// keeps the hard gate fail-closed without leaking document state to unauthenticated requests.
		add_filter( 'rest_request_before_callbacks', [ $this, 'guard' ], -100, 3 );
	}

	public function guard( $result, $handler, WP_REST_Request $request ) {
		if ( null !== $result || ! $this->is_export_route( (string) $request->get_route() ) ) { return $result; }

		$post_id = $this->post_id( $request );
		$scope = sanitize_key( (string) $request->get_param( 'scope' ) );
		$selected = array_values( array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'selected' ) ) ) ) );

		ExportDiagnostics::stage( 'target-sync-gate', [
			'postId' => $post_id,
			'scope' => $scope,
			'selected' => implode( ',', $selected ),
		] );

		try {
			$status = $this->resolver->status( $post_id, $scope, $selected, $this->client_present( $request ) );
		} catch ( \InvalidArgumentException $error ) {
			return new WP_Error( 'cresco_export_target_invalid', $error->getMessage(), [
				'status' => 400,
				'crescoDiagnostic' => ExportDiagnostics::snapshot(),
			] );
		} catch ( \Throwable $error ) {
			return new WP_Error( 'cresco_export_target_gate_error', $error->getMessage(), [
				'status' => 500,
				'crescoDiagnostic' => ExportDiagnostics::snapshot(),
			] );
		}

		ExportDiagnostics::stage( 'target-sync-gate', [
			'postId' => $post_id,
			'scope' => $scope,
			'selected' => implode( ',', $selected ),
			'targetState' => (string) ( $status['state'] ?? '' ),
			'clientPresent' => $status['clientPresent'] ?? null,
		] );

		if ( ! empty( $status['ready'] ) ) { return $result; }

		$state = (string) ( $status['state'] ?? 'target-missing' );
		$http_status = 'stale-target' === $state ? 410 : 409;
		return new WP_Error(
			'cresco_export_target_not_ready',
			(string) ( $status['message'] ?? 'The selected Elementor target is not ready for export.' ),
			[
				'status' => $http_status,
				'targetStatus' => $status,
				'crescoDiagnostic' => ExportDiagnostics::snapshot(),
			]
		);
	}

	private function is_export_route( string $route ): bool {
		return (bool) preg_match( '#^/cresco-layer/v1/documents/\d+/export$#', $route );
	}

	private function post_id( WP_REST_Request $request ): int {
		$params = $request->get_url_params();
		if ( isset( $params['id'] ) ) {
			$post_id = absint( $params['id'] );
			if ( $post_id ) { return $post_id; }
		}
		$post_id = absint( $request->get_param( 'id' ) );
		if ( $post_id ) { return $post_id; }
		if ( preg_match( '#/documents/(\d+)/export$#', (string) $request->get_route(), $match ) ) {
			return absint( $match[1] );
		}
		return 0;
	}

	private function client_present( WP_REST_Request $request ): ?bool {
		$value = strtolower( trim( (string) $request->get_param( 'client_present' ) ) );
		if ( '' === $value ) { return null; }
		if ( in_array( $value, [ '1', 'true', 'yes', 'on' ], true ) ) { return true; }
		if ( in_array( $value, [ '0', 'false', 'no', 'off' ], true ) ) { return false; }
		return null;
	}
}
