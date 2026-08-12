<?php
namespace CrescoLayer\Support;

final class Assets {
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
		add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'enqueue_editor_styles' ] );
		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_editor_scripts' ] );
	}

	public function enqueue_frontend(): void {
		wp_enqueue_style( 'cresco-layer-widgets', CRESCO_LAYER_URL . 'assets/frontend.css', [], CRESCO_LAYER_VERSION );
	}

	public function enqueue_editor_styles(): void {
		$this->enqueue_frontend();
		wp_enqueue_style( 'cresco-layer-editor', CRESCO_LAYER_URL . 'assets/editor.css', [], CRESCO_LAYER_VERSION );
	}

	public function enqueue_editor_scripts(): void {
		wp_enqueue_script( 'cresco-layer-editor', CRESCO_LAYER_URL . 'assets/editor.js', [], CRESCO_LAYER_VERSION, true );
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor bootstrap context.
		wp_localize_script( 'cresco-layer-editor', 'crescoLayerEditor', [
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'restRoot' => esc_url_raw( rest_url( 'cresco-layer/v1' ) ),
			'postId' => $post_id,
			'adminUrl' => esc_url_raw( admin_url( 'admin.php?page=cresco-layer' ) ),
		] );
	}
}
