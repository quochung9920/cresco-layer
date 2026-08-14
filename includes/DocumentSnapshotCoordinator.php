<?php
namespace CrescoLayer;

use CrescoLayer\Elementor\RuntimeSnapshotCoordinator;

/**
 * Backward-compatibility alias for early Cresco Layer builds that referenced
 * CrescoLayer\DocumentSnapshotCoordinator from Plugin.php.
 *
 * RuntimeSnapshotCoordinator is the canonical class. Keep this shim so a
 * partially overlaid plugin update cannot fatal before WordPress can load the
 * rest of Cresco Layer.
 */
if ( ! class_exists( __NAMESPACE__ . '\\DocumentSnapshotCoordinator', false ) ) {
	class_alias( RuntimeSnapshotCoordinator::class, __NAMESPACE__ . '\\DocumentSnapshotCoordinator' );
}
