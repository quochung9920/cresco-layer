<?php
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

require_once dirname( __DIR__, 2 ) . '/includes/Support/DocumentChecksum.php';
require_once dirname( __DIR__, 2 ) . '/includes/AI/ElementLocator.php';

use CrescoLayer\AI\ElementLocator;

function expect_true( $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}

$elements = [
	[
		'id' => 'root1', 'elType' => 'container', 'settings' => [ 'display' => 'flex' ], 'elements' => [
			[ 'id' => 'child1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'A' ], 'elements' => [] ],
			[ 'id' => 'child2', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'B' ], 'elements' => [] ],
		],
	],
	[ 'id' => 'root2', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
];

$locator = new ElementLocator();
$widget = $locator->scope_elements( $elements, 'widget', [ 'root1' ] );
expect_true( 1 === count( $widget ) && [] === $widget[0]['elements'], 'Widget scope must omit descendants.' );
$subtree = $locator->scope_elements( $elements, 'subtree', [ 'root1' ] );
expect_true( 2 === count( $subtree[0]['elements'] ), 'Subtree scope must preserve descendants.' );
$ids = $locator->scope_ids( $elements, 'subtree', [ 'root1' ] );
expect_true( [ 'root1', 'child1', 'child2' ] === $ids, 'Subtree editable IDs are incorrect.' );
$context = $locator->context( $elements, [ 'child1' ] );
expect_true( 'root1' === $context[0]['parent']['id'] && 2 === count( $context[0]['siblings'] ), 'Parent/sibling context is incorrect.' );
$before = $locator->scope_checksum( $elements, 'subtree', [ 'root1' ] );
$elements[1]['settings']['unrelated'] = 'changed';
$after = $locator->scope_checksum( $elements, 'subtree', [ 'root1' ] );
expect_true( hash_equals( $before, $after ), 'Scoped checksum changed because an unrelated element changed.' );
$elements[0]['settings']['display'] = 'grid';
$changed = $locator->scope_checksum( $elements, 'subtree', [ 'root1' ] );
expect_true( ! hash_equals( $before, $changed ), 'Scoped checksum did not detect target changes.' );

echo "Element locator and scoped checksum tests passed.\n";
