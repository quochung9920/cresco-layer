<?php
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

use CrescoLayer\AI\InternalPatchCompiler;

function delta_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$document = [
	[
		'id' => '3ed4781', 'elType' => 'container', 'settings' => [], 'elements' => [
			[ 'id' => 'aaa1111', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Existing' ], 'elements' => [] ],
		],
	],
];

$delta = [
	'schema' => 'cresco-layer-patch/v1',
	'base' => [ 'postId' => 3 ],
	'scope' => [ 'mode' => 'subtree', 'rootElementId' => '3ed4781', 'elementIds' => [ '3ed4781' ] ],
	'operations' => [
		[
			'operation' => 'insert-element',
			'parentId' => '3ed4781',
			'position' => 99,
			'element' => [
				'elType' => 'container',
				'settings' => [ 'flex_direction' => 'row' ],
				'elements' => [
					[ 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'New UI' ], 'elements' => [] ],
					[ 'id' => 'aaa1111', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => [ 'text' => 'CTA' ], 'elements' => [] ],
				],
			],
		],
	],
];

$result = ( new InternalPatchCompiler() )->compile( json_encode( $delta ), 3, $document, '3ed4781' );
$insert = $result['patch']['operations'][0]['element'];

delta_assert( 1 === preg_match( '/^[a-f0-9]{7}$/', $insert['id'] ), 'Inserted root must receive an Elementor ID.' );
delta_assert( 1 === preg_match( '/^[a-f0-9]{7}$/', $insert['elements'][0]['id'] ), 'ID-less nested widget must receive an Elementor ID.' );
delta_assert( 'aaa1111' !== $insert['elements'][1]['id'], 'Colliding nested ID must be replaced.' );
delta_assert( count( $result['report']['generatedIds'] ) >= 3, 'Generated IDs must be reported to Preview/UI diagnostics.' );
delta_assert( true === $result['report']['deltaNormalized'], 'Delta normalization must be reported.' );

echo "Delta insert ID normalization tests passed.\n";
