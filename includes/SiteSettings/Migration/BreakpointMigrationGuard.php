<?php
namespace CrescoLayer\SiteSettings\Migration;

/** Prevents deactivating responsive breakpoints that still own saved Elementor overrides. */
final class BreakpointMigrationGuard {
	public function __construct( private BreakpointUsageScanner $scanner ) {}

	/**
	 * @param string[] $currentActive Elementor active_breakpoints values (viewport_*).
	 * @param string[] $desiredActive Elementor active_breakpoints values (viewport_*).
	 */
	public function inspect( array $currentActive, array $desiredActive, string $policy = 'block-if-used' ): array {
		$current = array_values( array_unique( array_map( 'strval', $currentActive ) ) );
		$desired = array_values( array_unique( array_map( 'strval', $desiredActive ) ) );
		$removed_controls = array_values( array_diff( $current, $desired ) );
		$removed_devices = [];
		foreach ( $removed_controls as $control ) {
			if ( str_starts_with( $control, 'viewport_' ) ) { $removed_devices[] = substr( $control, 9 ); }
		}

		$base = [
			'policy' => $policy,
			'currentActive' => $current,
			'desiredActive' => $desired,
			'removedControls' => $removed_controls,
			'removedDevices' => $removed_devices,
			'blocking' => false,
			'safe' => true,
			'impact' => [ 'scannedDocuments' => 0, 'truncated' => false, 'totalSettingCount' => 0, 'hasUsage' => false, 'usage' => [] ],
			'message' => '',
		];
		if ( ! $removed_devices || 'preserve' === $policy ) { return $base; }

		$impact = $this->scanner->scan( $removed_devices );
		$has_usage = ! empty( $impact['hasUsage'] );
		$truncated = ! empty( $impact['truncated'] );
		$blocking = 'block-if-used' === $policy && ( $has_usage || $truncated );
		$base['impact'] = $impact;
		$base['blocking'] = $blocking;
		$base['safe'] = ! $blocking;

		if ( $truncated ) {
			$base['message'] = 'Breakpoint migration scan was truncated, so Cresco cannot prove that deactivating the removed breakpoints is safe.';
		} elseif ( $has_usage ) {
			$base['message'] = sprintf(
				'Breakpoint migration blocked: %d saved responsive override(s) still use breakpoint(s) that the profile would deactivate.',
				(int) ( $impact['totalSettingCount'] ?? 0 )
			);
		} elseif ( $removed_devices ) {
			$base['message'] = 'Removed breakpoints have no saved responsive overrides in the scanned Elementor documents.';
		}
		return $base;
	}
}
