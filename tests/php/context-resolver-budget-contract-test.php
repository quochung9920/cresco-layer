<?php
$root = dirname( __DIR__, 2 );
$source = file_get_contents( $root . '/includes/AI/ContextResolver.php' );

function cresco_budget_expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

cresco_budget_expect( false !== strpos( $source, 'DETAIL_BUDGET_WIDGETS = 24' ), 'Widget detail budget is missing.' );
cresco_budget_expect( false !== strpos( $source, 'DETAIL_BUDGET_ELEMENTS = 12' ), 'Element detail budget is missing.' );
cresco_budget_expect( false !== strpos( $source, 'registry-full-bounded-detail' ), 'Full registry must use bounded detailed capability loading.' );
cresco_budget_expect( false !== strpos( $source, "'registryIndexComplete' => true" ), 'Full registry index must remain available to external AI.' );
cresco_budget_expect( false !== strpos( $source, "'targetAndExistingTypesNeverTruncated' => true" ), 'Existing/editable runtime types must never be truncated.' );
cresco_budget_expect( false !== strpos( $source, "'insertion-candidate' => 2" ), 'Construction candidates must outrank generic full-profile entries.' );
cresco_budget_expect( false !== strpos( $source, '$preferredOptional' ), 'Budget must prioritize construction candidates before generic registry entries.' );
cresco_budget_expect( false !== strpos( $source, "ExportDiagnostics::stage( 'context.capability-details'" ), 'Capability loading stage diagnostics are missing.' );
cresco_budget_expect( false !== strpos( $source, "ExportDiagnostics::stage( 'context.runtime-catalogs'" ), 'Runtime catalog stage diagnostics are missing.' );

fwrite( STDOUT, "Cresco Layer bounded context contract passed.\n" );
