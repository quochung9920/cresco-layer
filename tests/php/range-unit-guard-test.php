<?php
/**
 * Cross-unit range enforcement contract.
 *
 * Elementor declares slider ranges per unit, but controls routinely offer more units in size_units
 * than they define ranges for. Comparing a value against a different unit's bounds (50vw against a
 * px minimum of 500) rejects valid patches, which is exactly what a reviewer sees as
 * "Value for Elementor control width is below its supported minimum".
 */

require_once dirname( __DIR__, 2 ) . '/includes/AI/ElementLocator.php';
require_once dirname( __DIR__, 2 ) . '/includes/AI/SemanticPatchGuard.php';

use CrescoLayer\AI\SemanticPatchGuard;

function range_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$guard  = new SemanticPatchGuard();
$method = new ReflectionMethod( SemanticPatchGuard::class, 'active_bounds' );
$method->setAccessible( true );

// A container width control: many units offered, ranges declared for only two of them.
$width = [
	'type' => 'slider',
	'size_units' => [ 'px', '%', 'em', 'rem', 'vw', 'custom' ],
	'range' => [
		'px' => [ 'min' => 500, 'max' => 1600 ],
		'%'  => [ 'min' => 0, 'max' => 100 ],
	],
];

// The reported bug: a unit with no declared range must not borrow the px bounds.
[ $min, $max ] = $method->invoke( $guard, $width, 'vw' );
range_assert( null === $min && null === $max, 'vw must not inherit the px range.' );

[ $min, $max ] = $method->invoke( $guard, $width, 'em' );
range_assert( null === $min, 'em must not inherit the px minimum.' );

// Units that do declare a range are still enforced.
[ $min, $max ] = $method->invoke( $guard, $width, 'px' );
range_assert( 500 === $min && 1600 === $max, 'px bounds must still be enforced.' );

[ $min, $max ] = $method->invoke( $guard, $width, '%' );
range_assert( 0 === $min && 100 === $max, 'percentage bounds must still be enforced.' );

// Elementor's custom unit carries raw CSS; numeric bounds cannot apply.
[ $min, $max ] = $method->invoke( $guard, $width, 'custom' );
range_assert( null === $min && null === $max, 'custom unit must skip numeric range checks.' );

// A bare number means px.
[ $min, $max ] = $method->invoke( $guard, $width, '' );
range_assert( 500 === $min, 'A unit-less number must use the px range.' );

// Flat (non per-unit) ranges still work.
$flat = [ 'type' => 'slider', 'range' => [ 'min' => 1, 'max' => 10 ] ];
[ $min, $max ] = $method->invoke( $guard, $flat, '' );
range_assert( 1 === $min && 10 === $max, 'Flat ranges must still be enforced.' );
[ $min, $max ] = $method->invoke( $guard, $flat, 'px' );
range_assert( 1 === $min, 'Flat ranges apply regardless of unit.' );

// Scalar min/max on the control itself remain the last resort.
$scalar = [ 'type' => 'number', 'min' => 2, 'max' => 8 ];
[ $min, $max ] = $method->invoke( $guard, $scalar, '' );
range_assert( 2 === $min && 8 === $max, 'Control-level min/max must still apply.' );

// No bounds declared anywhere means unbounded.
[ $min, $max ] = $method->invoke( $guard, [ 'type' => 'text' ], '' );
range_assert( null === $min && null === $max, 'A control without bounds must be unbounded.' );

// The message must name the offending value and the bound so the patch can be corrected.
$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/AI/SemanticPatchGuard.php' );
range_assert( str_contains( $source, 'is below its supported minimum of' ), 'Range errors must state the actual minimum.' );
range_assert( str_contains( $source, 'exceeds its supported maximum of' ), 'Range errors must state the actual maximum.' );

echo "Range unit guard contract tests passed.\n";
