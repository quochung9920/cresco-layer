<?php
/** Regression coverage for Hello Theme conditional Site Settings controls. */

require_once dirname( __DIR__, 2 ) . '/includes/SiteSettings/Support/HelloControlBridge.php';

use CrescoLayer\SiteSettings\Support\HelloControlBridge;

function hello_bridge_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$controls = [
	'hello_header_logo_type' => [ 'type' => 'select', 'default' => 'title' ],
	'hello_header_logo_width' => [ 'type' => 'slider' ],
	'hello_footer_logo_type' => [ 'type' => 'select', 'default' => 'logo' ],
	'hello_footer_logo_width' => [ 'type' => 'slider' ],
	'hello_footer_copyright_typography_typography' => [ 'type' => 'popover_toggle' ],
	'hello_footer_copyright_typography_font_size' => [ 'type' => 'slider' ],
	'hello_header_menu_typography_typography' => [ 'type' => 'popover_toggle' ],
	'hello_header_menu_typography_font_size' => [ 'type' => 'slider' ],
];

// Reproduces the live failure: a registered header logo-width control is inactive while branding
// uses the site title. Capability discovery must not plan a write that cannot survive read-back.
$filtered = HelloControlBridge::filter_controls( $controls, [ 'hello_header_logo_type' => 'title' ] );
hello_bridge_assert( ! isset( $filtered['hello_header_logo_width'] ), 'Header logo width must be hidden while logo type is title.' );
hello_bridge_assert( isset( $filtered['hello_footer_logo_width'] ), 'An active footer logo width must remain discoverable.' );

// The control default is authoritative when the display settings do not carry the selector value.
$filtered_from_default = HelloControlBridge::filter_controls( $controls, [] );
hello_bridge_assert( ! isset( $filtered_from_default['hello_header_logo_width'] ), 'Header logo-width default condition must be respected.' );
hello_bridge_assert( isset( $filtered_from_default['hello_footer_logo_width'] ), 'Footer logo-width default condition must be respected.' );

$active = HelloControlBridge::filter_controls( $controls, [ 'hello_header_logo_type' => 'logo' ] );
hello_bridge_assert( isset( $active['hello_header_logo_width'] ), 'Header logo width must remain available when logo type is logo.' );

// Reproduces the second live failure: a typography child without its group starter is filtered by
// Elementor's active-settings pass. The bridge must activate the group before save.
$prepared = HelloControlBridge::prepare_for_save(
	[
		'hello_footer_copyright_typography_font_size' => [ 'unit' => 'custom', 'size' => 'clamp(.875rem,.85rem + .1vw,.9375rem)', 'sizes' => [] ],
		'hello_header_menu_typography_font_size' => [ 'unit' => 'px', 'size' => 16, 'sizes' => [] ],
	],
	$controls
);
hello_bridge_assert( 'custom' === $prepared['hello_footer_copyright_typography_typography'], 'Copyright typography starter must be enabled.' );
hello_bridge_assert( 'custom' === $prepared['hello_header_menu_typography_typography'], 'Menu typography starter must be enabled.' );

// Do not invent a starter that the running theme did not register.
$without_starter = $controls;
unset( $without_starter['hello_header_menu_typography_typography'] );
$prepared_without_starter = HelloControlBridge::prepare_for_save(
	[ 'hello_header_menu_typography_font_size' => [ 'unit' => 'px', 'size' => 16, 'sizes' => [] ] ],
	$without_starter
);
hello_bridge_assert( ! isset( $prepared_without_starter['hello_header_menu_typography_typography'] ), 'Unregistered starters must never be invented.' );

// An existing explicit starter value is preserved.
$already = HelloControlBridge::prepare_for_save(
	[
		'hello_footer_copyright_typography_typography' => 'custom',
		'hello_footer_copyright_typography_font_size' => [ 'unit' => 'px', 'size' => 15, 'sizes' => [] ],
	],
	$controls
);
hello_bridge_assert( 'custom' === $already['hello_footer_copyright_typography_typography'], 'Existing typography starter must be preserved.' );

fwrite( STDOUT, "PASS: Hello Site Settings condition bridge\n" );
