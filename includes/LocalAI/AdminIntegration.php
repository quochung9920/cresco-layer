<?php
namespace CrescoLayer\LocalAI;

final class AdminIntegration {
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ], 30 );
	}

	public function enqueue( string $hook ): void {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_cresco_layer_screen( $hook ) ) { return; }
		wp_enqueue_style( 'cresco-layer-local-ai-admin', CRESCO_LAYER_URL . 'assets/local-ai-admin.css', [ 'cresco-layer-admin' ], CRESCO_LAYER_VERSION );
		wp_enqueue_script( 'cresco-layer-local-ai-admin', CRESCO_LAYER_URL . 'assets/local-ai-admin.js', [ 'cresco-layer-admin' ], CRESCO_LAYER_VERSION, true );
		wp_localize_script( 'cresco-layer-local-ai-admin', 'crescoLayerLocalAI', [
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'restRoot' => esc_url_raw( rest_url( 'cresco-layer/v1' ) ),
		] );
	}

	private function is_cresco_layer_screen( string $hook ): bool {
		if ( in_array( $hook, [ 'elementor_page_cresco-layer', 'plugins_page_cresco-layer', 'tools_page_cresco-layer' ], true ) ) { return true; }

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'cresco-layer' === $page ) { return true; }

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			$screen_id = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';
			if ( '' !== $screen_id && ( str_ends_with( $screen_id, '_page_cresco-layer' ) || 'cresco-layer' === $screen_id ) ) { return true; }
		}

		return false;
	}
}
