<?php
namespace CrescoLayer;

use CrescoLayer\Admin\AdminPage;
use CrescoLayer\AI\PackageBuilder;
use CrescoLayer\AI\PatchApplier;
use CrescoLayer\AI\PatchValidator;
use CrescoLayer\AI\SemanticPatchGuard;
use CrescoLayer\Audit\Auditor;
use CrescoLayer\Elementor\ConfigurationCatalog;
use CrescoLayer\Elementor\DynamicTagRegistry;
use CrescoLayer\Elementor\ProRegistry;
use CrescoLayer\Elementor\RuntimeSnapshotCoordinator;
use CrescoLayer\Elementor\WidgetRegistry;
use CrescoLayer\LocalAI\AdminIntegration as LocalAIAdminIntegration;
use CrescoLayer\LocalAI\Analyzer as LocalAIAnalyzer;
use CrescoLayer\LocalAI\ContextCompiler as LocalAIContextCompiler;
use CrescoLayer\LocalAI\Manager as LocalAIManager;
use CrescoLayer\LocalAI\PlanValidator as LocalAIPlanValidator;
use CrescoLayer\LocalAI\ProviderManager as LocalAIProviderManager;
use CrescoLayer\LocalAI\RESTController as LocalAIRESTController;
use CrescoLayer\LocalAI\Settings as LocalAISettings;
use CrescoLayer\REST\Controller;
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
		add_action( 'elementor/widgets/register', [ new WidgetRegistry(), 'register' ] );
		add_action( 'elementor/dynamic_tags/register', [ new DynamicTagRegistry(), 'register' ] );

		if ( $requirements->is_elementor_pro_available() ) {
			( new ProRegistry() )->register_hooks();
		} else {
			add_action( 'admin_notices', [ $requirements, 'render_pro_notice' ] );
		}

		$auditor    = new Auditor();
		$builder    = new PackageBuilder( $auditor );
		$validator  = new PatchValidator();
		$semantic   = new SemanticPatchGuard();
		$catalog    = new ConfigurationCatalog();
		$snapshot   = new RuntimeSnapshotCoordinator();
		$skills     = new WidgetSkillRuntime( $catalog );
		$local_settings = new LocalAISettings();
		$local_providers = new LocalAIProviderManager( $local_settings );
		$local_context = new LocalAIContextCompiler( $skills );
		$local_plan_validator = new LocalAIPlanValidator( $skills );
		$local_analyzer = new LocalAIAnalyzer( $local_context, $local_providers, $local_plan_validator );
		$local_ai   = new LocalAIManager( $local_settings, $local_providers );
		$applier    = new PatchApplier( $validator, $auditor );
		$controller = new Controller( $builder, $validator, $semantic, $catalog, $snapshot, $skills, $applier, $auditor );
		$local_rest = new LocalAIRESTController( $local_ai, $local_analyzer );
		$admin      = new AdminPage();

		add_action( 'rest_api_init', [ $controller, 'register_routes' ] );
		add_action( 'rest_api_init', [ $local_rest, 'register_routes' ] );
		add_action( 'admin_menu', [ $admin, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_assets' ] );
		( new LocalAIAdminIntegration() )->register_hooks();
		add_action( 'elementor/editor/after_save', [ $auditor, 'invalidate_post_cache' ], 10, 2 );
	}
}
