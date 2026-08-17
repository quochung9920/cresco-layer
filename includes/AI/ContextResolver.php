<?php
namespace CrescoLayer\AI;

use CrescoLayer\Diagnostics\ExportDiagnostics;
use CrescoLayer\Elementor\RuntimeDiscovery;
use Elementor\Plugin as ElementorPlugin;

final class ContextResolver {
	public const PROFILE_SMART = 'smart';
	public const PROFILE_FULL = 'full';
	public const PROFILES = [ self::PROFILE_SMART, self::PROFILE_FULL ];
	private const DETAIL_BUDGET_WIDGETS = 24;
	private const DETAIL_BUDGET_ELEMENTS = 12;

	private CapabilityScanner $scanner;
	private RuntimeDiscovery $runtime;

	public function __construct( ?CapabilityScanner $scanner = null, ?RuntimeDiscovery $runtime = null ) {
		$this->scanner = $scanner ?? new CapabilityScanner();
		$this->runtime = $runtime ?? new RuntimeDiscovery( $this->scanner );
	}

	public function resolve( array $editableElements, array $readOnlyContext = [], string $scope = 'document', string $profile = self::PROFILE_SMART ): array {
		$profile = in_array( $profile, self::PROFILES, true ) ? $profile : self::PROFILE_SMART;
		ExportDiagnostics::stage( 'context.catalog-index', [ 'profile' => $profile, 'scope' => $scope ] );
		$index = $this->scanner->catalog_index();
		$capabilityErrors = array_values( (array) ( $index['scanErrors'] ?? [] ) );
		$roles = [ 'widgets' => [], 'elements' => [] ];

		ExportDiagnostics::stage( 'context.collect-types' );
		$this->collect_types( $editableElements, $roles, 'editable' );
		$this->collect_types( $readOnlyContext, $roles, 'read-only-context' );
		$this->filter_registered_roles( $roles, $index, $capabilityErrors );

		if ( self::PROFILE_FULL === $profile ) {
			// Full export keeps the entire runtime registry in registryIndex, but detailed control
			// metadata is resource-bounded. Existing/editable types always win the budget; the rest
			// stays index-only so external AI can still see what the runtime supports without forcing
			// PHP to instantiate every Elementor control stack on every export.
			foreach ( array_keys( (array) ( $index['widgets'] ?? [] ) ) as $name ) {
				$name = (string) $name;
				$roles['widgets'][ $name ] = $this->stronger_role( $roles['widgets'][ $name ] ?? '', 'full-profile' );
			}
			foreach ( array_keys( (array) ( $index['elements'] ?? [] ) ) as $name ) {
				$name = (string) $name;
				$roles['elements'][ $name ] = $this->stronger_role( $roles['elements'][ $name ] ?? '', 'full-profile' );
			}
			$this->add_insertion_candidates( $roles, $index );
		} elseif ( in_array( $scope, [ 'document', 'subtree' ], true ) ) {
			$this->add_insertion_candidates( $roles, $index );
		}

		$budget = $this->apply_detail_budget( $roles, $index, $profile );
		$expectedCapabilities = count( $roles['widgets'] ) + count( $roles['elements'] );
		ExportDiagnostics::stage( 'context.capability-details', [
			'widgets' => count( $roles['widgets'] ),
			'elements' => count( $roles['elements'] ),
			'truncated' => (bool) ( $budget['truncated'] ?? false ),
		] );
		$widgets = $this->load_entries( 'widget', $roles['widgets'], $capabilityErrors );
		$elements = $this->load_entries( 'element', $roles['elements'], $capabilityErrors );
		$loadedCapabilities = count( $widgets ) + count( $elements );

		ExportDiagnostics::stage( 'context.site-context' );
		$siteErrors = [];
		$breakpoints = $this->breakpoints( $siteErrors );
		$designSystem = $this->design_system( $siteErrors );

		ExportDiagnostics::stage( 'context.runtime-catalogs' );
		$dynamicTags = $this->runtime->dynamic_tag_catalog();
		$modules = $this->runtime->module_catalog();
		$dependencies = $this->runtime->dependency_map();

		$controlStatus = $this->status_from_errors(
			$capabilityErrors,
			[ 'catalog', 'registry', 'capability', 'control', 'atomic' ],
			0 === $expectedCapabilities || $loadedCapabilities === $expectedCapabilities
		);
		$kitStatus = $this->status_from_errors( $siteErrors, [ 'active-kit', 'design-system', 'kit' ], ! empty( $designSystem['settings'] ?? [] ) );
		$breakpointStatus = $this->status_from_errors( $siteErrors, [ 'breakpoint' ], ! empty( $breakpoints ) );
		$errors = array_merge(
			$capabilityErrors,
			$siteErrors,
			(array) ( $dynamicTags['scanErrors'] ?? [] ),
			(array) ( $modules['scanErrors'] ?? [] )
		);

		ExportDiagnostics::stage( 'context.complete', [
			'loadedCapabilities' => $loadedCapabilities,
			'errors' => count( $errors ),
		] );
		return [
			'profile' => $profile,
			'resolver' => 'cresco-context-resolver/v2',
			'registryIndex' => [
				'widgets' => $index['widgets'] ?? [],
				'elements' => $index['elements'] ?? [],
				'controlMetadataVersion' => (int) ( $index['controlMetadataVersion'] ?? 0 ),
			],
			'capabilities' => [
				'widgets' => $widgets,
				'elements' => $elements,
				'roles' => $roles,
			],
			'contextBudget' => $budget,
			'siteContext' => [
				'breakpoints' => $breakpoints,
				// Preserve the v2 AI package contract: designSystem is the active Kit settings array.
				'designSystem' => (array) ( $designSystem['settings'] ?? [] ),
				'globalDesignSystem' => [
					'globalColors' => (array) ( $designSystem['globalColors'] ?? [] ),
					'globalFonts' => (array) ( $designSystem['globalFonts'] ?? [] ),
				],
			],
			'dynamicTags' => [
				'tags' => $dynamicTags['tags'] ?? [],
				'groups' => $dynamicTags['groups'] ?? [],
			],
			'runtime' => [
				'elementorModuleCount' => count( array_filter( (array) ( $modules['core'] ?? [] ), static fn( array $item ): bool => true === ( $item['active'] ?? null ) ) ),
				'elementorProModuleCount' => count( array_filter( (array) ( $modules['pro'] ?? [] ), static fn( array $item ): bool => true === ( $item['active'] ?? null ) ) ),
				'dependencies' => $dependencies,
			],
			'capabilityCoverage' => [
				'controls' => $controlStatus,
				'activeKit' => $kitStatus,
				'breakpoints' => $breakpointStatus,
				'dynamicTags' => (string) ( $dynamicTags['coverage']['status'] ?? 'partial' ),
				'proRuntimeModules' => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ( $modules['coverage']['status'] ?? 'partial' ) : 'unavailable',
			],
			'contextStats' => [
				'registeredWidgets' => count( (array) ( $index['widgets'] ?? [] ) ),
				'registeredElements' => count( (array) ( $index['elements'] ?? [] ) ),
				'detailedWidgets' => count( $widgets ),
				'detailedElements' => count( $elements ),
				'expectedDetailedCapabilities' => $expectedCapabilities,
				'indexOnlyWidgets' => max( 0, count( (array) ( $index['widgets'] ?? [] ) ) - count( $widgets ) ),
				'indexOnlyElements' => max( 0, count( (array) ( $index['elements'] ?? [] ) ) - count( $elements ) ),
				'detailStrategy' => (string) ( $budget['strategy'] ?? 'bounded-detail' ),
				'budget' => $budget,
				'dynamicTags' => (int) ( $dynamicTags['count'] ?? 0 ),
				'errors' => count( $errors ),
			],
			'scanErrors' => array_values( $errors ),
		];
	}

