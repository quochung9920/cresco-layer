<?php
namespace CrescoLayer\SiteSettings\Validation;

use CrescoLayer\SiteSettings\Discovery\RuntimeControlResolver;
use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;

/** Validates a five-context layout as one geometry/capability contract before diff/write. */
final class ResponsiveFoundationValidator {
	public function __construct( private RuntimeControlResolver $controls ) {}

	public function validate( array $spec ): array {
		$layout = (array) ( $spec['settings']['layout'] ?? [] );
		$errors = [];
		$warnings = [];

		$policy = (string) ( $layout['policy'] ?? '' );
		if ( '' !== $policy && ResponsiveLayoutPolicy::ID !== $policy ) {
			$warnings[] = $this->issue( 'unknown_layout_policy', 'The layout policy is not the Cresco five-context foundation.', [ 'policy' => $policy ] );
		}
		$strategy = (string) ( $layout['globalFluidStrategy'] ?? '' );
		if ( '' !== $strategy && ResponsiveLayoutPolicy::GLOBAL_FLUID_STRATEGY !== $strategy ) {
			$errors[] = $this->issue( 'global_fluid_strategy_mismatch', 'The professional profile must write supported fluid globals natively into Elementor.', [ 'strategy' => $strategy ] );
		}
		$width_strategy = (string) ( $layout['contentWidthStrategy'] ?? '' );
		if ( '' !== $width_strategy && ResponsiveLayoutPolicy::CONTENT_WIDTH_STRATEGY !== $width_strategy ) {
			$errors[] = $this->issue( 'content_width_strategy_mismatch', 'Content Width must follow the responsive canvas and use Custom Unit only when native px would exceed the live control range.', [ 'strategy' => $width_strategy ] );
		}

		$required = array_values( array_map( 'strval', (array) ( $layout['requiredDevices'] ?? [] ) ) );
		if ( $required && ResponsiveLayoutPolicy::devices() !== $required ) {
			$errors[] = $this->issue( 'required_devices_mismatch', 'The professional profile must declare exactly Mobile, Tablet, Laptop, Desktop and Widescreen in order.', [ 'requiredDevices' => $required ] );
		}

		$this->validate_breakpoints( $layout, $errors );
		$this->validate_capabilities( $errors, $warnings );
		$widths = $this->width_definitions( $layout );
		$this->validate_widths( $widths, $errors, $warnings );
		$this->validate_global_padding( (array) ( $layout['containerPadding'] ?? [] ), (array) ( $layout['pageGutter'] ?? [] ), $errors );
		$this->validate_gutters( (array) ( $layout['pageGutter'] ?? [] ), $widths, $errors );

		return [
			'policy' => $policy ?: ResponsiveLayoutPolicy::ID,
			'globalFluidStrategy' => $strategy ?: ResponsiveLayoutPolicy::GLOBAL_FLUID_STRATEGY,
			'contentWidthStrategy' => $width_strategy ?: ResponsiveLayoutPolicy::CONTENT_WIDTH_STRATEGY,
			'compatible' => ! $errors,
			'errors' => $errors,
			'warnings' => $warnings,
			'errorCount' => count( $errors ),
			'warningCount' => count( $warnings ),
		];
	}

	private function width_definitions( array $layout ): array {
		if ( ! empty( $layout['contentWidth'] ) && is_array( $layout['contentWidth'] ) ) { return (array) $layout['contentWidth']; }
		if ( empty( $layout['contentWidthPx'] ) || ! is_array( $layout['contentWidthPx'] ) ) { return []; }
		$out = [];
		foreach ( (array) $layout['contentWidthPx'] as $device => $size ) { $out[ (string) $device ] = [ 'unit' => 'px', 'size' => $size ]; }
		return $out;
	}

