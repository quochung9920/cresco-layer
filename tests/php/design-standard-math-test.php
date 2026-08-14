<?php
/**
 * Contrast and fluid-scale maths.
 *
 * These two carry the only objectively checkable design rules in the standard auditor, so they are
 * tested against known WCAG values and against the boundary conditions that produce nonsense CSS.
 */

require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/ContrastRatio.php';
require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/FluidScale.php';

use CrescoLayer\DesignSystem\ContrastRatio;
use CrescoLayer\DesignSystem\FluidScale;

function ds_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
function ds_close( ?float $actual, float $expected, float $tolerance, string $message ): void {
	ds_assert( null !== $actual && abs( $actual - $expected ) <= $tolerance, $message . ' (got ' . var_export( $actual, true ) . ')' );
}

/* ---------- Colour parsing ---------- */

ds_assert( [ 255, 255, 255 ] === ContrastRatio::parse( '#ffffff' ), 'Six-digit hex must parse.' );
ds_assert( [ 255, 255, 255 ] === ContrastRatio::parse( '#FFF' ), 'Shorthand hex must parse.' );
ds_assert( [ 0, 0, 0 ] === ContrastRatio::parse( '#000000ff' ), 'Eight-digit hex must drop the alpha channel.' );
ds_assert( [ 216, 166, 255 ] === ContrastRatio::parse( 'rgb(216, 166, 255)' ), 'rgb() must parse.' );
ds_assert( [ 216, 166, 255 ] === ContrastRatio::parse( 'rgba(216,166,255,0.5)' ), 'rgba() must parse.' );
ds_assert( null === ContrastRatio::parse( 'rebeccapurple' ), 'Named colours are not supported and must return null, not a guess.' );
ds_assert( null === ContrastRatio::parse( '' ), 'Empty input must return null.' );
ds_assert( null === ContrastRatio::parse( '#12345' ), 'Malformed hex must return null.' );

/* ---------- Known WCAG ratios ---------- */

ds_close( ContrastRatio::between( '#000000', '#ffffff' ), 21.0, 0.01, 'Black on white must be 21:1.' );
ds_close( ContrastRatio::between( '#ffffff', '#ffffff' ), 1.0, 0.01, 'Identical colours must be 1:1.' );
ds_close( ContrastRatio::between( '#777777', '#ffffff' ), 4.48, 0.05, 'Mid grey on white is the classic near-AA case.' );
ds_assert( ContrastRatio::between( '#ffffff', '#000000' ) === ContrastRatio::between( '#000000', '#ffffff' ), 'Contrast must be symmetric.' );
ds_assert( null === ContrastRatio::between( 'nope', '#fff' ), 'Unparseable input must not produce a ratio.' );

ds_assert( ContrastRatio::passes( 4.5 ), 'Exactly 4.5 must pass AA normal.' );
ds_assert( ! ContrastRatio::passes( 4.49 ), 'Just under 4.5 must fail AA normal.' );
ds_assert( ContrastRatio::passes( 3.2, ContrastRatio::AA_LARGE ), 'Large-text threshold must be honoured.' );

/* ---------- Contrast repair keeps the hue, only moves lightness ---------- */

$accent = '#D8A6FF';  // The pale purple from the user's own kit: fails badly on white.
$before = ContrastRatio::between( $accent, '#FFFFFF' );
ds_assert( null !== $before && $before < 4.5, 'The sample accent must genuinely fail AA on white.' );

$fixed = ContrastRatio::adjust_for( $accent, '#FFFFFF' );
ds_assert( null !== $fixed, 'A failing colour on white must be repairable.' );
$after = ContrastRatio::between( $fixed, '#FFFFFF' );
ds_assert( null !== $after && $after >= 4.5, 'The repaired colour must clear AA, got ' . var_export( $after, true ) );

$rgb = ContrastRatio::parse( $fixed );
ds_assert( $rgb[2] >= $rgb[0] && $rgb[2] >= $rgb[1], 'Repair must preserve the dominant blue channel of a purple hue.' );

// On a dark background the repair must move the other way.
$lightened = ContrastRatio::adjust_for( '#333333', '#000000' );
ds_assert( null !== $lightened, 'A dark colour on black must be repairable by lightening.' );
$lightened_rgb = ContrastRatio::parse( $lightened );
ds_assert( $lightened_rgb[0] > 51, 'Repair on a dark background must lighten, not darken.' );

// Already-passing colours need no change.
ds_assert( null === ContrastRatio::adjust_for( '#000000', '#FFFFFF' ), 'A passing colour must not be adjusted.' );

