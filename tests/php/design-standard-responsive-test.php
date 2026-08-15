<?php
/**
 * Balanced responsive preset and four-tier fluid planner contract.
 */

require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/FluidScale.php';
require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/KitSource.php';
require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/FluidPlanner.php';
require_once dirname( __DIR__, 2 ) . '/includes/DesignSystem/Presets.php';

use CrescoLayer\DesignSystem\FluidPlanner;
use CrescoLayer\DesignSystem\KitSource;
use CrescoLayer\DesignSystem\Presets;

function responsive_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

final class ResponsiveStubKit extends KitSource {
	public function __construct( private array $fixture ) {}
	public function read(): array {
		return array_merge(
			[ 'available' => true, 'postId' => 12, 'settings' => [], 'controls' => [], 'breakpoints' => [], 'errors' => [] ],
			$this->fixture
		);
	}
}

$controls = [
	'body_typography_font_size' => [ 'type' => 'slider', 'size_units' => [ 'px', 'rem', 'custom' ] ],
	'body_typography_font_size_mobile' => [ 'type' => 'slider', 'size_units' => [ 'px', 'custom' ] ],
	'body_typography_font_size_tablet' => [ 'type' => 'slider', 'size_units' => [ 'px', 'custom' ] ],
	'body_typography_font_size_laptop' => [ 'type' => 'slider', 'size_units' => [ 'px', 'custom' ] ],
	'container_width' => [ 'type' => 'slider', 'size_units' => [ 'px' ] ],
	'button_border_radius' => [ 'type' => 'dimensions', 'size_units' => [ 'px', 'rem', 'custom' ] ],
	'image_border_radius' => [ 'type' => 'dimensions', 'size_units' => [ 'px', 'rem', 'custom' ] ],
	'button_padding' => [ 'type' => 'dimensions', 'size_units' => [ 'px', 'rem', 'custom' ] ],
	'container_padding' => [ 'type' => 'dimensions', 'size_units' => [ 'px', '%', 'em', 'rem', 'vw', 'custom' ] ],
	'paragraph_spacing' => [ 'type' => 'slider', 'size_units' => [ 'px', 'em', 'rem', 'vh', 'custom' ] ],
	'active_breakpoints' => [ 'type' => 'select2' ],
	'viewport_mobile' => [ 'type' => 'number' ],
	'viewport_tablet' => [ 'type' => 'number' ],
	'viewport_laptop' => [ 'type' => 'number' ],
];

$kit = new ResponsiveStubKit( [
	'breakpoints' => [ 'mobile' => 767, 'tablet' => 1024, 'laptop' => 1366 ],
	'controls' => $controls,
	'settings' => [
		'body_typography_font_size' => [ 'unit' => 'px', 'size' => 18 ],
		'body_typography_font_size_mobile' => [ 'unit' => 'px', 'size' => 15 ],
		'body_typography_font_size_tablet' => [ 'unit' => 'px', 'size' => 16 ],
		'body_typography_font_size_laptop' => [ 'unit' => 'px', 'size' => 17 ],
		'active_breakpoints' => [ 'viewport_mobile', 'viewport_tablet' ],
	],
] );

$fluid = ( new FluidPlanner( $kit ) )->plan();
responsive_assert( 1 === count( $fluid['items'] ), 'Only the base font-size control should be planned.' );
$item = $fluid['items'][0];
responsive_assert( 15.0 === $item['minPx'], 'Mobile must remain the smallest anchor.' );
responsive_assert( str_starts_with( $item['expression'], 'clamp(' ), 'The planned font size must be fluid.' );
responsive_assert( in_array( 'body_typography_font_size_mobile', $item['replacesOverrides'], true ), 'Mobile override must be removed.' );
responsive_assert( in_array( 'body_typography_font_size_tablet', $item['replacesOverrides'], true ), 'Tablet override must be removed.' );
responsive_assert( in_array( 'body_typography_font_size_laptop', $item['replacesOverrides'], true ), 'Laptop override must be removed.' );

$preset = ( new Presets( $kit ) )->plan( 'balanced-responsive' );
responsive_assert( true === $preset['preservesBrandColors'], 'Balanced preset must not rewrite brand colours.' );
$ops = [];
foreach ( $preset['operations'] as $operation ) { $ops[ $operation['setting'] ] = $operation; }
responsive_assert( 1320 === $ops['container_width']['value']['size'], 'Balanced container must be 1320px.' );
responsive_assert( 6 === $ops['button_border_radius']['value']['top'], 'Balanced button radius must be 6px.' );
responsive_assert( 10 === $ops['image_border_radius']['value']['top'], 'Balanced image radius must be 10px.' );
responsive_assert( 'custom' === $ops['container_padding']['value']['unit'], 'Container gutter must use custom-unit clamp() when supported.' );
responsive_assert( str_starts_with( $ops['container_padding']['value']['left'], 'clamp(' ), 'Left gutter must be fluid.' );
responsive_assert( 'custom' === $ops['paragraph_spacing']['value']['unit'], 'Paragraph spacing must use clamp() when supported.' );
responsive_assert( str_starts_with( $ops['paragraph_spacing']['value']['size'], 'clamp(' ), 'Paragraph spacing must be fluid.' );
responsive_assert( 767 === $ops['viewport_mobile']['value'], 'Mobile breakpoint must be 767px.' );
responsive_assert( 1024 === $ops['viewport_tablet']['value'], 'Tablet breakpoint must be 1024px.' );
responsive_assert( 1366 === $ops['viewport_laptop']['value'], 'Laptop breakpoint must be 1366px.' );
responsive_assert( in_array( 'viewport_laptop', $ops['active_breakpoints']['value'], true ), 'Laptop breakpoint must be activated.' );

foreach ( $preset['operations'] as $operation ) {
	responsive_assert( isset( $controls[ $operation['setting'] ] ), 'Preset must never invent an unregistered Kit setting.' );
}

echo "Balanced responsive design standard tests passed.\n";