	private function validate_breakpoints( array $layout, array &$errors ): void {
		$breakpoints = (array) ( $layout['breakpoints'] ?? [] );
		$expected = ResponsiveLayoutPolicy::breakpoints();
		foreach ( $expected as $device => $value ) {
			if ( ! array_key_exists( $device, $breakpoints ) ) {
				$errors[] = $this->issue( 'missing_breakpoint', 'Required breakpoint is missing.', [ 'device' => $device ] );
				continue;
			}
			if ( (int) $breakpoints[ $device ] !== $value ) {
				$errors[] = $this->issue( 'breakpoint_value_mismatch', 'Breakpoint does not match the professional five-context foundation.', [ 'device' => $device, 'expected' => $value, 'actual' => (int) $breakpoints[ $device ] ] );
			}
		}
		$sequence = [ (int) ( $breakpoints['mobile'] ?? 0 ), (int) ( $breakpoints['tablet'] ?? 0 ), (int) ( $breakpoints['laptop'] ?? 0 ), (int) ( $breakpoints['widescreen'] ?? 0 ) ];
		if ( $sequence !== array_values( array_unique( $sequence ) ) || ! ( $sequence[0] < $sequence[1] && $sequence[1] < $sequence[2] && $sequence[2] < $sequence[3] ) ) {
			$errors[] = $this->issue( 'breakpoint_order_invalid', 'Breakpoints must increase strictly from Mobile to Widescreen.', [ 'values' => $sequence ] );
		}
	}

	private function validate_capabilities( array &$errors, array &$warnings ): void {
		foreach ( ResponsiveLayoutPolicy::breakpoints() as $device => $_ ) {
			$control = 'viewport_' . $device;
			if ( ! $this->controls->has( $control ) ) {
				$errors[] = $this->issue( 'required_breakpoint_unsupported', 'The running Elementor Kit does not expose a required breakpoint control.', [ 'control' => $control ] );
			}
		}
		if ( ! $this->controls->has( 'active_breakpoints' ) ) {
			$errors[] = $this->issue( 'active_breakpoints_unsupported', 'The running Elementor Kit does not expose active_breakpoints.' );
		}
		foreach ( [ 'container_width', 'container_padding' ] as $control ) {
			if ( ! $this->controls->has( $control ) ) {
				$errors[] = $this->issue( 'required_layout_control_unsupported', 'The running Elementor Kit does not expose a required responsive layout control.', [ 'control' => $control ] );
				continue;
			}
			if ( ! $this->controls->is_responsive( $control ) ) {
				$errors[] = $this->issue( 'layout_control_not_responsive', 'A required layout control is not responsive in this Elementor runtime.', [ 'control' => $control ] );
			}
			if ( ! $this->controls->supports_unit( $control, 'px' ) ) {
				$errors[] = $this->issue( 'px_unit_unsupported', 'A required layout control cannot be safely written in px.', [ 'control' => $control ] );
			}
		}
		if ( $this->controls->has( 'container_width' ) && ! $this->controls->supports_unit( 'container_width', '%' ) ) {
			$errors[] = $this->issue( 'content_width_percent_unit_unsupported', 'Desktop base Content Width requires Elementor percent-unit support so the canvas can remain 100%.', [ 'control' => 'container_width' ] );
		}
		if ( $this->controls->has( 'container_padding' ) && ! $this->controls->supports_unit( 'container_padding', 'custom' ) ) {
			$warnings[] = $this->issue(
				'container_padding_custom_unit_unsupported',
				'Elementor does not expose Custom Unit for Container Padding in this runtime; Cresco will use the profile px fallback instead of inventing an unsupported clamp() value.'
			);
		}
		if ( null === $this->controls->explicit_range( 'container_width', 'px' ) ) {
			$warnings[] = $this->issue( 'content_width_range_unavailable', 'Elementor did not expose a control-specific px range for Content Width; Cresco will use the policy range hint only for the Widescreen overflow decision.' );
		}
	}

