<?php
namespace CrescoLayer\Support;

use CrescoLayer\LocalAI\Settings as LocalAISettings;

final class Assets {
	private bool $editor_script_localized = false;

	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
		add_action( 'elementor/editor/before_enqueue_styles', [ $this, 'enqueue_editor_styles' ], 1 );
		add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'enqueue_editor_styles' ], 100 );
		add_action( 'elementor/editor/before_enqueue_scripts', [ $this, 'enqueue_editor_scripts' ], 1 );
		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_editor_scripts' ], 100 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_clipboard_guard' ], 1 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_editor_assets_fallback' ], 100 );
	}

	public function enqueue_frontend(): void {
		wp_enqueue_style( 'cresco-layer-widgets', CRESCO_LAYER_URL . 'assets/frontend.css', [], CRESCO_LAYER_VERSION );
	}

	public function enqueue_editor_styles(): void {
		$this->enqueue_frontend();
		wp_enqueue_style( 'cresco-layer-editor', CRESCO_LAYER_URL . 'assets/editor.css', [], CRESCO_LAYER_VERSION );
		wp_enqueue_style( 'cresco-layer-ai-panel', CRESCO_LAYER_URL . 'assets/ai-panel.css', [ 'cresco-layer-editor' ], CRESCO_LAYER_VERSION );
		wp_enqueue_style( 'cresco-layer-skills', CRESCO_LAYER_URL . 'assets/skills.css', [ 'cresco-layer-ai-panel' ], CRESCO_LAYER_VERSION );
		wp_enqueue_style( 'cresco-layer-semantic-ai', CRESCO_LAYER_URL . 'assets/semantic-ai.css', [ 'cresco-layer-skills' ], CRESCO_LAYER_VERSION );
	}

	public function enqueue_editor_scripts(): void {
		wp_enqueue_script( 'cresco-layer-clipboard-guard', CRESCO_LAYER_URL . 'assets/clipboard-guard.js', [], CRESCO_LAYER_VERSION, false );
		wp_enqueue_script( 'cresco-layer-editor', CRESCO_LAYER_URL . 'assets/editor.js', [ 'cresco-layer-clipboard-guard' ], CRESCO_LAYER_VERSION, false );
		wp_enqueue_script( 'cresco-layer-exact-runtime-export', CRESCO_LAYER_URL . 'assets/exact-runtime-export.js', [ 'cresco-layer-editor' ], CRESCO_LAYER_VERSION, false );
		wp_enqueue_script( 'cresco-layer-ai-context-v3', CRESCO_LAYER_URL . 'assets/ai-context-v3.js', [ 'cresco-layer-exact-runtime-export' ], CRESCO_LAYER_VERSION, false );
		wp_enqueue_script( 'cresco-layer-external-ai-intelligence', CRESCO_LAYER_URL . 'assets/external-ai-intelligence.js', [ 'cresco-layer-ai-context-v3' ], CRESCO_LAYER_VERSION, false );
		wp_enqueue_script( 'cresco-layer-ai-context-policy', CRESCO_LAYER_URL . 'assets/ai-context-policy.js', [ 'cresco-layer-external-ai-intelligence' ], CRESCO_LAYER_VERSION, false );
		wp_enqueue_script( 'cresco-layer-ai-bundle', CRESCO_LAYER_URL . 'assets/ai-bundle.js', [ 'cresco-layer-ai-context-policy' ], CRESCO_LAYER_VERSION, false );
		wp_enqueue_script( 'cresco-layer-ai-panel', CRESCO_LAYER_URL . 'assets/ai-panel.js', [ 'cresco-layer-ai-bundle' ], CRESCO_LAYER_VERSION, false );
		wp_enqueue_script( 'cresco-layer-skills', CRESCO_LAYER_URL . 'assets/skills.js', [ 'cresco-layer-ai-panel' ], CRESCO_LAYER_VERSION, false );
		wp_enqueue_script( 'cresco-layer-skills-accuracy', CRESCO_LAYER_URL . 'assets/skills-accuracy.js', [ 'cresco-layer-skills' ], CRESCO_LAYER_VERSION, false );
		wp_enqueue_script( 'cresco-layer-semantic-ai', CRESCO_LAYER_URL . 'assets/semantic-ai.js', [ 'cresco-layer-skills-accuracy' ], CRESCO_LAYER_VERSION, false );

		if ( $this->editor_script_localized ) { return; }
		$this->editor_script_localized = true;
		$local_ai = ( new LocalAISettings() )->editor_summary();
		wp_localize_script( 'cresco-layer-editor', 'crescoLayerEditor', [
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'restRoot'            => esc_url_raw( rest_url( 'cresco-layer/v1' ) ),
			'postId'              => $this->editor_post_id(),
			'adminUrl'            => esc_url_raw( admin_url( 'admin.php?page=cresco-layer' ) ),
			'version'             => CRESCO_LAYER_VERSION,
			'elementorVersion'    => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementorProVersion' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'localAI'             => $local_ai,
		] );
	}

	public function enqueue_admin_clipboard_guard( string $hook ): void {
		if ( 'elementor_page_cresco-layer' !== $hook && ! $this->is_elementor_editor_request() ) { return; }
		wp_enqueue_script( 'cresco-layer-clipboard-guard', CRESCO_LAYER_URL . 'assets/clipboard-guard.js', [], CRESCO_LAYER_VERSION, false );
	}

	public function enqueue_editor_assets_fallback(): void {
		if ( ! $this->is_elementor_editor_request() ) { return; }
		$this->enqueue_editor_styles();
		$this->enqueue_editor_scripts();
	}

	private function is_elementor_editor_request(): bool {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing context.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing context.
		$post   = $this->editor_post_id();
		if ( ! $post ) { return false; }
		return 'elementor' === $action || str_starts_with( $page, 'elementor' );
	}

	private function editor_post_id(): int {
		foreach ( [ 'post', 'post_id', 'editor_post_id' ] as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor bootstrap context.
				$post_id = absint( wp_unslash( (string) $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor bootstrap context.
				if ( $post_id ) { return $post_id; }
			}
		}
		return 0;
	}
}
