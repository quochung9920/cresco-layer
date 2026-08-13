<?php
namespace CrescoLayer\Elementor;

use CrescoLayer\AI\CapabilityScanner;
use CrescoLayer\Support\SerializableSanitizer;
use Elementor\Plugin as ElementorPlugin;

final class RuntimeSnapshot {
	public const SCHEMA = 'cresco-elementor-snapshot/v1';
	public const SECTIONS = [
		'environment',
		'global-settings',
		'features',
		'breakpoints',
		'active-kit',
		'dynamic-tags',
		'runtime',
		'records',
	];

	private CapabilityScanner $scanner;

	public function __construct( ?CapabilityScanner $scanner = null ) {
		$this->scanner = $scanner ?? new CapabilityScanner();
	}

	public function index(): array {
		$catalog = $this->scanner->catalog_index();
		$widgets = is_array( $catalog['widgets'] ?? null ) ? $catalog['widgets'] : [];
		$elements = is_array( $catalog['elements'] ?? null ) ? $catalog['elements'] : [];
		$records = $this->recordIndex();
		$sections = [];
		foreach ( self::SECTIONS as $slug ) {
			$sections[] = [
				'slug' => $slug,
				'label' => $this->sectionLabel( $slug ),
				'lazy' => true,
			];
		}

		$index = [
			'schema' => self::SCHEMA,
			'generatedAt' => gmdate( 'c' ),
			'pluginVersion' => defined( 'CRESCO_LAYER_VERSION' ) ? CRESCO_LAYER_VERSION : '',
			'elementorVersion' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'elementorProVersion' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
			'sections' => $sections,
			'registries' => [
				'widgets' => $widgets,
				'elements' => $elements,
			],
			'records' => $records,
			'coverage' => [
				'sections' => [
					'total' => count( self::SECTIONS ),
					'scanned' => 0,
					'failed' => 0,
					'status' => 'lazy',
				],
				'widgets' => [ 'total' => count( $widgets ), 'scanned' => 0, 'failed' => 0, 'status' => 'lazy' ],
				'elements' => [ 'total' => count( $elements ), 'scanned' => 0, 'failed' => 0, 'status' => 'lazy' ],
				'records' => [ 'total' => count( $records ), 'scanned' => 0, 'failed' => 0, 'status' => 'lazy' ],
			],
			'downloadPlan' => [
				'sections' => array_values( self::SECTIONS ),
				'widgets' => array_values( array_keys( $widgets ) ),
				'elements' => array_values( array_keys( $elements ) ),
				'recordIds' => array_values( array_map( static fn( array $record ): int => (int) $record['id'], $records ) ),
			],
			'notes' => [
				'Every snapshot payload keeps normalized and raw serializable representations side by side.',
				'Secrets and credentials are redacted by key and common token-bearing string patterns before leaving WordPress.',
				'Unsupported runtime objects, resources and callbacks are omitted and reported instead of being stringified unsafely.',
				'Widget and element details are loaded independently so one addon cannot terminate the full snapshot.',
				'Elementor-owned records are loaded independently so large documents, templates and Pro records do not exhaust one request.',
			],
		];

		$sanitizer = new SerializableSanitizer();
		$sanitized = $sanitizer->sanitize( $index, '$.index' );
		$sanitized['serialization'] = $sanitizer->report();
		return $sanitized;
	}

