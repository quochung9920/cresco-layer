<?php
require_once dirname( __DIR__, 2 ) . '/includes/Skills/ExpertProfiles.php';
require_once dirname( __DIR__, 2 ) . '/includes/Skills/SkillCompiler.php';

use CrescoLayer\Skills\ExpertProfiles;
use CrescoLayer\Skills\SkillCompiler;

function skill_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
function skill_throws( callable $callback, string $message ): void {
	try { $callback(); } catch ( Throwable $error ) { return; }
	fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 );
}
function skill_by_setting( array $compiled, string $setting ): ?array {
	foreach ( $compiled['skills'] as $skill ) { if ( $setting === $skill['setting'] ) { return $skill; } }
	return null;
}

$entry = [
	'name' => 'container',
	'title' => 'Container',
	'capabilitySource' => 'classic-controls',
	'isAtomic' => false,
	'defaultSettings' => [
		'padding' => [ 'unit' => 'px', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'isLinked' => true ],
		'background_background' => '',
	],
	'controls' => [
		'padding' => [ 'type' => 'dimensions', 'label' => 'Padding', 'responsive' => true, 'size_units' => [ 'px', '%', 'rem' ], 'source' => 'classic-control' ],
		'width' => [ 'type' => 'slider', 'label' => 'Width', 'responsive' => true, 'size_units' => [ '%', 'px' ], 'range' => [ '%' => [ 'min' => 0, 'max' => 100 ], 'px' => [ 'min' => 0, 'max' => 2000 ] ], 'source' => 'classic-control' ],
		'background_background' => [ 'type' => 'choose', 'label' => 'Background Type', 'options' => [ '' => 'None', 'classic' => 'Classic', 'gradient' => 'Gradient' ], 'source' => 'classic-control' ],
		'background_color' => [ 'type' => 'color', 'label' => 'Background Color', 'condition' => [ 'background_background' => 'classic' ], 'source' => 'classic-control' ],
		'overflow' => [ 'type' => 'select', 'label' => 'Overflow', 'options' => [ '' => 'Default', 'hidden' => 'Hidden', 'auto' => 'Auto' ], 'source' => 'classic-control' ],
		'hide_mobile' => [ 'type' => 'switcher', 'label' => 'Hide On Mobile', 'rawMetadata' => [ 'return_value' => 'yes' ], 'source' => 'classic-control' ],
		'custom_css' => [ 'type' => 'code', 'label' => 'Custom CSS', 'source' => 'classic-control' ],
		'layout_heading' => [ 'type' => 'heading', 'label' => 'Layout', 'source' => 'classic-control' ],
	],
];
$current = [ 'width' => [ 'unit' => '%', 'size' => 80, 'sizes' => [] ], 'overflow' => '' ];
$breakpoints = [ 'tablet' => [ 'value' => 1024 ], 'mobile' => [ 'value' => 767 ] ];
$knowledge = ExpertProfiles::for( 'element', 'container', $entry );
$compiler = new SkillCompiler();
$compiled = $compiler->compile( $entry, $current, $breakpoints, $knowledge );

skill_assert( SkillCompiler::SCHEMA === $compiled['schema'], 'Skill schema mismatch.' );
skill_assert( 8 === $compiled['skillCount'], 'Every runtime control should compile into a skill record.' );
skill_assert( 7 === $compiled['executableSkillCount'], 'Executable skill count is incorrect.' );
skill_assert( in_array( 'container-layout', $compiled['knowledge']['profiles'], true ), 'Container expert profile was not attached.' );

$padding = skill_by_setting( $compiled, 'padding' );
skill_assert( is_array( $padding ), 'Padding control did not compile.' );
skill_assert( 'spacing.padding' === $padding['role'], 'Padding semantic role is incorrect.' );
skill_assert( true === $padding['responsive'], 'Responsive metadata was lost.' );
skill_assert( [ 'desktop', 'tablet', 'mobile' ] === $padding['devices'], 'Active breakpoint variants were not compiled.' );

$resolved = $compiler->resolve( $compiled, $padding['id'], [ 'device' => 'mobile', 'top' => '20', 'right' => '16', 'bottom' => '20', 'left' => '16', 'unit' => 'px' ], $current, 'abc123' );
$last = $resolved['operations'][ count( $resolved['operations'] ) - 1 ];
skill_assert( 'padding_mobile' === $last['setting'], 'Responsive skill did not resolve to native Elementor suffix.' );
skill_assert( '16' === $last['value']['right'] && false === $last['value']['isLinked'], 'Dimensions were not normalized correctly.' );

