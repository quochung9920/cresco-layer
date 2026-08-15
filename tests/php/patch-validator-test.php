<?php
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

require_once dirname( __DIR__, 2 ) . '/includes/Support/DocumentChecksum.php';
require_once dirname( __DIR__, 2 ) . '/includes/AI/ElementLocator.php';
require_once dirname( __DIR__, 2 ) . '/includes/AI/PatchValidator.php';
require_once dirname( __DIR__, 2 ) . '/includes/AI/Diff.php';

use CrescoLayer\AI\Diff;
use CrescoLayer\AI\PatchValidator;
use CrescoLayer\Support\DocumentChecksum;

function expect_true( $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
function expect_exception( callable $callback, string $message ): void {
	try { $callback(); } catch ( Throwable $e ) { return; }
	fwrite( STDERR, $message . "\n" ); exit( 1 );
}

$validator = new PatchValidator();
$patch = $validator->validate( [
	'schema' => 'cresco-layer-patch/v1',
	'base' => [ 'postId' => 42 ],
	'scope' => [ 'mode' => 'subtree', 'rootElementId' => 'container1', 'elementIds' => [ 'container1' ] ],
	'label' => 'Test',
	'operations' => [
		[ 'operation' => 'update-setting', 'elementId' => 'abc123', 'setting' => 'title_color', 'value' => '#ffffff' ],
		[ 'operation' => 'move-element', 'elementId' => 'abc123', 'parentId' => 'container1', 'position' => 2 ],
	],
], 42 );
expect_true( 2 === count( $patch['operations'] ), 'Valid checksum-free patch was not accepted.' );
expect_true( 'subtree' === $patch['scope']['mode'], 'Scoped patch metadata was lost.' );
expect_true( ! isset( $patch['base']['checksum'] ) && ! isset( $patch['scope']['checksum'] ), 'Validator must not retain checksum fields in the patch contract.' );
$diff = Diff::summarize( $patch['operations'] );
expect_true( 1 === $diff['moved'] && 1 === $diff['updated'], 'Diff summary is incorrect.' );

$legacy = $validator->validate( [
	'schema' => 'cresco-layer-patch/v1',
	'base' => [ 'postId' => 42, 'checksum' => str_repeat( 'a', 64 ) ],
	'scope' => [ 'mode' => 'subtree', 'rootElementId' => 'container1', 'elementIds' => [ 'container1' ], 'checksum' => str_repeat( 'b', 64 ) ],
	'operations' => [],
], 42 );
expect_true( ! isset( $legacy['base']['checksum'] ) && ! isset( $legacy['scope']['checksum'] ), 'Legacy checksum fields should be tolerated but stripped.' );

$replacement = $validator->validate( [
	'schema' => 'cresco-layer-patch/v1',
	'base' => [ 'postId' => 42 ],
	'operations' => [ [
		'operation' => 'replace-element',
		'elementId' => 'abc123',
		'preserveChildren' => true,
		'element' => [
			'id' => 'abc123',
			'elType' => 'widget',
			'widgetType' => 'heading',
			'settings' => [ 'title' => 'Hello' ],
			'styles' => [ 'atomic-v4' => [ 'value' => 1 ] ],
			'future_elementor_field' => [ 'enabled' => true ],
			'elements' => [],
		],
	] ],
], 42 );
expect_true( true === $replacement['operations'][0]['element']['future_elementor_field']['enabled'], 'Unknown safe Elementor fields were not preserved.' );
expect_true( 1 === $replacement['operations'][0]['element']['styles']['atomic-v4']['value'], 'Atomic fields were not preserved.' );

$full = $validator->validate( [
	'schema' => 'cresco-layer-patch/v1',
	'base' => [ 'postId' => 42 ],
	'operations' => [ [
		'operation' => 'replace-document',
		'content' => [ [ 'id' => 'root1', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ],
		'pageSettings' => [ 'hide_title' => 'yes' ],
	] ],
], 42 );
expect_true( 'root1' === $full['operations'][0]['content'][0]['id'], 'Full document replacement was not validated.' );

expect_exception( fn() => $validator->validate( [ 'schema' => 'wrong', 'base' => [ 'postId' => 42 ], 'operations' => [] ], 42 ), 'Invalid schema was accepted.' );
expect_exception( fn() => $validator->validate( [ 'schema' => 'cresco-layer-patch/v1', 'base' => [ 'postId' => 42 ], 'scope' => [ 'mode' => 'widget', 'elementIds' => [ 'a', 'b' ] ], 'operations' => [] ], 42 ), 'Invalid widget scope was accepted.' );
expect_exception( fn() => $validator->validate( [ 'schema' => 'cresco-layer-patch/v1', 'base' => [ 'postId' => 42 ], 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'abc123', 'setting' => 'api_key', 'value' => 'secret' ] ] ], 42 ), 'Sensitive setting was accepted.' );
expect_exception( fn() => $validator->validate( [ 'schema' => 'cresco-layer-patch/v1', 'base' => [ 'postId' => 42 ], 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'abc123', 'setting' => 'content', 'value' => '<img src=x onerror=alert(1)>' ] ] ], 42 ), 'Unsafe event markup was accepted.' );
expect_exception( fn() => $validator->validate( [ 'schema' => 'cresco-layer-patch/v1', 'base' => [ 'postId' => 42 ], 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'abc123', 'setting' => 'url', 'value' => 'javascript:alert(1)' ] ] ], 42 ), 'javascript: URL was accepted.' );

$a = DocumentChecksum::hash( [ [ 'id' => '1', 'settings' => [ 'b' => 2, 'a' => 1 ] ] ], [ 'z' => 2, 'a' => 1 ] );
$b = DocumentChecksum::hash( [ [ 'settings' => [ 'a' => 1, 'b' => 2 ], 'id' => '1' ] ], [ 'a' => 1, 'z' => 2 ] );
expect_true( hash_equals( $a, $b ), 'Internal checksum canonicalization is not deterministic.' );

echo "Patch validator, checksum-free scope, lossless fields, diff and internal checksum tests passed.\n";
