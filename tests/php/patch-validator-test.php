<?php
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

require_once dirname( __DIR__, 2 ) . '/includes/AI/PatchValidator.php';
require_once dirname( __DIR__, 2 ) . '/includes/AI/Diff.php';
require_once dirname( __DIR__, 2 ) . '/includes/Support/DocumentChecksum.php';

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

$checksum = str_repeat( 'a', 64 );
$validator = new PatchValidator();
$patch = $validator->validate( [
	'schema' => 'cresco-layer-patch/v1',
	'base' => [ 'postId' => 42, 'checksum' => $checksum ],
	'label' => 'Test',
	'operations' => [
		[ 'operation' => 'update-setting', 'elementId' => 'abc123', 'setting' => 'title_color', 'value' => '#ffffff' ],
		[ 'operation' => 'move-element', 'elementId' => 'abc123', 'parentId' => 'container1', 'position' => 2 ],
	],
], 42 );
expect_true( 2 === count( $patch['operations'] ), 'Valid patch was not accepted.' );
$diff = Diff::summarize( $patch['operations'] );
expect_true( 1 === $diff['moved'] && 1 === $diff['updated'], 'Diff summary is incorrect.' );

expect_exception( fn() => $validator->validate( [ 'schema' => 'wrong', 'base' => [ 'postId' => 42, 'checksum' => $checksum ], 'operations' => [] ], 42 ), 'Invalid schema was accepted.' );
expect_exception( fn() => $validator->validate( [ 'schema' => 'cresco-layer-patch/v1', 'base' => [ 'postId' => 42, 'checksum' => $checksum ], 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'abc123', 'setting' => 'api_key', 'value' => 'secret' ] ] ], 42 ), 'Sensitive setting was accepted.' );
expect_exception( fn() => $validator->validate( [ 'schema' => 'cresco-layer-patch/v1', 'base' => [ 'postId' => 42, 'checksum' => $checksum ], 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'abc123', 'setting' => 'content', 'value' => '<img src=x onerror=alert(1)>' ] ] ], 42 ), 'Unsafe event markup was accepted.' );
expect_exception( fn() => $validator->validate( [ 'schema' => 'cresco-layer-patch/v1', 'base' => [ 'postId' => 42, 'checksum' => $checksum ], 'operations' => [ [ 'operation' => 'update-setting', 'elementId' => 'abc123', 'setting' => 'url', 'value' => 'javascript:alert(1)' ] ] ], 42 ), 'javascript: URL was accepted.' );

$a = DocumentChecksum::hash( [ [ 'id' => '1', 'settings' => [ 'b' => 2, 'a' => 1 ] ] ], [ 'z' => 2, 'a' => 1 ] );
$b = DocumentChecksum::hash( [ [ 'settings' => [ 'a' => 1, 'b' => 2 ], 'id' => '1' ] ], [ 'a' => 1, 'z' => 2 ] );
expect_true( hash_equals( $a, $b ), 'Checksum canonicalization is not deterministic.' );

echo "Patch validator, diff and checksum tests passed.\n";
