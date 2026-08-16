<?php
namespace CrescoLayer\AI;

final class FidelityPolicy {
	public const SCHEMA = 'cresco-fidelity-policy/v1';
	public const SNAPSHOT_SCHEMA = 'cresco-fidelity-snapshot/v1';
	public const REPORT_SCHEMA = 'cresco-fidelity-report/v1';
	public const GATE_SCHEMA = 'cresco-fidelity-gate/v1';
	public const DEFAULT_THRESHOLD = 96.0;

	public static function config(): array {
		return [
			'schema' => self::SCHEMA,
			'snapshotSchema' => self::SNAPSHOT_SCHEMA,
			'reportSchema' => self::REPORT_SCHEMA,
			'gateSchema' => self::GATE_SCHEMA,
			'threshold' => self::DEFAULT_THRESHOLD,
			'categoryFloor' => [
				'geometry' => 90.0,
				'spacing' => 90.0,
				'typography' => 90.0,
				'color' => 88.0,
				'structure' => 98.0,
				'quality' => 95.0,
			],
			'weights' => [
				'geometry' => 0.30,
				'spacing' => 0.18,
				'typography' => 0.18,
				'color' => 0.12,
				'structure' => 0.12,
				'quality' => 0.10,
			],
			'tolerances' => [
				'geometryPx' => 2.0,
				'spacingPx' => 2.0,
				'typographyPx' => 1.5,
				'opacity' => 0.03,
				'overflowPx' => 2.0,
			],
			'blockingRules' => [
				'missing-element',
				'parent-drift',
				'horizontal-overflow',
				'invisible-target',
				'invalid-geometry',
				'no-verification-evidence',
			],
			'capture' => [
				'maxElements' => 500,
				'includeDescendants' => true,
				'includeSiblingGraph' => true,
				'computedStyles' => true,
				'currentBreakpointOnly' => true,
			],
			'iteration' => [
				'maxCorrectionRounds' => 4,
				'requireScoreImprovement' => true,
				'rollbackOnRegression' => true,
			],
		];
	}

	public static function contract(): array {
		$policy = self::config();
		return [
			'schema' => self::SCHEMA,
			'snapshotSchema' => self::SNAPSHOT_SCHEMA,
			'reportSchema' => self::REPORT_SCHEMA,
			'gateSchema' => self::GATE_SCHEMA,
			'mode' => 'rendered-computed-fidelity',
			'threshold' => $policy['threshold'],
			'weights' => $policy['weights'],
			'tolerances' => $policy['tolerances'],
			'blockingRules' => $policy['blockingRules'],
			'guarantee' => 'deterministic-structural-fidelity-with-bounded-render-error',
			'nonGuarantee' => 'Not a promise of identical raster pixels across browsers, operating systems, fonts or graphics stacks.',
		];
	}
}
