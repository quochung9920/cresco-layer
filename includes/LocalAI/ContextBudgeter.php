<?php
namespace CrescoLayer\LocalAI;

final class ContextBudgeter {
	public function budget( array $context, int $context_window ): array {
		$max_chars = max( 12000, min( 160000, (int) floor( max( 2048, $context_window ) * 1.6 ) ) );
		$before = $this->size( $context );
		$trimmed = false;

		if ( $before > $max_chars ) {
			$trimmed = true;
			$context['availableSkills'] = array_slice( (array) ( $context['availableSkills'] ?? [] ), 0, 72 );
			$kept_skill_ids = array_values( array_filter( array_map(
				static fn( array $skill ): string => (string) ( $skill['skillId'] ?? '' ),
				(array) $context['availableSkills']
			) ) );
			if ( isset( $context['effectiveState'] ) ) {
				$context['effectiveState'] = array_intersect_key(
					(array) $context['effectiveState'],
					array_fill_keys( $kept_skill_ids, true )
				);
			}
			if ( isset( $context['contextGraph']['siblings'] ) ) { $context['contextGraph']['siblings'] = array_slice( (array) $context['contextGraph']['siblings'], 0, 12 ); }
			if ( isset( $context['contextGraph']['children'] ) ) { $context['contextGraph']['children'] = array_slice( (array) $context['contextGraph']['children'], 0, 20 ); }
			if ( isset( $context['expertCard']['designRules'] ) ) { $context['expertCard']['designRules'] = array_slice( (array) $context['expertCard']['designRules'], 0, 10 ); }
			if ( isset( $context['expertCard']['commonProblems'] ) ) { $context['expertCard']['commonProblems'] = array_slice( (array) $context['expertCard']['commonProblems'], 0, 12 ); }
		}

		if ( $this->size( $context ) > $max_chars ) {
			foreach ( (array) ( $context['availableSkills'] ?? [] ) as $index => $skill ) {
				if ( ! is_array( $skill ) ) { continue; }
				unset( $skill['description'], $skill['input']['options'] );
				$context['availableSkills'][ $index ] = $skill;
			}
		}

		if ( $this->size( $context ) > $max_chars ) {
			$context['effectiveState'] = array_slice( (array) ( $context['effectiveState'] ?? [] ), 0, 72, true );
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

	private function size( array $value ): int {
		$json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $json ? 0 : strlen( $json );
	}
}
