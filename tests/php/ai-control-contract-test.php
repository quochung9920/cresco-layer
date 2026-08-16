<?php
require_once dirname( __DIR__, 2 ) . '/includes/AI/ControlRegistry.php';
require_once dirname( __DIR__, 2 ) . '/includes/AI/PatchCapabilityValidator.php';

use CrescoLayer\AI\ControlRegistry;
use CrescoLayer\AI\PatchCapabilityValidator;

function expect_true( $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
function expect_failure( callable $callback, string $needle, string $message ): void {
	try { $callback(); }
	catch ( Throwable $error ) {
		if ( '' === $needle || false !== strpos( $error->getMessage(), $needle ) ) { return; }
		fwrite( STDERR, "FAIL: {$message} Got: {$error->getMessage()}\n" ); exit( 1 );
	}
	fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 );
}

$entry = [
	'name' => 'button',
	'title' => 'Button',
	'detailLoaded' => true,
	'controls' => [
		'align' => [ 'type' => 'choose', 'responsive' => true, 'options' => [ 'left' => 'Left', 'center' => 'Center', 'right' => 'Right' ], 'source' => 'classic-control' ],
		'border_radius' => [ 'type' => 'dimensions', 'responsive' => true, 'size_units' => [ 'px', '%', 'em', 'rem' ], 'source' => 'classic-control' ],
		'icon_size' => [ 'type' => 'slider', 'responsive' => true, 'size_units' => [ 'px', 'em' ], 'range' => [ 'px' => [ 'min' => 0, 'max' => 100, 'step' => 1 ] ], 'source' => 'classic-control' ],
		'html_id' => [ 'type' => 'text', 'responsive' => false, 'source' => 'classic-control' ],
	],
];

$registry = new ControlRegistry();
$normalized = $registry->build( [ 'controlMetadataVersion' => 5, 'widgets' => [ 'button' => $entry ], 'elements' => [] ] );
expect_true( ControlRegistry::SCHEMA === $normalized['schema'], 'Control registry schema changed unexpectedly.' );
expect_true( [ 'px', '%', 'em', 'rem' ] === $normalized['widgets']['button']['controls']['border_radius']['units'], 'Control units were not normalized.' );
expect_true( 'tablet' === $registry->resolve( $entry, 'align_tablet' )['device'], 'Responsive setting did not resolve to its base control.' );
expect_true( null === $registry->resolve( $entry, 'html_id_mobile' ), 'Non-responsive controls must reject device suffixes.' );

$resolver = static function ( string $kind, string $name ) use ( $entry ): array {
	if ( 'widget' !== $kind || 'button' !== $name ) { throw new InvalidArgumentException( 'Unexpected runtime type.' ); }
	return $entry;
};
$validator = new PatchCapabilityValidator( $registry, $resolver );
$elements = [ [
	'id' => 'button1',
	'elType' => 'widget',
	'widgetType' => 'button',
	'settings' => [ 'align' => 'left', 'future_field' => [ 'keep' => true ], '__globals__' => [ 'align' => 'globals/colors?id=primary' ] ],
	'elements' => [],
] ];

$report = $validator->validate( [ 'operations' => [
	[ 'operation' => 'update-setting', 'elementId' => 'button1', 'setting' => 'align_tablet', 'value' => 'center' ],
	[ 'operation' => 'update-setting', 'elementId' => 'button1', 'setting' => 'icon_size', 'value' => [ 'unit' => 'px', 'size' => 48 ] ],
	[ 'operation' => 'replace-settings', 'elementId' => 'button1', 'settings' => [ 'align' => 'right', 'future_field' => [ 'keep' => true ] ] ],
] ], $elements );
expect_true( 'trusted' === $report['status'], 'Valid runtime-proven patch was not trusted.' );
expect_true( 1 === $report['preservedUnknownSettings'], 'Unchanged unknown persisted setting should be preserved for forward compatibility.' );

expect_failure( fn() => $validator->validate( [ 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'button1', 'setting' => 'made_up_control', 'value' => 'x' ] ] ], $elements ), 'unsupported', 'Invented controls must fail closed.' );
expect_failure( fn() => $validator->validate( [ 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'button1', 'setting' => 'html_id_mobile', 'value' => 'x' ] ] ], $elements ), 'non-responsive', 'Responsive suffix on a non-responsive control must fail closed.' );
expect_failure( fn() => $validator->validate( [ 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'button1', 'setting' => 'align', 'value' => 'space-between' ] ] ], $elements ), 'allowed option', 'Invalid select/choose option must fail closed.' );
expect_failure( fn() => $validator->validate( [ 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'button1', 'setting' => 'icon_size', 'value' => [ 'unit' => 'rem', 'size' => 2 ] ] ] ], $elements ), 'not supported', 'Unsupported control unit must fail closed.' );
expect_failure( fn() => $validator->validate( [ 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'button1', 'setting' => 'icon_size', 'value' => [ 'unit' => 'px', 'size' => 101 ] ] ] ], $elements ), 'maximum', 'Out-of-range slider value must fail closed.' );
expect_failure( fn() => $validator->validate( [ 'operations' => [ [ 'operation' => 'replace-settings', 'elementId' => 'button1', 'settings' => [ 'future_field' => [ 'keep' => false ] ] ] ] ], $elements ), 'unsupported', 'Unknown persisted settings may be preserved but not mutated.' );
expect_failure( fn() => $validator->validate( [ 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'button1', 'setting' => '__globals__', 'value' => [ 'made_up_control' => 'globals/colors?id=primary' ] ] ] ], $elements ), 'Global style reference', 'Global references must point to runtime-proven controls.' );

echo "AI control registry and runtime patch capability validation tests passed.\n";
