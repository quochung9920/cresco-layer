<?php
namespace CrescoLayer\Admin;

final class AdminPage {
	private string $hook = '';

	public function register_menu(): void {
		$this->hook = (string) add_submenu_page(
			'elementor',
			esc_html__( 'Cresco Layer', 'cresco-layer' ),
			esc_html__( 'Cresco Layer', 'cresco-layer' ),
			'edit_posts',
			'cresco-layer',
			[ $this, 'render' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook && 'elementor_page_cresco-layer' !== $hook ) { return; }
		wp_enqueue_style( 'cresco-layer-admin', CRESCO_LAYER_URL . 'assets/admin.css', [], CRESCO_LAYER_VERSION );
		wp_enqueue_script( 'cresco-layer-admin', CRESCO_LAYER_URL . 'assets/admin.js', [ 'wp-api-fetch' ], CRESCO_LAYER_VERSION, true );
		wp_localize_script( 'cresco-layer-admin', 'crescoLayerAdmin', [
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'restRoot' => esc_url_raw( rest_url( 'cresco-layer/v1' ) ),
			'documents' => $this->documents(),
			'canManageSnapshot' => current_user_can( 'manage_options' ),
		] );
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) { return; }
		?>
		<div class="wrap cresco-layer-admin">
			<div class="cresco-layer-admin__hero">
				<div>
					<p class="cresco-layer-eyebrow"><?php echo esc_html__( 'Elementor intelligence layer', 'cresco-layer' ); ?></p>
					<h1><?php echo esc_html__( 'Cresco Layer', 'cresco-layer' ); ?></h1>
					<p><?php echo esc_html__( 'Export an AI-safe Elementor design package, inspect the live Elementor runtime, review a validated patch, then apply it through Elementor without giving an AI direct database access.', 'cresco-layer' ); ?></p>
				</div>
				<span class="cresco-layer-version">v<?php echo esc_html( CRESCO_LAYER_VERSION ); ?></span>
			</div>

			<div class="cresco-layer-grid">
				<section class="cresco-layer-card">
					<h2><?php echo esc_html__( '1. Choose document', 'cresco-layer' ); ?></h2>
					<label for="cresco-layer-document"><?php echo esc_html__( 'Elementor document', 'cresco-layer' ); ?></label>
					<select id="cresco-layer-document"></select>
					<div class="cresco-layer-actions">
						<button class="button button-primary" id="cresco-layer-export"><?php echo esc_html__( 'Export for AI', 'cresco-layer' ); ?></button>
						<button class="button" id="cresco-layer-audit"><?php echo esc_html__( 'Run audit', 'cresco-layer' ); ?></button>
					</div>
					<p class="description"><?php echo esc_html__( 'The package includes page content, page settings, Elementor design-system context, widget capabilities, an audit and AI instructions. Sensitive keys are redacted.', 'cresco-layer' ); ?></p>
				</section>

				<section class="cresco-layer-card">
					<h2><?php echo esc_html__( '2. Import AI patch', 'cresco-layer' ); ?></h2>
					<label for="cresco-layer-patch"><?php echo esc_html__( 'cresco-layer-patch/v1 JSON', 'cresco-layer' ); ?></label>
					<textarea id="cresco-layer-patch" rows="18" spellcheck="false" placeholder='{"schema":"cresco-layer-patch/v1",...}'></textarea>
					<div class="cresco-layer-actions">
						<button class="button" id="cresco-layer-preview"><?php echo esc_html__( 'Validate & Preview', 'cresco-layer' ); ?></button>
						<button class="button button-primary" id="cresco-layer-apply" disabled><?php echo esc_html__( 'Apply reviewed patch', 'cresco-layer' ); ?></button>
					</div>
					<p class="description"><?php echo esc_html__( 'Applying changes the Elementor document but does not publish it. Review the page in Elementor and use Elementor Update/Publish as normal.', 'cresco-layer' ); ?></p>
				</section>
			</div>

			<section class="cresco-layer-card cresco-layer-card--catalog">
				<div class="cresco-layer-result-head">
					<div>
						<p class="cresco-layer-eyebrow"><?php echo esc_html__( 'Live runtime inspector', 'cresco-layer' ); ?></p>
						<h2><?php echo esc_html__( 'Elementor Configuration & Full Runtime Snapshot', 'cresco-layer' ); ?></h2>
					</div>
					<span id="cresco-layer-catalog-status" aria-live="polite"></span>
				</div>
				<p class="description"><?php echo esc_html__( 'Load the lightweight widget/element catalog first. Open any entry to fetch its controls on demand. Administrators can also download a full cresco-elementor-snapshot/v1 containing normalized and raw serializable Elementor Core/Pro settings, Site Kit data, features, breakpoints, Dynamic Tags, runtime registries, Elementor-owned documents/templates/popups/Theme Builder records and every registered widget/element capability.', 'cresco-layer' ); ?></p>
				<div class="cresco-layer-catalog-toolbar">
					<div class="cresco-layer-actions">
						<button class="button button-primary" id="cresco-layer-catalog-load"><?php echo esc_html__( 'Load Elementor catalog', 'cresco-layer' ); ?></button>
						<button class="button" id="cresco-layer-catalog-download" disabled><?php echo esc_html__( 'Download controls JSON', 'cresco-layer' ); ?></button>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<button class="button" id="cresco-layer-snapshot-download" disabled><?php echo esc_html__( 'Download full Elementor snapshot', 'cresco-layer' ); ?></button>
						<?php endif; ?>
					</div>
					<label class="cresco-layer-catalog-search" for="cresco-layer-catalog-query">
						<span class="screen-reader-text"><?php echo esc_html__( 'Search Elementor widgets and elements', 'cresco-layer' ); ?></span>
						<input type="search" id="cresco-layer-catalog-query" placeholder="<?php echo esc_attr__( 'Search widget or element…', 'cresco-layer' ); ?>" disabled>
					</label>
				</div>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<p class="description"><?php echo esc_html__( 'Full snapshot export is administrator-only. Secrets, credentials, API keys, tokens, nonces and common token-bearing URL values are redacted; unsupported runtime objects/resources/callbacks are omitted and listed in the snapshot coverage report.', 'cresco-layer' ); ?></p>
				<?php endif; ?>
				<div id="cresco-layer-catalog-summary" class="cresco-layer-catalog-summary" hidden></div>
				<div id="cresco-layer-catalog-result" class="cresco-layer-catalog-result">
					<p><?php echo esc_html__( 'Catalog not loaded yet. Click “Load Elementor catalog” to inspect the current runtime configuration.', 'cresco-layer' ); ?></p>
				</div>
			</section>

			<section class="cresco-layer-card cresco-layer-card--result">
				<div class="cresco-layer-result-head">
					<h2><?php echo esc_html__( 'Review', 'cresco-layer' ); ?></h2>
					<span id="cresco-layer-status" aria-live="polite"></span>
				</div>
				<div id="cresco-layer-result" class="cresco-layer-result" aria-live="polite"><p><?php echo esc_html__( 'No audit or patch preview yet.', 'cresco-layer' ); ?></p></div>
			</section>
		</div>
		<?php
	}

	private function documents(): array {
		$query = new \WP_Query( [
			'post_type' => 'any',
			'post_status' => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => 100,
			'orderby' => 'modified',
			'order' => 'DESC',
			'meta_query' => [ [ 'key' => '_elementor_edit_mode', 'value' => 'builder' ] ],
			'no_found_rows' => true,
		] );
		$result = [];
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) { continue; }
			$result[] = [ 'id' => $post->ID, 'title' => $post->post_title ?: sprintf( __( '(no title) #%d', 'cresco-layer' ), $post->ID ), 'type' => $post->post_type, 'modified' => $post->post_modified_gmt ];
		}
		return $result;
	}
}
