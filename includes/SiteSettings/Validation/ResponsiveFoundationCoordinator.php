<?php
namespace CrescoLayer\SiteSettings\Validation;

use CrescoLayer\SiteSettings\Discovery\RuntimeControlResolver;
use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;
use CrescoLayer\SiteSettings\Migration\BreakpointMigrationGuard;
use CrescoLayer\SiteSettings\Migration\BreakpointUsageScanner;

/** Runs geometry/runtime validation and breakpoint migration safety as one pre-write gate. */
final class ResponsiveFoundationCoordinator {
	public function __construct(
		private RuntimeControlResolver $controls,
		private BreakpointUsageScanner $scanner
	) {}

	public function inspect( array $spec, array $current ): array {
		$layout = (array) ( $spec['settings']['layout'] ?? [] );
		if ( ResponsiveLayoutPolicy::ID !== (string) ( $layout['policy'] ?? '' ) ) {
			return [ 'applicable' => false, 'compatible' => true, 'readyToApply' => true, 'validation' => null, 'migration' => null ];
		}

		$validation = ( new ResponsiveFoundationValidator( $this->controls ) )->validate( $spec );
		$desired = [];
		foreach ( (array) ( $layout['breakpoints'] ?? [] ) as $device => $_ ) {
			$control = 'viewport_' . (string) $device;
			if ( $this->controls->has( $control ) ) { $desired[] = $control; }
		}
		if ( ! empty( $layout['preserveExistingBreakpoints'] ) ) {
			$desired = array_values( array_unique( array_merge( array_map( 'strval', (array) ( $current['active_breakpoints'] ?? [] ) ), $desired ) ) );
		}
		$migration = ( new BreakpointMigrationGuard( $this->scanner ) )->inspect(
			(array) ( $current['active_breakpoints'] ?? [] ),
			$desired,
			(string) ( $layout['breakpointMigrationPolicy'] ?? ResponsiveLayoutPolicy::MIGRATION_POLICY )
		);

		$compatible = ! empty( $validation['compatible'] );
		return [
			'applicable' => true,
			'compatible' => $compatible,
			'readyToApply' => $compatible && empty( $migration['blocking'] ),
			'validation' => $validation,
			'migration' => $migration,
		];
	}
}
