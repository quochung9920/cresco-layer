<?php
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

require_once dirname( __DIR__, 2 ) . '/includes/Support/DocumentChecksum.php';
require_once dirname( __DIR__, 2 ) . '/includes/AI/ElementLocator.php';
require_once dirname( __DIR__, 2 ) . '/includes/AI/SemanticPatchGuard.php';

use CrescoLayer\AI\SemanticPatchGuard;

function expect_semantic( $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}

function has_issue_code( array $analysis, string $code ): bool {
	foreach ( array_merge( (array) ( $analysis['errors'] ?? [] ), (array) ( $analysis['warnings'] ?? [] ) ) as $issue ) {
		if ( $code === ( $issue['code'] ?? '' ) ) { return true; }
	}
	return false;
}

$catalog = [
	'widgets' => [],
	'elements' => [
		'container' => [
			'controls' => [
				'padding' => [
					'type' => 'dimensions',
					'responsive' => true,
					'size_units' => [ 'px', '%', 'em', 'rem' ],
				],
				'min_height' => [
					'type' => 'slider',
					'responsive' => true,
					'size_units' => [ 'px', 'vh' ],
					'range' => [ 'px' => [ 'min' => 0, 'max' => 2000 ] ],
				],
				'overflow' => [
					'type' => 'select',
					'responsive' => false,
					'options' => [ '' => 'Default', 'hidden' => 'Hidden', 'auto' => 'Auto' ],
				],
				'custom_css' => [
					'type' => 'code',
					'responsive' => false,
				],
			],
		],
	],
];

$elements = [ [
	'id' => 'root1',
	'elType' => 'container',
	'settings' => [
		'min_height' => [ 'unit' => 'px', 'size' => 480 ],
		'overflow' => 'hidden',
		'future_elementor_field' => [ 'enabled' => true ],
	],
	'elements' => [],
] ];

$guard = new SemanticPatchGuard();
$base_patch = [
	'schema' => 'cresco-layer-patch/v1',
	'base' => [ 'postId' => 42, 'checksum' => str_repeat( 'a', 64 ) ],
	'operations' => [],
];

$native = $base_patch;
$native['operations'] = [ [
	'operation' => 'update-setting',
	'elementId' => 'root1',
	'setting' => 'padding_tablet',
	'value' => [ 'unit' => 'px', 'top' => '40', 'right' => '32', 'bottom' => '40', 'left' => '32', 'isLinked' => false ],
] ];
$analysis = $guard->analyze_data( $native, $elements, [], $catalog );
expect_semantic( false === $analysis['blocking'], 'Valid responsive native Elementor control was blocked.' );
expect_semantic( 1 === $analysis['nativeControlOperations'], 'Native responsive control was not classified correctly.' );
expect_semantic( 'native-control' === $analysis['items'][0]['classification'], 'Native control classification was not exposed in preview data.' );

$inert_css = $base_patch;
$inert_css['operations'] = [ [
	'operation' => 'update-setting',
	'elementId' => 'root1',
	'setting' => 'custom_css',
	'value' => '@media (max-width: 1024px) { selector { --min-height: auto; --padding-top: 40px; --padding-right: 32px; } }',
] ];
$analysis = $guard->analyze_data( $inert_css, $elements, [], $catalog );
expect_semantic( true === $analysis['blocking'], 'Inert custom CSS variables were not blocked.' );
expect_semantic( has_issue_code( $analysis, 'inert-css-variable' ), 'Inert CSS variable diagnostic is missing.' );

$css_fallback = $base_patch;
$css_fallback['operations'] = [ [
	'operation' => 'update-setting',
	'elementId' => 'root1',
	'setting' => 'custom_css',
	'value' => '@media (max-width: 1024px) { selector { padding: 40px 32px; min-height: auto; } }',
] ];
$analysis = $guard->analyze_data( $css_fallback, $elements, [], $catalog );
expect_semantic( false === $analysis['blocking'], 'Custom CSS fallback warning should not block an otherwise effective patch.' );
expect_semantic( has_issue_code( $analysis, 'custom-css-native-control' ), 'Native-control fallback warning is missing.' );
expect_semantic( 1 === $analysis['customCssOperations'], 'custom_css operation was not counted.' );

