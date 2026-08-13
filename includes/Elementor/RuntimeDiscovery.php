<?php
namespace CrescoLayer\Elementor;

use CrescoLayer\AI\CapabilityScanner;
use CrescoLayer\Support\SerializableSanitizer;
use Elementor\Plugin as ElementorPlugin;

final class RuntimeDiscovery {
	private CapabilityScanner $scanner;

	public function __construct( ?CapabilityScanner $scanner = null ) {
		$this->scanner = $scanner ?? new CapabilityScanner();
	}

	public function dynamic_tag_catalog(): array {
		$errors = [];
		$tags = [];
		$raw = [];
		$groups = [];
		$managerClass = '';

		try {
			$manager = ElementorPlugin::instance()->dynamic_tags ?? null;
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_tags' ) ) {
				throw new \RuntimeException( 'Elementor Dynamic Tags manager is unavailable.' );
			}
			$managerClass = get_class( $manager );
			$registered = (array) $manager->get_tags();

			foreach ( $registered as $fallbackName => $tagInfo ) {
				$instance = null;
				$className = '';
				if ( is_array( $tagInfo ) ) {
					$instance = isset( $tagInfo['instance'] ) && is_object( $tagInfo['instance'] ) ? $tagInfo['instance'] : null;
					$className = is_string( $tagInfo['class'] ?? null ) ? (string) $tagInfo['class'] : '';
				} elseif ( is_object( $tagInfo ) ) {
					$instance = $tagInfo;
					$className = get_class( $tagInfo );
				}

				if ( ! $instance ) {
					$errors[] = [ 'stage' => 'dynamic-tag-instance:' . (string) $fallbackName, 'message' => 'Registered Dynamic Tag did not expose an instance.' ];
					continue;
				}

				$name = (string) $this->safe_call( $instance, 'get_name', (string) $fallbackName, 'dynamic-tag-name', $errors );
				if ( '' === $name ) { $name = (string) $fallbackName; }
				if ( '' === $className ) { $className = get_class( $instance ); }

				$entry = [
					'name' => $name,
					'title' => wp_strip_all_tags( (string) $this->safe_call( $instance, 'get_title', $name, 'dynamic-tag-title:' . $name, $errors ) ),
					'className' => $className,
					'group' => (string) $this->safe_call( $instance, 'get_group', '', 'dynamic-tag-group:' . $name, $errors ),
					'categories' => array_values( array_map( 'strval', (array) $this->safe_call( $instance, 'get_categories', [], 'dynamic-tag-categories:' . $name, $errors ) ) ),
				];
				$editorConfig = $this->safe_call( $instance, 'get_editor_config', [], 'dynamic-tag-editor-config:' . $name, $errors );
				if ( is_array( $editorConfig ) ) { $entry['editorConfig'] = $editorConfig; }
				$tags[ $name ] = $entry;
				$raw[ $name ] = [
					'className' => $className,
					'editorConfig' => $editorConfig,
					'controls' => $this->safe_call( $instance, 'get_controls', [], 'dynamic-tag-controls:' . $name, $errors ),
				];
			}

			if ( method_exists( $manager, 'get_config' ) ) {
				$config = $manager->get_config();
				if ( is_array( $config ) && is_array( $config['groups'] ?? null ) ) { $groups = $config['groups']; }
			}
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'dynamic-tags', $error );
		}

		if ( defined( 'ELEMENTOR_PRO_VERSION' ) && 0 === count( $tags ) ) {
			$errors[] = [
				'stage' => 'dynamic-tags-empty',
				'message' => 'Elementor Pro is active but the Dynamic Tags registry is empty after registration was requested.',
			];
		}

		return [
			'managerClass' => $managerClass,
			'tags' => $tags,
			'groups' => $groups,
			'raw' => $raw,
			'count' => count( $tags ),
			'coverage' => [ 'status' => $errors ? 'partial' : 'complete', 'errors' => count( $errors ) ],
			'scanErrors' => $errors,
		];
	}

	public function module_catalog(): array {
		$errors = [];
		$core = [];
		$pro = [];
		$coreManagerClass = '';
		$proManagerClass = '';

		try {
			$plugin = ElementorPlugin::instance();
			$manager = $plugin->modules_manager ?? null;
			if ( is_object( $manager ) ) {
				$coreManagerClass = get_class( $manager );
				$core = $this->modules_from_manager( $manager, 'elementor-module', $errors );
			}
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'elementor-modules', $error );
		}

		if ( class_exists( '\\ElementorPro\\Plugin' ) ) {
			try {
				$proClass = '\\ElementorPro\\Plugin';
				$plugin = $proClass::instance();
				$manager = null;
				foreach ( [ 'modules_manager', 'modulesManager' ] as $property ) {
					if ( isset( $plugin->{$property} ) && is_object( $plugin->{$property} ) ) { $manager = $plugin->{$property}; break; }
				}
				if ( ! $manager && $this->is_public_method( $plugin, 'get_modules_manager' ) ) {
					$manager = $plugin->get_modules_manager();
				}
				if ( is_object( $manager ) ) {
					$proManagerClass = get_class( $manager );
					$pro = $this->modules_from_manager( $manager, 'elementor-pro-module', $errors );
				} else {
					$errors[] = [ 'stage' => 'elementor-pro-modules-manager', 'message' => 'Elementor Pro modules manager is unavailable.' ];
				}
			} catch ( \Throwable $error ) {
				$errors[] = $this->error( 'elementor-pro-modules', $error );
			}
		}

		return [
			'coreManagerClass' => $coreManagerClass,
			'proManagerClass' => $proManagerClass,
			'core' => $core,
			'pro' => $pro,
			'coverage' => [ 'status' => $errors ? 'partial' : 'complete', 'errors' => count( $errors ) ],
			'scanErrors' => $errors,
		];
	}

	public function dependency_map(): array {
		$activeFiles = array_values( (array) get_option( 'active_plugins', [] ) );
		if ( is_multisite() ) { $activeFiles = array_values( array_unique( array_merge( $activeFiles, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) ) ) ); }
		$signals = [
			'woocommerce' => in_array( 'woocommerce/woocommerce.php', $activeFiles, true ) || class_exists( '\\WooCommerce' ),
			'acf' => in_array( 'advanced-custom-fields/acf.php', $activeFiles, true ) || in_array( 'advanced-custom-fields-pro/acf.php', $activeFiles, true ) || class_exists( '\\ACF' ),
			'pods' => (bool) array_filter( $activeFiles, static fn( string $file ): bool => str_starts_with( $file, 'pods/' ) ),
			'toolset' => (bool) array_filter( $activeFiles, static fn( string $file ): bool => false !== stripos( $file, 'types/' ) || false !== stripos( $file, 'toolset' ) ),
		];

		$licensedFeatures = $this->pro_license_features();
		$featureDependencies = [
			'woocommerce' => [ 'woocommerce-menu-cart', 'product-single', 'product-archive', 'settings-woocommerce-pages', 'settings-woocommerce-notices', 'dynamic-tags-wc' ],
			'acf' => [ 'dynamic-tags-acf' ],
			'pods' => [ 'dynamic-tags-pods' ],
			'toolset' => [ 'dynamic-tags-toolset' ],
		];
		$conditional = [];
		foreach ( $featureDependencies as $dependency => $features ) {
			$licensed = array_values( array_intersect( $features, $licensedFeatures ) );
			if ( ! $licensed ) { continue; }
			$conditional[ $dependency ] = [
				'active' => (bool) ( $signals[ $dependency ] ?? false ),
				'licensedFeatures' => $licensed,
				'status' => ! empty( $signals[ $dependency ] ) ? 'available' : 'dependency-inactive',
			];
		}

		return [
			'activePluginFiles' => $activeFiles,
			'signals' => $signals,
			'elementorProLicensedFeatures' => $licensedFeatures,
			'conditionalCapabilities' => $conditional,
		];
	}

	public function dynamic_tags_snapshot(): array {
		$catalog = $this->dynamic_tag_catalog();
		return $this->snapshot_payload(
			'dynamic-tags',
			[ 'managerClass' => $catalog['managerClass'], 'tags' => $catalog['tags'], 'groups' => $catalog['groups'], 'count' => $catalog['count'] ],
			[ 'tags' => $catalog['raw'], 'groups' => $catalog['groups'] ],
			$catalog['scanErrors']
		);
	}

	public function runtime_snapshot(): array {
		$errors = [];
		$modules = $this->module_catalog();
		$errors = array_merge( $errors, (array) $modules['scanErrors'] );
		$catalog = $this->scanner->catalog_index();
		$errors = array_merge( $errors, (array) ( $catalog['scanErrors'] ?? [] ) );
		$dependencies = $this->dependency_map();

		$normalized = [
			'modules' => [
				'coreManagerClass' => $modules['coreManagerClass'],
				'proManagerClass' => $modules['proManagerClass'],
				'core' => $modules['core'],
				'pro' => $modules['pro'],
			],
			'dependencies' => $dependencies,
			'widgetRegistry' => $catalog['widgets'] ?? [],
			'elementRegistry' => $catalog['elements'] ?? [],
			'controlMetadataVersion' => $catalog['controlMetadataVersion'] ?? 0,
		];
		$raw = [ 'modules' => $modules, 'dependencies' => $dependencies, 'catalogIndex' => $catalog ];
		return $this->snapshot_payload( 'runtime', $normalized, $raw, $errors );
	}

	private function modules_from_manager( object $manager, string $stage, array &$errors ): array {
		$out = [];
		if ( ! $this->is_public_method( $manager, 'get_modules_names' ) || ! $this->is_public_method( $manager, 'get_modules' ) ) {
			$errors[] = [ 'stage' => $stage, 'message' => 'Modules manager does not expose get_modules_names() and get_modules().' ];
			return $out;
		}

		try { $names = array_values( array_map( 'strval', (array) $manager->get_modules_names() ) ); }
		catch ( \Throwable $error ) { $errors[] = $this->error( $stage . ':names', $error ); return $out; }

		foreach ( $names as $name ) {
			try {
				$module = $manager->get_modules( $name );
				$out[ $name ] = [
					'name' => $name,
					'active' => is_object( $module ),
					'className' => is_object( $module ) ? get_class( $module ) : '',
				];
				if ( is_object( $module ) && $this->is_public_method( $module, 'get_name' ) ) {
					$out[ $name ]['runtimeName'] = (string) $this->safe_call( $module, 'get_name', $name, $stage . ':' . $name . ':name', $errors );
				}
			} catch ( \Throwable $error ) {
				$errors[] = $this->error( $stage . ':' . $name, $error );
				$out[ $name ] = [ 'name' => $name, 'active' => null, 'className' => '' ];
			}
		}
		return $out;
	}

	private function pro_license_features(): array {
		$raw = get_option( '_elementor_pro_license_v2_data', [] );
		if ( is_array( $raw ) && isset( $raw['value'] ) ) { $raw = $raw['value']; }
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() ) { $raw = $decoded; }
		}
		return is_array( $raw ) && is_array( $raw['features'] ?? null ) ? array_values( array_map( 'strval', $raw['features'] ) ) : [];
	}

	private function safe_call( object $object, string $method, $default, string $stage, array &$errors ) {
		if ( ! $this->is_public_method( $object, $method ) ) { return $default; }
		try {
			$reflection = new \ReflectionMethod( $object, $method );
			if ( 0 !== $reflection->getNumberOfRequiredParameters() ) { return $default; }
			return $object->{$method}();
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( $stage, $error );
			return $default;
		}
	}

	private function is_public_method( object $object, string $method ): bool {
		if ( ! method_exists( $object, $method ) ) { return false; }
		try { return ( new \ReflectionMethod( $object, $method ) )->isPublic(); }
		catch ( \Throwable $error ) { return false; }
	}

	private function snapshot_payload( string $section, array $normalized, array $raw, array $errors ): array {
		$sanitizer = new SerializableSanitizer();
		$normalizedSafe = $sanitizer->sanitize( $normalized, '$.' . $section . '.normalized' );
		$rawSafe = $sanitizer->sanitize( $raw, '$.' . $section . '.raw' );
		$serialization = $sanitizer->report();
		return [
			'schema' => RuntimeSnapshot::SCHEMA,
			'generatedAt' => gmdate( 'c' ),
			'section' => $section,
			'normalized' => is_array( $normalizedSafe ) ? $normalizedSafe : [],
			'raw' => is_array( $rawSafe ) ? $rawSafe : [],
			'coverage' => [
				'status' => $errors ? 'partial' : 'complete',
				'errors' => count( $errors ),
				'redactions' => count( $serialization['redactions'] ?? [] ),
				'omissions' => count( $serialization['omissions'] ?? [] ),
			],
			'redactions' => $serialization['redactions'] ?? [],
			'omissions' => $serialization['omissions'] ?? [],
			'scanErrors' => array_values( $errors ),
		];
	}

	private function error( string $stage, \Throwable $error ): array {
		$message = wp_strip_all_tags( $error->getMessage() );
		return [ 'stage' => $stage, 'message' => '' !== $message ? $message : get_class( $error ), 'exception' => get_class( $error ) ];
	}
}
