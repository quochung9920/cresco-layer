<?php
$root = dirname( __DIR__, 2 );
$source = file_get_contents( $root . '/includes/Diagnostics/ExportDiagnostics.php' );
$plugin = file_get_contents( $root . '/includes/Plugin.php' );

function cresco_diag_expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

cresco_diag_expect( false !== strpos( $source, "cresco-export-diagnostic/v1" ), 'Export diagnostic schema is missing.' );
cresco_diag_expect( false !== strpos( $source, "rest_pre_dispatch" ), 'Export diagnostics must begin before the REST callback.' );
cresco_diag_expect( false !== strpos( $source, "rest_request_after_callbacks" ), 'Export diagnostics must inspect callback results.' );
cresco_diag_expect( false !== strpos( $source, "register_shutdown_function" ), 'Fatal PHP failures need a shutdown recovery path.' );
cresco_diag_expect( false !== strpos( $source, "RESERVE_BYTES" ), 'Fatal recovery needs emergency memory.' );
cresco_diag_expect( false !== strpos( $source, "rest-response-serialization" ), 'Diagnostics must remain active through REST JSON serialization.' );
cresco_diag_expect( false !== strpos( $source, "X-Cresco-Request-Id" ), 'Correlation request header is missing.' );
cresco_diag_expect( false !== strpos( $source, "cresco_export_fatal" ), 'Fatal response code is missing.' );
cresco_diag_expect( false !== strpos( $source, "error_log" ), 'Server-side diagnostic logging is missing.' );
cresco_diag_expect( false !== strpos( $plugin, 'new ExportDiagnostics()' ), 'Plugin does not register the export diagnostics monitor.' );

fwrite( STDOUT, "Cresco Layer export diagnostics PHP contract passed.\n" );
