<?php
namespace CrescoLayer\SiteSettings\Support;

/**
 * Builds Elementor control value shapes, choosing a fluid or native form from what the control
 * actually supports.
 *
 * Elementor renders a `custom` unit by substituting an empty unit, so the stored size reaches CSS
 * verbatim — that is what allows a clamp() to live in a slider. When a control does not offer the
 * custom unit, emitting one would produce a broken declaration, so the factory falls back to a
 * plain native value instead of failing the whole operation.
 */
final class ValueFactory {
	public function __construct( private ClampValidator $clamp ) {}

	/** Why a fluid expression is unsafe, or null when it may be emitted. */
	public function clamp_rejection( string $expression ): ?string {
		return $this->clamp->rejection_reason( $expression );
	}

	/**
	 * A slider value: fluid when the control accepts it and the expression is safe, native otherwise.
	 *
	 * @param string     $expression Fluid expression, e.g. clamp(...) or var(--cresco-...).
	 * @param float      $fallback   Native size used when the custom unit is unavailable.
	 * @return array{value:array,fluid:bool,reason:string}
	 */
	public function slider( string $expression, float $fallback, bool $supports_custom, string $fallback_unit = 'px' ): array {
		if ( ! $supports_custom ) {
			return [ 'value' => $this->slider_shape( $fallback_unit, $fallback ), 'fluid' => false, 'reason' => 'custom_unit_unsupported' ];
		}
		$rejection = $this->clamp->rejection_reason( $expression );
		if ( null !== $rejection ) {
			return [ 'value' => $this->slider_shape( $fallback_unit, $fallback ), 'fluid' => false, 'reason' => 'invalid_value:' . $rejection ];
		}
		return [ 'value' => $this->slider_shape( 'custom', $expression ), 'fluid' => true, 'reason' => 'custom_unit' ];
	}

	/**
	 * The complete slider value shape.
	 *
	 * Control_Slider::get_default_value() is `[ unit, size, sizes ]`, and Elementor merges that default
	 * into whatever is stored when the settings are read back. Writing a partial shape therefore round
	 * trips as a different array than the one that was sent, which reads as a failed write.
	 */
	public function slider_shape( string $unit, $size ): array {
		return [ 'unit' => $unit, 'size' => $size, 'sizes' => [] ];
	}

	/**
	 * A dimensions value (padding, margin, radius). With the custom unit each side carries its own
	 * expression; otherwise every side falls back to a native number.
	 *
	 * @param array{top:string,right:string,bottom:string,left:string} $sides
	 * @param array{top:float,right:float,bottom:float,left:float}     $fallback
	 * @return array{value:array,fluid:bool,reason:string}
	 */
	public function dimensions( array $sides, array $fallback, bool $supports_custom, bool $linked = false, string $fallback_unit = 'px' ): array {
		$native = [
			'unit' => $fallback_unit,
			'top' => (string) ( $fallback['top'] ?? 0 ),
			'right' => (string) ( $fallback['right'] ?? 0 ),
			'bottom' => (string) ( $fallback['bottom'] ?? 0 ),
			'left' => (string) ( $fallback['left'] ?? 0 ),
			'isLinked' => $linked,
		];
		if ( ! $supports_custom ) {
			return [ 'value' => $native, 'fluid' => false, 'reason' => 'custom_unit_unsupported' ];
		}
		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			$rejection = $this->clamp->rejection_reason( (string) ( $sides[ $side ] ?? '' ) );
			if ( null !== $rejection ) {
				return [ 'value' => $native, 'fluid' => false, 'reason' => 'invalid_value:' . $rejection ];
			}
		}
		return [
			'value' => [
				'unit' => 'custom',
				'top' => (string) $sides['top'],
				'right' => (string) $sides['right'],
				'bottom' => (string) $sides['bottom'],
				'left' => (string) $sides['left'],
				'isLinked' => $linked,
			],
			'fluid' => true,
			'reason' => 'custom_unit',
		];
	}

	/** A uniform dimensions value on all four sides. */
	public function dimensions_uniform( string $expression, float $fallback, bool $supports_custom, string $fallback_unit = 'px' ): array {
		return $this->dimensions(
			[ 'top' => $expression, 'right' => $expression, 'bottom' => $expression, 'left' => $expression ],
			[ 'top' => $fallback, 'right' => $fallback, 'bottom' => $fallback, 'left' => $fallback ],
			$supports_custom,
			true,
			$fallback_unit
		);
	}

	/** A repeater row for a global colour. */
	public function color_row( string $id, string $title, string $color ): array {
		return [ '_id' => $id, 'title' => $title, 'color' => strtoupper( $color ) ];
	}

	/**
	 * A repeater row for global typography. Only the properties the caller declares are written, so
	 * a global token never gains a size it did not ask for — that would resize every widget bound to it.
	 */
	public function typography_row( string $id, string $title, array $properties ): array {
		$row = [ '_id' => $id, 'title' => $title, 'typography_typography' => 'custom' ];
		foreach ( $properties as $key => $value ) {
			$row[ 'typography_' . $key ] = $value;
		}
		return $row;
	}
}
