<?php
$root = dirname( __DIR__, 2 );
$source = file_get_contents( $root . '/includes/AI/ContextResolver.php' );
$runtime = file_get_contents( $root . '/includes/AI/ExportRuntimeCatalog.php' );

function cresco_budget_expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

cresco_budget_expect( false !== strpos( $source, 'DETAIL_BUDGET_WIDGETS = 12' ), 'Widget detail budget must be resource-bounded.' );
cresco_budget_expect( false !== strpos( $source, 'DETAIL_BUDGET_ELEMENTS = 6' ), 'Element detail budget must be resource-bounded.' );
cresco_budget_expect( false !== strpos( $source, 'registry-full-bounded-detail' ), 'Full registry must use bounded detailed capability loading.' );
cresco_budget_expect( false !== strpos( $source, "'registryIndexComplete' => true" ), 'Full registry index must remain available to external AI.' );
cresco_budget_expect( false !== strpos( $source, "'targetAndExistingTypesNeverTruncated' => true" ), 'Existing/editable runtime types must never be truncated.' );
cresco_budget_expect( false !== strpos( $source, "'insertion-candidate' => 2" ), 'Construction candidates must outrank generic full-profile entries.' );
cresco_budget_expect( false !== strpos( $source, '$preferredOptional' ), 'Budget must prioritize construction candidates before generic registry entries.' );
cresco_budget_expect( false !== strpos( $source, "ExportDiagnostics::stage( 'context.capability-details'" ), 'Capability loading stage diagnostics are missing.' );
cresco_budget_expect( false !== strpos( $source, "'strategy' => 'compact-export'" ), 'Runtime catalog stage must use the compact export strategy.' );
cresco_budget_expect( false !== strpos( $source, 'ExportRuntimeCatalog' ), 'Context resolver must use the lightweight export runtime catalog.' );
cresco_budget_expect( false !== strpos( $runtime, 'metadata-only-no-controls' ), 'Dynamic Tags export must avoid deep control serialization.' );
cresco_budget_expect( false === strpos( $runtime, 'safe_call( $instance, \'get_controls\'' ), 'Export runtime catalog must not call Dynamic Tag get_controls().' );
cresco_budget_expect( false === strpos( $runtime, 'safe_call( $instance, \'get_editor_config\'' ), 'Export runtime catalog must not call Dynamic Tag get_editor_config().' );
cresco_budget_expect( false !== strpos( $runtime, 'manager-name-summary-no-module-instantiation' ), 'Module export must avoid instantiating every module.' );
cresco_budget_expect( false === strpos( $runtime, 'get_modules( $name' ), 'Compact export runtime must not instantiate every module.' );

fwrite( STDOUT, "Cresco Layer resilient bounded context contract passed.\n" );
