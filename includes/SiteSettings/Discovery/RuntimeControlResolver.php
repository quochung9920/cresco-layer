<?php
namespace CrescoLayer\SiteSettings\Discovery;

/**
 * Resolves the capabilities of the running Elementor control stack without version sniffing.
 *
 * Explicit Kit control metadata is authoritative. Generic control-type defaults are used only as a
 * conservative fallback for baseline capabilities (notably px); they never prove Custom Unit
 * support or a control-specific range. Existing persisted `unit=custom` is also valid evidence that
 * the running control accepted a custom unit even if optimized metadata was stripped.
 */
final class RuntimeControlResolver {
	/** @var callable|null */
	private $typeDefaultsProvider;
	private array $typeDefaultsCache = [];

	public function __construct(
		private array $controls,
		private array $current = [],
		?callable $type_defaults_provider = null
	) {
		$this->typeDefaultsProvider = $type_defaults_provider;
	}

	public function has( string $control ): bool {
		return isset( $this->controls[ $control ] ) && is_array( $this->controls[ $control ] );
	}

	public function control( string $control ): array {
		return $this->has( $control ) ? $this->controls[ $control ] : [];
	}

	/** A compact, normalized capability record for one control. */
	public function resolve( string $control ): array {
		$data = $this->control( $control );
		$type = (string) ( $data['type'] ?? '' );
		$defaults = $this->type_defaults( $type );
		$explicit_units = $this->string_list( $data['size_units'] ?? [] );
		$default_units = $this->string_list( $defaults['size_units'] ?? [] );
		$current = $this->current[ $control ] ?? null;
		$current_unit = is_array( $current ) ? (string) ( $current['unit'] ?? '' ) : '';
		$explicit_range = is_array( $data['range'] ?? null ) ? $data['range'] : [];

		return [
			'name' => $control,
			'exists' => [] !== $data,
			'type' => $type,
			'isResponsive' => ! empty( $data['is_responsive'] ) || ! empty( $data['responsive'] ) || $this->has_responsive_children( $control ),
			'explicitUnits' => $explicit_units,
			'fallbackUnits' => $default_units,
			'supportsPx' => in_array( 'px', $explicit_units, true ) || ( ! $explicit_units && in_array( 'px', $default_units, true ) ) || 'px' === $current_unit,
			'supportsCustom' => in_array( 'custom', $explicit_units, true ) || 'custom' === $current_unit,
			'customSupportEvidence' => in_array( 'custom', $explicit_units, true ) ? 'explicit-control-metadata' : ( 'custom' === $current_unit ? 'persisted-value' : 'none' ),
			'explicitRange' => $explicit_range,
			'conditions' => is_array( $data['conditions'] ?? null ) ? $data['conditions'] : [],
			'condition' => is_array( $data['condition'] ?? null ) ? $data['condition'] : [],
			'parent' => (string) ( $data['parent'] ?? '' ),
		];
	}

	public function supports_unit( string $control, string $unit ): bool {
		$resolved = $this->resolve( $control );
		if ( 'custom' === $unit ) { return ! empty( $resolved['supportsCustom'] ); }
		if ( 'px' === $unit ) { return ! empty( $resolved['supportsPx'] ); }
		$explicit = (array) $resolved['explicitUnits'];
		$fallback = (array) $resolved['fallbackUnits'];
		return in_array( $unit, $explicit, true ) || ( ! $explicit && in_array( $unit, $fallback, true ) );
	}

	/** Return only a control-specific range. Generic type defaults are not safe constraints. */
	public function explicit_range( string $control, string $unit ): ?array {
		$range = $this->resolve( $control )['explicitRange'] ?? [];
		if ( ! is_array( $range ) || ! is_array( $range[ $unit ] ?? null ) ) { return null; }
		return $range[ $unit ];
	}

	public function is_responsive( string $control ): bool {
		return ! empty( $this->resolve( $control )['isResponsive'] );
	}

	private function type_defaults( string $type ): array {
		if ( '' === $type ) { return []; }
		if ( array_key_exists( $type, $this->typeDefaultsCache ) ) { return $this->typeDefaultsCache[ $type ]; }

		$value = [];
		if ( is_callable( $this->typeDefaultsProvider ) ) {
			$candidate = call_user_func( $this->typeDefaultsProvider, $type );
			$value = is_array( $candidate ) ? $candidate : [];
		} elseif ( class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				$plugin = \Elementor\Plugin::instance();
				$manager = $plugin->controls_manager ?? null;
				$instance = is_object( $manager ) && method_exists( $manager, 'get_control' ) ? $manager->get_control( $type ) : false;
				if ( is_object( $instance ) && method_exists( $instance, 'get_settings' ) ) {
					$candidate = $instance->get_settings();
					$value = is_array( $candidate ) ? $candidate : [];
				}
			} catch ( \Throwable $error ) {
				$value = [];
			}
		}
		return $this->typeDefaultsCache[ $type ] = $value;
	}

	private function string_list( $value ): array {
		return is_array( $value ) ? array_values( array_unique( array_map( 'strval', $value ) ) ) : [];
	}

	private function has_responsive_children( string $base ): bool {
		foreach ( [ 'mobile', 'tablet', 'laptop', 'widescreen', 'mobile_extra', 'tablet_extra' ] as $device ) {
			if ( isset( $this->controls[ $base . '_' . $device ] ) ) { return true; }
		}
		return false;
	}
}
