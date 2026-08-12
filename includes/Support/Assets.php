<?php
namespace CrescoLayer\Support;

final class Assets {
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
		add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'enqueue_frontend' ] );
	}

	public function enqueue_frontend(): void {
		wp_enqueue_style( 'cresco-layer-widgets', CRESCO_LAYER_URL . 'assets/frontend.css', [], CRESCO_LAYER_VERSION );
	}
}
