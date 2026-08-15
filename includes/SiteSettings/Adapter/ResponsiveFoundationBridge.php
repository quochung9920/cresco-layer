<?php
namespace CrescoLayer\SiteSettings\Adapter;

use CrescoLayer\SiteSettings\Discovery\CapabilityReport;
use CrescoLayer\SiteSettings\Support\ValueFactory;

/** Adds the professional five-context responsive foundation to a Classic Kit build. */
final class ResponsiveFoundationBridge {
	private const DEVICES = [ 'desktop', 'mobile', 'tablet', 'laptop', 'widescreen' ];
	private array $enabledDevices = [ 'desktop' => true ];

	public function __construct(
		private CapabilityReport $capabilities,
		private ValueFactory $factory,
		private array $current
	) {}

	public function apply( array $spec, array $built ): array {
		$layout = (array) ( $spec['settings']['layout'] ?? [] );
		$settings = is_array( $built['settings'] ?? null ) ? $built['settings'] : [];
		$plan = is_array( $built['plan'] ?? null ) ? $built['plan'] : [];
		$skipped = is_array( $built['skipped'] ?? null ) ? $built['skipped'] : [];
		$notes = is_array( $built['notes'] ?? null ) ? $built['notes'] : [];
		$this->discover_enabled_devices( $layout );

		$widths = $this->width_definitions( $layout );
		if ( $widths ) {
			$this->remove_control_family( $settings, $plan, 'container_width' );
			$this->map_widths( $widths, 'container_width', 'settings.layout.contentWidth', $settings, $plan, $skipped, $notes );
		}
		if ( ! empty( $layout['containerPadding'] ) ) {
			$this->remove_control_family( $settings, $plan, 'container_padding' );
			$this->map_padding( (array) $layout['containerPadding'], 'container_padding', 'settings.layout.containerPadding', $settings, $plan, $skipped, $notes );
		}
		if ( ! empty( $layout['breakpoints'] ) ) { $this->map_breakpoints( $layout, $settings, $plan, $skipped ); }

		foreach ( [ 'helloHeader' => 'hello_header', 'helloFooter' => 'hello_footer' ] as $section => $prefix ) {
			$definition = (array) ( $spec['themeStyle'][ $section ] ?? [] );
			$section_widths = $this->width_definitions( $definition );
			if ( ! $section_widths ) { continue; }
			$base = $prefix . '_custom_width';
			$this->remove_control_family( $settings, $plan, $base );
			$this->map_widths( $section_widths, $base, 'themeStyle.' . $section . '.contentWidth', $settings, $plan, $skipped, $notes );
		}
		$built['settings'] = $settings;
		$built['plan'] = array_values( $plan );
		$built['skipped'] = array_values( $skipped );
		$built['notes'] = array_values( $notes );
		return $built;
	}

	private function width_definitions( array $source ): array {
		if ( ! empty( $source['contentWidth'] ) && is_array( $source['contentWidth'] ) ) {
			return (array) $source['contentWidth'];
		}
		if ( empty( $source['contentWidthPx'] ) || ! is_array( $source['contentWidthPx'] ) ) { return []; }
		$out = [];
		foreach ( (array) $source['contentWidthPx'] as $device => $size ) {
			$out[ (string) $device ] = [ 'unit' => 'px', 'size' => $size ];
		}
		return $out;
	}

	private function discover_enabled_devices( array $layout ): void {
		$this->enabledDevices = [ 'desktop' => true ];
		foreach ( array_keys( (array) ( $layout['breakpoints'] ?? [] ) ) as $device ) {
			$device = (string) $device;
			if ( in_array( $device, [ 'mobile', 'tablet', 'laptop', 'widescreen' ], true ) && $this->capabilities->has( 'viewport_' . $device ) ) {
				$this->enabledDevices[ $device ] = true;
			}
		}
	}

	private function map_widths( array $definitions, string $base, string $semantic, array &$settings, array &$plan, array &$skipped, array &$notes ): void {
		foreach ( self::DEVICES as $device ) {
			if ( ! array_key_exists( $device, $definitions ) ) { continue; }
			if ( empty( $this->enabledDevices[ $device ] ) ) { $this->skip_once( $skipped, $semantic . '.' . $device, 'breakpoint_unsupported' ); continue; }
			$control = $this->responsive_control( $base, $device );
			if ( null === $control ) { $this->skip_once( $skipped, $semantic . '.' . $device, 'unsupported_control' ); continue; }
			$definition = $this->normalize_width_definition( $definitions[ $device ] );
			if ( null === $definition ) { $this->skip_once( $skipped, $semantic . '.' . $device, 'invalid_width_definition' ); continue; }
			$materialized = $this->materialize_width( $definition, $control['capabilityName'] );
			if ( empty( $materialized['ok'] ) ) { $this->skip_once( $skipped, $semantic . '.' . $device, (string) $materialized['reason'] ); continue; }
			$name = $control['name'];
			$value = $materialized['value'];
			$settings[ $name ] = $value;
			if ( ! empty( $materialized['note'] ) ) {
				$notes[] = [
					'key' => $semantic . '.' . $device,
					'note' => (string) $materialized['note'],
					'requested' => $definition,
					'written' => $value,
				];
			}
			$this->replace_plan( $plan, $name, $semantic . '.' . $device, (string) ( $control['data']['type'] ?? 'slider' ), $value );
		}
	}

