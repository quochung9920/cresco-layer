<?php
namespace CrescoLayer\DesignSystem;

use CrescoLayer\AI\PatchApplier;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST surface for the design standard.
 *
 * Proposals are turned into a normal `cresco-layer-patch/v1` document-scope patch against the Kit
 * post, then handed to the existing PatchApplier. That means validation, the semantic guard, the
 * before/after diff, patch history and one-click rollback all apply to Site Settings changes without
 * a second write path existing anywhere in the plugin.
 */
final class StandardController {
	private KitReader $kit;
	private StandardAuditor $auditor;
	private FluidPlanner $fluid;
	private Presets $presets;

	public function __construct( private PatchApplier $applier, ?KitReader $kit = null ) {
		$this->kit = $kit ?? new KitReader();
		$this->auditor = new StandardAuditor( $this->kit );
		$this->fluid = new FluidPlanner( $this->kit );
		$this->presets = new Presets( $this->kit );
	}

	public function register_routes(): void {
		$permission = [ $this, 'can_manage' ];
		register_rest_route( 'cresco-layer/v1', '/design-standard', [ 'methods' => 'GET', 'callback' => [ $this, 'audit' ], 'permission_callback' => $permission ] );
		register_rest_route( 'cresco-layer/v1', '/design-standard/fluid', [ 'methods' => 'GET', 'callback' => [ $this, 'fluid' ], 'permission_callback' => $permission ] );
		register_rest_route( 'cresco-layer/v1', '/design-standard/presets', [ 'methods' => 'GET', 'callback' => [ $this, 'preset_catalog' ], 'permission_callback' => $permission ] );
		register_rest_route( 'cresco-layer/v1', '/design-standard/presets/(?P<preset>[a-z0-9-]+)', [ 'methods' => 'GET', 'callback' => [ $this, 'preset_plan' ], 'permission_callback' => $permission ] );
		register_rest_route( 'cresco-layer/v1', '/design-standard/preview', [ 'methods' => 'POST', 'callback' => [ $this, 'preview' ], 'permission_callback' => $permission ] );
		register_rest_route( 'cresco-layer/v1', '/design-standard/apply', [ 'methods' => 'POST', 'callback' => [ $this, 'apply' ], 'permission_callback' => $permission ] );
	}

	/** Site Settings are global; editing them is an administrator-level decision. */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function audit(): WP_REST_Response|WP_Error {
		try { return new WP_REST_Response( $this->auditor->audit() ); }
		catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function fluid(): WP_REST_Response|WP_Error {
		try { return new WP_REST_Response( $this->fluid->plan() ); }
		catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function preset_catalog(): WP_REST_Response|WP_Error {
		try { return new WP_REST_Response( $this->presets->catalog() ); }
		catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function preset_plan( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try { return new WP_REST_Response( $this->presets->plan( (string) $request['preset'] ) ); }
		catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			[ $kit_id, $patch ] = $this->build_patch( $this->body( $request ) );
			return new WP_REST_Response( $this->applier->preview( $kit_id, $patch ) );
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			[ $kit_id, $patch ] = $this->build_patch( $this->body( $request ) );
			$result = $this->applier->apply( $kit_id, $patch );
			$result['kitPostId'] = $kit_id;
			$result['publishReminder'] = 'Cresco wrote Elementor working data for the Site Settings Kit. Open Elementor Site Settings and use its own Save/Publish to make this live.';
			return new WP_REST_Response( $result );
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	/**
	 * Turn a source ('audit' | 'fluid' | 'preset') into a checksum-bound patch for the Kit document.
	 *
	 * @return array{0:int,1:array}
	 */
	private function build_patch( array $body ): array {
		$kit = $this->kit->read();
		if ( ! $kit['available'] || $kit['postId'] <= 0 ) {
			throw new \RuntimeException( 'Elementor has no readable active Kit to write to.' );
		}
		$kit_id = $kit['postId'];

		$source = sanitize_key( (string) ( $body['source'] ?? '' ) );
		$plan = match ( $source ) {
			'audit'  => $this->auditor->audit(),
			'fluid'  => $this->fluid->plan(),
			'preset' => $this->presets->plan( sanitize_key( (string) ( $body['preset'] ?? '' ) ) ),
			default  => throw new \InvalidArgumentException( 'Choose a design standard source: audit, fluid or preset.' ),
		};

		$operations = (array) ( $plan['proposedOperations'] ?? $plan['operations'] ?? [] );
		if ( ! $operations ) {
			throw new \RuntimeException( 'This design standard has nothing to change on the current Kit.' );
		}

		// Let the reviewer drop individual operations before applying.
		$selected = $body['settings'] ?? null;
		if ( is_array( $selected ) && $selected ) {
			$allow = array_fill_keys( array_map( 'strval', $selected ), true );
			$operations = array_values( array_filter( $operations, static fn( array $op ): bool => isset( $allow[ (string) ( $op['setting'] ?? '' ) ] ) ) );
			if ( ! $operations ) { throw new \RuntimeException( 'None of the selected settings are part of this proposal.' ); }
		}

		// crescoReason is explanatory metadata for the UI; the patch schema must stay clean.
		$operations = array_map(
			static function ( array $op ): array { unset( $op['crescoReason'] ); return $op; },
			$operations
		);

		return [ $kit_id, [
			'schema' => 'cresco-layer-patch/v1',
			'base' => [ 'postId' => $kit_id, 'checksum' => $this->applier->current_checksum( $kit_id ) ],
			'label' => (string) ( $body['label'] ?? 'Cresco design standard' ),
			'operations' => $operations,
		] ];
	}

	private function body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : [];
	}

	private function error( \Throwable $error ): WP_Error {
		return new WP_Error( 'cresco_layer_design_standard', wp_strip_all_tags( $error->getMessage() ), [ 'status' => 400 ] );
	}
}
