<?php
namespace CrescoLayer\SiteSettings\Verify;

/**
 * Confirms that what Elementor stored means what Cresco asked for.
 *
 * Scope is everything Cresco actually planned to write — nothing else. A control the running
 * Elementor does not register, a value the profile deliberately preserved, or a setting owned by a
 * theme or addon was never part of the request, so treating any of them as a failure would report a
 * broken transaction over a Kit that is exactly right.
 *
 * When a real mismatch does occur, the diagnostic has to be enough to fix it without re-running:
 * which semantic property, which Elementor control, its type, and both values before and after
 * normalisation.
 */
final class Verifier {
	public const REASON_MISSING = 'missing_control_value';
	public const REASON_MISMATCH = 'semantic_value_mismatch';

	private ValueNormalizer $normalizer;

	public function __construct( ?ValueNormalizer $normalizer = null ) {
		$this->normalizer = $normalizer ?? new ValueNormalizer();
	}

	/**
	 * @param array $plan  Planned writes: each entry has semanticPath, control, controlType, value.
	 * @param array $saved Settings read back from the Kit after the write.
	 * @return array{status:string,scopeCount:int,matchedCount:int,mismatchCount:int,mismatches:array,matched:array}
	 */
	public function verify( array $plan, array $saved ): array {
		$mismatches = [];
		$matched = [];

		foreach ( $plan as $entry ) {
			$control = (string) ( $entry['control'] ?? '' );
			if ( '' === $control ) { continue; }

			$type = (string) ( $entry['controlType'] ?? '' );
			$expected = $entry['value'] ?? null;
			$path = (string) ( $entry['semanticPath'] ?? $control );

			if ( ! array_key_exists( $control, $saved ) ) {
				$mismatches[] = $this->mismatch( $path, $control, $type, $expected, null, self::REASON_MISSING );
				continue;
			}

			$actual = $saved[ $control ];
			if ( $this->normalizer->satisfies( $actual, $expected, $type ) ) {
				$matched[] = $path;
				continue;
			}

			$mismatches[] = $this->mismatch( $path, $control, $type, $expected, $actual, self::REASON_MISMATCH );
		}

		$scope = count( $matched ) + count( $mismatches );

		return [
			'status' => [] === $mismatches ? 'pass' : 'failed',
			'scopeCount' => $scope,
			'matchedCount' => count( $matched ),
			'mismatchCount' => count( $mismatches ),
			'mismatches' => $mismatches,
			'matched' => $matched,
		];
	}

	private function mismatch( string $path, string $control, string $type, $expected, $actual, string $reason ): array {
		return [
			'semanticPath' => $path,
			'elementorControl' => $control,
			'controlType' => $type,
			'expectedRaw' => $expected,
			'actualRaw' => $actual,
			'expectedNormalized' => $this->normalizer->normalize( $expected, $type ),
			'actualNormalized' => $this->normalizer->normalize( $actual, $type ),
			'reason' => $reason,
		];
	}

	/** Human-readable block for the server log; mismatches come first because they are the point. */
	public function render( array $result, array $skipped, array $preserved, ?array $rollback = null ): string {
		$out = [ '[CRESCO][SITE_SETTINGS][VERIFY]', '' ];
		$out[] = 'RESULT: ' . strtoupper( (string) $result['status'] );
		$out[] = 'VERIFY_SCOPE_COUNT: ' . (int) $result['scopeCount'];
		$out[] = 'MATCHED_COUNT: ' . (int) $result['matchedCount'];
		$out[] = 'MISMATCH_COUNT: ' . (int) $result['mismatchCount'];

		if ( $result['mismatches'] ) {
			$out[] = '';
			$out[] = 'MISMATCHED:';
			foreach ( $result['mismatches'] as $index => $mismatch ) {
				$out[] = '';
				$out[] = ( $index + 1 ) . '.';
				$out[] = 'semantic_path: ' . $mismatch['semanticPath'];
				$out[] = 'elementor_control: ' . $mismatch['elementorControl'];
				$out[] = 'control_type: ' . ( '' !== $mismatch['controlType'] ? $mismatch['controlType'] : 'unknown' );
				$out[] = 'expected_raw: ' . $this->encode( $mismatch['expectedRaw'] );
				$out[] = 'actual_raw: ' . $this->encode( $mismatch['actualRaw'] );
				$out[] = 'expected_normalized: ' . $this->encode( $mismatch['expectedNormalized'] );
				$out[] = 'actual_normalized: ' . $this->encode( $mismatch['actualNormalized'] );
				$out[] = 'reason: ' . $mismatch['reason'];
			}
		}

		if ( $skipped ) {
			$out[] = '';
			$out[] = 'SKIPPED_FROM_VERIFICATION:';
			foreach ( $skipped as $entry ) {
				$out[] = '- ' . (string) ( $entry['key'] ?? '' );
				$out[] = '  reason: ' . (string) ( $entry['reason'] ?? '' );
			}
		}

		if ( $preserved ) {
			$out[] = '';
			$out[] = 'PRESERVED:';
			foreach ( $preserved as $entry ) {
				$out[] = '- ' . (string) ( is_array( $entry ) ? ( $entry['key'] ?? '' ) : $entry );
			}
		}

		if ( null !== $rollback ) {
			$out[] = '';
			$out[] = 'ROLLBACK:';
			$out[] = strtoupper( (string) ( $rollback['status'] ?? 'not_available' ) );
		}

		return implode( "\n", $out );
	}

	private function encode( $value ): string {
		$json = wp_json_encode( $value );
		if ( ! is_string( $json ) ) { return '(unencodable)'; }
		return strlen( $json ) > 400 ? substr( $json, 0, 400 ) . '…' : $json;
	}
}