	private function load_entries( string $kind, array $roles, array &$errors ): array {
		$out = [];
		foreach ( array_keys( $roles ) as $name ) {
			try {
				$entry = $this->scanner->catalog_entry( $kind, (string) $name, false );
				$entry['contextRole'] = $roles[ $name ];
				$out[ (string) $name ] = $entry;
				foreach ( (array) ( $entry['scanErrors'] ?? [] ) as $error ) { $errors[] = $error; }
			} catch ( \Throwable $error ) {
				$errors[] = [
					'kind' => $kind,
					'name' => (string) $name,
					'stage' => 'capability-detail',
					'message' => wp_strip_all_tags( $error->getMessage() ),
				];
			}
		}
		return $out;
	}

	private function collect_types( $value, array &$roles, string $role ): void {
		if ( ! is_array( $value ) ) { return; }
		if ( isset( $value['widgetType'] ) && is_string( $value['widgetType'] ) && '' !== $value['widgetType'] ) {
			$roles['widgets'][ $value['widgetType'] ] = $this->stronger_role( $roles['widgets'][ $value['widgetType'] ] ?? '', $role );
		}
		if ( isset( $value['elType'] ) && is_string( $value['elType'] ) && '' !== $value['elType'] ) {
			$roles['elements'][ $value['elType'] ] = $this->stronger_role( $roles['elements'][ $value['elType'] ] ?? '', $role );
		}
		foreach ( $value as $child ) { if ( is_array( $child ) ) { $this->collect_types( $child, $roles, $role ); } }
	}

