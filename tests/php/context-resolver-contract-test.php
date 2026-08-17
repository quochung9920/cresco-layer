<?php
$root = dirname( __DIR__, 2 );
$resolverPath = $root . '/includes/AI/ContextResolver.php';
$builderPath = $root . '/includes/AI/PackageBuilder.php';
$controllerPath = $root . '/includes/REST/Controller.php';
$adminPagePath = $root . '/includes/Admin/AdminPage.php';
$adminJsPath = $root . '/assets/admin.js';
$resolver = is_file( $resolverPath ) ? file_get_contents( $resolverPath ) : '';
$builder = is_file( $builderPath ) ? file_get_contents( $builderPath ) : '';
$controller = is_file( $controllerPath ) ? file_get_contents( $controllerPath ) : '';
$adminPage = is_file( $adminPagePath ) ? file_get_contents( $adminPagePath ) : '';
$adminJs = is_file( $adminJsPath ) ? file_get_contents( $adminJsPath ) : '';

function context_resolver_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

context_resolver_assert( str_contains( $resolver, "PROFILE_SMART = 'smart'" ), 'Smart context profile must exist.' );
context_resolver_assert( str_contains( $resolver, "PROFILE_FULL = 'full'" ), 'Full context escape hatch must exist.' );
context_resolver_assert( str_contains( $resolver, 'cresco-context-resolver/v3' ), 'Context resolver must expose the current versioned identity.' );
context_resolver_assert( str_contains( $resolver, 'registryIndex' ), 'Resolver must keep a compact registry index of all registered types.' );
context_resolver_assert( str_contains( $resolver, 'capabilityCoverage' ), 'Resolver must expose trust/coverage information.' );
context_resolver_assert( str_contains( $resolver, 'insertion-candidate' ), 'Smart document/subtree exports must add bounded insertion candidates.' );
context_resolver_assert( str_contains( $resolver, 'filter_registered_roles' ) && str_contains( $resolver, "'widget' !== \$name" ), 'Generic persisted elType=widget must not be treated as a missing element capability.' );
context_resolver_assert( str_contains( $resolver, 'globalDesignSystem' ), 'Resolver must expose normalized global colors/fonts while preserving legacy designSystem settings.' );
context_resolver_assert( str_contains( $builder, 'contextProfile' ), 'AI package manifest must record the context profile.' );
context_resolver_assert( str_contains( $builder, 'registryIndex' ), 'AI package must include the compact registry index.' );
context_resolver_assert( str_contains( $builder, 'fullRuntimeSnapshotIsSeparate' ), 'AI package must explicitly keep the full runtime snapshot separate.' );
context_resolver_assert( str_contains( $builder, 'Never invent a setting name.' ), 'AI instructions must forbid invented Elementor settings.' );
context_resolver_assert( ! str_contains( $builder, '$this->scanner->catalog();' ), 'Normal AI export must not scan the full detailed runtime catalog.' );
context_resolver_assert( str_contains( $controller, "'context' =>" ), 'REST export must expose context profile selection.' );
context_resolver_assert( str_contains( $controller, "'aiContextResolver' => 'smart-v1'" ), 'Health endpoint must advertise the smart resolver profile.' );
context_resolver_assert( str_contains( $adminPage, 'cresco-layer-context-profile' ), 'Admin export must let the user choose Smart or Full context.' );
context_resolver_assert( str_contains( $adminJs, '&context=' ), 'Admin export request must send the selected context profile.' );
context_resolver_assert( str_contains( $adminPage, 'isIncomplete(data)' ) && str_contains( $adminPage, 'row.partial' ), 'Full snapshot top-level coverage must include internal partial scanner results, not only HTTP failures.' );

echo "AI context resolver contract tests passed.\n";
