<?php
namespace CrescoLayer;

use CrescoLayer\Admin\AdminPage;
use CrescoLayer\AI\ExchangeSafetyGuard;
use CrescoLayer\AI\ExportTargetGate;
use CrescoLayer\AI\ExportTargetResolver;
use CrescoLayer\AI\PackageBuilder;
use CrescoLayer\AI\PatchApplier;
use CrescoLayer\AI\PatchValidator;
use CrescoLayer\AI\SemanticPatchGuard;
use CrescoLayer\Audit\Auditor;
use CrescoLayer\DesignSystem\StandardController;
use CrescoLayer\Diagnostics\ExportDiagnostics;
use CrescoLayer\Elementor\ConfigurationCatalog;
use CrescoLayer\Elementor\DynamicTagRegistry;
use CrescoLayer\Elementor\ProRegistry;
use CrescoLayer\Elementor\RuntimeSnapshotCoordinator;
use CrescoLayer\Elementor\WidgetRegistry;
use CrescoLayer\REST\Controller;
use CrescoLayer\REST\ExportTargetSyncController;
use CrescoLayer\SiteSettings\RESTController as SiteSettingsRESTController;
use CrescoLayer\Skills\WidgetSkillRuntime;
use CrescoLayer\Support\Assets;
use CrescoLayer\Support\Requirements;

final class Plugin {
	private static ?self $instance = null;
	private bool $booted = false;

	public static function instance(): self { return self::$instance ??= new self(); }
	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) { return; }
		$this->booted = true;

		$requirements = new Requirements();
		if ( ! $requirements->is_elementor_available() ) {
			add_action( 'admin_notices', [ $requirements, 'render_elementor_notice' ] );
			return;
		}

		( new Assets() )->register_hooks();
		( new ExportDiagnostics() )->register_hooks();
		( new ExchangeSafetyGuard() )->register_hooks();
		add_action( 'elementor/widgets/register', [ new WidgetRegistry(), 'register' ] );
		add_action( 'elementor/dynamic_tags/register', [ new DynamicTagRegistry(), 'register' ] );

		if ( $requirements->is_elementor_pro_available() ) {
			( new ProRegistry() )->register_hooks();
		} else {
			add_action( 'admin_notices', [ $requirements, 'render_pro_notice' ] );
		}

		$auditor         = new Auditor();
		$builder         = new PackageBuilder( $auditor );
		$validator       = new PatchValidator();
		$semantic        = new SemanticPatchGuard();
		$catalog         = new ConfigurationCatalog();
		$snapshot        = new RuntimeSnapshotCoordinator();
		$skills          = new WidgetSkillRuntime( $catalog );
		$applier         = new PatchApplier( $validator, $auditor );
		$target_resolver = new ExportTargetResolver();
		$target_gate     = new ExportTargetGate( $target_resolver );
		$controller      = new Controller( $builder, $validator, $semantic, $catalog, $snapshot, $skills, $applier, $auditor );
		$target_sync     = new ExportTargetSyncController( $target_resolver );
		$standard        = new StandardController( $applier );
		$admin           = new AdminPage();

		$target_gate->register_hooks();
		add_action( 'rest_api_init', [ $controller, 'register_routes' ] );
		add_action( 'rest_api_init', [ $target_sync, 'register_routes' ] );
		add_action( 'rest_api_init', [ $standard, 'register_routes' ] );
		add_action( 'rest_api_init', [ new SiteSettingsRESTController(), 'register_routes' ] );
		add_action( 'admin_menu', [ $admin, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_assets' ] );
		add_action( 'elementor/editor/after_save', [ $auditor, 'invalidate_post_cache' ], 10, 2 );
	}
}