	private function validate_widths( array $widths, array &$errors, array &$warnings ): void {
		$contexts = ResponsiveLayoutPolicy::contexts();
		$expected = ResponsiveLayoutPolicy::content_widths();
		$range = $this->controls->explicit_range( 'container_width', 'px' );
		foreach ( ResponsiveLayoutPolicy::devices() as $device ) {
			$definition = $this->normalize_width_definition( $widths[ $device ] ?? null );
			$wanted = $this->normalize_width_definition( $expected[ $device ] ?? null );
			if ( null === $definition || null === $wanted ) {
				$errors[] = $this->issue( 'content_width_missing', 'Content Width requires an explicit unit and value for every responsive context.', [ 'device' => $device ] );
				continue;
			}
			if ( $definition['unit'] !== $wanted['unit'] || (string) $definition['size'] !== (string) $wanted['size'] ) {
				$errors[] = $this->issue( 'content_width_canvas_mismatch', 'Content Width must follow the Cresco canvas contract for this device.', [ 'device' => $device, 'expected' => $wanted, 'actual' => $definition ] );
			}

			$unit = $definition['unit'];
			if ( 'custom' === $unit ) {
				if ( ! $this->controls->supports_unit( 'container_width', 'custom' ) ) { $errors[] = $this->issue( 'content_width_custom_unit_unsupported', 'The requested Content Width needs Custom Unit but Elementor does not expose it.', [ 'device' => $device ] ); }
				continue;
			}
			if ( ! $this->controls->supports_unit( 'container_width', $unit ) ) {
				$errors[] = $this->issue( 'content_width_unit_unsupported', 'Elementor does not support the requested Content Width unit.', [ 'device' => $device, 'unit' => $unit ] );
				continue;
			}
			$value = (float) $definition['size'];
			if ( $value <= 0 ) { $errors[] = $this->issue( 'content_width_invalid', 'Content Width must be greater than zero.', [ 'device' => $device, 'value' => $value ] ); continue; }
			if ( '%' === $unit ) {
				if ( $value > 100 ) { $errors[] = $this->issue( 'content_width_percent_invalid', 'Percent Content Width cannot exceed 100% for the base canvas.', [ 'device' => $device, 'value' => $value ] ); }
				continue;
			}

			$max_context = $contexts[ $device ]['max'];
			if ( null !== $max_context && $value > $max_context ) {
				$errors[] = $this->issue( 'content_width_exceeds_context', 'Content Width exceeds the maximum viewport of its context.', [ 'device' => $device, 'value' => $value, 'contextMax' => $max_context ] );
			}
			$max = is_array( $range ) && isset( $range['max'] ) ? (float) $range['max'] : ( isset( $definition['nativeMaxPxHint'] ) ? (float) $definition['nativeMaxPxHint'] : null );
			$min = is_array( $range ) && isset( $range['min'] ) ? (float) $range['min'] : null;
			if ( null !== $min && $value < $min ) { $errors[] = $this->issue( 'content_width_below_control_range', 'Content Width is below Elementor\'s explicit px range.', [ 'device' => $device, 'value' => $value, 'min' => $min ] ); }
			if ( null !== $max && $value > $max ) {
				if ( 'custom' === (string) ( $definition['overflowUnit'] ?? '' ) && $this->controls->supports_unit( 'container_width', 'custom' ) ) {
					$warnings[] = $this->issue( 'content_width_custom_overflow', 'Content Width exceeds the native px slider range and will be written through Elementor Custom Unit without reducing the requested canvas.', [ 'device' => $device, 'value' => $value, 'nativeMax' => $max, 'writtenCss' => $this->px_css( $value ) ] );
				} else {
					$errors[] = $this->issue( 'content_width_above_control_range_custom_unsupported', 'Content Width exceeds Elementor\'s native px range and Custom Unit is unavailable.', [ 'device' => $device, 'value' => $value, 'max' => $max ] );
				}
			}
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

	private function validate_global_padding( array $padding, array $gutters, array &$errors ): void {
		foreach ( ResponsiveLayoutPolicy::devices() as $device ) {
			$definition = is_array( $padding[ $device ] ?? null ) ? $padding[ $device ] : [];
			$gutter = is_array( $gutters[ $device ] ?? null ) ? $gutters[ $device ] : [];
			$expression = (string) ( $definition['fluid'] ?? '' );
			if ( null === $this->clamp_px_limits( $expression ) ) {
				$errors[] = $this->issue( 'global_container_padding_not_fluid', 'Global Elementor Container Padding must carry the responsive clamp() gutter on left/right; top/bottom are materialized as zero by the adapter.', [ 'device' => $device, 'definition' => $definition ] );
				continue;
			}
			if ( ! isset( $definition['fallbackPx'] ) || (float) $definition['fallbackPx'] <= 0 ) { $errors[] = $this->issue( 'global_container_padding_fallback_invalid', 'Global Container Padding requires a positive px fallback for runtimes that cannot render the custom value.', [ 'device' => $device, 'definition' => $definition ] ); }
			if ( $expression !== (string) ( $gutter['fluid'] ?? '' ) || (float) ( $definition['fallbackPx'] ?? 0 ) !== (float) ( $gutter['fallbackPx'] ?? 0 ) ) { $errors[] = $this->issue( 'global_container_padding_gutter_mismatch', 'Global Container Padding and the semantic page gutter must be the same professional fluid baseline.', [ 'device' => $devinition, 'pageGutter' => $gutter ] ); }
		}
	}

	private function validate_gutters( array $gutters, array $widths, array &$errors ): void {
		$contexts = ResponsiveLayoutPolicy::contexts();
		foreach ( ResponsiveLayoutPolicy::devices() as $device ) {
			$definition = is_array( $gutters[ $device ] ?? null ) ? $gutters[ $device ] : [];
			$expression = (string) ( $definition['fluid'] ?? '' );
			$limits = $this->clamp_px_limits( $expression );
			if ( null === $limits ) { $errors[] = $this->issue( 'page_gutter_invalid', 'Page gutter must be a clamp() with px minimum and maximum values.', [ 'device' => $device, 'value' => $expression ] ); continue; }
			if ( $limits['min'] > $limits['max'] ) { $errors[] = $this->issue( 'page_gutter_range_invalid', 'Page gutter minimum cannot exceed its maximum.', [ 'device' => $device, 'min' => $limits['min'], 'max' => $limits['max'] ] ); }
			$width_definition = $this->normalize_width_definition( $widths[ $device ] ?? null );
			$width = 0.0;
			if ( is_array( $width_definition ) ) {
				if ( 'px' === $width_definition['unit'] ) { $width = (float) $width_definition['size']; }
				if ( '%' === $width_definition['unit'] ) { $width = (float) $contexts[ $device ]['min'] * ( (float) $width_definition['size'] / 100 ); }
			}
			if ( $width > 0 && ( 2 * $limits['max'] ) >= $width ) { $errors[] = $this->issue( 'page_gutter_consumes_content', 'The maximum horizontal gutter leaves no safe content area.', [ 'device' => $device, 'gutterMax' => $limits['max'], 'contentWidth' => $width ] ); }
		}
	}

	private function px_css( float $value ): string {
		$formatted = floor( $value ) === $value ? (string) (int) $value : rtrim( rtrim( sprintf( '%.4F', $value ), '0' ), '.' );
		return $formatted . 'px';
	}
	private function clamp_px_limits( string $expression ): ?array {
		if ( ! preg_match( '/^clamp\(\s*(-?(?:\d+(?:\.\d+)?|\.\d+))px\s*,.*?,,s*(-?(?:\d+(?:\.\d+)?|\.\d+))px\s*\)$/i', trim( $expression ), $match ) ) { return null; }
		return [ 'min' => (float) $match[1], 'max' => (float) $match[2] ];
	}
	private function issue( string $code, string $message, array $data = [] ): array { return [ 'code' => $code, 'message' => $message, 'data' => $data ]; }
}
