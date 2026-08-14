<?php
/**
 * StandardAuditor / FluidPlanner / Presets decision contract.
 *
 * Driven through a stub KitReader so the rules can be exercised without WordPress or Elementor. The
 * invariant that matters most: a proposal must never write a setting the live Kit does not register,
 * because inventing a setting key is the one thing this plugin must never do.
 */

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
}

require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/ContrastRatio.php';
require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/FluidScale.php';
require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/KitSource.php';
require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/StandardAuditor.php';
require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/FluidPlanner.php';
require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/Presets.php';

use CrescoLayer\DesignSystem\FluidPlanner;
use CrescoLayer\DesignSystem\KitSource;
use CrescoLayer\DesignSystem\Presets;
use CrescoLayer\DesignSystem\StandardAuditor;

function plan_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

/** A KitReader that serves fixture data instead of touching Elementor. */
final class StubKitReader extends KitSource {
	public function __construct( private array $fixture ) {}
	public function read(): array {
		return array_merge(
			[ 'available' => true, 'postId' => 8, 'settings' => [], 'controls' => [], 'breakpoints' => [], 'errors' => [] ],
			$this->fixture
		);
	}
}

function find_code( array $findings, string $code ): ?array {
	foreach ( $findings as $finding ) {
		if ( $finding['code'] === $code ) { return $finding; }
	}
	return null;
}
function ops_for( array $operations, string $setting ): array {
	return array_values( array_filter( $operations, static fn( array $op ): bool => ( $op['setting'] ?? '' ) === $setting ) );
}

/* ---------- Auditor: contrast repair keeps hue, background tokens are exempt ---------- */

$kit = new StubKitReader( [
	'controls' => [
		'system_colors' => [ 'type' => 'repeater' ],
		'container_width' => [ 'type' => 'slider' ],
		'body_typography_font_size' => [ 'type' => 'slider', 'size_units' => [ 'px', 'rem', 'custom' ] ],
	],
	'settings' => [
		'system_colors' => [
			[ '_id' => 'primary', 'title' => 'Primary', 'color' => '#D8A6FF' ],   // fails AA on white
			[ '_id' => 'text', 'title' => 'Text', 'color' => '#1A1A1A' ],          // passes
			[ '_id' => 'surface', 'title' => 'Background', 'color' => '#FAFAFA' ], // background token, exempt
		],
		'system_typography' => [ [ '_id' => 'primary', 'title' => 'Primary', 'typography_font_family' => 'Inter' ] ],
		'body_typography_font_size' => [ 'unit' => 'px', 'size' => 14 ],
		'container_width' => [ 'unit' => 'px', 'size' => 1920 ],
	],
] );

$audit = ( new StandardAuditor( $kit ) )->audit();
plan_assert( true === $audit['available'], 'A readable Kit must be auditable.' );
plan_assert( 8 === $audit['kitPostId'], 'The audit must report the Kit post id.' );

$contrast = find_code( $audit['findings'], 'global-color-contrast' );
plan_assert( null !== $contrast, 'The failing brand colour must be reported.' );
plan_assert( str_contains( $contrast['message'], '4.5:1' ), 'The finding must state the AA threshold.' );
plan_assert( isset( $contrast['data']['suggested'] ), 'A same-hue repair must be proposed.' );

$suggested = $contrast['data']['suggested'];
$rgb = \CrescoLayer\DesignSystem\ContrastRatio::parse( $suggested );
plan_assert( $rgb[2] >= $rgb[0] && $rgb[2] >= $rgb[1], 'The repaired brand colour must stay in the purple hue family.' );
plan_assert( \CrescoLayer\DesignSystem\ContrastRatio::between( $suggested, '#FFFFFF' ) >= 4.5, 'The repair must clear AA.' );

