<?php
/**
 * Central Elementor ID allocation contract.
 *
 * "Inserted element ID already exists" is the most common import failure, and it comes from asking
 * the wrong party to solve an impossible problem: an external AI cannot see the rest of the document,
 * so any final ID it mints may collide with an element it never knew about. Temporary references let
 * it name its own nodes symbolically while Cresco owns the one decision it can make safely.
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

use CrescoLayer\AI\ElementorIdGenerator;

function id_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

/** A document large enough that a guessed ID would plausibly collide. */
function large_document(): array {
	$children = [];
	for ( $i = 0; $i < 400; $i++ ) {
		$children[] = [ 'id' => substr( md5( 'seed' . $i ), 0, 7 ), 'elType' => 'widget', 'widgetType' => 'heading', 'elements' => [] ];
	}
	return [ [ 'id' => 'roooot1', 'elType' => 'container', 'elements' => $children ] ];
}

/* ---------- Temporary references ---------- */

id_assert( ElementorIdGenerator::is_ref( '$new:hero' ), 'A $new: value is a temporary reference.' );
id_assert( ! ElementorIdGenerator::is_ref( '$new:' ), 'A reference must actually name something.' );
id_assert( ! ElementorIdGenerator::is_ref( 'a1b2c3d' ), 'A real Elementor ID is not a reference.' );

$generator = new ElementorIdGenerator( large_document() );
$tree = [
	'id' => 'roooot1', 'elType' => 'container', 'elements' => [
		[ 'ref' => '$new:headline', 'elType' => 'widget', 'widgetType' => 'heading', 'elements' => [] ],
		[ 'ref' => '$new:cta', 'elType' => 'widget', 'widgetType' => 'button', 'elements' => [] ],
	],
];
$result = $generator->normalize( $tree, 'roooot1' );

id_assert( 'roooot1' === $result['element']['id'], 'The target root keeps its real ID; it is never remapped.' );
id_assert( 2 === count( $result['generated'] ), 'Both referenced nodes must receive allocated IDs.' );
id_assert( [] === $result['duplicateRefs'], 'Distinct references are not duplicates.' );

foreach ( $result['element']['elements'] as $child ) {
	id_assert( 1 === preg_match( '/^[a-f0-9]{7}$/', $child['id'] ), 'An allocated ID must be Elementor-shaped, got ' . $child['id'] );
	id_assert( ! array_key_exists( 'ref', $child ), 'The temporary reference must not survive into Elementor data.' );
}

// The mapping is reported so a caller can rewrite references elsewhere in the answer.
id_assert( 2 === count( $result['refs'] ), 'The resolved reference map must be reported.' );
id_assert( isset( $result['refs']['$new:headline'] ), 'Each reference must appear in the map.' );
id_assert( $result['refs']['$new:headline'] === $result['element']['elements'][0]['id'], 'The map must match the allocated ID.' );

/* ---------- Collisions against a large existing document ---------- */

$existing = [];
foreach ( large_document()[0]['elements'] as $child ) { $existing[ $child['id'] ] = true; }
foreach ( $result['generated'] as $id ) {
	id_assert( ! isset( $existing[ $id ] ), 'An allocated ID must not collide with the 400-element document: ' . $id );
}

// Allocating many IDs in one pass must stay unique among themselves.
$bulk = new ElementorIdGenerator( large_document() );
$seen = [];
for ( $i = 0; $i < 300; $i++ ) {
	$id = $bulk->generate();
	id_assert( ! isset( $seen[ $id ] ), 'Bulk allocation must not repeat an ID.' );
	id_assert( ! isset( $existing[ $id ] ), 'Bulk allocation must not hit the existing document.' );
	$seen[ $id ] = true;
}

/* ---------- A repeated reference is a mistake, and is reported ---------- */

$dupes = new ElementorIdGenerator( large_document() );
$duped = $dupes->normalize( [
	'id' => 'roooot1', 'elType' => 'container', 'elements' => [
		[ 'ref' => '$new:card', 'elType' => 'widget', 'widgetType' => 'heading', 'elements' => [] ],
		[ 'ref' => '$new:card', 'elType' => 'widget', 'widgetType' => 'button', 'elements' => [] ],
	],
], 'roooot1' );

id_assert( in_array( '$new:card', $duped['duplicateRefs'], true ), 'A reference used twice must be reported, not silently merged.' );

/* ---------- Explicit IDs still behave as before ---------- */

$mixed = new ElementorIdGenerator( large_document() );
$taken_id = large_document()[0]['elements'][5]['id'];
$mixed_result = $mixed->normalize( [
	'id' => 'roooot1', 'elType' => 'container', 'elements' => [
		[ 'id' => 'c0ffee1', 'elType' => 'widget', 'widgetType' => 'heading', 'elements' => [] ],
		[ 'id' => $taken_id, 'elType' => 'widget', 'widgetType' => 'button', 'elements' => [] ],
		[ 'ref' => '$new:extra', 'elType' => 'widget', 'widgetType' => 'icon', 'elements' => [] ],
	],
], 'roooot1' );

id_assert( 'c0ffee1' === $mixed_result['element']['elements'][0]['id'], 'A valid unused explicit ID is still honoured.' );
id_assert( $taken_id !== $mixed_result['element']['elements'][1]['id'], 'An explicit ID that collides with the document must be reallocated.' );
id_assert( 1 === preg_match( '/^[a-f0-9]{7}$/', $mixed_result['element']['elements'][2]['id'], $m ), 'References and explicit IDs must coexist.' );

$all = array_column( $mixed_result['element']['elements'], 'id' );
id_assert( count( $all ) === count( array_unique( $all ) ), 'The finished subtree must contain no duplicate IDs.' );

/* ---------- An unknown reference cannot be honoured ---------- */

$threw = false;
try { ( new ElementorIdGenerator() )->resolve_ref( 'not-a-ref' ); }
catch ( \InvalidArgumentException $expected ) { $threw = true; }
id_assert( $threw, 'Resolving something that is not a reference must fail closed.' );

$fresh = new ElementorIdGenerator();
id_assert( ! $fresh->has_ref( '$new:never' ), 'An unallocated reference must report as unknown so a pointer to it can fail.' );
$allocated = $fresh->resolve_ref( '$new:once' );
id_assert( $allocated === $fresh->resolve_ref( '$new:once' ), 'The same reference must resolve to the same ID every time.' );

echo "AI ID allocator contract tests passed.\n";
