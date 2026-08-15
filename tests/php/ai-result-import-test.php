<?php
/**
 * AI result import contract: normalizer, ID generation and internal patch compilation.
 *
 * The point of this workflow is that a chat model can answer with the interface it wants and the
 * user pastes that answer directly. These tests fix the two halves of that promise: whatever shape
 * the model wrapped its answer in is still understood, and nothing about Elementor's patch
 * mechanics — operations, scope objects, element IDs — is the model's or the user's problem.
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

use CrescoLayer\AI\AIResultNormalizer;
use CrescoLayer\AI\ElementorIdGenerator;
use CrescoLayer\AI\InternalPatchCompiler;

function ai_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
function ai_throws( callable $callback, string $needle, string $message ): void {
	try { $callback(); } catch ( \Throwable $error ) {
		ai_assert( str_contains( $error->getMessage(), $needle ), $message . ' (got: ' . $error->getMessage() . ')' );
		return;
	}
	fwrite( STDERR, "FAIL: {$message} (nothing was thrown)\n" ); exit( 1 );
}

/** The document the user has open. */
function document_elements(): array {
	return [
		[
			'id' => '3ed4781', 'elType' => 'container', 'settings' => [], 'elements' => [
				[ 'id' => 'aaa1111', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [], 'elements' => [] ],
			],
		],
		[ 'id' => 'bbb2222', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
	];
}

/** The minimal answer a model is asked for: a target and an Elementor tree. */
function minimal_result(): array {
	return [
		'schema' => 'cresco-layer-ai-result/v1',
		'target' => [ 'postId' => 3, 'id' => '3ed4781' ],
		'element' => [
			'id' => '3ed4781', 'elType' => 'container', 'settings' => [ 'content_width' => 'boxed' ],
			'elements' => [
				[ 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'HELLO' ], 'elements' => [] ],
				[ 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'Buy' ], 'elements' => [] ],
			],
		],
	];
}

$normalizer = new AIResultNormalizer();

/* ---------- Every shape a model actually returns ---------- */

$direct = json_encode( minimal_result() );
ai_assert( 'ai-result' === $normalizer->normalize( $direct )['kind'], 'A direct result must be accepted.' );

$wrapped = json_encode( [ 'result' => minimal_result() ] );
ai_assert( 'ai-result' === $normalizer->normalize( $wrapped )['kind'], 'A {result:...} envelope must be accepted.' );

$data_wrapped = json_encode( [ 'data' => [ 'output' => minimal_result() ] ] );
ai_assert( 'ai-result' === $normalizer->normalize( $data_wrapped )['kind'], 'Nested envelopes must be unwrapped.' );

$fenced = "```json\n" . json_encode( minimal_result() ) . "\n```";
ai_assert( 'ai-result' === $normalizer->normalize( $fenced )['kind'], 'A markdown code fence must be accepted.' );

$fenced_plain = "```\n" . json_encode( minimal_result() ) . "\n```";
ai_assert( 'ai-result' === $normalizer->normalize( $fenced_plain )['kind'], 'An unlabelled fence must be accepted.' );

$chatty = "Sure! Here is the design you asked for:\n\n" . json_encode( minimal_result() ) . "\n\nLet me know if you want changes.";
ai_assert( 'ai-result' === $normalizer->normalize( $chatty )['kind'], 'Prose around the JSON must not break the import.' );

// A model that forgets the schema line but returns the right shape is still understood.
$no_schema = minimal_result();
unset( $no_schema['schema'] );
ai_assert( 'ai-result' === $normalizer->normalize( json_encode( $no_schema ) )['kind'], 'A result recognisable by shape must be accepted.' );