	private function normalize_width_definition( $definition ): ?array {
		if ( is_numeric( $definition ) ) { return [ 'unit' => 'px', 'size' => (float) $definition ]; }
		if ( ! is_array( $definition ) || ! array_key_exists( 'size', $definition ) ) { return null; }
		$unit = (string) ( $definition['unit'] ?? 'px' );
		if ( ! in_array( $unit, [ 'px', '%', 'custom' ], true ) ) { return null; }
		if ( 'custom' === $unit ) {
			if ( ! is_string( $definition['size'] ) || '' === trim( $definition['size'] ) ) { return null; }
			return $definition;
		}
		if ( ! is_numeric( $definition['size'] ) ) { return null; }
		$definition['size'] = (float) $definition['size'];
		return $definition;
	}

	private function materialize_width( array $definition, string $capability_name ): array {
		$unit = (string) $definition['unit'];
		$size = $definition['size'];
		if ( 'custom' === $unit ) {
			if ( ! $this->capabilities->supports_custom_unit( $capability_name ) ) { return [ 'ok' => false, 'reason' => 'custom_unit_unsupported' ]; }
			return [ 'ok' => true, 'value' => $this->factory->slider_shape( 'custom', (string) $size ) ];
		}
		if ( ! $this->capabilities->supports_unit( $capability_name, $unit ) ) { return [ 'ok' => false, 'reason' => $unit . '_unit_unsupported' ]; }
		if ( '%' === $unit ) {
			return [ 'ok' => true, 'value' => $this->factory->slider_shape( '%', (float) $size ) ];
		}

		$range = $this->capabilities->explicit_range( $capability_name, 'px' );
		$max = is_array( $range ) && isset( $range['max'] ) ? (float) $range['max'] : ( isset( $definition['nativeMaxPxHint'] ) ? (float) $definition['nativeMaxPxHint'] : null );
		$min = is_array( $range ) && isset( $range['min'] ) ? (float) $range['min'] : null;
		$numeric = (float) $size;
		if ( null !== $min && $numeric < $min ) { return [ 'ok' => false, 'reason' => 'content_width_below_px_range' ]; }
		if ( null !== $max && $numeric > $max ) {
			if ( 'custom' !== (string) ( $definition['overflowUnit'] ?? '' ) || ! $this->capabilities->supports_custom_unit( $capability_name ) ) {
				return [ 'ok' => false, 'reason' => 'content_width_above_px_range_custom_unsupported' ];
			}
			return [
				'ok' => true,
				'value' => $this->factory->slider_shape( 'custom', $this->px_css( $numeric ) ),
				'note' => 'native_px_range_exceeded_used_custom_unit',
			];
		}
		return [ 'ok' => true, 'value' => $this->factory->slider_shape( 'px', $numeric ) ];
	}

	private function px_css( float $value ): string {
		$formatted = floor( $value ) === $value ? (string) (int) $value : rtrim( rtrim( sprintf( '%.4F', $value ), '0' ), '.' );
		return $formatted . 'px';
	}

