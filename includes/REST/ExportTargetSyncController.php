<?php
namespace CrescoLayer\REST;

use CrescoLayer\AI\ContextResolver;
use CrescoLayer\AI\ExportTargetResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ExportTargetSyncController {
	public function __construct( private ExportTargetResolver $resolver ) {}

	public function register_routes(): void {
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/export-target-status', [
			'methods' => 'GET',
			'callback' => [ $this, 'status' ],
			'permission_callback' => [ $this, 'can_edit' ],
			'args' => [
				'scope' => [ 'default' => 'document', 'sanitize_callback' => 'sanitize_key' ],
				'selected' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );
	}

	public function can_edit( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', absint( $request['id'] ) );
	}

	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$selected = array_values( array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'selected' ) ) ) ) );
			return new WP_REST_Response(
				$this->resolver->status(
					absint( $request['id'] ),
					(string) $request->get_param( 'scope' ),
					$selected
				),
				200
			);
		} catch ( \Throwable $error ) {
			$status = $error instanceof \InvalidArgumentException ? 400 : 500;
			return new WP_Error( 'cresco_export_target_status_error', $error->getMessage(), [ 'status' => $status ] );
		}
	}
}