plan_assert( null !== find_code( $audit['findings'], 'global-color-ok' ), 'A passing colour must be reported as passing.' );
foreach ( $audit['findings'] as $finding ) {
	plan_assert( ! str_contains( $finding['message'], 'Background' ) || 'global-color-contrast' !== $finding['code'], 'A background token must not be judged as foreground text.' );
}

plan_assert( null !== find_code( $audit['findings'], 'body-size-small' ), '14px body text must be flagged.' );
plan_assert( null !== find_code( $audit['findings'], 'container-too-wide' ), 'A 1920px container must be flagged.' );

$proposed = $audit['proposedOperations'];
plan_assert( count( ops_for( $proposed, 'system_colors' ) ) === 1, 'The colour fix must rewrite the whole repeater once.' );
$color_op = ops_for( $proposed, 'system_colors' )[0];
plan_assert( 3 === count( $color_op['value'] ), 'Rewriting one colour must preserve every sibling entry.' );
plan_assert( '#1A1A1A' === $color_op['value'][1]['color'], 'Untouched colours must keep their value.' );
plan_assert( $color_op['value'][0]['color'] === $suggested, 'The failing colour must carry the repaired value.' );
plan_assert( 16 === ops_for( $proposed, 'body_typography_font_size' )[0]['value']['size'], 'Body size must be proposed at 16px.' );
plan_assert( 1200 === ops_for( $proposed, 'container_width' )[0]['value']['size'], 'Container width must be proposed at 1200px.' );
foreach ( $proposed as $op ) {
	plan_assert( 'update-page-setting' === $op['operation'], 'Kit proposals must use page-setting operations.' );
}

/* ---------- Auditor: never propose a setting the Kit does not register ---------- */

$bare = new StubKitReader( [
	'controls' => [ 'system_colors' => [ 'type' => 'repeater' ] ],  // no container_width, no body size
	'settings' => [
		'system_colors' => [ [ '_id' => 'primary', 'title' => 'Primary', 'color' => '#000000' ] ],
		'system_typography' => [ [ '_id' => 'p', 'title' => 'P', 'typography_font_family' => 'Inter' ] ],
		'body_typography_font_size' => [ 'unit' => 'px', 'size' => 10 ],
		'container_width' => [ 'unit' => 'px', 'size' => 3000 ],
	],
] );
$bare_audit = ( new StandardAuditor( $bare ) )->audit();
plan_assert( [] === ops_for( $bare_audit['proposedOperations'], 'container_width' ), 'An unregistered control must never be written.' );
plan_assert( [] === ops_for( $bare_audit['proposedOperations'], 'body_typography_font_size' ), 'An unregistered font size must never be written.' );

/* ---------- Auditor: unreadable Kit degrades cleanly ---------- */

$missing = new StubKitReader( [ 'available' => false, 'errors' => [ [ 'stage' => 'active-kit', 'message' => 'none' ] ] ] );
$missing_audit = ( new StandardAuditor( $missing ) )->audit();
plan_assert( false === $missing_audit['available'], 'An unreadable Kit must report unavailable.' );
plan_assert( ! isset( $missing_audit['proposedOperations'] ), 'An unreadable Kit must not propose changes.' );

/* ---------- Fluid planner ---------- */

$fluid_kit = new StubKitReader( [
	'breakpoints' => [ 'mobile' => 767, 'tablet' => 1024 ],
	'controls' => [
		'body_typography_font_size' => [ 'type' => 'slider', 'size_units' => [ 'px', 'rem', 'custom' ] ],
		'body_typography_font_size_mobile' => [ 'type' => 'slider', 'size_units' => [ 'px', 'custom' ] ],
		'h1_typography_font_size' => [ 'type' => 'slider', 'size_units' => [ 'px', 'rem', 'custom' ] ],
		'legacy_font_size' => [ 'type' => 'slider', 'size_units' => [ 'px' ] ],  // no custom unit
	],
	'settings' => [
		'body_typography_font_size' => [ 'unit' => 'px', 'size' => 18 ],
		'body_typography_font_size_mobile' => [ 'unit' => 'px', 'size' => 15 ],
		'h1_typography_font_size' => [ 'unit' => 'px', 'size' => 56 ],
		'legacy_font_size' => [ 'unit' => 'px', 'size' => 40 ],
	],
] );
$plan = ( new FluidPlanner( $fluid_kit ) )->plan();

