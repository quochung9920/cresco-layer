<?php
namespace CrescoLayer\Support;

final class Assets {
	private bool $editor_script_localized = false;

	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );

		// Load before Elementor whenever possible so the bridge cannot miss the documented
		// window `elementor/init` event. The after hooks remain as compatibility fallbacks.
		add_action( 'elementor/editor/before_enqueue_styles', [ $this, 'enqueue_editor_styles' ], 1 );
		add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'enqueue_editor_styles' ], 100 );
		add_action( 'elementor/editor/before_enqueue_scripts', [ $this, 'enqueue_editor_scripts' ], 1 );
		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_editor_scripts' ], 100 );

		// Elementor 4.x can boot the editor through newer app surfaces. Keep a narrowly
		// scoped admin fallback so the bridge is still present on an Elementor edit request.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_editor_assets_fallback' ], 100 );
	}

	public function enqueue_frontend(): void {
		wp_enqueue_style( 'cresco-layer-widgets', CRESCO_LAYER_URL . 'assets/frontend.css', [], CRESCO_LAYER_VERSION );
	}

	public function enqueue_editor_styles(): void {
		$this->enqueue_frontend();
		wp_enqueue_style( 'cresco-layer-editor', CRESCO_LAYER_URL . 'assets/editor.css', [], CRESCO_LAYER_VERSION );
		wp_enqueue_style( 'cresco-layer-skills', CRESCO_LAYER_URL . 'assets/skills.css', [ 'cresco-layer-editor' ], CRESCO_LAYER_VERSION );
	}

	public function enqueue_editor_scripts(): void {
		// Keep this script in the document head. Elementor's documented context-menu
		// integration listens for `elementor/init`; a footer-only script can arrive after
		// that event on fast or Atomic/V4 editor boots.
		wp_enqueue_script( 'cresco-layer-editor', CRESCO_LAYER_URL . 'assets/editor.js', [], CRESCO_LAYER_VERSION, false );
		wp_enqueue_script( 'cresco-layer-skills', CRESCO_LAYER_URL . 'assets/skills.js', [ 'cresco-layer-editor' ], CRESCO_LAYER_VERSION, false );

		if ( $this->editor_script_localized ) {
			return;
		}

		$this->editor_script_localized = true;
		wp_localize_script( 'cresco-layer-editor', 'crescoLayerEditor', [
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'restRoot'            => esc_url_raw( rest_url( 'cresco-layer/v1' ) ),
			'postId'              => $this->editor_post_id(),
			'adminUrl'            => esc_url_raw( admin_url( 'admin.php?page=cresco-layer' ) ),
			'version'             => CRESCO_LAYER_VERSION,
			'elementorVersion'    => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementorProVersion' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
		] );
	}

	public function enqueue_editor_assets_fallback(): void {
		if ( ! $this->is_elementor_editor_request() ) {
			return;
		}

		$this->enqueue_editor_styles();
		$this->enqueue_editor_scripts();
	}

	private function is_elementor_editor_request(): bool {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing context.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing context.
		$post   = $this->editor_post_id();

		if ( ! $post ) {
			return false;
		}

		return 'elementor' === $action || str_starts_with( $page, 'elementor' );
	}

	private function editor_post_id(): int {
		foreach ( [ 'post', 'post_id', 'editor_post_id' ] as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor bootstrap context.
				$post_id = absint( wp_unslash( (string) $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor bootstrap context.
				if ( $post_id ) {
					return $post_id;
				}
			}
		}

		return 0;
	}
}
