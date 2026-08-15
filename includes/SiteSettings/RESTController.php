<?php
namespace CrescoLayer\SiteSettings;

use CrescoLayer\SiteSettings\Gateway\ElementorKitGateway;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Internal API for the Site Settings engine.
 *
 * Deliberately not a management UI: Elementor → Site Settings remains the place a designer edits
 * these values. These routes exist so Cresco (or an operator) can configure the Kit once and then
 * hand it back to Elementor.
 *
 * Site Settings are global, so every route requires `manage_options`.
 */
final class RESTController {
	private SiteSettingsEngine $engine;

	public function __construct( ?SiteSettingsEngine $engine = null ) {
		$this->engine = $engine ?? new SiteSettingsEngine( new ElementorKitGateway() );
	}

	public function register_routes(): void {
		$permission = [ $this, 'can_manage' ];
		register_rest_route( 'cresco-layer/v1', '/site-settings/profile', [
			'methods' => 'GET', 'callback' => [ $this, 'profile' ], 'permission_callback' => $permission,
		] );
		register_rest_route( 'cresco-layer/v1', '/site-settings/preview', [
			'methods' => 'POST', 'callback' => [ $this, 'preview' ], 'permission_callback' => $permission,
		] );
		register_rest_route( 'cresco-layer/v1', '/site-settings/apply', [
			'methods' => 'POST', 'callback' => [ $this, 'apply' ], 'permission_callback' => $permission,
		] );
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function profile(): WP_REST_Response|WP_Error {
		try { return new WP_REST_Response( $this->engine->profile_spec() ); }
		catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try { return new WP_REST_Response( $this->engine->preview( $this->spec( $request ) ) ); }
		catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try { return new WP_REST_Response( $this->engine->apply( $this->spec( $request ) ) ); }
		catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	/** A caller may post its own spec; omitting one runs the built-in profile. */
	private function spec( WP_REST_Request $request ): ?array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { return null; }
		$spec = $body['spec'] ?? null;
		return is_array( $spec ) ? $spec : null;
	}

	private function error( \Throwable $error ): WP_Error {
		return new WP_Error( 'cresco_layer_site_settings', wp_strip_all_tags( $error->getMessage() ), [ 'status' => 400 ] );
	}
}
