<?php
namespace CrescoLayer\LocalAI;

final class Analyzer {
	public function __construct( private ContextCompiler $context, private ProviderManager $providers ) {}

	public function prepare( int $post_id, string $element_id, array $input ): array {
		$config = $this->providers->summary();
		$settings = is_array( $config['settings'] ?? null ) ? $config['settings'] : [];
		if ( empty( $settings['enabled'] ) ) { throw new \RuntimeException( 'Local AI is disabled in Cresco Layer.' ); }
		$model = trim( (string) ( $settings['analysisModel'] ?? '' ) );
		if ( '' === $model ) { throw new \RuntimeException( 'Choose an analysis model in Cresco Layer → Local AI.' ); }
		$task = $this->task( $input );
		$context = $this->context->compile( $post_id, $element_id, $task, [
			'liveSettings' => is_array( $input['liveSettings'] ?? null ) ? $input['liveSettings'] : [],
			'includeNeighborContext' => ! empty( $settings['includeNeighborContext'] ),
			'contextWindow' => (int) ( $settings['contextWindow'] ?? 32768 ),
		] );
		$available = array_values( array_filter( array_map( static fn( array $skill ): string => (string) ( $skill['skillId'] ?? '' ), (array) ( $context['availableSkills'] ?? [] ) ) ) );
		$schema_json = wp_json_encode( PlannerContract::json_schema(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$system = PlannerContract::system_prompt() . "\nExact planning JSON Schema:\n" . ( is_string( $schema_json ) ? $schema_json : '{}' );
		$messages = [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user', 'content' => "Cresco Semantic Context:\n" . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ],
		];
		return [
			'schema' => 'cresco-layer-local-ai-prepared/v1',
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
		$validated = $this->validate_against_prepared( $plan, $prepared );
		return [
			'schema' => 'cresco-layer-local-ai-analysis/v1',
			'browserRequired' => false,
			'plan' => $validated['plan'],
			'decision' => $validated['decision'],
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
		$validated = $this->validate_against_prepared( $plan, $prepared );
		return [
			'schema' => 'cresco-layer-local-ai-analysis/v1',
			'browserRequired' => false,
			'plan' => $validated['plan'],
			'decision' => $validated['decision'],
			'context' => $prepared['context'],
		];
	}

	private function validate_against_prepared( array $plan, array $prepared ): array {
		$plan = PlannerContract::validate( $plan, (array) ( $prepared['availableSkillIds'] ?? [] ) );
		$minimum = (float) ( $prepared['minimumConfidence'] ?? 0.85 );
		$has_questions = ! empty( $plan['questions'] );
		$has_skills = ! empty( $plan['requestedSkills'] );
		$accepted = ! $has_questions && $has_skills && $plan['confidence'] >= $minimum;
		$reason = 'accepted';
		if ( $has_questions ) { $reason = 'clarification-required'; }
		elseif ( ! $has_skills ) { $reason = 'no-effective-plan'; }
		elseif ( $plan['confidence'] < $minimum ) { $reason = 'below-confidence-threshold'; }
		return [
			'plan' => $plan,
			'decision' => [ 'accepted' => $accepted, 'reason' => $reason, 'minimumConfidence' => $minimum, 'confidence' => $plan['confidence'], 'requirePreview' => ! empty( $prepared['requirePreview'] ) ],
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
