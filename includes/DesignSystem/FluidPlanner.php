<?php
namespace CrescoLayer\DesignSystem;

/**
 * Converts the Kit's per-device font sizes into fluid clamp() values.
 *
 * Elementor renders a `custom` unit by replacing the unit placeholder with __EMPTY__, so the stored
 * size is emitted into CSS verbatim. That is what makes `[ 'unit' => 'custom', 'size' => 'clamp(…)' ]`
 * the correct shape here — anything else would append a stray unit to the expression.
 *
 * A device override left in place would win over the fluid value at that breakpoint and defeat the
 * whole point, so the plan removes the overrides it replaces and says so in each operation's reason.
 * Everything is reversible through the normal patch history.
 */
final class FluidPlanner {
	public const SCHEMA = 'cresco-fluid-typography/v1';

	/** Elementor's responsive suffixes, smallest device first. */
	private const DEVICE_SUFFIXES = [ '_mobile', '_tablet' ];
	/** When no mobile override exists, assume the mobile size is this fraction of the desktop one. */
	private const DEFAULT_MOBILE_FACTOR = 0.72;
	/** Below this the fluid range is not worth the indirection. */
	private const MIN_GROWTH_PX = 2.0;

	public function __construct( private KitSource $kit ) {}

	public function plan(): array {
		$kit = $this->kit->read();
		if ( ! $kit['available'] ) {
			return [ 'schema' => self::SCHEMA, 'available' => false, 'items' => [], 'operations' => [], 'errors' => $kit['errors'] ];
		}

		[ $min_viewport, $max_viewport ] = FluidScale::viewport_range( $kit['breakpoints'] );

		$items = [];
		$operations = [];
		$skipped = [];

		foreach ( $this->kit->font_size_controls() as $name => $control ) {
			// A responsive variant is handled with its base control, never on its own.
			if ( $this->is_device_variant( $name ) ) { continue; }

			$desktop = $this->px_value( $this->kit->setting( $name ) );
			if ( null === $desktop || $desktop <= 0 ) { continue; }

			if ( ! $this->kit->supports_custom_unit( $name ) ) {
				$skipped[] = [ 'setting' => $name, 'reason' => 'This control does not accept Elementor\'s custom unit, so it cannot hold a clamp() expression.' ];
				continue;
			}

			$mobile = $this->smallest_device_value( $name );
			$derived = null === $mobile;
			if ( null === $mobile ) { $mobile = round( $desktop * self::DEFAULT_MOBILE_FACTOR, 2 ); }

			if ( $desktop - $mobile < self::MIN_GROWTH_PX ) {
				$skipped[] = [ 'setting' => $name, 'reason' => sprintf( 'Desktop %spx and mobile %spx are too close to benefit from a fluid range.', $desktop, $mobile ) ];
				continue;
			}

			$expression = FluidScale::clamp( $mobile, $desktop, $min_viewport, $max_viewport );
			if ( null === $expression ) {
				$skipped[] = [ 'setting' => $name, 'reason' => 'The size range could not produce a valid clamp() expression.' ];
				continue;
			}

			$replaced = $this->existing_device_overrides( $name );
			$items[] = [
				'setting' => $name,
				'minPx' => $mobile,
				'maxPx' => $desktop,
				'mobileDerived' => $derived,
				'expression' => $expression,
				'replacesOverrides' => $replaced,
			];
			$operations[] = [
				'operation' => 'update-page-setting',
				'setting' => $name,
				'value' => [ 'unit' => 'custom', 'size' => $expression ],
				'crescoReason' => sprintf( 'Scale %s fluidly from %spx to %spx across the site\'s real viewport range.', $name, $mobile, $desktop ),
			];
			foreach ( $replaced as $override ) {
				$operations[] = [
					'operation' => 'remove-page-setting',
					'setting' => $override,
					'crescoReason' => 'A device override would win over the fluid value at this breakpoint, so it is removed.',
				];
			}
		}

		return [
			'schema' => self::SCHEMA,
			'available' => true,
			'kitPostId' => $kit['postId'],
			'viewportRange' => [ 'min' => $min_viewport, 'max' => $max_viewport ],
			'items' => $items,
			'skipped' => $skipped,
			'operations' => $operations,
			'errors' => $kit['errors'],
		];
	}

	private function is_device_variant( string $name ): bool {
		foreach ( self::DEVICE_SUFFIXES as $suffix ) {
			if ( str_ends_with( $name, $suffix ) ) { return true; }
		}
		// Custom breakpoints add their own suffixes; treat any _<device> tail on a font size as a variant.
		return (bool) preg_match( '/_(?:mobile|tablet|laptop|widescreen|mobile_extra|tablet_extra)(?:_extra)?$/', $name );
	}

	/** The smallest device override actually set for this control, in px. */
	private function smallest_device_value( string $name ): ?float {
		$found = [];
		foreach ( self::DEVICE_SUFFIXES as $suffix ) {
			$px = $this->px_value( $this->kit->setting( $name . $suffix ) );
			if ( null !== $px && $px > 0 ) { $found[] = $px; }
		}
		if ( ! $found ) { return null; }
		return min( $found );
	}

	/** @return string[] Device override setting names that currently hold a value. */
	private function existing_device_overrides( string $name ): array {
		$out = [];
		foreach ( self::DEVICE_SUFFIXES as $suffix ) {
			$key = $name . $suffix;
			if ( null !== $this->px_value( $this->kit->setting( $key ) ) ) { $out[] = $key; }
		}
		return $out;
	}

	private function px_value( $value ): ?float {
		if ( is_numeric( $value ) ) { return (float) $value; }
		if ( ! is_array( $value ) || ! isset( $value['size'] ) || ! is_numeric( $value['size'] ) ) { return null; }
		$size = (float) $value['size'];
		$unit = strtolower( (string) ( $value['unit'] ?? 'px' ) );
		if ( 'px' === $unit ) { return $size; }
		if ( 'rem' === $unit || 'em' === $unit ) { return $size * FluidScale::ROOT_PX; }
		return null;
	}
}