	private function map_padding( array $definitions, string $base, string $semantic, array &$settings, array &$plan, array &$skipped, array &$notes ): void {
		foreach ( self::DEVICES as $device ) {
			if ( empty( $definitions[ $device ] ) || ! is_array( $definitions[ $device ] ) ) { continue; }
			if ( empty( $this->enabledDevices[ $device ] ) ) { $this->skip_once( $skipped, $semantic . '.' . $device, 'breakpoint_unsupported' ); continue; }
			$control = $this->responsive_control( $base, $device );
			if ( null === $control ) { $this->skip_once( $skipped, $semantic . '.' . $device, 'unsupported_control' ); continue; }
			$definition = $definitions[ $device ];
			$name = $control['name'];
			$data = $control['data'];
			$capability_name = $control['capabilityName'];

			if ( array_key_exists( 'fixedPx', $definition ) ) {
				if ( ! $this->capabilities->supports_unit( $capability_name, 'px' ) ) { $this->skip_once( $skipped, $semantic . '.' . $device, 'px_unit_unsupported' ); continue; }
				$px = (float) $definition['fixedPx'];
				$value = [ 'unit' => 'px', 'top' => (string) $px, 'right' => (string) $px, 'bottom' => (string) $px, 'left' => (string) $px, 'isLinked' => true ];
				$settings[ $name ] = $value;
				$this->replace_plan( $plan, $name, $semantic . '.' . $device, (string) ( $data['type'] ?? 'dimensions' ), $value );
				continue;
			}

			$fluid = (string) ( $definition['fluid'] ?? '' );
			$fallback = (float) ( $definition['fallbackPx'] ?? 0 );
			$result = $this->factory->dimensions(
				[ 'top' => '0', 'right' => $fluid, 'bottom' => '0', 'left' => $fluid ],
				[ 'top' => 0, 'right' => $fallback, 'bottom' => 0, 'left' => $fallback ],
				$this->capabilities->supports_custom_unit( $capability_name ), false, 'px'
			);
			if ( ! $result['fluid'] ) { $notes[] = [ 'key' => $semantic . '.' . $device, 'note' => $result['reason'] ]; }
			$settings[ $name ] = $result['value'];
			$this->replace_plan( $plan, $name, $semantic . '.' . $device, (string) ( $data['type'] ?? 'dimensions' ), $result['value'] );
		}
	}

	private function map_breakpoints( array $layout, array &$settings, array &$plan, array &$skipped ): void {
		$definitions = (array) ( $layout['breakpoints'] ?? [] );
		$active = [];
		foreach ( [ 'mobile', 'tablet', 'laptop', 'widescreen' ] as $device ) {
			if ( ! array_key_exists( $device, $definitions ) ) { continue; }
			$control = 'viewport_' . $device;
			if ( ! $this->capabilities->has( $control ) ) {
				unset( $settings[ $control ] ); $this->remove_plan( $plan, $control );
				$this->skip_once( $skipped, 'settings.layout.breakpoints.' . $device, 'unsupported_control' ); continue;
			}
			$active[] = $control;
			$value = (int) $definitions[ $device ];
			$settings[ $control ] = $value;
			$this->replace_plan( $plan, $control, 'settings.layout.breakpoints.' . $device, (string) ( $this->capabilities->control( $control )['type'] ?? 'number' ), $value );
		}
		if ( ! $this->capabilities->has( 'active_breakpoints' ) ) { $this->skip_once( $skipped, 'settings.layout.breakpoints.active', 'unsupported_control' ); return; }
		if ( ! empty( $layout['preserveExistingBreakpoints'] ) ) {
			$active = array_values( array_unique( array_merge( array_map( 'strval', (array) ( $this->current['active_breakpoints'] ?? [] ) ), $active ) ) );
		}
		$settings['active_breakpoints'] = $active;
		$this->replace_plan( $plan, 'active_breakpoints', 'settings.layout.breakpoints.active', (string) ( $this->capabilities->control( 'active_breakpoints' )['type'] ?? 'select2' ), $active );
	}

	private function responsive_control( string $base, string $device ): ?array {
		if ( 'desktop' === $device ) {
			if ( ! $this->capabilities->has( $base ) ) { return null; }
			return [ 'name' => $base, 'capabilityName' => $base, 'data' => $this->capabilities->control( $base ) ];
		}
		$name = $base . '_' . $device;
		if ( $this->capabilities->has( $name ) ) { return [ 'name' => $name, 'capabilityName' => $name, 'data' => $this->capabilities->control( $name ) ]; }
		if ( ! $this->capabilities->has( $base ) || ! $this->capabilities->is_responsive( $base ) ) { return null; }
		return [ 'name' => $name, 'capabilityName' => $base, 'data' => $this->capabilities->control( $base ) ];
	}

	private function remove_control_family( array &$settings, array &$plan, string $base ): void {
		foreach ( self::DEVICES as $device ) { $name = 'desktop' === $device ? $base : $base . '_' . $device; unset( $settings[ $name ] ); $this->remove_plan( $plan, $name ); }
	}
	private function remove_plan( array &$plan, string $control ): void { $plan = array_values( array_filter( $plan, static fn( $entry ): bool => (string) ( $entry['control'] ?? '' ) !== $control ) ); }
	private function replace_plan( array &$plan, string $control, string $semantic, string $type, $value ): void {
		$this->remove_plan( $plan, $control );
		$plan[] = [ 'semanticPath' => $semantic, 'control' => $control, 'controlType' => $type, 'value' => $value ];
	}
	private function skip_once( array &$skipped, string $key, string $reason ): void {
		foreach ( $skipped as $item ) { if ( (string) ( $item['key'] ?? '' ) === $key && (string) ( $item['reason'] ?? '' ) === $reason ) { return; } }
		$skipped[] = [ 'key' => $key, 'reason' => $reason ];
	}
}
