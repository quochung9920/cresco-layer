<?php
require_once dirname( __DIR__, 2 ) . '/includes/AI/FidelityPolicy.php';

use CrescoLayer\AI\FidelityPolicy;

function expect_true( $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$policy = FidelityPolicy::config();
expect_true( FidelityPolicy::SCHEMA === $policy['schema'], 'Policy schema mismatch.' );
expect_true( FidelityPolicy::SNAPSHOT_SCHEMA === $policy['snapshotSchema'], 'Snapshot schema missing.' );
expect_true( FidelityPolicy::REPORT_SCHEMA === $policy['reportSchema'], 'Report schema missing.' );
expect_true( FidelityPolicy::GATE_SCHEMA === $policy['gateSchema'], 'Gate schema missing.' );
expect_true( abs( array_sum( $policy['weights'] ) - 1.0 ) < 0.000001, 'Fidelity weights must sum to 1.' );
expect_true( $policy['threshold'] >= 90, 'Fidelity threshold is unexpectedly low.' );
expect_true( in_array( 'horizontal-overflow', $policy['blockingRules'], true ), 'Horizontal overflow must block verification.' );
expect_true( in_array( 'no-verification-evidence', $policy['blockingRules'], true ), 'Missing rendered evidence must block verification.' );
expect_true( 4 === $policy['iteration']['maxCorrectionRounds'], 'Correction round budget changed unexpectedly.' );

echo "Fidelity policy contract tests passed.\n";
