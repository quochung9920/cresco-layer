<?php
require_once dirname( __DIR__, 2 ) . '/includes/Elementor/RuntimeSnapshotCoordinator.php';
require_once dirname( __DIR__, 2 ) . '/includes/DocumentSnapshotCoordinator.php';

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

echo "Snapshot coordinator compatibility test passed.\n";
