<?php
/** Five-context Site Settings responsive foundation contract. */

$base = dirname( __DIR__, 2 ) . '/includes/SiteSettings/';
require_once $base . 'Contract/Spec.php';
require_once $base . 'Support/ClampValidator.php';
require_once $base . 'Support/ValueFactory.php';
require_once $base . 'Gateway/KitGateway.php';
require_once $base . 'Discovery/RuntimeControlResolver.php';
require_once $base . 'Discovery/CapabilityReport.php';
require_once $base . 'Adapter/ResponsiveFoundationBridge.php';
require_once $base . 'Profiles/ProfessionalCommerceProfile.php';
require_once $base . 'Layout/ResponsiveLayoutPolicy.php';

use CrescoLayer\SiteSettings\Adapter\ResponsiveFoundationBridge;
use CrescoLayer\SiteSettings\Discovery\CapabilityReport;
use CrescoLayer\SiteSettings\Gateway\KitGateway;
use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;
use CrescoLayer\SiteSettings\Profiles\ProfessionalCommerceProfile;
use CrescoLayer\SiteSettings\Support\ClampValidator;
use CrescoLayer\SiteSettings\Support\ValueFactory;

function rf_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

final class ResponsiveFakeGateway implements KitGateway {
	public function __construct( private array $kitControls, private array $kitSettings = [] ) {}
	public function is_available(): bool { return true; }
	public function kit_id(): int { return 9; }
	public function controls(): array { return $this->kitControls; }
	public function settings(): array { return $this->kitSettings; }
	public function save( array $settings ): bool { $this->kitSettings = $settings; return true; }
	public function refresh(): void {}
	public function errors(): array { return []; }
}

function responsive_controls( bool $with_widescreen = true ): array {
	$slider = [ 'type' => 'slider', 'is_responsive' => true, 'size_units' => [ 'px', '%', 'custom' ], 'range' => [ 'px' => [ 'min' => 300, 'max' => 1500 ] ] ];
	$dimensions = [ 'type' => 'dimensions', 'is_responsive' => true, 'size_units' => [ 'px', 'custom' ] ];
	$controls = [
		'container_width' => $slider,
		'container_padding' => $dimensions,
		'hello_header_custom_width' => $slider,
		'hello_footer_custom_width' => $slider,
		'active_breakpoints' => [ 'type' => 'select2' ],
		'viewport_mobile' => [ 'type' => 'number' ],
		'viewport_tablet' => [ 'type' => 'number' ],
		'viewport_laptop' => [ 'type' => 'number' ],
	];
	if ( $with_widescreen ) { $controls['viewport_widescreen'] = [ 'type' => 'number' ]; }
	return $controls;
}

$gateway = new ResponsiveFakeGateway( responsive_controls(), [
	'active_breakpoints' => [ 'viewport_mobile', 'viewport_tablet', 'viewport_mobile_extra' ],
] );
$bridge = new ResponsiveFoundationBridge(
	new CapabilityReport( $gateway ),
	new ValueFactory( new ClampValidator() ),
	$gateway->settings()
);
$spec = ResponsiveLayoutPolicy::apply_to_spec( ( new ProfessionalCommerceProfile() )->spec() );
$built = $bridge->apply( $spec, [
	'settings' => [
		'container_width' => [ 'unit' => 'rem', 'size' => 82, 'sizes' => [] ],
		'active_breakpoints' => [ 'viewport_mobile', 'viewport_tablet', 'viewport_mobile_extra' ],
	],
	'plan' => [
		[ 'semanticPath' => 'settings.layout.contentWidth', 'control' => 'container_width', 'controlType' => 'slider', 'value' => [ 'unit' => 'rem', 'size' => 82, 'sizes' => [] ] ],
	],
	'skipped' => [],
	'preserved' => [],
	'notes' => [],
] );

$settings = $built['settings'];
rf_assert( [ 'viewport_mobile', 'viewport_tablet', 'viewport_laptop', 'viewport_widescreen' ] === $settings['active_breakpoints'], 'The profile must activate exactly four breakpoint controls plus implicit Desktop.' );
rf_assert( 767 === $settings['viewport_mobile'], 'Mobile breakpoint must be 767px.' );
rf_assert( 1024 === $settings['viewport_tablet'], 'Tablet breakpoint must be 1024px.' );
rf_assert( 1440 === $settings['viewport_laptop'], 'Laptop breakpoint must be 1440px.' );
rf_assert( 1920 === $settings['viewport_widescreen'], 'Widescreen breakpoint must start at 1920px.' );