	public function section( string $section ): array {
		if ( ! in_array( $section, self::SECTIONS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown Elementor snapshot section: ' . $section );
		}

		switch ( $section ) {
			case 'environment': return $this->environmentSection();
			case 'global-settings': return $this->globalSettingsSection();
			case 'features': return $this->featuresSection();
			case 'breakpoints': return $this->breakpointsSection();
			case 'active-kit': return $this->activeKitSection();
			case 'dynamic-tags': return $this->dynamicTagsSection();
			case 'runtime': return $this->runtimeSection();
			case 'records': return $this->recordsSection();
		}

		throw new \InvalidArgumentException( 'Unknown Elementor snapshot section: ' . $section );
	}

	public function registryEntry( string $kind, string $name ): array {
		if ( ! in_array( $kind, [ 'widget', 'element' ], true ) ) {
			throw new \InvalidArgumentException( 'Snapshot registry kind must be widget or element.' );
		}
		$entry = $this->scanner->catalog_entry( $kind, $name, true );
		$errors = array_values( (array) ( $entry['scanErrors'] ?? [] ) );
		$normalized = $this->stripRawMetadata( $entry );
		return $this->payload( 'registry:' . $kind . ':' . $name, $normalized, $entry, $errors );
	}

	public function record( int $postId ): array {
		$post = get_post( $postId );
		if ( ! $post ) {
			throw new \InvalidArgumentException( 'Elementor snapshot record not found.' );
		}
		if ( ! $this->isElementorRecord( $post ) ) {
			throw new \InvalidArgumentException( 'Requested post is not recognized as Elementor-owned configuration or an Elementor document.' );
		}

		$errors = [];
		$meta = $this->postMeta( $postId );
		$terms = $this->postTerms( $post, $errors );
		$document = $this->documentData( $postId, false, $errors );
		$workingDocument = $this->documentData( $postId, true, $errors );
		$templateType = (string) get_post_meta( $postId, '_elementor_template_type', true );
		$classification = $this->classifyRecord( (string) $post->post_type, $templateType, (string) get_post_meta( $postId, '_elementor_edit_mode', true ) );

		$normalized = [
			'id' => (int) $post->ID,
			'title' => (string) $post->post_title,
			'postType' => (string) $post->post_type,
			'status' => (string) $post->post_status,
			'modifiedGmt' => (string) $post->post_modified_gmt,
			'classification' => $classification,
			'templateType' => $templateType,
			'editMode' => (string) get_post_meta( $postId, '_elementor_edit_mode', true ),
			'pageSettings' => $this->firstMetaValue( $meta, '_elementor_page_settings', [] ),
			'displayConditions' => $this->firstExistingMetaValue( $meta, [ '_elementor_conditions', 'elementor_conditions', '_elementor_display_conditions' ], [] ),
			'document' => $document,
			'workingDocument' => $workingDocument,
			'terms' => $terms,
		];

		$raw = [
			'post' => [
				'ID' => (int) $post->ID,
				'post_author' => (int) $post->post_author,
				'post_date' => (string) $post->post_date,
				'post_date_gmt' => (string) $post->post_date_gmt,
				'post_content' => (string) $post->post_content,
				'post_title' => (string) $post->post_title,
				'post_excerpt' => (string) $post->post_excerpt,
				'post_status' => (string) $post->post_status,
				'comment_status' => (string) $post->comment_status,
				'ping_status' => (string) $post->ping_status,
				'post_name' => (string) $post->post_name,
				'post_parent' => (int) $post->post_parent,
				'menu_order' => (int) $post->menu_order,
				'post_type' => (string) $post->post_type,
				'post_mime_type' => (string) $post->post_mime_type,
				'post_modified' => (string) $post->post_modified,
				'post_modified_gmt' => (string) $post->post_modified_gmt,
			],
			'meta' => $meta,
			'terms' => $terms,
			'document' => $document,
			'workingDocument' => $workingDocument,
		];

		return $this->payload( 'record:' . $postId, $normalized, $raw, $errors );
	}

	private function environmentSection(): array {
		$errors = [];
		$theme = wp_get_theme();
		$plugins = [];
		$activeFiles = array_values( (array) get_option( 'active_plugins', [] ) );
		$networkFiles = is_multisite() ? array_values( array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) ) : [];
		$allActiveFiles = array_values( array_unique( array_merge( $activeFiles, $networkFiles ) ) );

		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			$pluginFile = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_readable( $pluginFile ) ) { require_once $pluginFile; }
		}
		if ( function_exists( 'get_plugins' ) ) {
			try {
				$installed = get_plugins();
				foreach ( $allActiveFiles as $file ) {
					$info = is_array( $installed[ $file ] ?? null ) ? $installed[ $file ] : [];
					$plugins[ $file ] = [
						'name' => (string) ( $info['Name'] ?? $file ),
						'version' => (string) ( $info['Version'] ?? '' ),
						'pluginUri' => (string) ( $info['PluginURI'] ?? '' ),
						'author' => wp_strip_all_tags( (string) ( $info['AuthorName'] ?? $info['Author'] ?? '' ) ),
						'networkActive' => in_array( $file, $networkFiles, true ),
					];
				}
			} catch ( \Throwable $error ) {
				$errors[] = $this->error( 'plugins', $error );
			}
		}

		$normalized = [
			'wordpressVersion' => get_bloginfo( 'version' ),
			'phpVersion' => PHP_VERSION,
			'elementorVersion' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'elementorProVersion' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
			'isMultisite' => is_multisite(),
			'homeUrl' => home_url( '/' ),
			'siteUrl' => site_url( '/' ),
			'locale' => get_locale(),
			'theme' => [
				'name' => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'template' => $theme->get_template(),
				'stylesheet' => $theme->get_stylesheet(),
			],
			'activePlugins' => $plugins,
		];
		$raw = [
			'active_plugins' => $activeFiles,
			'active_sitewide_plugins' => $networkFiles,
			'themeHeaders' => [
				'Name' => $theme->get( 'Name' ),
				'Version' => $theme->get( 'Version' ),
				'Template' => $theme->get_template(),
				'Stylesheet' => $theme->get_stylesheet(),
				'ThemeURI' => $theme->get( 'ThemeURI' ),
				'Author' => $theme->get( 'Author' ),
			],
			'activePluginHeaders' => $plugins,
		];
		return $this->payload( 'environment', $normalized, $raw, $errors );
	}

	private function globalSettingsSection(): array {
		global $wpdb;
		$errors = [];
		$options = [];
		$networkOptions = [];
		$currentUserMeta = [];

		try {
			if ( ! isset( $wpdb ) || ! isset( $wpdb->options ) ) {
				throw new \RuntimeException( 'WordPress options table is unavailable.' );
			}
			$elementorLike = '%' . $wpdb->esc_like( 'elementor' ) . '%';
			$kitLike = $wpdb->esc_like( 'e_kit' ) . '%';
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name ASC",
					$elementorLike,
					$kitLike
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$name = (string) ( $row['option_name'] ?? '' );
				if ( '' === $name ) { continue; }
				$options[ $name ] = [
					'value' => maybe_unserialize( $row['option_value'] ?? null ),
					'autoload' => $row['autoload'] ?? null,
				];
			}
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'options', $error );
		}

		if ( is_multisite() ) {
			try {
				$siteId = get_current_network_id();
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s) ORDER BY meta_key ASC",
						$siteId,
						'%' . $wpdb->esc_like( 'elementor' ) . '%',
						$wpdb->esc_like( 'e_kit' ) . '%'
					),
					ARRAY_A
				);
				foreach ( (array) $rows as $row ) {
					$name = (string) ( $row['meta_key'] ?? '' );
					if ( '' !== $name ) { $networkOptions[ $name ] = maybe_unserialize( $row['meta_value'] ?? null ); }
				}
			} catch ( \Throwable $error ) {
				$errors[] = $this->error( 'network-options', $error );
			}
		}

		try {
			$userId = get_current_user_id();
			foreach ( (array) get_user_meta( $userId ) as $key => $values ) {
				if ( false === stripos( (string) $key, 'elementor' ) ) { continue; }
				$currentUserMeta[ (string) $key ] = $this->decodeValues( (array) $values, (string) $key );
			}
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'current-user-meta', $error );
		}

		$groups = [ 'core' => [], 'pro' => [], 'experiments' => [], 'integrations' => [], 'other' => [] ];
		foreach ( $options as $name => $row ) {
			$group = $this->optionGroup( $name );
			$groups[ $group ][ $name ] = $row['value'];
		}
		$normalized = [
			'groups' => $groups,
			'network' => $networkOptions,
			'currentUserEditorSettings' => $currentUserMeta,
			'counts' => [
				'options' => count( $options ),
				'networkOptions' => count( $networkOptions ),
				'currentUserMeta' => count( $currentUserMeta ),
			],
		];
		$raw = [ 'options' => $options, 'networkOptions' => $networkOptions, 'currentUserMeta' => $currentUserMeta ];
		return $this->payload( 'global-settings', $normalized, $raw, $errors );
	}

	private function featuresSection(): array {
		$errors = [];
		$features = [];
		$managerClass = '';
		try {
			$plugin = ElementorPlugin::instance();
			$manager = $plugin->experiments ?? null;
			if ( is_object( $manager ) ) {
				$managerClass = get_class( $manager );
				if ( $this->isPublicMethod( $manager, 'get_features' ) ) {
					$rawFeatures = (array) $manager->get_features();
					foreach ( $rawFeatures as $name => $feature ) {
						$featureName = (string) ( is_array( $feature ) ? ( $feature['name'] ?? $name ) : $name );
						$active = null;
						if ( $this->isPublicMethod( $manager, 'is_feature_active' ) ) {
							try { $active = (bool) $manager->is_feature_active( $featureName ); }
							catch ( \Throwable $error ) { $errors[] = $this->error( 'feature-active:' . $featureName, $error ); }
						}
						$features[ $featureName ] = [ 'active' => $active, 'definition' => $feature ];
					}
				}
			}
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'runtime-features', $error );
		}

		$optionStates = [];
		try {
			global $wpdb;
			if ( isset( $wpdb ) && isset( $wpdb->options ) ) {
				$prefix = $wpdb->esc_like( 'elementor_experiment-' ) . '%';
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC", $prefix ), ARRAY_A );
				foreach ( (array) $rows as $row ) {
					$optionStates[ (string) $row['option_name'] ] = maybe_unserialize( $row['option_value'] ?? null );
				}
			}
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'feature-options', $error );
		}

		$normalized = [];
		foreach ( $features as $name => $item ) {
			$definition = is_array( $item['definition'] ?? null ) ? $item['definition'] : [];
			$normalized[ $name ] = [
				'name' => $name,
				'title' => wp_strip_all_tags( (string) ( $definition['title'] ?? $name ) ),
				'active' => $item['active'],
				'state' => $definition['state'] ?? null,
				'default' => $definition['default'] ?? null,
				'releaseStatus' => $definition['release_status'] ?? null,
				'mutable' => $definition['mutable'] ?? null,
				'tags' => $definition['tags'] ?? $definition['tag'] ?? [],
			];
		}
		return $this->payload(
			'features',
			[ 'managerClass' => $managerClass, 'features' => $normalized, 'savedStates' => $optionStates ],
			[ 'managerClass' => $managerClass, 'features' => $features, 'savedStates' => $optionStates ],
			$errors
		);
	}

	private function breakpointsSection(): array {
		$errors = [];
		$all = [];
		$active = [];
		$managerClass = '';
		try {
			$plugin = ElementorPlugin::instance();
			$manager = $plugin->breakpoints ?? null;
			if ( ! is_object( $manager ) ) { throw new \RuntimeException( 'Elementor breakpoint manager is unavailable.' ); }
			$managerClass = get_class( $manager );
			if ( $this->isPublicMethod( $manager, 'get_breakpoints' ) ) {
				foreach ( (array) $manager->get_breakpoints() as $name => $breakpoint ) {
					$all[ (string) $name ] = $this->describeBreakpoint( $breakpoint, (string) $name, $errors );
				}
			}
			if ( $this->isPublicMethod( $manager, 'get_active_breakpoints' ) ) {
				foreach ( (array) $manager->get_active_breakpoints() as $name => $breakpoint ) {
					$active[ (string) $name ] = $this->describeBreakpoint( $breakpoint, (string) $name, $errors );
				}
			}
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'breakpoints', $error );
		}
		$normalized = [ 'managerClass' => $managerClass, 'all' => $all, 'active' => $active ];
		return $this->payload( 'breakpoints', $normalized, $normalized, $errors );
	}

	private function activeKitSection(): array {
		$errors = [];
		$normalized = [];
		$raw = [];
		try {
			$plugin = ElementorPlugin::instance();
			$manager = $plugin->kits_manager ?? null;
			if ( ! is_object( $manager ) || ! $this->isPublicMethod( $manager, 'get_active_kit' ) ) {
				throw new \RuntimeException( 'Elementor active Kit manager is unavailable.' );
			}
			$kit = $manager->get_active_kit();
			if ( ! is_object( $kit ) ) { throw new \RuntimeException( 'Elementor active Kit was not found.' ); }
			$post = $this->safeCall( $kit, 'get_post', [], 'kit-post', $errors );
			$postId = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
			$settingsForDisplay = $this->safeCall( $kit, 'get_settings_for_display', [], 'kit-settings-for-display', $errors );
			$settings = $this->safeCall( $kit, 'get_settings', [], 'kit-settings', $errors );
			$data = $this->safeCall( $kit, 'get_data', [], 'kit-data', $errors );
			$meta = $postId ? $this->postMeta( $postId ) : [];
			$settingsArray = is_array( $settingsForDisplay ) ? $settingsForDisplay : ( is_array( $settings ) ? $settings : [] );
			$normalized = [
				'id' => $postId,
				'title' => is_object( $post ) && isset( $post->post_title ) ? (string) $post->post_title : '',
				'className' => get_class( $kit ),
				'settings' => $settingsArray,
				'globalColors' => [
					'system' => $settingsArray['system_colors'] ?? [],
					'custom' => $settingsArray['custom_colors'] ?? [],
				],
				'globalFonts' => [
					'system' => $settingsArray['system_typography'] ?? [],
					'custom' => $settingsArray['custom_typography'] ?? [],
				],
			];
			$raw = [
				'id' => $postId,
				'className' => get_class( $kit ),
				'settingsForDisplay' => $settingsForDisplay,
				'settings' => $settings,
				'data' => $data,
				'postMeta' => $meta,
			];
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'active-kit', $error );
		}
		return $this->payload( 'active-kit', $normalized, $raw, $errors );
	}

	private function dynamicTagsSection(): array {
		$errors = [];
		$normalized = [];
		$raw = [];
		try {
			$plugin = ElementorPlugin::instance();
			$manager = $plugin->dynamic_tags ?? null;
			if ( ! is_object( $manager ) || ! $this->isPublicMethod( $manager, 'get_tags' ) ) {
				throw new \RuntimeException( 'Elementor Dynamic Tags manager is unavailable.' );
			}
			foreach ( (array) $manager->get_tags() as $fallbackName => $tag ) {
				if ( ! is_object( $tag ) ) { continue; }
				$name = (string) $this->safeCall( $tag, 'get_name', (string) $fallbackName, 'dynamic-tag-name', $errors );
				if ( '' === $name ) { $name = (string) $fallbackName; }
				$normalized[ $name ] = [
					'name' => $name,
					'title' => wp_strip_all_tags( (string) $this->safeCall( $tag, 'get_title', $name, 'dynamic-tag-title:' . $name, $errors ) ),
					'className' => get_class( $tag ),
					'group' => (string) $this->safeCall( $tag, 'get_group', '', 'dynamic-tag-group:' . $name, $errors ),
					'categories' => array_values( array_map( 'strval', (array) $this->safeCall( $tag, 'get_categories', [], 'dynamic-tag-categories:' . $name, $errors ) ) ),
				];
				$raw[ $name ] = [
					'className' => get_class( $tag ),
					'name' => $name,
					'title' => $normalized[ $name ]['title'],
					'group' => $normalized[ $name ]['group'],
					'categories' => $normalized[ $name ]['categories'],
					'controls' => $this->safeCall( $tag, 'get_controls', [], 'dynamic-tag-controls:' . $name, $errors ),
				];
			}
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'dynamic-tags', $error );
		}
		return $this->payload( 'dynamic-tags', [ 'tags' => $normalized, 'count' => count( $normalized ) ], [ 'tags' => $raw ], $errors );
	}

	private function runtimeSection(): array {
		$errors = [];
		$catalog = $this->scanner->catalog_index();
		$elementorManagers = [];
		$proManagers = [];
		$proModules = [];
		$raw = [];

		try {
			$plugin = ElementorPlugin::instance();
			$elementorManagers = $this->publicObjectIndex( $plugin );
			$raw['elementorPlugin'] = $this->runtimeObjectSnapshot( $plugin, 'elementor-plugin', $errors );
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'elementor-runtime', $error );
		}

		if ( class_exists( '\\ElementorPro\\Plugin' ) ) {
			try {
				$proClass = '\\ElementorPro\\Plugin';
				$pro = $proClass::instance();
				$proManagers = $this->publicObjectIndex( $pro );
				$raw['elementorProPlugin'] = $this->runtimeObjectSnapshot( $pro, 'elementor-pro-plugin', $errors );
				$moduleManager = null;
				foreach ( [ 'modules_manager', 'modulesManager' ] as $property ) {
					if ( isset( $pro->{$property} ) && is_object( $pro->{$property} ) ) { $moduleManager = $pro->{$property}; break; }
				}
				if ( ! $moduleManager && $this->isPublicMethod( $pro, 'get_modules_manager' ) ) {
					$moduleManager = $pro->get_modules_manager();
				}
				if ( is_object( $moduleManager ) && $this->isPublicMethod( $moduleManager, 'get_modules' ) ) {
					foreach ( (array) $moduleManager->get_modules() as $name => $module ) {
						if ( ! is_object( $module ) ) { continue; }
						$moduleName = $this->isPublicMethod( $module, 'get_name' ) ? (string) $this->safeCall( $module, 'get_name', (string) $name, 'pro-module-name', $errors ) : (string) $name;
						$proModules[ $moduleName ] = [ 'name' => $moduleName, 'className' => get_class( $module ) ];
					}
				}
			} catch ( \Throwable $error ) {
				$errors[] = $this->error( 'elementor-pro-runtime', $error );
			}
		}

		$normalized = [
			'elementorManagers' => $elementorManagers,
			'elementorProManagers' => $proManagers,
			'elementorProModules' => $proModules,
			'widgetRegistry' => $catalog['widgets'] ?? [],
			'elementRegistry' => $catalog['elements'] ?? [],
			'controlMetadataVersion' => $catalog['controlMetadataVersion'] ?? 0,
		];
		$raw['catalogIndex'] = $catalog;
		return $this->payload( 'runtime', $normalized, $raw, $errors );
	}

	private function recordsSection(): array {
		$errors = [];
		$postTypes = [];
		$rawPostTypes = [];
		try {
			foreach ( (array) get_post_types( [], 'objects' ) as $name => $object ) {
				if ( ! is_object( $object ) || ! $this->isElementorPostType( (string) $name, $object ) ) { continue; }
				$postTypes[ (string) $name ] = [
					'name' => (string) $name,
					'label' => wp_strip_all_tags( (string) ( $object->label ?? $name ) ),
					'public' => (bool) ( $object->public ?? false ),
					'showUi' => (bool) ( $object->show_ui ?? false ),
					'restBase' => (string) ( $object->rest_base ?? '' ),
					'supports' => function_exists( 'get_all_post_type_supports' ) ? array_keys( (array) get_all_post_type_supports( (string) $name ) ) : [],
				];
				$rawPostTypes[ (string) $name ] = get_object_vars( $object );
			}
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'post-types', $error );
		}
		$records = $this->recordIndex();
		$classifications = [];
		foreach ( $records as $record ) {
			$classification = (string) ( $record['classification'] ?? 'other' );
			$classifications[ $classification ] = (int) ( $classifications[ $classification ] ?? 0 ) + 1;
		}
		return $this->payload(
			'records',
			[ 'postTypes' => $postTypes, 'records' => $records, 'classifications' => $classifications, 'count' => count( $records ) ],
			[ 'postTypes' => $rawPostTypes, 'records' => $records ],
			$errors
		);
	}

	private function recordIndex(): array {
		$ids = [];
		$postTypes = [];
		try {
			foreach ( (array) get_post_types( [], 'objects' ) as $name => $object ) {
				if ( is_object( $object ) && $this->isElementorPostType( (string) $name, $object ) ) { $postTypes[] = (string) $name; }
			}
			if ( $postTypes ) {
				$query = new \WP_Query( [
					'post_type' => array_values( array_unique( $postTypes ) ),
					'post_status' => 'any',
					'posts_per_page' => -1,
					'fields' => 'ids',
					'no_found_rows' => true,
					'orderby' => 'ID',
					'order' => 'ASC',
				] );
				$ids = array_merge( $ids, array_map( 'absint', (array) $query->posts ) );
			}
			$query = new \WP_Query( [
				'post_type' => 'any',
				'post_status' => 'any',
				'posts_per_page' => -1,
				'fields' => 'ids',
				'no_found_rows' => true,
				'meta_query' => [ [ 'key' => '_elementor_edit_mode', 'compare' => 'EXISTS' ] ],
				'orderby' => 'ID',
				'order' => 'ASC',
			] );
			$ids = array_merge( $ids, array_map( 'absint', (array) $query->posts ) );
		} catch ( \Throwable $error ) {
			return [];
		}

		$out = [];
		foreach ( array_values( array_unique( array_filter( $ids ) ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! $this->isElementorRecord( $post ) ) { continue; }
			$templateType = (string) get_post_meta( $id, '_elementor_template_type', true );
			$editMode = (string) get_post_meta( $id, '_elementor_edit_mode', true );
			$out[] = [
				'id' => (int) $id,
				'title' => (string) $post->post_title,
				'postType' => (string) $post->post_type,
				'status' => (string) $post->post_status,
				'modifiedGmt' => (string) $post->post_modified_gmt,
				'templateType' => $templateType,
				'editMode' => $editMode,
				'classification' => $this->classifyRecord( (string) $post->post_type, $templateType, $editMode ),
			];
		}
		return $out;
	}

	private function isElementorRecord( object $post ): bool {
		$postTypeObject = get_post_type_object( (string) $post->post_type );
		if ( $postTypeObject && $this->isElementorPostType( (string) $post->post_type, $postTypeObject ) ) { return true; }
		return '' !== (string) get_post_meta( (int) $post->ID, '_elementor_edit_mode', true ) || metadata_exists( 'post', (int) $post->ID, '_elementor_data' );
	}

	private function isElementorPostType( string $name, object $object ): bool {
		$known = [ 'elementor_library', 'elementor_font', 'elementor_icons', 'elementor_snippet' ];
		if ( in_array( $name, $known, true ) || false !== stripos( $name, 'elementor' ) ) { return true; }
		$label = (string) ( $object->label ?? '' );
		return false !== stripos( $label, 'Elementor' );
	}

	private function classifyRecord( string $postType, string $templateType, string $editMode ): string {
		if ( 'elementor_font' === $postType ) { return 'custom-font'; }
		if ( 'elementor_icons' === $postType ) { return 'custom-icon'; }
		if ( 'elementor_snippet' === $postType ) { return 'custom-code'; }
		if ( 'popup' === $templateType ) { return 'popup'; }
		$themeBuilderTypes = [ 'header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'search-results', 'error-404', 'product', 'product-archive', 'loop-item' ];
		if ( in_array( $templateType, $themeBuilderTypes, true ) ) { return 'theme-builder'; }
		if ( 'elementor_library' === $postType ) { return 'template'; }
		if ( '' !== $editMode ) { return 'document'; }
		return 'elementor-record';
	}

	private function postMeta( int $postId ): array {
		$out = [];
		foreach ( (array) get_post_meta( $postId ) as $key => $values ) {
			$out[ (string) $key ] = $this->decodeValues( (array) $values, (string) $key );
		}
		return $out;
	}

	private function decodeValues( array $values, string $key ) {
		$decoded = [];
		foreach ( $values as $value ) {
			$value = maybe_unserialize( $value );
			if ( is_string( $value ) && ( '_elementor_data' === $key || str_contains( $key, 'settings' ) || str_contains( $key, 'conditions' ) ) ) {
				$json = json_decode( $value, true );
				if ( JSON_ERROR_NONE === json_last_error() ) { $value = $json; }
			}
			$decoded[] = $value;
		}
		return 1 === count( $decoded ) ? $decoded[0] : $decoded;
	}

	private function postTerms( object $post, array &$errors ): array {
		$out = [];
		try {
			foreach ( (array) get_object_taxonomies( (string) $post->post_type ) as $taxonomy ) {
				$terms = wp_get_object_terms( (int) $post->ID, (string) $taxonomy );
				if ( is_wp_error( $terms ) ) {
					$errors[] = [ 'stage' => 'terms:' . $taxonomy, 'message' => $terms->get_error_message() ];
					continue;
				}
				$out[ (string) $taxonomy ] = array_map( static function ( $term ): array {
					return [
						'termId' => (int) $term->term_id,
						'name' => (string) $term->name,
						'slug' => (string) $term->slug,
						'termTaxonomyId' => (int) $term->term_taxonomy_id,
					];
				}, (array) $terms );
			}
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'terms', $error );
		}
		return $out;
	}

	private function documentData( int $postId, bool $preferAutosave, array &$errors ): array {
		try {
			$manager = ElementorPlugin::instance()->documents ?? null;
			if ( ! is_object( $manager ) ) { return []; }
			$document = null;
			if ( $preferAutosave && $this->isPublicMethod( $manager, 'get_doc_or_auto_save' ) ) {
				$document = $manager->get_doc_or_auto_save( $postId, get_current_user_id() );
			}
			if ( ! $document && $this->isPublicMethod( $manager, 'get' ) ) { $document = $manager->get( $postId ); }
			if ( ! is_object( $document ) ) { return []; }
			$post = $this->safeCall( $document, 'get_post', null, 'document-post', $errors );
			return [
				'className' => get_class( $document ),
				'name' => (string) $this->safeCall( $document, 'get_name', '', 'document-name', $errors ),
				'postId' => is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : $postId,
				'elements' => $this->safeCall( $document, 'get_elements_data', [], 'document-elements', $errors ),
				'settings' => $this->safeCall( $document, 'get_settings', [], 'document-settings', $errors ),
				'settingsForDisplay' => $this->safeCall( $document, 'get_settings_for_display', [], 'document-settings-display', $errors ),
				'data' => $this->safeCall( $document, 'get_data', [], 'document-data', $errors ),
			];
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( $preferAutosave ? 'working-document' : 'document', $error );
			return [];
		}
	}

	private function publicObjectIndex( object $object ): array {
		$out = [];
		foreach ( get_object_vars( $object ) as $name => $value ) {
			if ( is_object( $value ) ) { $out[ (string) $name ] = get_class( $value ); }
			elseif ( is_array( $value ) ) { $out[ (string) $name ] = 'array(' . count( $value ) . ')'; }
			else { $out[ (string) $name ] = gettype( $value ); }
		}
		ksort( $out );
		return $out;
	}

	private function runtimeObjectSnapshot( object $object, string $stage, array &$errors ): array {
		$out = [ 'className' => get_class( $object ), 'publicProperties' => get_object_vars( $object ), 'getters' => [] ];
		foreach ( [ 'get_name', 'get_version', 'get_config', 'get_settings' ] as $method ) {
			if ( ! $this->isPublicMethod( $object, $method ) ) { continue; }
			try {
				$reflection = new \ReflectionMethod( $object, $method );
				if ( 0 !== $reflection->getNumberOfRequiredParameters() ) { continue; }
				$out['getters'][ $method ] = $object->{$method}();
			} catch ( \Throwable $error ) {
				$errors[] = $this->error( $stage . ':' . $method, $error );
			}
		}
		return $out;
	}

	private function describeBreakpoint( $breakpoint, string $name, array &$errors ): array {
		if ( ! is_object( $breakpoint ) ) { return [ 'name' => $name, 'value' => $breakpoint ]; }
		$out = [ 'name' => $name, 'className' => get_class( $breakpoint ) ];
		foreach ( [ 'get_label' => 'label', 'get_value' => 'value', 'get_default_value' => 'defaultValue', 'get_direction' => 'direction', 'is_enabled' => 'enabled' ] as $method => $key ) {
			if ( ! $this->isPublicMethod( $breakpoint, $method ) ) { continue; }
			$out[ $key ] = $this->safeCall( $breakpoint, $method, null, 'breakpoint:' . $name . ':' . $method, $errors );
		}
		return $out;
	}

	private function safeCall( object $object, string $method, $default, string $stage, array &$errors ) {
		if ( ! $this->isPublicMethod( $object, $method ) ) { return $default; }
		try {
			$reflection = new \ReflectionMethod( $object, $method );
			if ( 0 !== $reflection->getNumberOfRequiredParameters() ) { return $default; }
			return $object->{$method}();
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( $stage, $error );
			return $default;
		}
	}

	private function isPublicMethod( object $object, string $method ): bool {
		if ( ! method_exists( $object, $method ) ) { return false; }
		try { return ( new \ReflectionMethod( $object, $method ) )->isPublic(); }
		catch ( \Throwable $error ) { return false; }
	}

	private function payload( string $section, array $normalized, array $raw, array $errors ): array {
		$sanitizer = new SerializableSanitizer();
		$normalizedSafe = $sanitizer->sanitize( $normalized, '$.' . $section . '.normalized' );
		$rawSafe = $sanitizer->sanitize( $raw, '$.' . $section . '.raw' );
		$serialization = $sanitizer->report();
		return [
			'schema' => self::SCHEMA,
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

	private function stripRawMetadata( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		$out = [];
		foreach ( $value as $key => $child ) {
			if ( in_array( (string) $key, [ 'rawMetadata', 'atomicControls' ], true ) ) { continue; }
			$out[ $key ] = $this->stripRawMetadata( $child );
		}
		return $out;
	}

	private function optionGroup( string $name ): string {
		$lower = strtolower( $name );
		if ( str_starts_with( $lower, 'elementor_experiment-' ) || str_contains( $lower, 'experiment' ) || str_contains( $lower, 'feature' ) ) { return 'experiments'; }
		if ( str_contains( $lower, 'api' ) || str_contains( $lower, 'integration' ) || str_contains( $lower, 'mailchimp' ) || str_contains( $lower, 'recaptcha' ) || str_contains( $lower, 'smtp' ) ) { return 'integrations'; }
		if ( str_contains( $lower, 'elementor_pro' ) || str_contains( $lower, 'elementor-pro' ) ) { return 'pro'; }
		if ( str_starts_with( $lower, 'elementor' ) || str_starts_with( $lower, '_elementor' ) || str_starts_with( $lower, 'e_kit' ) ) { return 'core'; }
		return 'other';
	}

	private function firstMetaValue( array $meta, string $key, $default ) {
		return array_key_exists( $key, $meta ) ? $meta[ $key ] : $default;
	}

	private function firstExistingMetaValue( array $meta, array $keys, $default ) {
		foreach ( $keys as $key ) { if ( array_key_exists( $key, $meta ) ) { return $meta[ $key ]; } }
		return $default;
	}

	private function error( string $stage, \Throwable $error ): array {
		$message = wp_strip_all_tags( $error->getMessage() );
		return [ 'stage' => $stage, 'message' => '' !== $message ? $message : get_class( $error ), 'exception' => get_class( $error ) ];
	}

	private function sectionLabel( string $slug ): string {
		$labels = [
			'environment' => 'Environment & active stack',
			'global-settings' => 'Elementor global options & editor settings',
			'features' => 'Features & experiments',
			'breakpoints' => 'Responsive breakpoints',
			'active-kit' => 'Active Kit, Site Settings & design system',
			'dynamic-tags' => 'Registered Dynamic Tags',
			'runtime' => 'Core/Pro runtime managers & registries',
			'records' => 'Elementor documents, templates, Theme Builder, popups and Pro records',
		];
		return $labels[ $slug ] ?? $slug;
	}
}
