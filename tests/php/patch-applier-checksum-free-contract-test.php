<?php
$path = dirname( __DIR__, 2 ) . '/includes/AI/PatchApplier.php';
$source = file_get_contents( $path );
if ( false === $source ) { fwrite( STDERR, "FAIL: could not read PatchApplier.\n" ); exit( 1 ); }
$forbidden = [ 'assert_freshness(', 'assert_checksum(', 'staleDocumentButScopeUnchanged' ];
foreach ( $forbidden as $needle ) {
	if ( str_contains( $source, $needle ) ) { fwrite( STDERR, "FAIL: freshness gate still present: {$needle}\n" ); exit( 1 ); }
}
$required = [ 'assert_expected_scope(', 'assert_scope_operations(', 'DocumentChecksum::hash', 'history->record' ];
foreach ( $required as $needle ) {
	if ( ! str_contains( $source, $needle ) ) { fwrite( STDERR, "FAIL: required safety/integrity behavior missing: {$needle}\n" ); exit( 1 ); }
}
echo "PASS: PatchApplier uses target/scope guards without checksum freshness gating\n";
