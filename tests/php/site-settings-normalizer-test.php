<?php
/**
 * ValueNormalizer / Verifier semantic comparison contract.
 *
 * Elementor does not store back exactly what it is given, so verification has to compare meaning
 * rather than representation. Every case here is a difference that must NOT be reported as a
 * mismatch, paired with a real difference that must still be caught — a normalizer that accepts
 * everything is as broken as one that accepts nothing.
 */

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
}

$base = dirname( __DIR__, 2 ) . '/includes/SiteSettings/';
require_once $base . 'Support/ManagedCssBlock.php';
require_once $base . 'Verify/ValueNormalizer.php';
require_once $base . 'Verify/Verifier.php';

use CrescoLayer\SiteSettings\Verify\ValueNormalizer;
use CrescoLayer\SiteSettings\Verify\Verifier;

function norm_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$n = new ValueNormalizer();

/* ---------- Slider: control defaults, number/string, custom units ---------- */

norm_assert(
	$n->satisfies( [ 'unit' => 'custom', 'size' => 'clamp(2.25rem, 1.6rem + 2.6vw, 4rem)', 'sizes' => [] ], [ 'unit' => 'custom', 'size' => 'clamp(2.25rem, 1.6rem + 2.6vw, 4rem)' ], 'slider' ),
	'The sizes key Elementor adds from the slider default must not be a mismatch.'
);
norm_assert(
	$n->satisfies( [ 'unit' => 'px', 'size' => '16', 'sizes' => [] ], [ 'unit' => 'px', 'size' => 16 ], 'slider' ),
	'"16" and 16 are the same slider size.'
);
norm_assert(
	$n->satisfies( [ 'unit' => 'px', 'size' => 16.0 ], [ 'unit' => 'px', 'size' => 16 ], 'slider' ),
	'16.0 and 16 are the same slider size.'
);
norm_assert(
	$n->satisfies( [ 'unit' => 'custom', 'size' => 'clamp(1rem,2vw,3rem)' ], [ 'unit' => 'custom', 'size' => 'clamp( 1rem , 2vw , 3rem )' ], 'slider' ),
	'Whitespace inside a fluid expression is not meaning.'
);
norm_assert(
	$n->satisfies( [ 'unit' => 'px', 'size' => 1, 'sizes' => [] ], [ 'size' => 1 ], 'slider' ),
	'A control with no unit switcher must not be judged against Elementor\'s default unit.'
);

norm_assert( ! $n->satisfies( [ 'unit' => 'px', 'size' => 16 ], [ 'unit' => 'px', 'size' => 18 ], 'slider' ), 'A real size change must be caught.' );
norm_assert( ! $n->satisfies( [ 'unit' => 'px', 'size' => 16 ], [ 'unit' => 'rem', 'size' => 16 ], 'slider' ), 'A real unit change must be caught.' );
norm_assert( ! $n->satisfies( [ 'unit' => 'custom', 'size' => 'clamp(1rem,2vw,3rem)' ], [ 'unit' => 'custom', 'size' => 'clamp(1rem,2vw,4rem)' ], 'slider' ), 'A different clamp must be caught.' );
norm_assert( ! $n->satisfies( 'nonsense', [ 'unit' => 'px', 'size' => 16 ], 'slider' ), 'A non-array stored value must be caught.' );

// A range slider that genuinely uses sizes still compares them.
norm_assert( ! $n->satisfies( [ 'unit' => 'px', 'sizes' => [ 'from' => 1 ] ], [ 'unit' => 'px', 'sizes' => [ 'from' => 2 ] ], 'slider' ), 'A populated sizes array must still be compared.' );

/* ---------- Dimensions ---------- */

