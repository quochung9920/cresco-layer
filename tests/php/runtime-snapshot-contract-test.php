<?php
require_once dirname( __DIR__, 2 ) . '/includes/AI/CapabilityScanner.php';
require_once dirname( __DIR__, 2 ) . '/includes/Support/SerializableSanitizer.php';
require_once dirname( __DIR__, 2 ) . '/includes/Elementor/RuntimeSnapshot.php';

use CrescoLayer\AI\CapabilityScanner;
use CrescoLayer\Elementor\RuntimeSnapshot;

function snapshot_contract_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

snapshot_contract_assert( 'cresco-elementor-snapshot/v1' === RuntimeSnapshot::SCHEMA, 'Snapshot schema must remain versioned.' );
$requiredSections = [ 'environment', 'global-settings', 'features', 'breakpoints', 'active-kit', 'dynamic-tags', 'runtime', 'records' ];
foreach ( $requiredSections as $section ) {
	snapshot_contract_assert( in_array( $section, RuntimeSnapshot::SECTIONS, true ), 'Missing snapshot section: ' . $section );
}

$snapshot = new RuntimeSnapshot( new CapabilityScanner() );
$classify = new ReflectionMethod( RuntimeSnapshot::class, 'classifyRecord' );
$classify->setAccessible( true );
snapshot_contract_assert( 'custom-font' === $classify->invoke( $snapshot, 'elementor_font', '', '' ), 'Custom fonts must be classified.' );
snapshot_contract_assert( 'custom-icon' === $classify->invoke( $snapshot, 'elementor_icons', '', '' ), 'Custom icons must be classified.' );
snapshot_contract_assert( 'custom-code' === $classify->invoke( $snapshot, 'elementor_snippet', '', '' ), 'Custom code must be classified.' );
snapshot_contract_assert( 'popup' === $classify->invoke( $snapshot, 'elementor_library', 'popup', 'builder' ), 'Popups must be classified.' );
snapshot_contract_assert( 'theme-builder' === $classify->invoke( $snapshot, 'elementor_library', 'header', 'builder' ), 'Theme Builder templates must be classified.' );
snapshot_contract_assert( 'template' === $classify->invoke( $snapshot, 'elementor_library', 'section', 'builder' ), 'Library templates must be classified.' );
snapshot_contract_assert( 'document' === $classify->invoke( $snapshot, 'page', '', 'builder' ), 'Builder pages must be classified.' );

$strip = new ReflectionMethod( RuntimeSnapshot::class, 'stripRawMetadata' );
$strip->setAccessible( true );
$normalized = $strip->invoke( $snapshot, [
	'name' => 'heading',
	'controls' => [ 'title' => [ 'type' => 'text', 'rawMetadata' => [ 'callback' => 'hidden' ] ] ],
	'atomicControls' => [ 'raw-tree' ],
] );
snapshot_contract_assert( ! isset( $normalized['atomicControls'] ), 'Normalized capability must not duplicate the raw Atomic tree.' );
snapshot_contract_assert( ! isset( $normalized['controls']['title']['rawMetadata'] ), 'Normalized controls must exclude rawMetadata duplication.' );
snapshot_contract_assert( 'text' === $normalized['controls']['title']['type'], 'Normalized control metadata must be preserved.' );

echo "Runtime snapshot contract tests passed.\n";
