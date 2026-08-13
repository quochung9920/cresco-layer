<?php
namespace CrescoLayer\LocalAI;

final class PlannerContract {
	public const SCHEMA = 'cresco-layer-local-ai-plan/v1';

	public static function descriptor(): array {
		return [
			'schema' => self::SCHEMA,
			'role' => 'analysis-and-planning-only',
			'executionAuthority' => 'Cresco Skill Runtime only',
			'allowedOutput' => [ 'intent', 'confidence', 'summary', 'requestedSkills', 'questions' ],
			'forbidden' => [
				'invent-elementor-setting',
				'arbitrary-css',
				'direct-document-write',
				'javascript-execution',
				'scope-escape',
				'validator-bypass',
			],
			'jsonSchema' => self::json_schema(),
		];
	}

	public static function system_prompt(): string {
		return implode( "\n", [
			'You are the local analysis and planning engine for Cresco Layer.',
			'You never modify Elementor directly and you never invent Elementor setting keys.',
			'You may only request skills whose exact skillId appears in availableSkills.',
			'Use runtime facts, current values, responsive inheritance, parent/child/sibling context and design-system references supplied by Cresco.',
			'If the request is ambiguous, return questions instead of guessing.',
			'Return JSON only and conform to schema ' . self::SCHEMA . '.',
		] );
	}

	public static function json_schema(): array {
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => [ 'schema', 'intent', 'confidence', 'summary', 'requestedSkills', 'questions' ],
			'properties' => [
				'schema' => [ 'const' => self::SCHEMA ],
				'intent' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 120 ],
				'confidence' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1 ],
				'summary' => [ 'type' => 'string', 'maxLength' => 600 ],
				'requestedSkills' => [
					'type' => 'array',
					'maxItems' => 24,
					'items' => [
						'type' => 'object',
						'additionalProperties' => false,
						'required' => [ 'skillId', 'params', 'reason' ],
						'properties' => [
							'skillId' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ],
							'params' => [ 'type' => 'object' ],
							'reason' => [ 'type' => 'string', 'maxLength' => 320 ],
						],
					],
				],
				'questions' => [ 'type' => 'array', 'maxItems' => 4, 'items' => [ 'type' => 'string', 'maxLength' => 240 ] ],
			],
		];
	}

	public static function validate( array $plan, array $available_skill_ids ): array {
		if ( self::SCHEMA !== (string) ( $plan['schema'] ?? '' ) ) { throw new \InvalidArgumentException( 'Local AI plan schema is invalid.' ); }
		$confidence = $plan['confidence'] ?? null;
		if ( ! is_numeric( $confidence ) || (float) $confidence < 0 || (float) $confidence > 1 ) { throw new \InvalidArgumentException( 'Local AI plan confidence must be between 0 and 1.' ); }
		$available = array_fill_keys( array_map( 'strval', $available_skill_ids ), true );
		$requested = (array) ( $plan['requestedSkills'] ?? [] );
		if ( count( $requested ) > 24 ) { throw new \InvalidArgumentException( 'Local AI plan requests too many skills.' ); }
		foreach ( $requested as $item ) {
			if ( ! is_array( $item ) ) { throw new \InvalidArgumentException( 'Local AI plan skill entry is invalid.' ); }
			$id = (string) ( $item['skillId'] ?? '' );
			if ( '' === $id || ! isset( $available[ $id ] ) ) { throw new \InvalidArgumentException( 'Local AI requested a skill that is not available for the selected Elementor context.' ); }
			if ( ! is_array( $item['params'] ?? null ) ) { throw new \InvalidArgumentException( 'Local AI skill params must be an object.' ); }
		}
		$questions = (array) ( $plan['questions'] ?? [] );
		if ( count( $questions ) > 4 ) { throw new \InvalidArgumentException( 'Local AI plan asks too many clarification questions.' ); }
		return [
			'schema' => self::SCHEMA,
			'intent' => sanitize_text_field( (string) ( $plan['intent'] ?? '' ) ),
			'confidence' => (float) $confidence,
			'summary' => sanitize_textarea_field( (string) ( $plan['summary'] ?? '' ) ),
			'requestedSkills' => array_values( $requested ),
			'questions' => array_values( array_map( 'sanitize_text_field', $questions ) ),
		];
	}
}
