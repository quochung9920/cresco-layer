<?php
namespace CrescoLayer\LocalAI;

final class ConfidenceScorer {
	public const VERSION = 1;

	public function score( array $plan, array $context, array $evidence_validation, array $runtime_validation ): array {
		$ai = $this->clamp( (float) ( $plan['confidence'] ?? 0.0 ) );
		$evidence = $this->clamp( (float) ( $evidence_validation['score'] ?? 0.0 ) );
		$retrieval = $this->requested_skill_match( $plan, $context );
		$completeness = $this->context_completeness( $context );
		$runtime = ! empty( $runtime_validation['validated'] ) ? 1.0 : 0.0;
		$final = ( 0.22 * $ai ) + ( 0.26 * $evidence ) + ( 0.20 * $retrieval ) + ( 0.12 * $completeness ) + ( 0.20 * $runtime );
		if ( empty( $evidence_validation['valid'] ) || ! $runtime ) { $final = min( $final, 0.69 ); }
		return [
			'version' => self::VERSION,
			'final' => round( $this->clamp( $final ), 4 ),
			'components' => [
				'aiSelfReport' => round( $ai, 4 ),
				'evidenceValidity' => round( $evidence, 4 ),
				'skillRetrievalMatch' => round( $retrieval, 4 ),
				'contextCompleteness' => round( $completeness, 4 ),
				'runtimeValidation' => round( $runtime, 4 ),
			],
		];
	}

	private function requested_skill_match( array $plan, array $context ): float {
		$retrieved = [];
		$top = 0.0;
		foreach ( (array) ( $context['availableSkills'] ?? [] ) as $skill ) {
			if ( ! is_array( $skill ) ) { continue; }
			$id = (string) ( $skill['skillId'] ?? '' );
			$score = max( 0.0, (float) ( $skill['retrievalScore'] ?? 0.0 ) );
			if ( '' !== $id ) { $retrieved[ $id ] = $score; $top = max( $top, $score ); }
		}
		$requested = (array) ( $plan['requestedSkills'] ?? [] );
		if ( ! $requested ) { return 0.0; }
		$sum = 0.0;
		foreach ( $requested as $item ) {
			$id = is_array( $item ) ? (string) ( $item['skillId'] ?? '' ) : '';
			if ( '' === $id || ! array_key_exists( $id, $retrieved ) ) { continue; }
			$sum += $top > 0 ? min( 1.0, $retrieved[ $id ] / $top ) : 1.0;
		}
		return $this->clamp( $sum / count( $requested ) );
	}

	private function context_completeness( array $context ): float {
		$checks = [
			! empty( $context['selectedElement']['id'] ),
			! empty( $context['selectedElement']['type'] ),
			! empty( $context['availableSkills'] ),
			! empty( $context['facts'] ),
			isset( $context['contextGraph']['relationships'] ),
			! empty( $context['expertCard']['purpose'] ),
			isset( $context['designSystem'] ),
			isset( $context['renderObservation'] ),
		];
		$passed = count( array_filter( $checks ) );
		return $passed / count( $checks );
	}

	private function clamp( float $value ): float { return max( 0.0, min( 1.0, $value ) ); }
}
