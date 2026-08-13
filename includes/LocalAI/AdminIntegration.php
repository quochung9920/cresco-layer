<?php
namespace CrescoLayer\LocalAI;

final class AdminIntegration {
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ], 30 );
	}

	public function enqueue( string $hook ): void {
		if ( ! current_user_can( 'manage_options' ) || 'elementor_page_cresco-layer' !== $hook ) { return; }
		wp_enqueue_style( 'cresco-layer-local-ai-admin', CRESCO_LAYER_URL . 'assets/local-ai-admin.css', [ 'cresco-layer-admin' ], CRESCO_LAYER_VERSION );
		wp_enqueue_script( 'cresco-layer-local-ai-admin', CRESCO_LAYER_URL . 'assets/local-ai-admin.js', [ 'cresco-layer-admin' ], CRESCO_LAYER_VERSION, true );
		wp_localize_script( 'cresco-layer-local-ai-admin', 'crescoLayerLocalAI', [
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'restRoot' => esc_url_raw( rest_url( 'cresco-layer/v1' ) ),
		] );
	}
}
