<?php
require_once dirname( __DIR__, 2 ) . '/includes/Skills/SkillCompiler.php';

use CrescoLayer\Skills\SkillCompiler;

$compiler = new SkillCompiler();
$compiled = $compiler->compile( [
	'name' => 'atomic-test',
	'isAtomic' => true,
	'capabilitySource' => 'atomic-controls+props-schema',
	'controls' => [
		'atomic_control_0' => [ 'type' => 'text', 'label' => 'Unbound UI control', 'source' => 'atomic-control', 'bind' => '' ],
		'title' => [ 'type' => 'text', 'label' => 'Title', 'source' => 'atomic-props-schema', 'bind' => 'title' ],
	],
], [ 'title' => 'Hello' ] );

$unbound = null;
$bound = null;
foreach ( $compiled['skills'] as $skill ) {
	if ( 'control.atomic_control_0' === $skill['id'] ) { $unbound = $skill; }
	if ( 'control.title' === $skill['id'] ) { $bound = $skill; }
}
if ( ! is_array( $unbound ) || 'read-only' !== $unbound['mode'] || '' !== $unbound['setting'] ) {
	fwrite( STDERR, "FAIL: unbound Atomic UI control became an executable fake setting.\n" );
	exit( 1 );
}
if ( ! is_array( $bound ) || 'title' !== $bound['setting'] || 'direct' !== $bound['mode'] ) {
	fwrite( STDERR, "FAIL: bound Atomic prop was not compiled as an executable native skill.\n" );
	exit( 1 );
}
try {
	$compiler->resolve( $compiled, $unbound['id'], [ 'value' => 'Nope' ], [], 'atomic1' );
	fwrite( STDERR, "FAIL: unbound Atomic UI control was resolved.\n" );
	exit( 1 );
} catch ( InvalidArgumentException $expected ) {}

$resolution = $compiler->resolve( $compiled, $bound['id'], [ 'value' => 'Updated' ], [ 'title' => 'Hello' ], 'atomic1' );
if ( 'title' !== $resolution['operations'][0]['setting'] ) {
	fwrite( STDERR, "FAIL: bound Atomic prop lost its runtime binding.\n" );
	exit( 1 );
}

echo "Atomic skill binding tests passed.\n";