$native_widths = [
	'container_width_mobile' => [ 'px', 767.0 ],
	'container_width_tablet' => [ 'px', 1024.0 ],
	'container_width_laptop' => [ 'px', 1440.0 ],
	'container_width' => [ '%', 100.0 ],
];
foreach ( $native_widths as $control => [ $unit, $expected ] ) {
	rf_assert( isset( $settings[ $control ] ), "{$control} must be written." );
	rf_assert( $unit === $settings[ $control ]['unit'], "{$control} must use {$unit}." );
	rf_assert( $expected === (float) $settings[ $control ]['size'], "{$control} must have the expected canvas width." );
}
rf_assert( 'custom' === $settings['container_width_widescreen']['unit'], 'Widescreen must switch to Custom Unit above native px range.' );
rf_assert( '1920px' === $settings['container_width_widescreen']['size'], 'Widescreen Custom Unit must preserve the requested 1920px canvas.' );

$padding_prefix = [
	'container_padding_mobile' => 'clamp(16px, 4vw, 20px)',
	'container_padding_tablet' => 'clamp(20px, 2.5vw, 28px)',
	'container_padding_laptop' => 'clamp(24px, 2.2vw, 32px)',
	'container_padding' => 'clamp(32px, 2.5vw, 48px)',
	'container_padding_widescreen' => 'clamp(48px, 3vw, 80px)',
];
foreach ( $padding_prefix as $control => $expected ) {
	rf_assert( 'custom' === $settings[ $control ]['unit'], "{$control} must use a custom fluid unit." );
	rf_assert( '0' === $settings[ $control ]['top'] && '0' === $settings[ $control ]['bottom'], "{$control} vertical padding must stay zero." );
	rf_assert( $expected === $settings[ $control ]['left'] && $expected === $settings[ $control ]['right'], "{$control} horizontal padding must match the profile clamp()." );
	rf_assert( false === $settings[ $control ]['isLinked'], "{$control} must not link vertical and horizontal sides." );
}

rf_assert( '%' === $settings['hello_header_custom_width']['unit'] && 100.0 === (float) $settings['hello_header_custom_width']['size'], 'Hello header Desktop Content width must follow the 100% base canvas.' );
rf_assert( 'custom' === $settings['hello_footer_custom_width_widescreen']['unit'] && '1920px' === $settings['hello_footer_custom_width_widescreen']['size'], 'Hello footer Widescreen must preserve the 1920px canvas through Custom Unit.' );

$paths = array_column( $built['plan'], 'semanticPath', 'control' );
rf_assert( 'settings.layout.contentWidth.laptop' === $paths['container_width_laptop'], 'Responsive content width must have a semantic verification path.' );
rf_assert( 'settings.layout.containerPadding.widescreen' === $paths['container_padding_widescreen'], 'Responsive padding must have a semantic verification path.' );

// A runtime without Widescreen must not invent an orphan Widescreen context.
$limited_gateway = new ResponsiveFakeGateway( responsive_controls( false ) );
$limited = ( new ResponsiveFoundationBridge(
	new CapabilityReport( $limited_gateway ),
	new ValueFactory( new ClampValidator() ),
	[]
) )->apply( $spec, [ 'settings' => [], 'plan' => [], 'skipped' => [], 'preserved' => [], 'notes' => [] ] );

rf_assert( ! in_array( 'viewport_widescreen', $limited['settings']['active_breakpoints'], true ), 'Unsupported Widescreen must not be activated.' );
rf_assert( ! isset( $limited['settings']['container_width_widescreen'] ), 'Unsupported Widescreen width must not be invented.' );
$limited_skips = [];
foreach ( $limited['skipped'] as $item ) { $limited_skips[ $item['key'] ] = $item['reason']; }
rf_assert( 'unsupported_control' === ( $limited_skips['settings.layout.breakpoints.widescreen'] ?? '' ), 'Missing Widescreen breakpoint must be reported.' );
rf_assert( 'breakpoint_unsupported' === ( $limited_skips['settings.layout.contentWidth.widescreen'] ?? '' ), 'Widescreen width must explain why it was skipped.' );

echo "PASS: five-context Site Settings responsive foundation\n";