norm_assert(
	$n->satisfies( [ 'unit' => 'px', 'top' => '14', 'right' => '22', 'bottom' => '14', 'left' => '22', 'isLinked' => false ], [ 'unit' => 'px', 'top' => 14, 'right' => 22, 'bottom' => 14, 'left' => 22 ], 'dimensions' ),
	'Dimension sides compare as numbers, and isLinked is editor state.'
);
norm_assert(
	$n->satisfies( [ 'unit' => 'px', 'top' => 14, 'right' => 22, 'bottom' => 14, 'left' => 22, 'isLinked' => true ], [ 'unit' => 'px', 'top' => 14, 'right' => 22, 'bottom' => 14, 'left' => 22, 'isLinked' => false ], 'dimensions' ),
	'isLinked differs in the editor only and must not fail verification.'
);
norm_assert(
	$n->satisfies( [ 'unit' => 'custom', 'top' => 'clamp(.75rem,.71rem + .18vw,.875rem)', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem' ], [ 'unit' => 'custom', 'top' => 'clamp(.75rem, .71rem + .18vw, .875rem)', 'right' => '1rem', 'bottom' => '1rem', 'left' => '1rem' ], 'dimensions' ),
	'Custom-unit dimensions compare by expression, not by spacing.'
);
norm_assert( ! $n->satisfies( [ 'unit' => 'px', 'top' => 14, 'right' => 22, 'bottom' => 14, 'left' => 24 ], [ 'unit' => 'px', 'top' => 14, 'right' => 22, 'bottom' => 14, 'left' => 22 ], 'dimensions' ), 'A changed side must be caught.' );

/* ---------- Gaps ---------- */

norm_assert(
	$n->satisfies( [ 'unit' => 'px', 'column' => '20', 'row' => '20', 'isLinked' => true ], [ 'unit' => 'px', 'column' => 20, 'row' => 20 ], 'gaps' ),
	'Gap axes compare as numbers.'
);
norm_assert( ! $n->satisfies( [ 'unit' => 'px', 'column' => 20, 'row' => 24 ], [ 'unit' => 'px', 'column' => 20, 'row' => 20 ], 'gaps' ), 'A changed gap axis must be caught.' );

/* ---------- Typography group: only declared properties are managed ---------- */

$stored_typography = [
	'typography_typography' => 'custom',
	'typography_font_family' => 'Inter',
	'typography_font_weight' => '700',
	'typography_font_style' => '',      // Elementor default the profile never asked for
	'typography_text_transform' => '',
];
norm_assert(
	$n->satisfies( $stored_typography, [ 'typography_typography' => 'custom', 'typography_font_family' => 'Inter', 'typography_font_weight' => '700' ], '' ),
	'Typography properties Cresco does not declare must not be compared.'
);
norm_assert(
	! $n->satisfies( $stored_typography, [ 'typography_font_weight' => '600' ], '' ),
	'A changed declared typography property must be caught.'
);

/* ---------- Background group ---------- */

norm_assert(
	$n->satisfies( [ 'background_background' => 'classic', 'background_color' => '#ffffff', 'background_position' => '' ], [ 'background_background' => 'classic', 'background_color' => '#FFFFFF' ], '' ),
	'Background colour case and undeclared background fields must not fail.'
);

/* ---------- Colour ---------- */

norm_assert( $n->satisfies( '#0f172a', '#0F172A', 'color' ), 'Colour comparison is case-insensitive.' );
norm_assert( $n->satisfies( '#FFF', '#FFFFFF', 'color' ), 'Shorthand hex equals its long form.' );
norm_assert( ! $n->satisfies( '#0F172A', '#2563EB', 'color' ), 'A different colour must be caught.' );

/* ---------- Global colour repeater ---------- */

$stored_rows = [
	[ '_id' => 'b2', 'title' => 'Surface', 'color' => '#ffffff' ],
	[ '_id' => 'a1', 'title' => 'Primary', 'color' => '#0F172A', 'addon_extra' => 'x' ],
];
$expected_rows = [
	[ '_id' => 'a1', 'title' => 'Primary', 'color' => '#0F172A' ],
	[ '_id' => 'b2', 'title' => 'Surface', 'color' => '#FFFFFF' ],
];
norm_assert( $n->satisfies( $stored_rows, $expected_rows, 'repeater' ), 'Repeater rows compare by _id, ignoring order and addon fields.' );
norm_assert(
	! $n->satisfies( [ [ '_id' => 'a1', 'title' => 'Primary', 'color' => '#000000' ] ], [ [ '_id' => 'a1', 'title' => 'Primary', 'color' => '#0F172A' ] ], 'repeater' ),
	'A changed repeater colour must be caught.'
);
norm_assert(
	! $n->satisfies( [ [ '_id' => 'a1', 'title' => 'Primary', 'color' => '#0F172A' ] ], $expected_rows, 'repeater' ),
	'A missing repeater row must be caught.'
);

/* ---------- Custom CSS: only the managed block, whitespace-insensitive ---------- */

$css_a = ".user{color:red}\n\n/* CRESCO:FLUID-TOKENS:START */\n:root {\n\t--cresco-fs-h1: clamp(2rem, 4vw, 4rem);\n}\n/* CRESCO:FLUID-TOKENS:END */";
$css_b = ".different-user-css{margin:0}\n/* CRESCO:FLUID-TOKENS:START */\n:root{--cresco-fs-h1:clamp(2rem, 4vw, 4rem);}\n/* CRESCO:FLUID-TOKENS:END */";
norm_assert( $n->satisfies( $css_a, $css_b, 'code' ), 'Only the managed CSS block is compared, and formatting is not meaning.' );

$css_changed = str_replace( '4rem)', '5rem)', $css_a );
norm_assert( ! $n->satisfies( $css_changed, $css_a, 'code' ), 'A changed token value must be caught.' );

/* ---------- Verifier scope and diagnostics ---------- */

$verifier = new Verifier( $n );

$plan = [
	[ 'semanticPath' => 'themeStyle.typography.h1.fontSize', 'control' => 'h1_typography_font_size', 'controlType' => 'slider', 'value' => [ 'unit' => 'custom', 'size' => 'clamp(2.25rem,1.6rem + 2.6vw,4rem)' ] ],
	[ 'semanticPath' => 'settings.layout.widgetGap', 'control' => 'space_between_widgets', 'controlType' => 'slider', 'value' => [ 'unit' => 'custom', 'size' => 'clamp(1rem,0.86rem + 0.71vw,1.5rem)' ] ],
	[ 'semanticPath' => 'themeStyle.typography.h1.color', 'control' => 'h1_color', 'controlType' => 'color', 'value' => '#0F172A' ],
];

$all_good = $verifier->verify( $plan, [
	'h1_typography_font_size' => [ 'unit' => 'custom', 'size' => 'clamp(2.25rem, 1.6rem + 2.6vw, 4rem)', 'sizes' => [] ],
	'space_between_widgets' => [ 'unit' => 'custom', 'size' => 'clamp(1rem, 0.86rem + 0.71vw, 1.5rem)', 'sizes' => [] ],
	'h1_color' => '#0f172a',
	'some_addon_setting' => 'untouched',
] );
norm_assert( 'pass' === $all_good['status'], 'A correct round trip must verify.' );
norm_assert( 3 === $all_good['scopeCount'], 'Scope is the plan, not the whole Kit.' );
norm_assert( 3 === $all_good['matchedCount'], 'Every planned control must match.' );
norm_assert( 0 === $all_good['mismatchCount'], 'Nothing must mismatch.' );

$two_bad = $verifier->verify( $plan, [
	'h1_typography_font_size' => [ 'unit' => 'px', 'size' => 48, 'sizes' => [] ],
	'h1_color' => '#0F172A',
] );
norm_assert( 'failed' === $two_bad['status'], 'A real mismatch must fail verification.' );
norm_assert( 3 === $two_bad['scopeCount'], 'Scope stays the plan size.' );
norm_assert( 2 === $two_bad['mismatchCount'], 'Both problems must be reported, got ' . $two_bad['mismatchCount'] );

$by_path = [];
foreach ( $two_bad['mismatches'] as $mismatch ) { $by_path[ $mismatch['semanticPath'] ] = $mismatch; }
norm_assert( isset( $by_path['themeStyle.typography.h1.fontSize'] ), 'The changed value must be reported by semantic path.' );
norm_assert( Verifier::REASON_MISMATCH === $by_path['themeStyle.typography.h1.fontSize']['reason'], 'A changed value is a semantic mismatch.' );
norm_assert( isset( $by_path['settings.layout.widgetGap'] ), 'A control Elementor did not store at all must be reported.' );
norm_assert( Verifier::REASON_MISSING === $by_path['settings.layout.widgetGap']['reason'], 'A missing control has its own reason.' );
norm_assert( 'slider' === $by_path['settings.layout.widgetGap']['controlType'], 'The control type must be carried through.' );
norm_assert( null === $by_path['settings.layout.widgetGap']['actualRaw'], 'A missing value must report null, not a guess.' );

$rendered = $verifier->render( $two_bad, [ [ 'key' => 'themeStyle.helloHeader.menuColor', 'reason' => 'unsupported_control' ] ], [ [ 'key' => 'settings.layout.defaultPageTemplate' ] ], [ 'status' => 'success' ] );
norm_assert( str_contains( $rendered, 'MISMATCH_COUNT: 2' ), 'The log must lead with the mismatch count.' );
norm_assert( str_contains( $rendered, 'expected_normalized' ), 'The log must show normalized values.' );
norm_assert( str_contains( $rendered, 'SKIPPED_FROM_VERIFICATION' ), 'Skipped controls must be a separate section.' );
norm_assert( str_contains( $rendered, 'unsupported_control' ), 'The skip reason must be logged.' );
norm_assert( str_contains( $rendered, 'PRESERVED' ), 'Preserved values must be listed.' );
norm_assert( str_contains( $rendered, 'ROLLBACK' ), 'Rollback status must be logged.' );

// An empty plan verifies trivially rather than throwing.
$empty = $verifier->verify( [], [ 'anything' => 1 ] );
norm_assert( 'pass' === $empty['status'], 'An empty plan has nothing to fail.' );
norm_assert( 0 === $empty['scopeCount'], 'An empty plan has an empty scope.' );

echo "Site settings normalizer and verifier tests passed.\n";
