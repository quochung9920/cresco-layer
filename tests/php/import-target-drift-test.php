<?php
/**
 * Reproduces and locks the "import landed on a different container" failure.
 *
 * A user selects one container, imports an AI result, and another container is rewritten instead.
 * Two independent holes allow that, and either one alone is enough to lose the user's work:
 *
 *   1. A patch with no scope, or document scope, skips operation-level target enforcement entirely,
 *      so an operation may address any element in the document.
 *   2. The editor's expectedScope only guards when the patch declares a root element, so a patch
 *      that omits one is applied wherever its operations point.
 */

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'CrescoLayer\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) { return; }
		$path = dirname( __DIR__, 2 ) . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) { require_once $path; }
	}
);

use CrescoLayer\AI\PatchApplier;

function drift_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

/** Two sibling containers. The user is working in the first one. */
function drift_document(): array {
	return [
		[ 'id' => 'aaaaaa1', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
		[ 'id' => 'bbbbbb2', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
	];
}

$applier = new PatchApplier( new CrescoLayer\AI\PatchValidator(), new CrescoLayer\Audit\Auditor() );

$scope_check = new ReflectionMethod( PatchApplier::class, 'assert_scope_operations' );
$scope_check->setAccessible( true );
$expected_check = new ReflectionMethod( PatchApplier::class, 'assert_expected_scope' );
$expected_check->setAccessible( true );

/* ---------- Hole 1: an unscoped patch may address any element ---------- */

// The user selected aaaaaa1, but the operation names bbbbbb2.
$stray = [
	'schema' => 'cresco-layer-patch/v1',
	'base' => [ 'postId' => 3 ],
	'operations' => [
		[ 'operation' => 'replace-element', 'elementId' => 'bbbbbb2', 'element' => [ 'id' => 'bbbbbb2', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ],
	],
];

$threw = false;
try {
	$scope_check->invoke( $applier, $stray, drift_document() );
} catch ( \Throwable $error ) { $threw = true; }
drift_assert( $threw, 'An operation addressing an element outside the imported target must be refused even when the patch declares no scope.' );

// Document scope is a deliberate whole-page rewrite and stays allowed.
$document_wide = $stray;
$document_wide['scope'] = [ 'mode' => 'document', 'rootElementId' => '', 'elementIds' => [] ];
$document_wide['operations'] = [ [ 'operation' => 'replace-document', 'content' => [], 'pageSettings' => [] ] ];
$threw = false;
try { $scope_check->invoke( $applier, $document_wide, drift_document() ); }
catch ( \Throwable $error ) { $threw = true; }
drift_assert( ! $threw, 'An explicit document-scope rewrite must still be allowed.' );

/* ---------- Hole 2: expectedScope must bind the target, not merely the mode ---------- */

// The editor says "the user has aaaaaa1 selected"; the patch carries no root at all.
$rootless = [
	'schema' => 'cresco-layer-patch/v1',
	'base' => [ 'postId' => 3 ],
	'scope' => [ 'mode' => 'subtree', 'rootElementId' => '', 'elementIds' => [ 'bbbbbb2' ] ],
	'operations' => [],
];
$threw = false;
try {
	$expected_check->invoke( $applier, $rootless, [ 'mode' => 'subtree', 'rootElementId' => 'aaaaaa1' ] );
} catch ( \Throwable $error ) { $threw = true; }
drift_assert( $threw, 'A patch whose scope does not name the selected element must be refused, not applied to whatever its elementIds point at.' );

// A patch that does match the selection still applies.
$matching = $rootless;
$matching['scope'] = [ 'mode' => 'subtree', 'rootElementId' => 'aaaaaa1', 'elementIds' => [ 'aaaaaa1' ] ];
$threw = false;
try { $expected_check->invoke( $applier, $matching, [ 'mode' => 'subtree', 'rootElementId' => 'aaaaaa1' ] ); }
catch ( \Throwable $error ) { $threw = true; }
drift_assert( ! $threw, 'A patch that targets the selected element must still apply.' );

/* ---------- The editor must not answer with a remembered selection ---------- */

$editor = file_get_contents( dirname( __DIR__, 2 ) . '/assets/editor.js' );
drift_assert(
	str_contains( $editor, 'function liveSelectedId' ),
	'Selection for a mutating import must come from a live source, not module memory.'
);
drift_assert(
	preg_match( '/liveSelectedId\s*\(\s*\)/', $editor ) > 0,
	'The strict resolver must actually be used.'
);

echo "Import target drift contract tests passed.\n";
