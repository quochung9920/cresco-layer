<?php
namespace CrescoLayer\AI;

use CrescoLayer\Elementor\RuntimeDiscovery;
use Elementor\Plugin as ElementorPlugin;

final class ContextResolver {
	public const PROFILE_SMART = 'smart';
	public const PROFILE_FULL = 'full';
	public const PROFILES = [ self::PROFILE_SMART, self::PROFILE_FULL ];

	private CapabilityScanner $scanner;
	private RuntimeDiscovery $runtime;

	public function __construct( ?CapabilityScanner $scanner = null, ?RuntimeDiscovery $runtime = null ) {
		$this->scanner = $scanner ?? new CapabilityScanner();
		$this->runtime = $runtime ?? new RuntimeDiscovery( $this->scanner );
	}

	public function resolve( array $editableElements, array $readOnlyContext = [], string $scope = 'document', string $profile = self::PROFILE_SMART ): array {
		$profile = in_array( $profile, self::PROFILES, true ) ? $profile : self::PROFILE_SMART;
		$index = $this->scanner->catalog_index();
		$errors = array_values( (array) ( $index['scanErrors'] ?? [] ) );
		$roles = [ 'widgets' => [], 'elements' => [] ];

		$this->collect_types( $editableElements, $roles, 'editable' );
		$this->collect_types( $readOnlyContext, $roles, 'read-only-context' );

		if ( self::PROFILE_FULL === $profile ) {
			foreach ( array_keys( (array) ( $index['widgets'] ?? [] ) ) as $name ) { $roles['widgets'][ (string) $name ] = 'full-profile'; }
			foreach ( array_keys( (array) ( $index['elements'] ?? [] ) ) as $name ) { $roles['elements'][ (string) $name ] = 'full-profile'; }
		} elseif ( in_array( $scope, [ 'document', 'subtree' ], true ) ) {
			$this->add_insertion_candidates( $roles, $index );
		}

		$widgets = $this->load_entries( 'widget', $roles['widgets'], $errors );
		$elements = $this->load_entries( 'element', $roles['elements'], $errors );
		$breakpoints = $this->breakpoints( $errors );
		$designSystem = $this->design_system( $errors );
		$dynamicTags = $this->runtime->dynamic_tag_catalog();
		$modules = $this->runtime->module_catalog();
		$dependencies = $this->runtime->dependency_map();

		$errors = array_merge( $errors, (array) ( $dynamicTags['scanErrors'] ?? [] ), (array) ( $modules['scanErrors'] ?? [] ) );
		$controlStatus = $this->status_from_errors( $errors, [ 'catalog', 'registry', 'capability', 'control', 'atomic', 'widget', 'element' ], (bool) ( $widgets || $elements || ! $roles['widgets'] && ! $roles['elements'] ) );
		$kitStatus = $this->status_from_errors( $errors, [ 'active-kit', 'design-system', 'kit' ], ! empty( $designSystem ) );
		$breakpointStatus = $this->status_from_errors( $errors, [ 'breakpoint' ], ! empty( $breakpoints ) );

		return [
			'profile' => $profile,
			'resolver' => 'cresco-context-resolver/v1',
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
			'siteContext' => [
				'breakpoints' => $breakpoints,
				'designSystem' => $designSystem,
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
				'proRuntimeModules' => (string) ( $modules['coverage']['status'] ?? 'partial' ),
			],
			'contextStats' => [
				'registeredWidgets' => count( (array) ( $index['widgets'] ?? [] ) ),
				'registeredElements' => count( (array) ( $index['elements'] ?? [] ) ),
				'detailedWidgets' => count( $widgets ),
				'detailedElements' => count( $elements ),
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

	private function stronger_role( string $existing, string $incoming ): string {
		$weights = [ 'insertion-candidate' => 1, 'read-only-context' => 2, 'full-profile' => 2, 'editable' => 3 ];
		return ( $weights[ $incoming ] ?? 0 ) >= ( $weights[ $existing ] ?? 0 ) ? $incoming : $existing;
	}

	private function add_insertion_candidates( array &$roles, array $index ): void {
		$widgetCandidates = [ 'heading', 'text-editor', 'button', 'image', 'icon', 'divider', 'spacer', 'shortcode', 'html', 'form', 'e-heading', 'e-paragraph', 'e-button', 'e-image' ];
		$elementCandidates = [ 'container', 'section', 'column', 'e-div-block', 'e-flexbox', 'e-grid' ];
		foreach ( $widgetCandidates as $name ) {
			if ( isset( $index['widgets'][ $name ] ) && ! isset( $roles['widgets'][ $name ] ) ) { $roles['widgets'][ $name ] = 'insertion-candidate'; }
		}
		foreach ( $elementCandidates as $name ) {
			if ( isset( $index['elements'][ $name ] ) && ! isset( $roles['elements'][ $name ] ) ) { $roles['elements'][ $name ] = 'insertion-candidate'; }
		}
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
