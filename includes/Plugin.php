<?php
namespace CrescoLayer;

use CrescoLayer\Admin\AdminPage;
use CrescoLayer\AI\PackageBuilder;
use CrescoLayer\AI\PatchApplier;
use CrescoLayer\AI\PatchValidator;
use CrescoLayer\Audit\Auditor;
use CrescoLayer\Elementor\DynamicTagRegistry;
use CrescoLayer\Elementor\ProRegistry;
use CrescoLayer\Elementor\WidgetRegistry;
use CrescoLayer\REST\Controller;
use CrescoLayer\Support\Assets;
use CrescoLayer\Support\Requirements;

final class Plugin {
	private static ?self $instance = null;
	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

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
		$applier    = new PatchApplier( $validator, $auditor );
		$controller = new Controller( $builder, $validator, $applier, $auditor );
		$admin      = new AdminPage();

		add_action( 'rest_api_init', [ $controller, 'register_routes' ] );
		add_action( 'admin_menu', [ $admin, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_assets' ] );
		add_action( 'elementor/editor/after_save', [ $auditor, 'invalidate_post_cache' ], 10, 2 );
	}
}
