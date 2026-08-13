<?php
$root = dirname( __DIR__, 2 );
$path = $root . '/includes/Elementor/RuntimeDiscovery.php';
$source = is_file( $path ) ? file_get_contents( $path ) : '';

function runtime_discovery_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

runtime_discovery_assert( '' !== $source, 'RuntimeDiscovery.php must exist.' );
runtime_discovery_assert( str_contains( $source, "['instance']" ), 'Dynamic Tag registry entries must use the registered instance field.' );
runtime_discovery_assert( str_contains( $source, 'get_tags()' ), 'Dynamic Tags must be requested from the runtime registry.' );
runtime_discovery_assert( str_contains( $source, 'get_modules_names()' ), 'Module discovery must enumerate module names.' );
runtime_discovery_assert( str_contains( $source, 'get_modules( $name )' ), 'Module discovery must request each named module instead of calling get_modules() without an argument.' );
runtime_discovery_assert( ! str_contains( $source, '$manager->get_modules() as' ), 'No-argument Pro module iteration must not return.' );
runtime_discovery_assert( str_contains( $source, 'conditionalCapabilities' ), 'Dependency-aware Pro capability reporting must be present.' );
runtime_discovery_assert( str_contains( $source, 'dependency-inactive' ), 'Licensed capabilities with inactive dependencies must be explicit.' );
runtime_discovery_assert( str_contains( $source, 'dynamic-tags-empty' ), 'An unexpectedly empty Pro Dynamic Tags registry must not be reported as complete.' );

echo "Runtime discovery contract tests passed.\n";
