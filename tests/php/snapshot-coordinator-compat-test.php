<?php
$root = dirname( __DIR__, 2 );

require_once $root . '/includes/Elementor/RuntimeSnapshotCoordinator.php';
require_once $root . '/includes/DocumentSnapshotCoordinator.php';

function snapshot_compat_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

snapshot_compat_assert(
	class_exists( 'CrescoLayer\\Elementor\\RuntimeSnapshotCoordinator', false ),
	'Canonical RuntimeSnapshotCoordinator class is missing.'
);
snapshot_compat_assert(
	class_exists( 'CrescoLayer\\DocumentSnapshotCoordinator', false ),
	'Legacy DocumentSnapshotCoordinator compatibility alias is missing.'
);
snapshot_compat_assert(
	is_a(
		'CrescoLayer\\DocumentSnapshotCoordinator',
		'CrescoLayer\\Elementor\\RuntimeSnapshotCoordinator',
		true
	),
	'Legacy snapshot coordinator does not resolve to the canonical runtime coordinator.'
);

$plugin = file_get_contents( $root . '/includes/Plugin.php' );
snapshot_compat_assert( is_string( $plugin ), 'Plugin.php could not be read.' );
snapshot_compat_assert(
	str_contains( $plugin, 'use CrescoLayer\\Elementor\\RuntimeSnapshotCoordinator;' ),
	'Plugin.php must import the canonical RuntimeSnapshotCoordinator.'
);
snapshot_compat_assert(
	str_contains( $plugin, 'new RuntimeSnapshotCoordinator()' ),
	'Plugin.php must instantiate the canonical RuntimeSnapshotCoordinator.'
);
snapshot_compat_assert(
	! str_contains( $plugin, 'new DocumentSnapshotCoordinator()' ),
	'Plugin.php must not regress to the legacy DocumentSnapshotCoordinator name.'
);

echo "Snapshot coordinator compatibility test passed.\n";