	private function filter_registered_roles( array &$roles, array $index, array &$errors ): void {
		foreach ( array_keys( $roles['widgets'] ) as $name ) {
			if ( isset( $index['widgets'][ $name ] ) ) { continue; }
			$errors[] = [ 'kind' => 'widget', 'name' => (string) $name, 'stage' => 'registry-type-missing', 'message' => 'Widget type is present in document/context data but is not registered in the current Elementor runtime.' ];
			unset( $roles['widgets'][ $name ] );
		}
		foreach ( array_keys( $roles['elements'] ) as $name ) {
			if ( isset( $index['elements'][ $name ] ) ) { continue; }
			// Elementor widgets persist elType=widget; widgetType is the real registered capability key.
			if ( 'widget' !== $name ) {
				$errors[] = [ 'kind' => 'element', 'name' => (string) $name, 'stage' => 'registry-type-missing', 'message' => 'Element type is present in document/context data but is not registered in the current Elementor runtime.' ];
			}
			unset( $roles['elements'][ $name ] );
		}
	}

	private function stronger_role( string $existing, string $incoming ): string {
		$weights = [ 'full-profile' => 1, 'insertion-candidate' => 1, 'read-only-context' => 2, 'editable' => 3 ];
		return ( $weights[ $incoming ] ?? 0 ) > ( $weights[ $existing ] ?? 0 ) ? $incoming : ( $existing ?: $incoming );
	}

	private function add_insertion_candidates( array &$roles, array $index ): void {
		$widgetCandidates = [ 'heading', 'text-editor', 'button', 'image', 'icon', 'icon-list', 'divider', 'spacer', 'shortcode', 'html', 'form', 'e-heading', 'e-paragraph', 'e-button', 'e-image' ];
		$elementCandidates = [ 'container', 'section', 'column', 'e-div-block', 'e-flexbox', 'e-grid' ];
		foreach ( $widgetCandidates as $name ) {
			if ( isset( $index['widgets'][ $name ] ) ) { $roles['widgets'][ $name ] = $this->stronger_role( $roles['widgets'][ $name ] ?? '', 'insertion-candidate' ); }
		}
		foreach ( $elementCandidates as $name ) {
			if ( isset( $index['elements'][ $name ] ) ) { $roles['elements'][ $name ] = $this->stronger_role( $roles['elements'][ $name ] ?? '', 'insertion-candidate' ); }
		}
	}

	private function apply_detail_budget( array &$roles, array $index, string $profile ): array {
		$widgetBudget = $this->budget_kind( $roles['widgets'], self::DETAIL_BUDGET_WIDGETS );
		$elementBudget = $this->budget_kind( $roles['elements'], self::DETAIL_BUDGET_ELEMENTS );
		$roles['widgets'] = $widgetBudget['roles'];
		$roles['elements'] = $elementBudget['roles'];

		return [
			'schema' => 'cresco-context-budget/v1',
			'strategy' => self::PROFILE_FULL === $profile ? 'registry-full-bounded-detail' : 'smart-bounded-detail',
			'registryIndexComplete' => true,
			'targetAndExistingTypesNeverTruncated' => true,
			'limits' => [ 'widgets' => self::DETAIL_BUDGET_WIDGETS, 'elements' => self::DETAIL_BUDGET_ELEMENTS ],
			'effectiveLimits' => [ 'widgets' => $widgetBudget['effectiveLimit'], 'elements' => $elementBudget['effectiveLimit'] ],
			'required' => [ 'widgets' => $widgetBudget['required'], 'elements' => $elementBudget['required'] ],
			'optionalKept' => [ 'widgets' => $widgetBudget['optionalKept'], 'elements' => $elementBudget['optionalKept'] ],
			'optionalIndexOnly' => [ 'widgets' => $widgetBudget['removed'], 'elements' => $elementBudget['removed'] ],
			'truncated' => ! empty( $widgetBudget['removed'] ) || ! empty( $elementBudget['removed'] ),
			'registered' => [
				'widgets' => count( (array) ( $index['widgets'] ?? [] ) ),
				'elements' => count( (array) ( $index['elements'] ?? [] ) ),
			],
		];
	}