ds_assert( '#D8A6FF' === ContrastRatio::to_hex( [ 216, 166, 255 ] ), 'to_hex must round-trip.' );
ds_assert( '#000000' === ContrastRatio::to_hex( [ -20, 0, 0 ] ), 'to_hex must clamp out-of-range channels.' );

/* ---------- Fluid clamp ---------- */

$clamp = FluidScale::clamp( 40, 64, 480, 1440 );
ds_assert( null !== $clamp, 'A valid growth range must produce a clamp().' );
ds_assert( str_starts_with( $clamp, 'clamp(' ) && str_ends_with( $clamp, ')' ), 'Output must be a clamp() expression.' );
ds_assert( str_contains( $clamp, 'rem +' ) && str_contains( $clamp, 'vw' ), 'The middle term must combine rem and vw so zoom still works.' );
ds_assert( str_contains( $clamp, '2.5rem' ), '40px must be expressed as 2.5rem.' );
ds_assert( str_contains( $clamp, '4rem' ), '64px must be expressed as 4rem.' );

// The line must actually pass through both anchors.
preg_match( '/clamp\(([\d.]+)rem, ([\d.-]+)rem \+ ([\d.-]+)vw, ([\d.]+)rem\)/', $clamp, $m );
ds_assert( 5 === count( $m ), 'clamp() output must match the documented shape: ' . $clamp );
$at = static fn( float $viewport ): float => ( (float) $m[2] * 16 ) + ( (float) $m[3] / 100 * $viewport );
ds_close( $at( 480 ), 40.0, 0.5, 'The fluid value must equal the min size at the min viewport.' );
ds_close( $at( 1440 ), 64.0, 0.5, 'The fluid value must equal the max size at the max viewport.' );

// Degenerate inputs must return null rather than emit broken CSS.
ds_assert( null === FluidScale::clamp( 40, 40, 480, 1440 ), 'A flat scale has nothing to interpolate.' );
ds_assert( null === FluidScale::clamp( 64, 40, 480, 1440 ), 'An inverted scale must be refused.' );
ds_assert( null === FluidScale::clamp( 0, 40, 480, 1440 ), 'A zero minimum must be refused.' );
ds_assert( null === FluidScale::clamp( 40, 64, 1440, 1440 ), 'A zero viewport spread must be refused.' );

/* ---------- Viewport range comes from the site's real breakpoints ---------- */

[ $min, $max ] = FluidScale::viewport_range( [ 'mobile' => 767, 'tablet' => 1024 ] );
ds_assert( 767.0 === $min, 'The smallest breakpoint must anchor the fluid range.' );
ds_assert( $max > 1024, 'The range must extend past the largest breakpoint.' );

[ $min, $max ] = FluidScale::viewport_range( [ 'mobile' => [ 'value' => 600 ], 'tablet' => [ 'value' => 900 ] ] );
ds_assert( 600.0 === $min, 'Breakpoints given as Elementor arrays must be understood.' );

[ $min, $max ] = FluidScale::viewport_range( [] );
ds_assert( 480.0 === $min && 1440.0 === $max, 'A site without usable breakpoints must fall back to sane defaults.' );
[ $min, $max ] = FluidScale::viewport_range( [ 'only' => 768 ] );
ds_assert( 480.0 === $min, 'A single breakpoint cannot define a range and must fall back.' );

/* ---------- Type scale ---------- */

$scale = FluidScale::modular_scale( 16, 1.25, 4 );
ds_assert( 4 === count( $scale ), 'A modular scale must return the requested number of steps.' );
ds_assert( 16.0 === $scale[0], 'Step 0 is the base size.' );
ds_close( $scale[1], 20.0, 0.01, 'Step 1 of a 1.25 scale from 16px is 20px.' );
ds_close( $scale[3], 31.25, 0.01, 'Step 3 of a 1.25 scale from 16px is 31.25px.' );

ds_close( FluidScale::observed_ratio( [ 16, 20, 25, 31.25 ] ), 1.25, 0.01, 'A clean 1.25 scale must be detected.' );
$flat = FluidScale::observed_ratio( [ 30, 32 ] );
ds_assert( null !== $flat && $flat < 1.1, 'A nearly flat scale must report a ratio close to 1.' );
ds_assert( null === FluidScale::observed_ratio( [ 16 ] ), 'A single size has no ratio.' );
ds_assert( null === FluidScale::observed_ratio( [] ), 'No sizes means no ratio.' );

ds_close( FluidScale::to_rem( 24 ), 1.5, 0.0001, '24px is 1.5rem at a 16px root.' );

echo "Design standard maths tests passed.\n";