// Legacy patches keep working.
$legacy = json_encode( [ 'schema' => 'cresco-layer-patch/v1', 'base' => [ 'postId' => 3 ], 'operations' => [] ] );
ai_assert( 'legacy-patch' === $normalizer->normalize( $legacy )['kind'], 'A legacy patch must still import.' );
$legacy_wrapped = json_encode( [ 'patch' => [ 'schema' => 'cresco-layer-patch/v1', 'base' => [ 'postId' => 3 ], 'operations' => [] ] ] );
ai_assert( 'legacy-patch' === $normalizer->normalize( $legacy_wrapped )['kind'], 'A legacy {patch:...} envelope must still import.' );

/* ---------- Refusal still happens, and explains itself ---------- */

ai_throws( fn() => $normalizer->normalize( '' ), 'empty', 'Empty input must be refused.' );
ai_throws( fn() => $normalizer->normalize( 'not json at all' ), 'not valid JSON', 'Non-JSON must be refused.' );
ai_throws(
	fn() => $normalizer->normalize( json_encode( [ 'result' => [ 'metadata' => [ 'x' => 1 ] ] ] ) ),
	'Detected top-level keys',
	'An unrecognised payload must report what it found.'
);
try {
	$normalizer->normalize( json_encode( [ 'foo' => 1, 'bar' => 2 ] ) );
	fwrite( STDERR, "FAIL: unrelated JSON must be refused\n" ); exit( 1 );
} catch ( \Throwable $error ) {
	ai_assert( str_contains( $error->getMessage(), 'cresco-layer-ai-result/v1' ), 'The refusal must name the expected schema.' );
	ai_assert( str_contains( $error->getMessage(), 'foo' ), 'The refusal must list the keys that were found.' );
}

/* ---------- Element IDs are Cresco's job, not the model's ---------- */

$generator = new ElementorIdGenerator( document_elements() );
$normalized = $generator->normalize( minimal_result()['element'], '3ed4781' );

ai_assert( '3ed4781' === $normalized['element']['id'], 'The root must keep the target ID.' );
ai_assert( 2 === count( $normalized['generated'] ), 'Both ID-less children must be given IDs.' );
foreach ( $normalized['element']['elements'] as $child ) {
	ai_assert( 1 === preg_match( '/^[a-f0-9]{7}$/', $child['id'] ), 'A generated ID must be Elementor-shaped, got ' . $child['id'] );
	ai_assert( 'aaa1111' !== $child['id'] && 'bbb2222' !== $child['id'], 'A generated ID must not collide with the document.' );
}
$ids = array_column( $normalized['element']['elements'], 'id' );
ai_assert( count( $ids ) === count( array_unique( $ids ) ), 'Generated IDs must be unique among themselves.' );

// A valid, unused ID the model supplied is respected.
$with_ids = minimal_result()['element'];
$with_ids['elements'][0]['id'] = 'c0ffee1';
$kept = ( new ElementorIdGenerator( document_elements() ) )->normalize( $with_ids, '3ed4781' );
ai_assert( 'c0ffee1' === $kept['element']['elements'][0]['id'], 'A valid unused ID must be kept.' );
ai_assert( in_array( 'c0ffee1', $kept['reused'], true ), 'A kept ID must be reported as reused.' );

// An ID that collides with the document, or is malformed, is replaced.
$colliding = minimal_result()['element'];
$colliding['elements'][0]['id'] = 'aaa1111';   // already used elsewhere in the document
$colliding['elements'][1]['id'] = 'NOT-VALID';
$fixed = ( new ElementorIdGenerator( document_elements() ) )->normalize( $colliding, '3ed4781' );
ai_assert( 'aaa1111' !== $fixed['element']['elements'][0]['id'], 'A colliding ID must be replaced.' );
ai_assert( 'NOT-VALID' !== $fixed['element']['elements'][1]['id'], 'A malformed ID must be replaced.' );
ai_assert( 2 === count( $fixed['generated'] ), 'Both replacements must be reported.' );

