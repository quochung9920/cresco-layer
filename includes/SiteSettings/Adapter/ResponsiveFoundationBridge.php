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

		if ( ! empty( $layout['contentWidthPx'] ) ) {
			$this->remove_control_family( $settings, $plan, 'container_width' );
			$this->map_widths( (array) $layout['contentWidthPx'], 'container_width', 'settings.layout.contentWidth', $settings, $plan, $skipped );
		}
		if ( ! empty( $layout['containerPadding'] ) ) {
			$this->remove_control_family( $settings, $plan, 'container_padding' );
			$this->map_padding( (array) $layout['containerPadding'], 'container_padding', 'settings.layout.containerPadding', $settings, $plan, $skipped, $notes );
		}
		if ( ! empty( $layout['breakpoints'] ) ) { $this->map_breakpoints( $layout, $settings, $plan, $skipped ); }

		foreach ( [ 'helloHeader' => 'hello_header', 'helloFooter' => 'hello_footer' ] as $section => $prefix ) {
			$definition = (array) ( $spec['themeStyle'][ $section ] ?? [] );
			if ( empty( $definition['contentWidthPx'] ) ) { continue; }
			$base = $prefix . '_custom_width';
			$this->remove_control_family( $settings, $plan, $base );
			$this->map_widths( (array) $definition['contentWidthPx'], $base, 'themeStyle.' . $section . '.contentWidth', $settings, $plan, $skipped );
		}
		$built['settings'] = $settings;
		$built['plan'] = array_values( $plan );
		$built['skipped'] = array_values( $skipped );
		$built['notes'] = array_values( $notes );
		return $built;
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

	private function map_widths( array $definitions, string $base, string $semantic, array &$settings, array &$plan, array &$skipped ): void {
		foreach ( self::DEVICES as $device ) {
			if ( ! array_key_exists( $device, $definitions ) ) { continue; }
			if ( empty( $this->enabledDevices[ $device ] ) ) { $this->skip_once( $skipped, $semantic . '.' . $device, 'breakpoint_unsupported' ); continue; }
			$control = $this->responsive_control( $base, $device );
			if ( null === $control ) { $this->skip_once( $skipped, $semantic . '.' . $device, 'unsupported_control' ); continue; }
			if ( ! $this->capabilities->supports_unit( $control['capabilityName'], 'px' ) ) { $this->skip_once( $skipped, $semantic . '.' . $device, 'px_unit_unsupported' ); continue; }
			$name = $control['name'];
			$data = $control['data'];
			$value = $this->factory->slider_shape( 'px', (float) $definitions[ $device ] );
			$settings[ $name ] = $value;
			$this->replace_plan( $plan, $name, $semantic . '.' . $device, (string) ( $data['type'] ?? 'slider' ), $value );
		}
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
