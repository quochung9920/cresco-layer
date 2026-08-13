<?php
$root = dirname( __DIR__, 2 );
$resolverPath = $root . '/includes/AI/ContextResolver.php';
$builderPath = $root . '/includes/AI/PackageBuilder.php';
$controllerPath = $root . '/includes/REST/Controller.php';
$resolver = is_file( $resolverPath ) ? file_get_contents( $resolverPath ) : '';
$builder = is_file( $builderPath ) ? file_get_contents( $builderPath ) : '';
$controller = is_file( $controllerPath ) ? file_get_contents( $controllerPath ) : '';

function context_resolver_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

context_resolver_assert( str_contains( $resolver, "PROFILE_SMART = 'smart'" ), 'Smart context profile must exist.' );
context_resolver_assert( str_contains( $resolver, "PROFILE_FULL = 'full'" ), 'Full context escape hatch must exist.' );
context_resolver_assert( str_contains( $resolver, 'cresco-context-resolver/v1' ), 'Context resolver must expose a versioned identity.' );
context_resolver_assert( str_contains( $resolver, 'registryIndex' ), 'Resolver must keep a compact registry index of all registered types.' );
context_resolver_assert( str_contains( $resolver, 'capabilityCoverage' ), 'Resolver must expose trust/coverage information.' );
context_resolver_assert( str_contains( $resolver, 'insertion-candidate' ), 'Smart document/subtree exports must add bounded insertion candidates.' );
context_resolver_assert( str_contains( $resolver, 'globalDesignSystem' ), 'Resolver must expose normalized global colors/fonts while preserving legacy designSystem settings.' );
context_resolver_assert( str_contains( $builder, 'contextProfile' ), 'AI package manifest must record the context profile.' );
context_resolver_assert( str_contains( $builder, 'registryIndex' ), 'AI package must include the compact registry index.' );
context_resolver_assert( str_contains( $builder, 'fullRuntimeSnapshotIsSeparate' ), 'AI package must explicitly keep the full runtime snapshot separate.' );
context_resolver_assert( str_contains( $builder, 'Never invent a setting name.' ), 'AI instructions must forbid invented Elementor settings.' );
context_resolver_assert( ! str_contains( $builder, '$this->scanner->catalog();' ), 'Normal AI export must not scan the full detailed runtime catalog.' );
context_resolver_assert( str_contains( $controller, "'context' =>" ), 'REST export must expose context profile selection.' );
context_resolver_assert( str_contains( $controller, "'aiContextResolver' => 'smart-v1'" ), 'Health endpoint must advertise the smart resolver.' );

echo "AI context resolver contract tests passed.\n";
