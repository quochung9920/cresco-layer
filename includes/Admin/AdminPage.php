<?php
namespace CrescoLayer\Admin;

final class AdminPage {
	private string $hook = '';
	private ?array $documents = null;

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
			'elementorEditTemplate' => esc_url_raw( admin_url( 'post.php?post=__ID__&action=elementor' ) ),
		] );
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_inline_script( 'cresco-layer-admin', $this->snapshot_inline_script(), 'after' );
		}
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) { return; }
		$documents  = $this->documents();
		$can_manage = current_user_can( 'manage_options' );
		$elementor  = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null;
		$pro        = defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null;
		?>
		<div class="wrap cresco-layer-admin" id="cresco-layer-app">
			<div class="cresco-layer-admin__hero">
				<div class="cresco-layer-admin__hero-main">
					<p class="cresco-layer-eyebrow"><?php echo esc_html__( 'Elementor intelligence layer', 'cresco-layer' ); ?></p>
					<h1><?php echo esc_html__( 'Cresco Layer', 'cresco-layer' ); ?></h1>
					<p class="cresco-layer-admin__lede"><?php echo esc_html__( 'Export a context-resolved AI-safe Elementor package, inspect the live Elementor runtime, review a validated patch, then apply it through Elementor without giving an AI direct database access.', 'cresco-layer' ); ?></p>
					<div class="cresco-layer-hero-meta">
						<span class="cresco-layer-chip cresco-layer-version">v<?php echo esc_html( CRESCO_LAYER_VERSION ); ?></span>
						<span class="cresco-layer-chip <?php echo $elementor ? 'is-positive' : 'is-muted'; ?>">
							<?php echo $elementor ? esc_html( sprintf( __( 'Elementor %s', 'cresco-layer' ), $elementor ) ) : esc_html__( 'Elementor not detected', 'cresco-layer' ); ?>
						</span>
						<span class="cresco-layer-chip <?php echo $pro ? 'is-positive' : 'is-muted'; ?>">
							<?php echo $pro ? esc_html( sprintf( __( 'Pro %s', 'cresco-layer' ), $pro ) ) : esc_html__( 'Pro not detected', 'cresco-layer' ); ?>
						</span>
						<span class="cresco-layer-chip is-muted"><?php echo esc_html( sprintf( _n( '%d Elementor document', '%d Elementor documents', count( $documents ), 'cresco-layer' ), count( $documents ) ) ); ?></span>
					</div>
				</div>
				<div class="cresco-layer-admin__hero-side">
					<button type="button" class="cresco-layer-theme-toggle" id="cresco-layer-theme-toggle" aria-pressed="false">
						<span class="cresco-layer-theme-toggle__icon" aria-hidden="true"></span>
						<span class="cresco-layer-theme-toggle__label"><?php echo esc_html__( 'Dark mode', 'cresco-layer' ); ?></span>
					</button>
				</div>
			</div>

			<ol class="cresco-layer-steps" aria-label="<?php echo esc_attr__( 'AI exchange workflow', 'cresco-layer' ); ?>">
				<li class="cresco-layer-step"><span class="cresco-layer-step__num">1</span><span class="cresco-layer-step__text"><strong><?php echo esc_html__( 'Export', 'cresco-layer' ); ?></strong><small><?php echo esc_html__( 'Build an AI-safe package', 'cresco-layer' ); ?></small></span></li>
				<li class="cresco-layer-step"><span class="cresco-layer-step__num">2</span><span class="cresco-layer-step__text"><strong><?php echo esc_html__( 'Hand to AI', 'cresco-layer' ); ?></strong><small><?php echo esc_html__( 'Any model, no DB access', 'cresco-layer' ); ?></small></span></li>
				<li class="cresco-layer-step"><span class="cresco-layer-step__num">3</span><span class="cresco-layer-step__text"><strong><?php echo esc_html__( 'Validate', 'cresco-layer' ); ?></strong><small><?php echo esc_html__( 'Preview the returned patch', 'cresco-layer' ); ?></small></span></li>
				<li class="cresco-layer-step"><span class="cresco-layer-step__num">4</span><span class="cresco-layer-step__text"><strong><?php echo esc_html__( 'Apply', 'cresco-layer' ); ?></strong><small><?php echo esc_html__( 'Through Elementor, never publish', 'cresco-layer' ); ?></small></span></li>
			</ol>

			<nav class="cresco-layer-tabs" role="tablist" aria-label="<?php echo esc_attr__( 'Cresco Layer sections', 'cresco-layer' ); ?>">
				<button type="button" class="cresco-layer-tab is-active" role="tab" aria-selected="true" data-cresco-tab="exchange"><?php echo esc_html__( 'AI Exchange', 'cresco-layer' ); ?></button>
				<?php if ( $can_manage ) : ?>
					<button type="button" class="cresco-layer-tab" role="tab" aria-selected="false" data-cresco-tab="site-settings"><?php echo esc_html__( 'Elementor Site Settings', 'cresco-layer' ); ?></button>
					<button type="button" class="cresco-layer-tab" role="tab" aria-selected="false" data-cresco-tab="standard"><?php echo esc_html__( 'Design Standard', 'cresco-layer' ); ?></button>
				<?php endif; ?>
				<button type="button" class="cresco-layer-tab" role="tab" aria-selected="false" data-cresco-tab="history"><?php echo esc_html__( 'History', 'cresco-layer' ); ?></button>
				<button type="button" class="cresco-layer-tab" role="tab" aria-selected="false" data-cresco-tab="inspector"><?php echo esc_html__( 'Runtime Inspector', 'cresco-layer' ); ?></button>
			</nav>

			<div class="cresco-layer-tab-panel" data-cresco-tab-panel="exchange" role="tabpanel">
				<div class="cresco-layer-grid">
					<section class="cresco-layer-card">
						<h2><?php echo esc_html__( '1. Choose document', 'cresco-layer' ); ?></h2>
						<label for="cresco-layer-document"><?php echo esc_html__( 'Elementor document', 'cresco-layer' ); ?></label>
						<select id="cresco-layer-document"></select>
						<p class="cresco-layer-doc-link"><a id="cresco-layer-open-editor" href="#" target="_blank" rel="noopener" hidden><?php echo esc_html__( 'Open this document in Elementor ↗', 'cresco-layer' ); ?></a></p>
						<label for="cresco-layer-context-profile"><?php echo esc_html__( 'AI context profile', 'cresco-layer' ); ?></label>
						<select id="cresco-layer-context-profile">
							<option value="smart" selected><?php echo esc_html__( 'Smart — relevant controls + global context', 'cresco-layer' ); ?></option>
							<option value="full"><?php echo esc_html__( 'Full — detailed controls for every registered type', 'cresco-layer' ); ?></option>
						</select>
						<div class="cresco-layer-actions">
							<button class="button button-primary" id="cresco-layer-export"><?php echo esc_html__( 'Export for AI', 'cresco-layer' ); ?></button>
							<button class="button" id="cresco-layer-audit"><?php echo esc_html__( 'Run audit', 'cresco-layer' ); ?></button>
							<button class="button" id="cresco-layer-copy-package" disabled><?php echo esc_html__( 'Copy package', 'cresco-layer' ); ?></button>
							<button class="button" id="cresco-layer-copy-instructions" disabled><?php echo esc_html__( 'Copy AI instructions', 'cresco-layer' ); ?></button>
						</div>
						<p class="description"><?php echo esc_html__( 'After exporting, use “Copy AI instructions” to put the scope-aware briefing on your clipboard and paste it into your AI chat together with the downloaded package.', 'cresco-layer' ); ?></p>
						<p class="description"><?php echo esc_html__( 'Smart is recommended: it keeps the full registered type index but expands only controls relevant to the task, plus Site Kit, breakpoints, Dynamic Tags and capability coverage. Use Full only when an AI must choose freely from every registered Elementor type.', 'cresco-layer' ); ?></p>
					</section>

					<section class="cresco-layer-card">
						<h2><?php echo esc_html__( '2. Import AI patch', 'cresco-layer' ); ?></h2>
						<div class="cresco-layer-admin-drop" id="cresco-layer-patch-drop" role="button" tabindex="0" aria-label="<?php echo esc_attr__( 'Load a patch JSON file', 'cresco-layer' ); ?>">
							<span class="cresco-layer-admin-drop__icon" aria-hidden="true">⇪</span>
							<span class="cresco-layer-admin-drop__text"><strong><?php echo esc_html__( 'Drop a .json patch here', 'cresco-layer' ); ?></strong> <?php echo esc_html__( 'or click to browse', 'cresco-layer' ); ?></span>
							<input type="file" id="cresco-layer-patch-file" accept=".json,application/json" hidden>
						</div>
						<label for="cresco-layer-patch"><?php echo esc_html__( 'cresco-layer-patch/v1 JSON', 'cresco-layer' ); ?></label>
						<textarea id="cresco-layer-patch" rows="12" spellcheck="false" placeholder='{"schema":"cresco-layer-patch/v1",...}'></textarea>
						<div class="cresco-layer-patch-meta">
							<span class="cresco-layer-chip is-muted" id="cresco-layer-patch-state"><?php echo esc_html__( 'Empty', 'cresco-layer' ); ?></span>
							<span class="cresco-layer-patch-hint" id="cresco-layer-patch-hint"><?php echo esc_html__( 'Tip: press Ctrl+Enter to validate', 'cresco-layer' ); ?></span>
						</div>
						<div class="cresco-layer-actions">
							<button class="button" id="cresco-layer-preview"><?php echo esc_html__( 'Validate & Preview', 'cresco-layer' ); ?></button>
							<button class="button button-primary" id="cresco-layer-apply" disabled><?php echo esc_html__( 'Apply reviewed patch', 'cresco-layer' ); ?></button>
						</div>
						<p class="description"><?php echo esc_html__( 'Applying changes the Elementor document but does not publish it. Review the page in Elementor and use Elementor Update/Publish as normal.', 'cresco-layer' ); ?></p>
					</section>
				</div>

				<section class="cresco-layer-card cresco-layer-card--result">
					<div class="cresco-layer-result-head">
						<h2><?php echo esc_html__( 'Review', 'cresco-layer' ); ?></h2>
						<span id="cresco-layer-status" aria-live="polite"></span>
					</div>
					<div id="cresco-layer-result" class="cresco-layer-result" aria-live="polite">
						<div class="cresco-layer-empty">
							<span class="cresco-layer-empty__icon" aria-hidden="true">◎</span>
							<p><?php echo esc_html__( 'No audit or patch preview yet.', 'cresco-layer' ); ?></p>
							<p class="description"><?php echo esc_html__( 'Run an audit or validate a patch to see scores, metrics and semantic warnings here.', 'cresco-layer' ); ?></p>
						</div>
					</div>
				</section>
			</div>

			<?php if ( $can_manage ) : ?>
				<div class="cresco-layer-tab-panel" data-cresco-tab-panel="site-settings" role="tabpanel" hidden>
					<section class="cresco-layer-card">
						<div class="cresco-layer-result-head">
							<div>
								<p class="cresco-layer-eyebrow"><?php echo esc_html__( 'Import / sync console', 'cresco-layer' ); ?></p>
								<h2><?php echo esc_html__( 'Elementor Global Settings', 'cresco-layer' ); ?></h2>
							</div>
							<span id="cresco-layer-ss-status" aria-live="polite"></span>
						</div>
						<p class="description"><?php echo esc_html__( 'Configures Elementor’s own Site Settings from a Cresco profile, then hands control back. Colours, fonts, typography, buttons, forms and layout are still edited in Elementor → Site Settings; this screen only previews, imports and verifies.', 'cresco-layer' ); ?></p>

						<h3><?php echo esc_html__( 'Environment', 'cresco-layer' ); ?></h3>
						<div id="cresco-layer-ss-environment" class="cresco-layer-ss-env">
							<p class="description"><?php echo esc_html__( 'Checking the Elementor runtime…', 'cresco-layer' ); ?></p>
						</div>

						<h3><?php echo esc_html__( 'Profile', 'cresco-layer' ); ?></h3>
						<div class="cresco-layer-ss-controls">
							<label for="cresco-layer-ss-profile"><?php echo esc_html__( 'Profile', 'cresco-layer' ); ?>
								<select id="cresco-layer-ss-profile">
									<option value="professional-commerce">professional-commerce</option>
								</select>
							</label>
							<label for="cresco-layer-ss-mode"><?php echo esc_html__( 'Sync mode', 'cresco-layer' ); ?>
								<select id="cresco-layer-ss-mode">
									<option value="merge"><?php echo esc_html__( 'Merge — update declared values, preserve everything else', 'cresco-layer' ); ?></option>
									<option value="sync-owned"><?php echo esc_html__( 'Sync owned — only resources Cresco created', 'cresco-layer' ); ?></option>
								</select>
							</label>
						</div>

						<div class="cresco-layer-actions">
							<button class="button" id="cresco-layer-ss-preview"><?php echo esc_html__( 'Preview changes', 'cresco-layer' ); ?></button>
							<button class="button button-primary" id="cresco-layer-ss-import"><?php echo esc_html__( 'Import to Elementor', 'cresco-layer' ); ?></button>
							<button class="button" id="cresco-layer-ss-verify"><?php echo esc_html__( 'Verify current settings', 'cresco-layer' ); ?></button>
						</div>
						<p class="description"><?php echo esc_html__( 'Preview never writes. Import writes Elementor working data for the Kit and rolls back automatically if verification fails.', 'cresco-layer' ); ?></p>

						<div id="cresco-layer-ss-result" class="cresco-layer-ss-result">
							<div class="cresco-layer-empty">
								<span class="cresco-layer-empty__icon" aria-hidden="true">⇄</span>
								<p><?php echo esc_html__( 'No operation run yet.', 'cresco-layer' ); ?></p>
								<p class="description"><?php echo esc_html__( 'Start with “Preview changes” to see what would be written.', 'cresco-layer' ); ?></p>
							</div>
						</div>

						<details class="cresco-layer-ss-technical" id="cresco-layer-ss-technical" hidden>
							<summary><?php echo esc_html__( 'Show technical details', 'cresco-layer' ); ?></summary>
							<pre id="cresco-layer-ss-raw"></pre>
						</details>
					</section>
				</div>

				<div class="cresco-layer-tab-panel" data-cresco-tab-panel="standard" role="tabpanel" hidden>
					<section class="cresco-layer-card">
						<div class="cresco-layer-result-head">
							<div>
								<p class="cresco-layer-eyebrow"><?php echo esc_html__( 'Global design system', 'cresco-layer' ); ?></p>
								<h2><?php echo esc_html__( 'Design Standard for Site Settings', 'cresco-layer' ); ?></h2>
							</div>
							<span id="cresco-layer-standard-status" aria-live="polite"></span>
						</div>
						<p class="description"><?php echo esc_html__( 'Measures the active Elementor Kit — Global Colors, Global Fonts, Typography, Layout — against standards that can be checked objectively, then proposes concrete fixes. Brand colours keep their hue: a failing colour only moves in lightness until it clears WCAG AA contrast. Changes are written through the same validated patch path as an AI patch, so they appear in History and can be rolled back.', 'cresco-layer' ); ?></p>

						<nav class="cresco-layer-subtabs" role="tablist">
							<button type="button" class="cresco-layer-subtab is-active" data-cresco-standard="audit"><?php echo esc_html__( 'Audit', 'cresco-layer' ); ?></button>
							<button type="button" class="cresco-layer-subtab" data-cresco-standard="fluid"><?php echo esc_html__( 'Fluid clamp()', 'cresco-layer' ); ?></button>
							<button type="button" class="cresco-layer-subtab" data-cresco-standard="preset"><?php echo esc_html__( 'Presets', 'cresco-layer' ); ?></button>
						</nav>

						<div class="cresco-layer-actions">
							<button class="button button-primary" id="cresco-layer-standard-run"><?php echo esc_html__( 'Scan Kit', 'cresco-layer' ); ?></button>
							<button class="button" id="cresco-layer-standard-preview" disabled><?php echo esc_html__( 'Preview changes', 'cresco-layer' ); ?></button>
							<button class="button button-primary" id="cresco-layer-standard-apply" disabled><?php echo esc_html__( 'Apply to Kit', 'cresco-layer' ); ?></button>
						</div>
						<p class="description"><?php echo esc_html__( 'Applying writes Elementor working data for the Kit. Open Elementor → Site Settings and use its own Save to make it live.', 'cresco-layer' ); ?></p>

						<div id="cresco-layer-standard-result" class="cresco-layer-standard-result">
							<div class="cresco-layer-empty">
								<span class="cresco-layer-empty__icon" aria-hidden="true">◈</span>
								<p><?php echo esc_html__( 'Kit not scanned yet.', 'cresco-layer' ); ?></p>
								<p class="description"><?php echo esc_html__( 'Click “Scan Kit” to measure the current Site Settings.', 'cresco-layer' ); ?></p>
							</div>
						</div>
					</section>
				</div>
			<?php endif; ?>

			<div class="cresco-layer-tab-panel" data-cresco-tab-panel="history" role="tabpanel" hidden>
				<section class="cresco-layer-card">
					<div class="cresco-layer-result-head">
						<div>
							<p class="cresco-layer-eyebrow"><?php echo esc_html__( 'Applied change log', 'cresco-layer' ); ?></p>
							<h2><?php echo esc_html__( 'Patch history & rollback', 'cresco-layer' ); ?></h2>
						</div>
						<span id="cresco-layer-history-status" aria-live="polite"></span>
					</div>
					<p class="description"><?php echo esc_html__( 'Every applied patch stores the Elementor working document exactly as it was beforehand, so a change can be undone without digging through WordPress revisions. Rolling back is itself recorded, so it can be undone too. Restoring writes to Elementor working data only — it never publishes.', 'cresco-layer' ); ?></p>
					<div class="cresco-layer-actions">
						<button class="button" id="cresco-layer-history-refresh"><?php echo esc_html__( 'Refresh history', 'cresco-layer' ); ?></button>
					</div>
					<div id="cresco-layer-history-result" class="cresco-layer-history">
						<div class="cresco-layer-empty">
							<span class="cresco-layer-empty__icon" aria-hidden="true">⟲</span>
							<p><?php echo esc_html__( 'No history loaded yet.', 'cresco-layer' ); ?></p>
							<p class="description"><?php echo esc_html__( 'Choose a document in the AI Exchange tab, then refresh.', 'cresco-layer' ); ?></p>
						</div>
					</div>
				</section>
			</div>

			<div class="cresco-layer-tab-panel" data-cresco-tab-panel="inspector" role="tabpanel" hidden>
				<section class="cresco-layer-card cresco-layer-card--catalog">
					<div class="cresco-layer-result-head">
						<div>
							<p class="cresco-layer-eyebrow"><?php echo esc_html__( 'Live runtime inspector', 'cresco-layer' ); ?></p>
							<h2><?php echo esc_html__( 'Elementor Configuration & Full Runtime Snapshot', 'cresco-layer' ); ?></h2>
						</div>
						<span id="cresco-layer-catalog-status" aria-live="polite"></span>
					</div>
					<p class="description"><?php echo esc_html__( 'Load the lightweight widget/element catalog first. Open any entry to fetch its controls on demand. Administrators can also download a full cresco-elementor-snapshot/v1 containing normalized and raw serializable Elementor Core/Pro settings, Site Kit data, features, breakpoints, Dynamic Tags, runtime modules, Elementor-owned documents/templates/popups/Theme Builder records and every registered widget/element capability.', 'cresco-layer' ); ?></p>
					<div class="cresco-layer-catalog-toolbar">
						<div class="cresco-layer-actions">
							<button class="button button-primary" id="cresco-layer-catalog-load"><?php echo esc_html__( 'Load Elementor catalog', 'cresco-layer' ); ?></button>
							<button class="button" id="cresco-layer-catalog-download" disabled><?php echo esc_html__( 'Download controls JSON', 'cresco-layer' ); ?></button>
							<?php if ( $can_manage ) : ?>
								<button class="button" id="cresco-layer-snapshot-download" disabled><?php echo esc_html__( 'Download full Elementor snapshot', 'cresco-layer' ); ?></button>
							<?php endif; ?>
						</div>
						<label class="cresco-layer-catalog-search" for="cresco-layer-catalog-query">
							<span class="screen-reader-text"><?php echo esc_html__( 'Search Elementor widgets and elements', 'cresco-layer' ); ?></span>
							<input type="search" id="cresco-layer-catalog-query" placeholder="<?php echo esc_attr__( 'Search widget or element…', 'cresco-layer' ); ?>" disabled>
						</label>
					</div>
					<?php if ( $can_manage ) : ?>
						<p class="description"><?php echo esc_html__( 'Full snapshot export is administrator-only. Secrets, credentials, API keys, tokens, nonces and common token-bearing URL values are redacted; unsupported runtime objects/resources/callbacks are omitted and listed in the snapshot coverage report. Coverage is partial if any request or scanner reports a partial/failed result.', 'cresco-layer' ); ?></p>
					<?php endif; ?>
					<div id="cresco-layer-catalog-summary" class="cresco-layer-catalog-summary" hidden></div>
					<div id="cresco-layer-catalog-result" class="cresco-layer-catalog-result">
						<div class="cresco-layer-empty">
							<span class="cresco-layer-empty__icon" aria-hidden="true">▤</span>
							<p><?php echo esc_html__( 'Catalog not loaded yet.', 'cresco-layer' ); ?></p>
							<p class="description"><?php echo esc_html__( 'Click “Load Elementor catalog” to inspect the current runtime configuration.', 'cresco-layer' ); ?></p>
						</div>
					</div>
				</section>
			</div>

			<div class="cresco-layer-toasts" id="cresco-layer-toasts"></div>
		</div>
		<?php
	}

	/** Both enqueue_assets() and render() need this list on the same request; query it once. */
	private function documents(): array {
		if ( null !== $this->documents ) { return $this->documents; }
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
		$this->documents = $result;
		return $result;
	}

	private function snapshot_inline_script(): string {
		return <<<'JS'
(function(){
	'use strict';
	var config=window.crescoLayerAdmin||{};
	if(!config.canManageSnapshot)return;
	var button=document.getElementById('cresco-layer-snapshot-download');
	var status=document.getElementById('cresco-layer-catalog-status');
	var catalogLoad=document.getElementById('cresco-layer-catalog-load');
	var catalogDownload=document.getElementById('cresco-layer-catalog-download');
	if(!button)return;
	button.disabled=false;

	function setStatus(text,tone){if(!status)return;status.textContent=text||'';status.className=tone?'is-'+tone:'';}
	function endpoint(path){return String(config.restRoot||'').replace(/\/$/,'')+path;}
	function request(path){
		return fetch(endpoint(path),{headers:{'X-WP-Nonce':config.nonce,'Content-Type':'application/json'}}).then(function(response){
			return response.text().then(function(text){
				var body={};
				if(text){try{body=JSON.parse(text);}catch(e){body={};}}
				if(!response.ok){throw new Error(body&&body.message?body.message:'Snapshot request failed ('+response.status+').');}
				return body;
			});
		});
	}
	function download(data){
		var blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'});
		var url=URL.createObjectURL(blob);
		var a=document.createElement('a');
		a.href=url;a.download='cresco-layer-elementor-snapshot.json';document.body.appendChild(a);a.click();a.remove();
		setTimeout(function(){URL.revokeObjectURL(url);},1000);
	}
	function sequential(items,worker){return items.reduce(function(chain,item,index){return chain.then(function(){return worker(item,index);});},Promise.resolve());}
	function message(error){return error&&error.message?error.message:String(error);}
	function internalStatus(data){return data&&data.coverage&&typeof data.coverage.status==='string'?data.coverage.status:'complete';}
	function isIncomplete(data){var value=internalStatus(data);return value==='partial'||value==='failed'||value==='unavailable';}

	button.addEventListener('click',function(){
		button.disabled=true;if(catalogLoad)catalogLoad.disabled=true;if(catalogDownload)catalogDownload.disabled=true;
		setStatus('Preparing full Elementor snapshot index…','busy');
		request('/elementor-snapshot').then(function(index){
			var plan=index.downloadPlan||{};
			var sections=Array.isArray(plan.sections)?plan.sections:[];
			var widgets=Array.isArray(plan.widgets)?plan.widgets:[];
			var elements=Array.isArray(plan.elements)?plan.elements:[];
			var recordIds=Array.isArray(plan.recordIds)?plan.recordIds:[];
			var total=sections.length+widgets.length+elements.length+recordIds.length;
			var completed=0;
			var snapshot={
				schema:index.schema||'cresco-elementor-snapshot/v1',
				generatedAt:new Date().toISOString(),
				index:index,
				sections:{},
				registries:{widgets:{},elements:{}},
				records:{},
				downloadErrors:[],
				coverage:{sections:{total:sections.length,scanned:0,failed:0,partial:0},widgets:{total:widgets.length,scanned:0,failed:0,partial:0},elements:{total:elements.length,scanned:0,failed:0,partial:0},records:{total:recordIds.length,scanned:0,failed:0,partial:0}}
			};
			function progress(label){completed++;setStatus('Building full Elementor snapshot '+completed+'/'+Math.max(total,1)+' · '+label,'busy');}
			function failed(bucket,id,error){snapshot.coverage[bucket].failed++;snapshot.downloadErrors.push({bucket:bucket,id:String(id),message:message(error)});}
			function scanned(bucket,data){snapshot.coverage[bucket].scanned++;if(isIncomplete(data)){snapshot.coverage[bucket].partial++;snapshot.downloadErrors.push({bucket:bucket,id:String(data&&data.section?data.section:''),message:'Scanner coverage: '+internalStatus(data)});}}

			return sequential(sections,function(slug){
				return request('/elementor-snapshot/section/'+encodeURIComponent(slug)).then(function(data){snapshot.sections[slug]=data;scanned('sections',data);}).catch(function(error){failed('sections',slug,error);snapshot.sections[slug]={coverage:{status:'failed'},scanErrors:[{stage:'section-request',message:message(error)}]};}).then(function(){progress('section '+slug);});
			}).then(function(){
				return sequential(widgets,function(name){
					return request('/elementor-snapshot/widget/'+encodeURIComponent(name)).then(function(data){snapshot.registries.widgets[name]=data;scanned('widgets',data);}).catch(function(error){failed('widgets',name,error);snapshot.registries.widgets[name]={coverage:{status:'failed'},scanErrors:[{stage:'registry-request',message:message(error)}]};}).then(function(){progress('widget '+name);});
				});
			}).then(function(){
				return sequential(elements,function(name){
					return request('/elementor-snapshot/element/'+encodeURIComponent(name)).then(function(data){snapshot.registries.elements[name]=data;scanned('elements',data);}).catch(function(error){failed('elements',name,error);snapshot.registries.elements[name]={coverage:{status:'failed'},scanErrors:[{stage:'registry-request',message:message(error)}]};}).then(function(){progress('element '+name);});
				});
			}).then(function(){
				return sequential(recordIds,function(id){
					return request('/elementor-snapshot/record/'+encodeURIComponent(String(id))).then(function(data){snapshot.records[String(id)]=data;scanned('records',data);}).catch(function(error){failed('records',id,error);snapshot.records[String(id)]={coverage:{status:'failed'},scanErrors:[{stage:'record-request',message:message(error)}]};}).then(function(){progress('record #'+id);});
				});
			}).then(function(){
				Object.keys(snapshot.coverage).forEach(function(key){var row=snapshot.coverage[key];row.status=row.failed>0?(row.scanned>0?'partial':'failed'):(row.partial>0?'partial':'complete');});
				var buckets=['sections','widgets','elements','records'];
				snapshot.coverage.status=buckets.some(function(key){return snapshot.coverage[key].status!=='complete';})?'partial':'complete';
				return snapshot;
			});
		}).then(function(snapshot){
			download(snapshot);
			setStatus('Full Elementor snapshot built. Coverage: '+snapshot.coverage.status+'. Issues: '+snapshot.downloadErrors.length+'.',snapshot.coverage.status==='complete'?'success':'error');
		}).catch(function(error){setStatus(message(error),'error');}).finally(function(){
			button.disabled=false;if(catalogLoad)catalogLoad.disabled=false;if(catalogDownload&&window.crescoLayerAdmin)catalogDownload.disabled=false;
		});
	});
})();
JS;
	}
}
