<?php
namespace CrescoLayer\LocalAI;

final class ContextBudgeter {
	public function budget( array $context, int $context_window ): array {
		$max_chars = max( 12000, min( 160000, (int) floor( max( 2048, $context_window ) * 1.6 ) ) );
		$before = $this->size( $context );
		$trimmed = false;

		if ( $before > $max_chars ) {
			$trimmed = true;
			$context['availableSkills'] = array_slice( (array) ( $context['availableSkills'] ?? [] ), 0, 24 );
			$this->trim_skill_state_and_facts( $context );
			if ( isset( $context['contextGraph']['siblings'] ) ) { $context['contextGraph']['siblings'] = array_slice( (array) $context['contextGraph']['siblings'], 0, 10 ); }
			if ( isset( $context['contextGraph']['children'] ) ) { $context['contextGraph']['children'] = array_slice( (array) $context['contextGraph']['children'], 0, 16 ); }
			if ( isset( $context['renderObservation']['children'] ) ) { $context['renderObservation']['children'] = array_slice( (array) $context['renderObservation']['children'], 0, 8 ); }
			if ( isset( $context['expertCard']['designRules'] ) ) { $context['expertCard']['designRules'] = array_slice( (array) $context['expertCard']['designRules'], 0, 8 ); }
			if ( isset( $context['expertCard']['commonProblems'] ) ) { $context['expertCard']['commonProblems'] = array_slice( (array) $context['expertCard']['commonProblems'], 0, 10 ); }
		}

		if ( $this->size( $context ) > $max_chars ) {
			foreach ( (array) ( $context['availableSkills'] ?? [] ) as $index => $skill ) {
				if ( ! is_array( $skill ) ) { continue; }
				unset( $skill['description'], $skill['purpose'], $skill['input']['options'] );
				$context['availableSkills'][ $index ] = $skill;
			}
		}

		if ( $this->size( $context ) > $max_chars ) {
			$context['availableSkills'] = array_slice( (array) ( $context['availableSkills'] ?? [] ), 0, 14 );
			$this->trim_skill_state_and_facts( $context );
		}

		$context['contextBudget'] = [
			'contextWindow' => $context_window,
			'maxCharacters' => $max_chars,
			'originalCharacters' => $before,
			'finalCharacters' => $this->size( $context ),
			'trimmed' => $trimmed,
		];
		return $context;
	}

	private function trim_skill_state_and_facts( array &$context ): void {
		$kept_skill_ids = [];
		$kept_refs = [];
		foreach ( (array) ( $context['availableSkills'] ?? [] ) as $skill ) {
			if ( ! is_array( $skill ) ) { continue; }
			$id = (string) ( $skill['skillId'] ?? '' );
			$ref = (string) ( $skill['evidenceRef'] ?? '' );
			if ( '' !== $id ) { $kept_skill_ids[ $id ] = true; }
			if ( '' !== $ref ) { $kept_refs[] = $ref . '.'; }
		}
		if ( isset( $context['effectiveState'] ) ) { $context['effectiveState'] = array_intersect_key( (array) $context['effectiveState'], $kept_skill_ids ); }
		if ( isset( $context['facts'] ) ) {
			$context['facts'] = array_filter(
				(array) $context['facts'],
				static function ( $record, $fact_id ) use ( $kept_refs ): bool {
					$fact_id = (string) $fact_id;
					if ( ! str_starts_with( $fact_id, 'skill.' ) ) { return true; }
					foreach ( $kept_refs as $prefix ) { if ( str_starts_with( $fact_id, $prefix ) ) { return true; } }
					return false;
				},
				ARRAY_FILTER_USE_BOTH
			);
		}
		if ( isset( $context['retrieval'] ) ) {
			$context['retrieval']['returned'] = count( (array) ( $context['availableSkills'] ?? [] ) );
			$context['retrieval']['dropped'] = max( 0, (int) ( $context['retrieval']['totalExecutableCandidates'] ?? 0 ) - (int) $context['retrieval']['returned'] );
		}
	}

	private function size( array $value ): int {
		$json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $json ? 0 : strlen( $json );
	}
}