$command = $compiler->command( $compiled, 'mobile padding 24px', $current, 'abc123' );
skill_assert( 'padding_mobile' === $command['operations'][0]['setting'], 'Deterministic command did not route to mobile padding.' );
skill_assert( '24' === $command['operations'][0]['value']['top'], 'Deterministic command value was not parsed.' );

$background = skill_by_setting( $compiled, 'background_color' );
$background_resolution = $compiler->resolve( $compiled, $background['id'], [ 'value' => '#07133F' ], $current, 'abc123' );
skill_assert( 2 === count( $background_resolution['operations'] ), 'Safe control prerequisite should be enabled automatically.' );
skill_assert( 'background_background' === $background_resolution['operations'][0]['setting'], 'Background prerequisite setting is incorrect.' );
skill_assert( 'classic' === $background_resolution['operations'][0]['value'], 'Background prerequisite value is incorrect.' );
skill_assert( 'background_color' === $background_resolution['operations'][1]['setting'], 'Requested background control was not preserved.' );

$overflow = skill_by_setting( $compiled, 'overflow' );
$overflow_resolution = $compiler->resolve( $compiled, $overflow['id'], [ 'value' => 'hidden' ], $current, 'abc123' );
skill_assert( 'hidden' === $overflow_resolution['operations'][0]['value'], 'Valid select option was rejected.' );
skill_throws( fn() => $compiler->resolve( $compiled, $overflow['id'], [ 'value' => 'visible-but-invalid' ], $current, 'abc123' ), 'Invalid select option was accepted.' );

$width = skill_by_setting( $compiled, 'width' );
$width_resolution = $compiler->resolve( $compiled, $width['id'], [ 'value' => '50%' ], $current, 'abc123' );
skill_assert( '%' === $width_resolution['operations'][0]['value']['unit'] && 50 === $width_resolution['operations'][0]['value']['size'], 'Slider unit/value normalization failed.' );
skill_throws( fn() => $compiler->resolve( $compiled, $width['id'], [ 'value' => '120%' ], $current, 'abc123' ), 'Slider range maximum was not enforced.' );
skill_throws( fn() => $compiler->resolve( $compiled, $width['id'], [ 'value' => '20rem' ], $current, 'abc123' ), 'Unsupported slider unit was accepted.' );

$visibility = $compiler->command( $compiled, 'hide mobile', $current, 'abc123' );
skill_assert( 'hide_mobile' === $visibility['operations'][0]['setting'] && 'yes' === $visibility['operations'][0]['value'], 'Visibility command did not resolve through switcher skill.' );
skill_throws( fn() => $compiler->command( $compiled, 'make it magical', $current, 'abc123' ), 'Unknown natural-language request should not be guessed.' );

$atomic = $compiler->compile( [
	'name' => 'e-heading',
	'isAtomic' => true,
	'capabilitySource' => 'atomic-controls+props-schema',
	'controls' => [
		'atomic_control_0' => [ 'type' => 'text', 'label' => 'Title', 'source' => 'atomic-control', 'bind' => 'title' ],
	],
], [ 'title' => 'Hello' ] );
skill_assert( 'title' === $atomic['skills'][0]['setting'], 'Atomic skill must bind to the runtime prop bind rather than an invented legacy key.' );
$atomic_resolution = $compiler->resolve( $atomic, $atomic['skills'][0]['id'], [ 'value' => 'Updated' ], [ 'title' => 'Hello' ], 'atomic1' );
skill_assert( 'title' === $atomic_resolution['operations'][0]['setting'], 'Atomic resolution lost its prop binding.' );

$code = skill_by_setting( $compiled, 'custom_css' );
skill_assert( 'expert' === $code['mode'] && 'expert' === $code['risk'], 'Code controls should be explicitly expert-risk skills.' );
$heading = skill_by_setting( $compiled, 'layout_heading' );
skill_assert( 'read-only' === $heading['mode'], 'Non-value Elementor heading control should not be executable.' );

echo "Runtime widget skill compiler tests passed.\n";
