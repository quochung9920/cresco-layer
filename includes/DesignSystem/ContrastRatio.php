<?php
namespace CrescoLayer\DesignSystem;

/**
 * WCAG 2.1 relative luminance and contrast ratio.
 *
 * Kept free of WordPress and Elementor so the colour maths can be unit tested directly. Contrast is
 * the one design rule that is objectively measurable, which is why the standard auditor leans on it
 * instead of on taste.
 */
final class ContrastRatio {
	/** WCAG AA for normal text. */
	public const AA_NORMAL = 4.5;
	/** WCAG AA for large text (>= 24px, or >= 18.66px bold). */
	public const AA_LARGE = 3.0;
	/** WCAG AAA for normal text. */
	public const AAA_NORMAL = 7.0;

	/**
	 * Parse #rgb, #rrggbb, rgb()/rgba() into [r, g, b] 0-255, or null when unparseable.
	 * Elementor stores colours as authored, so all of these show up in real kits.
	 */
	public static function parse( string $color ): ?array {
		$value = strtolower( trim( $color ) );
		if ( '' === $value ) { return null; }

		if ( preg_match( '/^#([0-9a-f]{3,8})$/', $value, $match ) ) {
			$hex = $match[1];
			if ( 3 === strlen( $hex ) || 4 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			} elseif ( 6 === strlen( $hex ) || 8 === strlen( $hex ) ) {
				$hex = substr( $hex, 0, 6 );
			} else {
				return null;
			}
			return [ hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ];
		}

		if ( preg_match( '/^rgba?\(([^)]+)\)$/', $value, $match ) ) {
			$parts = preg_split( '/[\s,\/]+/', trim( $match[1] ) );
			$parts = array_values( array_filter( (array) $parts, static fn( $part ): bool => '' !== $part ) );
			if ( count( $parts ) < 3 ) { return null; }
			$rgb = [];
			for ( $i = 0; $i < 3; $i++ ) {
				$part = $parts[ $i ];
				if ( str_ends_with( $part, '%' ) ) {
					$rgb[] = (int) round( ( (float) rtrim( $part, '%' ) / 100 ) * 255 );
				} elseif ( is_numeric( $part ) ) {
					$rgb[] = (int) round( (float) $part );
				} else {
					return null;
				}
			}
			foreach ( $rgb as $channel ) {
				if ( $channel < 0 || $channel > 255 ) { return null; }
			}
			return $rgb;
		}

		return null;
	}

	/** WCAG relative luminance (0 = black, 1 = white). */
	public static function luminance( array $rgb ): float {
		$channels = [];
		foreach ( $rgb as $value ) {
			$srgb = max( 0.0, min( 1.0, ( (float) $value ) / 255 ) );
			$channels[] = $srgb <= 0.04045 ? $srgb / 12.92 : pow( ( $srgb + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/** Contrast ratio between two colours, 1.0 (identical) to 21.0 (black on white). Null if unparseable. */
	public static function between( string $foreground, string $background ): ?float {
		$fg = self::parse( $foreground );
		$bg = self::parse( $background );
		if ( null === $fg || null === $bg ) { return null; }
		$lighter = max( self::luminance( $fg ), self::luminance( $bg ) );
		$darker  = min( self::luminance( $fg ), self::luminance( $bg ) );
		return round( ( $lighter + 0.05 ) / ( $darker + 0.05 ), 2 );
	}

	public static function passes( float $ratio, float $threshold = self::AA_NORMAL ): bool {
		return $ratio >= $threshold;
	}

	/**
	 * Darken or lighten a colour just enough to clear a contrast threshold against a background,
	 * preserving hue. Returns null when even pure black/white cannot reach the threshold.
	 *
	 * This is what lets the auditor propose a concrete fix instead of only reporting a failure: the
	 * brand hue is kept and only its lightness moves.
	 */
	public static function adjust_for( string $foreground, string $background, float $threshold = self::AA_NORMAL ): ?string {
		$fg = self::parse( $foreground );
		$bg = self::parse( $background );
		if ( null === $fg || null === $bg ) { return null; }

		$current = self::between( $foreground, $background );
		if ( null !== $current && $current >= $threshold ) { return null; }

		// Move away from the background: darken on light backgrounds, lighten on dark ones.
		$toward_black = self::luminance( $bg ) > 0.5;
		$best = null;
		for ( $step = 1; $step <= 100; $step++ ) {
			$factor = $step / 100;
			$candidate = [];
			foreach ( $fg as $channel ) {
				$candidate[] = $toward_black
					? (int) round( $channel * ( 1 - $factor ) )
					: (int) round( $channel + ( 255 - $channel ) * $factor );
			}
			$hex = self::to_hex( $candidate );
			$ratio = self::between( $hex, $background );
			if ( null !== $ratio && $ratio >= $threshold ) { $best = $hex; break; }
		}
		return $best;
	}

	public static function to_hex( array $rgb ): string {
		$out = '#';
		foreach ( array_slice( $rgb, 0, 3 ) as $channel ) {
			$out .= str_pad( dechex( max( 0, min( 255, (int) $channel ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return strtoupper( $out );
	}
}