$unknown = $base_patch;
$unknown['operations'] = [ [
	'operation' => 'update-setting',
	'elementId' => 'root1',
	'setting' => 'invented_ai_spacing',
	'value' => 42,
] ];
$analysis = $guard->analyze_data( $unknown, $elements, [], $catalog );
expect_semantic( true === $analysis['blocking'], 'Invented Elementor setting was not blocked.' );
expect_semantic( has_issue_code( $analysis, 'unknown-setting' ), 'Unknown setting diagnostic is missing.' );

$bad_responsive = $base_patch;
$bad_responsive['operations'] = [ [
	'operation' => 'update-setting',
	'elementId' => 'root1',
	'setting' => 'overflow_mobile',
	'value' => 'hidden',
] ];
$analysis = $guard->analyze_data( $bad_responsive, $elements, [], $catalog );
expect_semantic( true === $analysis['blocking'], 'Responsive suffix on non-responsive control was not blocked.' );
expect_semantic( has_issue_code( $analysis, 'non-responsive-control' ), 'Non-responsive control diagnostic is missing.' );

$invalid_value = $base_patch;
$invalid_value['operations'] = [ [
	'operation' => 'update-setting',
	'elementId' => 'root1',
	'setting' => 'min_height',
	'value' => [ 'unit' => 'px', 'size' => 5000 ],
] ];
$analysis = $guard->analyze_data( $invalid_value, $elements, [], $catalog );
expect_semantic( true === $analysis['blocking'], 'Out-of-range slider value was not blocked.' );
expect_semantic( has_issue_code( $analysis, 'value-above-range' ), 'Slider range diagnostic is missing.' );

$noop = $base_patch;
$noop['operations'] = [ [
	'operation' => 'update-setting',
	'elementId' => 'root1',
	'setting' => 'overflow',
	'value' => 'hidden',
] ];
$analysis = $guard->analyze_data( $noop, $elements, [], $catalog );
expect_semantic( false === $analysis['blocking'], 'No-op should be a warning, not a blocking error.' );
expect_semantic( 1 === $analysis['noOpOperations'] && 0 === $analysis['effectiveOperations'], 'No-op effectiveness accounting is incorrect.' );
expect_semantic( has_issue_code( $analysis, 'no-op' ), 'No-op diagnostic is missing.' );

$drop_unknown = $base_patch;
$drop_unknown['operations'] = [ [
	'operation' => 'replace-settings',
	'elementId' => 'root1',
	'settings' => [ 'overflow' => 'auto' ],
] ];
$analysis = $guard->analyze_data( $drop_unknown, $elements, [], $catalog );
expect_semantic( true === $analysis['blocking'], 'Lossy replace-settings was not blocked.' );
expect_semantic( has_issue_code( $analysis, 'drop-unknown-setting' ), 'Lossless unknown-setting diagnostic is missing.' );

$verify_patch = $base_patch;
$verify_patch['operations'] = [
	[ 'operation' => 'update-setting', 'elementId' => 'root1', 'setting' => 'overflow', 'value' => 'auto' ],
	[ 'operation' => 'update-page-setting', 'setting' => 'hide_title', 'value' => 'yes' ],
];
$verification = $guard->verify_data( $verify_patch, [ [
	'id' => 'root1', 'elType' => 'container', 'settings' => [ 'overflow' => 'auto' ], 'elements' => [],
] ], [ 'hide_title' => 'yes' ] );
expect_semantic( true === $verification['verified'] && 2 === $verification['passed'], 'Post-apply verification did not confirm saved values.' );

$verification_failed = $guard->verify_data( $verify_patch, [ [
	'id' => 'root1', 'elType' => 'container', 'settings' => [ 'overflow' => 'hidden' ], 'elements' => [],
] ], [ 'hide_title' => 'yes' ] );
expect_semantic( false === $verification_failed['verified'] && 1 === $verification_failed['failed'], 'Post-apply verification failed to detect a mismatched value.' );

echo "Semantic Elementor patch guard and post-apply verification tests passed.\n";
