<?php
namespace CrescoLayer\DesignSystem;

/**
 * Fluid CSS clamp() generation from the site's real viewport range.
 *
 * Setting a separate font size per device leaves visible jumps at each breakpoint and leaves the
 * sizes between them unconsidered. A clamp() built from the actual viewport range scales smoothly
 * across every width instead.
 *
 * The generated form is `clamp(min, <intercept>rem + <slope>vw, max)`, which keeps the middle term
 * anchored in rem so browser zoom and user font-size preferences still work — a bare vw middle term
 * breaks both, which is why it is never emitted here.
 *
 * Kept free of WordPress so the maths can be unit tested directly.
 */
final class FluidScale {
	/** CSS root font size assumed when converting px to rem. */
	public const ROOT_PX = 16.0;
	private const MIN_VIEWPORT_SPREAD = 1.0;

	/**
	 * Build a clamp() that grows from $min_px at $min_viewport to $max_px at $max_viewport.
	 *
	 * @return string|null Null when the inputs cannot produce a meaningful fluid value.
	 */
	public static function clamp( float $min_px, float $max_px, float $min_viewport, float $max_viewport ): ?string {
		if ( $min_px <= 0 || $max_px <= 0 ) { return null; }
		if ( $max_viewport - $min_viewport < self::MIN_VIEWPORT_SPREAD ) { return null; }
		// A flat or inverted scale has nothing to interpolate; the caller should keep the fixed value.
		if ( $max_px <= $min_px ) { return null; }

		$min_rem = self::to_rem( $min_px );
		$max_rem = self::to_rem( $max_px );

		// slope in px per px of viewport, expressed as vw; intercept keeps the line through both anchors.
		$slope = ( $max_px - $min_px ) / ( $max_viewport - $min_viewport );
		$intercept_px = $min_px - ( $slope * $min_viewport );
		$slope_vw = $slope * 100;
		$intercept_rem = self::to_rem( $intercept_px );

		return sprintf(
			'clamp(%srem, %srem + %svw, %srem)',
			self::number( $min_rem ),
			self::number( $intercept_rem ),
			self::number( $slope_vw ),
			self::number( $max_rem )
		);
	}

	/**
	 * Viewport anchors derived from the site's active Elementor breakpoints.
	 *
	 * The smallest breakpoint marks where the mobile size should apply, and the largest marks where
	 * growth should stop. Hardcoding 375/1440 would ignore a site that has customised its breakpoints.
	 *
	 * @param array<string,int|float> $breakpoints Elementor breakpoint values keyed by name.
	 * @return array{0:float,1:float} [ min viewport px, max viewport px ]
	 */
	public static function viewport_range( array $breakpoints ): array {
		$values = [];
		foreach ( $breakpoints as $value ) {
			if ( is_array( $value ) ) { $value = $value['value'] ?? null; }
			if ( is_numeric( $value ) && (float) $value > 0 ) { $values[] = (float) $value; }
		}
		if ( count( $values ) < 2 ) { return [ 480.0, 1440.0 ]; }
		sort( $values );
		$min = $values[0];
		$max = $values[ count( $values ) - 1 ];
		// Elementor breakpoint values are max-widths, so the largest one is where the desktop layout
		// starts rather than ends; give the fluid range room above it.
		if ( $max <= $min + self::MIN_VIEWPORT_SPREAD ) { return [ 480.0, 1440.0 ]; }
		return [ $min, max( $max * 1.25, $min + 200 ) ];
	}

	/**
	 * A modular type scale: each step multiplies the previous one by $ratio.
	 *
	 * @return array<int,float> Sizes in px, index 0 = base, ascending.
	 */
	public static function modular_scale( float $base_px, float $ratio, int $steps ): array {
		$out = [];
		for ( $i = 0; $i < max( 1, $steps ); $i++ ) {
			$out[] = round( $base_px * pow( $ratio, $i ), 2 );
		}
		return $out;
	}

	/**
	 * The observed ratio between consecutive sizes, used to tell a deliberate scale from a flat one.
	 *
	 * @param array<int,float> $sizes Ascending sizes.
	 */
	public static function observed_ratio( array $sizes ): ?float {
		$clean = array_values( array_filter( $sizes, static fn( $size ): bool => is_numeric( $size ) && $size > 0 ) );
		if ( count( $clean ) < 2 ) { return null; }
		sort( $clean );
		$ratios = [];
		for ( $i = 1; $i < count( $clean ); $i++ ) {
			$previous = (float) $clean[ $i - 1 ];
			if ( $previous <= 0 ) { continue; }
			$ratios[] = (float) $clean[ $i ] / $previous;
		}
		if ( ! $ratios ) { return null; }
		return round( array_sum( $ratios ) / count( $ratios ), 3 );
	}

	public static function to_rem( float $px ): float {
		return round( $px / self::ROOT_PX, 4 );
	}

	/** Trim trailing zeros so the emitted CSS stays readable. */
	private static function number( float $value ): string {
		$text = rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
		return '' === $text || '-' === $text ? '0' : $text;
	}
}
