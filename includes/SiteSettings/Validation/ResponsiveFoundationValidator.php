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

		$required = array_values( array_map( 'strval', (array) ( $layout['requiredDevices'] ?? [] ) ) );
		if ( $required && ResponsiveLayoutPolicy::devices() !== $required ) {
			$errors[] = $this->issue( 'required_devices_mismatch', 'The professional profile must declare exactly Mobile, Tablet, Laptop, Desktop and Widescreen in order.', [ 'requiredDevices' => $required ] );
		}

		$this->validate_breakpoints( $layout, $errors );
		$this->validate_capabilities( $errors, $warnings );
		$this->validate_widths( (array) ( $layout['contentWidthPx'] ?? [] ), $errors, $warnings );
		$this->validate_global_padding( (array) ( $layout['containerPadding'] ?? [] ), $errors );
		$this->validate_gutters( (array) ( $layout['pageGutter'] ?? [] ), (array) ( $layout['contentWidthPx'] ?? [] ), $errors );

		return [
			'policy' => $policy ?: ResponsiveLayoutPolicy::ID,
			'compatible' => ! $errors,
			'errors' => $errors,
			'warnings' => $warnings,
			'errorCount' => count( $errors ),
			'warningCount' => count( $warnings ),
		];
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

		if ( null === $this->controls->explicit_range( 'container_width', 'px' ) ) {
			$warnings[] = $this->issue( 'content_width_range_unavailable', 'Elementor did not expose a control-specific px range for Content Width; Cresco will validate geometry but cannot enforce the editor slider range from metadata.' );
		}
	}

	private function validate_widths( array $widths, array &$errors, array &$warnings ): void {
		$contexts = ResponsiveLayoutPolicy::contexts();
		$range = $this->controls->explicit_range( 'container_width', 'px' );
		foreach ( ResponsiveLayoutPolicy::devices() as $device ) {
			if ( ! isset( $widths[ $device ] ) || ! is_numeric( $widths[ $device ] ) ) {
				$errors[] = $this->issue( 'content_width_missing', 'Content Width must be a numeric px value for every context.', [ 'device' => $device ] );
				continue;
			}
			$value = (float) $widths[ $device ];
			if ( $value <= 0 ) {
				$errors[] = $this->issue( 'content_width_invalid', 'Content Width must be greater than zero.', [ 'device' => $device, 'value' => $value ] );
			}
			$max = $contexts[ $device ]['max'];
			if ( null !== $max && $value > $max ) {
				$errors[] = $this->issue( 'content_width_exceeds_context', 'Content Width exceeds the maximum viewport of its context.', [ 'device' => $device, 'value' => $value, 'contextMax' => $max ] );
			}
			if ( is_array( $range ) ) {
				if ( isset( $range['min'] ) && $value < (float) $range['min'] ) {
					$errors[] = $this->issue( 'content_width_below_control_range', 'Content Width is below Elementor\'s explicit px range.', [ 'device' => $device, 'value' => $value, 'min' => $range['min'] ] );
				}
				if ( isset( $range['max'] ) && $value > (float) $range['max'] ) {
					$errors[] = $this->issue( 'content_width_above_control_range', 'Content Width exceeds Elementor\'s explicit px range.', [ 'device' => $device, 'value' => $value, 'max' => $range['max'] ] );
				}
			}
		}
		if ( $widths && isset( $widths['desktop'], $widths['widescreen'] ) && (float) $widths['widescreen'] < (float) $widths['desktop'] ) {
			$warnings[] = $this->issue( 'widescreen_narrower_than_desktop', 'Widescreen content max-width is narrower than Desktop.', [ 'desktop' => $widths['desktop'], 'widescreen' => $widths['widescreen'] ] );
		}
	}

	private function validate_global_padding( array $padding, array &$errors ): void {
		foreach ( ResponsiveLayoutPolicy::devices() as $device ) {
			$definition = is_array( $padding[ $device ] ?? null ) ? $padding[ $device ] : [];
			if ( ! array_key_exists( 'fixedPx', $definition ) || 0.0 !== (float) $definition['fixedPx'] ) {
				$errors[] = $this->issue( 'global_container_padding_must_be_zero', 'Global Elementor Container Padding must be 0; page gutters belong to section-shell containers.', [ 'device' => $device, 'definition' => $definition ] );
			}
		}
	}

	private function validate_gutters( array $gutters, array $widths, array &$errors ): void {
		foreach ( ResponsiveLayoutPolicy::devices() as $device ) {
			$definition = is_array( $gutters[ $device ] ?? null ) ? $gutters[ $device ] : [];
			$expression = (string) ( $definition['fluid'] ?? '' );
			$limits = $this->clamp_px_limits( $expression );
			if ( null === $limits ) {
				$errors[] = $this->issue( 'page_gutter_invalid', 'Page gutter must be a clamp() with px minimum and maximum values.', [ 'device' => $device, 'value' => $expression ] );
				continue;
			}
			if ( $limits['min'] > $limits['max'] ) {
				$errors[] = $this->issue( 'page_gutter_range_invalid', 'Page gutter minimum cannot exceed its maximum.', [ 'device' => $device, 'min' => $limits['min'], 'max' => $limits['max'] ] );
			}
			$width = isset( $widths[ $device ] ) && is_numeric( $widths[ $device ] ) ? (float) $widths[ $device ] : 0.0;
			if ( $width > 0 && ( 2 * $limits['max'] ) >= $width ) {
				$errors[] = $this->issue( 'page_gutter_consumes_content', 'The maximum horizontal gutter leaves no safe content area.', [ 'device' => $device, 'gutterMax' => $limits['max'], 'contentWidth' => $width ] );
			}
		}
	}

	/** @return array{min:float,max:float}|null */
	private function clamp_px_limits( string $expression ): ?array {
		if ( ! preg_match( '/^clamp\(\s*(-?(?:\d+(?:\.\d+)?|\.\d+))px\s*,.*?,\s*(-?(?:\d+(?:\.\d+)?|\.\d+))px\s*\)$/i', trim( $expression ), $match ) ) {
			return null;
		}
		return [ 'min' => (float) $match[1], 'max' => (float) $match[2] ];
	}

	private function issue( string $code, string $message, array $data = [] ): array {
		return [ 'code' => $code, 'message' => $message, 'data' => $data ];
	}
}
