<?php
namespace CrescoLayer\Support;

use CrescoLayer\AI\FidelityPolicy;

final class Assets {
	private bool $editor_script_localized = false;

	/**
	 * Legacy asset references remain discoverable for architecture/compatibility diagnostics, but
	 * Safe Bootstrap intentionally does not enqueue them on the Elementor startup path.
	 */
	private const LEGACY_EDITOR_ASSET_REFERENCES = [
		'cresco-layer-skills',
		'assets/skills.js',
		'assets/skills.css',
	];

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
		if ( $this->is_safe_mode() ) { return; }
		$this->enqueue_frontend();
		// Keep startup CSS intentionally small. Legacy editor/skills CSS is loaded only by legacy tools,
		// while the external exchange launcher and panel are fully covered by ai-panel.css.
		wp_enqueue_style( 'cresco-layer-ai-panel', CRESCO_LAYER_URL . 'assets/ai-panel.css', [], CRESCO_LAYER_VERSION );
	}

	public function enqueue_editor_scripts(): void {
		if ( $this->is_safe_mode() ) { return; }

		// Safe Bootstrap rule: Elementor must become usable before Cresco loads runtime scanners,
		// visual capture, AI context builders, fetch wrappers, verification or legacy skills.
		wp_enqueue_script(
			'cresco-layer-editor-bootstrap',
			CRESCO_LAYER_URL . 'assets/editor-bootstrap.js',
			[],
			CRESCO_LAYER_VERSION,
			true
		);

		// This tiny preflight guard is intentionally safe on startup: it adds one delegated click
		// listener only. It does not poll, observe the DOM or wrap fetch. Autosave/status checks run
		// only after the user explicitly clicks an external Export button.
		wp_enqueue_script(
			'cresco-layer-export-target-sync',
			CRESCO_LAYER_URL . 'assets/export-target-sync.js',
			[ 'cresco-layer-editor-bootstrap' ],
			CRESCO_LAYER_VERSION,
			true
		);

		if ( $this->editor_script_localized ) { return; }
		$this->editor_script_localized = true;
		wp_localize_script( 'cresco-layer-editor-bootstrap', 'crescoLayerEditor', [
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'restRoot'            => esc_url_raw( rest_url( 'cresco-layer/v1' ) ),
			'postId'              => $this->editor_post_id(),
			'adminUrl'            => esc_url_raw( admin_url( 'admin.php?page=cresco-layer' ) ),
			'assetBaseUrl'        => esc_url_raw( CRESCO_LAYER_URL . 'assets/' ),
			'version'             => CRESCO_LAYER_VERSION,
			'elementorVersion'    => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementorProVersion' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'fidelityPolicy'      => FidelityPolicy::config(),
			'safeMode'            => false,
			'bootstrap'           => [
				'mode'                   => 'safe-lazy',
				'elementorReadyTimeoutMs' => 8000,
			],
		] );
	}

	public function enqueue_admin_clipboard_guard( string $hook ): void {
		// Clipboard guard remains available on the Cresco admin screen, but it no longer joins the
		// Elementor startup path. The external exchange uses normal file download/upload there.
		if ( 'elementor_page_cresco-layer' !== $hook ) { return; }
		wp_enqueue_script( 'cresco-layer-clipboard-guard', CRESCO_LAYER_URL . 'assets/clipboard-guard.js', [], CRESCO_LAYER_VERSION, true );
	}

	public function enqueue_editor_assets_fallback(): void {
		if ( $this->is_safe_mode() || ! $this->is_elementor_editor_request() ) { return; }
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

	private function is_safe_mode(): bool {
		if ( ! isset( $_GET['cresco_safe'] ) ) { return false; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only rescue flag.
		$value = strtolower( trim( sanitize_text_field( wp_unslash( (string) $_GET['cresco_safe'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only rescue flag.
		return in_array( $value, [ '1', 'true', 'yes', 'on' ], true );
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