$settings_planned = array_column( $plan['items'], 'setting' );
plan_assert( in_array( 'body_typography_font_size', $settings_planned, true ), 'A control with a mobile override must be planned.' );
plan_assert( in_array( 'h1_typography_font_size', $settings_planned, true ), 'A control without an override must still be planned from a derived mobile size.' );
plan_assert( ! in_array( 'body_typography_font_size_mobile', $settings_planned, true ), 'A responsive variant must not be planned on its own.' );
plan_assert( ! in_array( 'legacy_font_size', $settings_planned, true ), 'A control without the custom unit cannot hold clamp() and must be skipped.' );

$skipped = array_column( $plan['skipped'], 'setting' );
plan_assert( in_array( 'legacy_font_size', $skipped, true ), 'The skipped control must be reported, not silently dropped.' );

foreach ( $plan['items'] as $item ) {
	if ( 'body_typography_font_size' === $item['setting'] ) {
		plan_assert( 15.0 === $item['minPx'], 'The real mobile override must anchor the fluid minimum.' );
		plan_assert( false === $item['mobileDerived'], 'A real override must not be reported as derived.' );
		plan_assert( in_array( 'body_typography_font_size_mobile', $item['replacesOverrides'], true ), 'The override being superseded must be listed.' );
	}
	if ( 'h1_typography_font_size' === $item['setting'] ) {
		plan_assert( true === $item['mobileDerived'], 'A missing override must be reported as derived.' );
	}
	plan_assert( str_starts_with( $item['expression'], 'clamp(' ), 'Each item must carry a clamp() expression.' );
}

$custom_ops = array_values( array_filter( $plan['operations'], static fn( array $op ): bool => 'update-page-setting' === $op['operation'] ) );
foreach ( $custom_ops as $op ) {
	plan_assert( 'custom' === $op['value']['unit'], 'A clamp() must be written with Elementor\'s custom unit.' );
	plan_assert( is_string( $op['value']['size'] ), 'The custom-unit size must be the raw CSS string.' );
}
$removals = array_values( array_filter( $plan['operations'], static fn( array $op ): bool => 'remove-page-setting' === $op['operation'] ) );
plan_assert( [] !== ops_for( $removals, 'body_typography_font_size_mobile' ), 'A superseded override must be removed, or it would win at that breakpoint.' );

/* ---------- Presets ---------- */

$presets = new Presets( $fluid_kit );
$catalog = $presets->catalog();
plan_assert( 3 === count( $catalog['presets'] ), 'Three presets must be offered.' );

$saas = $presets->plan( 'saas' );
plan_assert( true === $saas['preservesBrandColors'], 'Presets must declare that they leave brand colours alone.' );
foreach ( $saas['operations'] as $op ) {
	plan_assert( ! in_array( $op['setting'], [ 'system_colors', 'custom_colors' ], true ), 'A preset must never rewrite brand colours.' );
}
plan_assert( [] !== ops_for( $saas['operations'], 'body_typography_font_size' ), 'A registered control must be written.' );
plan_assert( [] === ops_for( $saas['operations'], 'container_width' ), 'An unregistered control must be skipped, not invented.' );
plan_assert( array_column( $saas['unsupported'], 'setting' ) === [ 'container_width', 'button_border_radius', 'image_border_radius' ], 'Skipped settings must be reported.' );

$threw = false;
try { $presets->plan( 'does-not-exist' ); } catch ( InvalidArgumentException $expected ) { $threw = true; }
plan_assert( $threw, 'An unknown preset id must be rejected.' );

echo "Design standard plan contract tests passed.\n";
