<?php
namespace CrescoLayer\LocalAI;

final class Analyzer {
	private EvidenceValidator $evidence_validator;
	private ConfidenceScorer $confidence_scorer;

	public function __construct(
		private ContextCompiler $context,
		private ProviderManager $providers,
		private PlanValidator $plan_validator,
		?EvidenceValidator $evidence_validator = null,
		?ConfidenceScorer $confidence_scorer = null
	) {
		$this->evidence_validator = $evidence_validator ?? new EvidenceValidator();
		$this->confidence_scorer = $confidence_scorer ?? new ConfidenceScorer();
	}

	public function prepare( int $post_id, string $element_id, array $input ): array {
		$config = $this->providers->summary();
		$settings = is_array( $config['settings'] ?? null ) ? $config['settings'] : [];
		if ( empty( $settings['enabled'] ) ) { throw new \RuntimeException( 'Local AI is disabled in Cresco Layer.' ); }
		$model = trim( (string) ( $settings['analysisModel'] ?? '' ) );
		if ( '' === $model ) { throw new \RuntimeException( 'Choose an analysis model in Cresco Layer → Local AI.' ); }
		$task = $this->task( $input );
		$context = $this->context->compile( $post_id, $element_id, $task, [
			'liveSettings' => is_array( $input['liveSettings'] ?? null ) ? $input['liveSettings'] : [],
			'renderObservation' => is_array( $input['renderObservation'] ?? null ) ? $input['renderObservation'] : [],
			'includeNeighborContext' => ! empty( $settings['includeNeighborContext'] ),
			'contextWindow' => (int) ( $settings['contextWindow'] ?? 32768 ),
			'skillLimit' => 18,
		] );
		$available = array_values( array_filter( array_map( static fn( array $skill ): string => (string) ( $skill['skillId'] ?? '' ), (array) ( $context['availableSkills'] ?? [] ) ) ) );
		$schema_json = wp_json_encode( PlannerContract::json_schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$system = PlannerContract::system_prompt() . "\nExact planning JSON Schema:\n" . ( is_string( $schema_json ) ? $schema_json : '{}' );
		$messages = [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user', 'content' => "Cresco Semantic Context:\n" . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ],
		];
		return [
			'schema' => 'cresco-layer-local-ai-prepared/v2',
			'browserRequired' => 'browser' === (string) ( $settings['connectionMode'] ?? 'browser' ),
			'descriptor' => $this->providers->browser_descriptor(),
			'model' => $model,
			'messages' => $messages,
			'context' => $context,
			'availableSkillIds' => $available,
			'minimumConfidence' => (float) ( $settings['minimumConfidence'] ?? 0.85 ),
			'requirePreview' => ! empty( $settings['requirePreview'] ),
			'requestOptions' => [
				'temperature' => (float) ( $settings['temperature'] ?? 0.2 ),
				'contextWindow' => (int) ( $settings['contextWindow'] ?? 32768 ),
				'maxOutputTokens' => (int) ( $settings['maxOutputTokens'] ?? 4096 ),
			],
			'planningContract' => PlannerContract::descriptor(),
		];
	}

	public function analyze( int $post_id, string $element_id, array $input ): array {
		$prepared = $this->prepare( $post_id, $element_id, $input );
		if ( ! empty( $prepared['browserRequired'] ) ) {
			$prepared['status'] = 'browser-inference-required';
			return $prepared;
		}
		$inference = $this->providers->chat( (array) $prepared['messages'] );
		$plan = $this->decode_plan( (string) ( $inference['content'] ?? '' ) );
		$validated = $this->validate_against_prepared( $plan, $prepared, $post_id, $element_id, $input );
		return [
			'schema' => 'cresco-layer-local-ai-analysis/v2',
			'browserRequired' => false,
			'plan' => $validated['plan'],
			'decision' => $validated['decision'],
			'evidenceValidation' => $validated['evidenceValidation'],
			'confidence' => $validated['confidence'],
			'runtimeValidation' => $validated['runtimeValidation'],
			'inference' => [
				'provider' => (string) ( $inference['provider'] ?? '' ),
				'model' => (string) ( $inference['model'] ?? '' ),
				'latencyMs' => (int) ( $inference['latencyMs'] ?? 0 ),
			],
			'context' => $prepared['context'],
		];
	}

	public function validate_external_plan( int $post_id, string $element_id, array $input ): array {
		$prepared = $this->prepare( $post_id, $element_id, $input );
		$plan = is_array( $input['plan'] ?? null ) ? $input['plan'] : [];
		if ( ! $plan ) { throw new \InvalidArgumentException( 'Browser Local AI response must contain a plan object.' ); }
		$validated = $this->validate_against_prepared( $plan, $prepared, $post_id, $element_id, $input );
		return [
			'schema' => 'cresco-layer-local-ai-analysis/v2',
			'browserRequired' => false,
			'plan' => $validated['plan'],
			'decision' => $validated['decision'],
			'evidenceValidation' => $validated['evidenceValidation'],
			'confidence' => $validated['confidence'],
			'runtimeValidation' => $validated['runtimeValidation'],
			'context' => $prepared['context'],
		];
	}

	private function validate_against_prepared( array $plan, array $prepared, int $post_id, string $element_id, array $input ): array {
		$plan = PlannerContract::validate( $plan, (array) ( $prepared['availableSkillIds'] ?? [] ) );
		$minimum = (float) ( $prepared['minimumConfidence'] ?? 0.85 );
		$has_questions = ! empty( $plan['questions'] );
		$has_skills = ! empty( $plan['requestedSkills'] );
		$accepted = false;
		$reason = 'accepted';
		$runtime_validation = [ 'validated' => false, 'skipped' => true, 'reason' => 'not-eligible' ];
		try {
			$evidence_validation = $this->evidence_validator->validate( (array) ( $plan['analysis']['evidence'] ?? [] ), (array) ( $prepared['context'] ?? [] ) );
		} catch ( \Throwable $error ) {
			$evidence_validation = [ 'version' => EvidenceValidator::VERSION, 'valid' => false, 'passed' => 0, 'total' => count( (array) ( $plan['analysis']['evidence'] ?? [] ) ), 'score' => 0.0, 'items' => [], 'error' => $error->getMessage() ];
		}

		if ( $has_questions ) { $reason = 'clarification-required'; }
		elseif ( ! $has_skills ) { $reason = 'no-effective-plan'; }
		elseif ( empty( $evidence_validation['valid'] ) ) { $reason = 'evidence-validation-failed'; }
		else {
			try {
				$runtime_validation = $this->plan_validator->validate(
					$post_id,
					$element_id,
					$plan,
					is_array( $input['liveSettings'] ?? null ) ? $input['liveSettings'] : []
				);
			} catch ( \Throwable $error ) {
				$reason = 'runtime-validation-failed';
				$runtime_validation = [ 'validated' => false, 'skipped' => false, 'error' => $error->getMessage() ];
			}
		}

		$confidence = $this->confidence_scorer->score( $plan, (array) ( $prepared['context'] ?? [] ), $evidence_validation, $runtime_validation );
		if ( 'accepted' === $reason && (float) ( $confidence['final'] ?? 0.0 ) < $minimum ) { $reason = 'below-semantic-confidence-threshold'; }
		if ( 'accepted' === $reason && ! empty( $runtime_validation['validated'] ) ) { $accepted = true; }

		return [
			'plan' => $plan,
			'evidenceValidation' => $evidence_validation,
			'confidence' => $confidence,
			'runtimeValidation' => $runtime_validation,
			'decision' => [
				'accepted' => $accepted,
				'reason' => $reason,
				'minimumConfidence' => $minimum,
				'aiConfidence' => $plan['confidence'],
				'confidence' => (float) ( $confidence['final'] ?? 0.0 ),
				'confidenceComponents' => $confidence['components'] ?? [],
				'evidenceScore' => (float) ( $evidence_validation['score'] ?? 0.0 ),
				'requirePreview' => ! empty( $prepared['requirePreview'] ),
				'maxRisk' => (string) ( $runtime_validation['maxRisk'] ?? '' ),
			],
		];
	}

	private function decode_plan( string $content ): array {
		$content = trim( $content );
		$content = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $content ) ?? $content;
		$data = json_decode( trim( $content ), true );
		if ( ! is_array( $data ) ) { throw new \RuntimeException( 'Local AI did not return valid Cresco plan JSON.' ); }
		return $data;
	}

	private function task( array $input ): string {
		$task = trim( sanitize_textarea_field( (string) ( $input['task'] ?? $input['command'] ?? '' ) ) );
		if ( '' === $task ) { throw new \InvalidArgumentException( 'Local AI task is required.' ); }
		return $task;
	}
}