	private function budget_kind( array $roles, int $limit ): array {
		$required = [];
		$optional = [];
		foreach ( $roles as $name => $role ) {
			if ( in_array( $role, [ 'editable', 'read-only-context' ], true ) ) { $required[ $name ] = $role; }
			else { $optional[ $name ] = $role; }
		}
		$effectiveLimit = max( $limit, count( $required ) );
		$allowance = max( 0, $effectiveLimit - count( $required ) );
		$keptOptional = array_slice( $optional, 0, $allowance, true );
		$removed = array_values( array_diff( array_keys( $optional ), array_keys( $keptOptional ) ) );
		return [
			'roles' => $required + $keptOptional,
			'effectiveLimit' => $effectiveLimit,
			'required' => array_values( array_keys( $required ) ),
			'optionalKept' => array_values( array_keys( $keptOptional ) ),
			'removed' => $removed,
		];
	}

	private function breakpoints( array &$errors ): array {
		$out = [];
		try {
			$manager = ElementorPlugin::instance()->breakpoints ?? null;
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_active_breakpoints' ) ) {
				$errors[] = [ 'stage' => 'breakpoints-unavailable', 'message' => 'Elementor active breakpoint manager is unavailable.' ];
				return [];
			}
			foreach ( (array) $manager->get_active_breakpoints() as $name => $breakpoint ) {
				if ( ! is_object( $breakpoint ) ) { continue; }
				$out[ (string) $name ] = [
					'label' => method_exists( $breakpoint, 'get_label' ) ? (string) $breakpoint->get_label() : (string) $name,
					'value' => method_exists( $breakpoint, 'get_value' ) ? $breakpoint->get_value() : null,
					'direction' => method_exists( $breakpoint, 'get_direction' ) ? (string) $breakpoint->get_direction() : '',
				];
			}
		} catch ( \Throwable $error ) {
			$errors[] = [ 'stage' => 'breakpoints', 'message' => wp_strip_all_tags( $error->getMessage() ) ];
		}
		return $out;
	}

	private function design_system( array &$errors ): array {
		try {
			$manager = ElementorPlugin::instance()->kits_manager ?? null;
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_active_kit' ) ) {
				$errors[] = [ 'stage' => 'active-kit-unavailable', 'message' => 'Elementor active Kit manager is unavailable.' ];
				return [];
			}
			$kit = $manager->get_active_kit();
			if ( ! is_object( $kit ) ) {
				$errors[] = [ 'stage' => 'active-kit-unavailable', 'message' => 'Elementor active Kit is unavailable.' ];
				return [];
			}
			$settings = method_exists( $kit, 'get_settings_for_display' ) ? $kit->get_settings_for_display() : [];
			$settings = is_array( $settings ) ? $settings : [];
			return [
				'settings' => $settings,
				'globalColors' => [ 'system' => $settings['system_colors'] ?? [], 'custom' => $settings['custom_colors'] ?? [] ],
				'globalFonts' => [ 'system' => $settings['system_typography'] ?? [], 'custom' => $settings['custom_typography'] ?? [] ],
			];
		} catch ( \Throwable $error ) {
			$errors[] = [ 'stage' => 'active-kit', 'message' => wp_strip_all_tags( $error->getMessage() ) ];
			return [];
		}
	}

	private function status_from_errors( array $errors, array $needles, bool $hasData ): string {
		foreach ( $errors as $error ) {
			$stage = strtolower( (string) ( $error['stage'] ?? '' ) );
			foreach ( $needles as $needle ) { if ( false !== strpos( $stage, strtolower( $needle ) ) ) { return 'partial'; } }
		}
		return $hasData ? 'trusted' : 'unavailable';
	}
}