// Duplicates inside the AI answer itself are resolved.
$dupes = minimal_result()['element'];
$dupes['elements'][0]['id'] = 'ddddddd';
$dupes['elements'][1]['id'] = 'ddddddd';
$deduped = ( new ElementorIdGenerator( document_elements() ) )->normalize( $dupes, '3ed4781' );
ai_assert( $deduped['element']['elements'][0]['id'] !== $deduped['element']['elements'][1]['id'], 'Duplicate IDs inside the result must be resolved.' );

// Nested children are handled at any depth.
$nested = minimal_result()['element'];
$nested['elements'][0]['elements'] = [ [ 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [], 'elements' => [] ] ];
$deep = ( new ElementorIdGenerator( document_elements() ) )->normalize( $nested, '3ed4781' );
ai_assert( '' !== $deep['element']['elements'][0]['elements'][0]['id'], 'A nested child must also get an ID.' );

/* ---------- Compiling into the internal patch ---------- */

$compiler = new InternalPatchCompiler();
$compiled = $compiler->compile( json_encode( minimal_result() ), 3, document_elements(), '3ed4781' );
$patch = $compiled['patch'];

ai_assert( 'cresco-layer-patch/v1' === $patch['schema'], 'The compiler must emit the internal patch schema.' );
ai_assert( 3 === $patch['base']['postId'], 'The patch must target the open document.' );
ai_assert( ! isset( $patch['base']['checksum'] ), 'The checksum-free workflow must not reintroduce a checksum.' );
ai_assert( 'subtree' === $patch['scope']['mode'], 'Replacing an element and its children is a subtree scope.' );
ai_assert( '3ed4781' === $patch['scope']['rootElementId'], 'The scope must be anchored to the target.' );
ai_assert( 1 === count( $patch['operations'] ), 'One replacement is one operation.' );
ai_assert( 'replace-element' === $patch['operations'][0]['operation'], 'A returned subtree compiles to replace-element.' );
ai_assert( '3ed4781' === $patch['operations'][0]['elementId'], 'The operation must address the target.' );
ai_assert( 3 === $compiled['report']['elementCount'], 'The report must count the whole returned tree.' );
ai_assert( 2 === count( $compiled['report']['generatedIds'] ), 'The report must say which IDs Cresco generated.' );

// The AI never has to name the document or the element when the editor already knows.
$bare = minimal_result();
unset( $bare['target'] );
unset( $bare['element']['id'] );
$from_selection = $compiler->compile( json_encode( $bare ), 3, document_elements(), '3ed4781' );
ai_assert( '3ed4781' === $from_selection['patch']['operations'][0]['elementId'], 'The current selection must supply a missing target.' );

// Legacy patches pass straight through.
$legacy_compiled = $compiler->compile( $legacy, 3, document_elements(), '' );
ai_assert( 'legacy-patch' === $legacy_compiled['report']['source'], 'A legacy patch must be passed through unchanged.' );

/* ---------- Target safety ---------- */

ai_throws(
	fn() => $compiler->compile( json_encode( minimal_result() ), 99, document_elements(), '3ed4781' ),
	'document #3',
	'A result built for another document must be refused.'
);
ai_throws(
	fn() => $compiler->compile( json_encode( minimal_result() ), 3, document_elements(), 'bbb2222' ),
	'is selected in Elementor',
	'A result that targets something other than the selection must be refused, not silently applied.'
);
$deleted = minimal_result();
$deleted['target']['id'] = 'deleted1';
$deleted['element']['id'] = 'deleted1';
ai_throws(
	fn() => $compiler->compile( json_encode( $deleted ), 3, document_elements(), '' ),
	'no longer exists',
	'A target removed since the export must be refused.'
);
$inconsistent = minimal_result();
$inconsistent['element']['id'] = 'bbb2222';
ai_throws(
	fn() => $compiler->compile( json_encode( $inconsistent ), 3, document_elements(), '3ed4781' ),
	'inconsistent',
	'A result whose root disagrees with its own target must be refused.'
);

echo "AI result import contract tests passed.\n";
