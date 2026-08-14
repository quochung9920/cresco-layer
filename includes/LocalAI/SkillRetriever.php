<?php
namespace CrescoLayer\LocalAI;

final class SkillRetriever {
	public const VERSION = 1;

	public function retrieve( array $skills, string $task, array $expert_card = [], int $limit = 18 ): array {
		$limit = max( 6, min( 28, $limit ) );
		$task_text = $this->normalize( $task );
		$task_tokens = $this->tokens( $task_text );
		$preferred = array_fill_keys( array_map( 'strval', (array) ( $expert_card['preferredRoles'] ?? [] ) ), true );
		$domain_hints = $this->domain_hints( $task_text );
		$ranked = [];

		foreach ( $skills as $skill ) {
			if ( ! is_array( $skill ) || 'read-only' === (string) ( $skill['mode'] ?? '' ) ) { continue; }
			$risk = (string) ( $skill['risk'] ?? 'safe' );
			if ( in_array( $risk, [ 'expert', 'structural', 'external' ], true ) ) { continue; }
			$score = 0;
			$role = (string) ( $skill['role'] ?? '' );
			$domain = (string) ( $skill['semanticDomain'] ?? '' );
			if ( isset( $preferred[ $role ] ) ) { $score += 32; }
			if ( 'safe' === $risk ) { $score += 8; }
			elseif ( 'conditional' === $risk ) { $score += 3; }
			if ( ! empty( $skill['responsive'] ) && preg_match( '/\b(mobile|tablet|responsive|phone|dien thoai|may tinh bang)\b/u', $task_text ) ) { $score += 22; }
			if ( isset( $domain_hints[ $domain ] ) ) { $score += 28; }
			$haystack = $this->normalize( implode( ' ', [
				(string) ( $skill['semanticId'] ?? '' ),
				(string) ( $skill['displayLabel'] ?? '' ),
				(string) ( $skill['label'] ?? '' ),
				$role,
				(string) ( $skill['targetPart'] ?? '' ),
				(string) ( $skill['property'] ?? '' ),
				(string) ( $skill['category'] ?? '' ),
				(string) ( $skill['description'] ?? '' ),
				implode( ' ', array_map( 'strval', (array) ( $skill['searchTerms'] ?? [] ) ) ),
			] ) );
			$matched = 0;
			foreach ( $task_tokens as $token ) {
				if ( strlen( $token ) < 3 ) { continue; }
				if ( str_contains( $haystack, $token ) ) { $score += strlen( $token ) >= 6 ? 10 : 6; $matched++; }
			}
			if ( $matched >= 2 ) { $score += min( 18, $matched * 3 ); }
			$score += $this->semantic_bonus( $task_text, $role, $haystack );
			$skill['retrievalScore'] = $score;
			$ranked[] = $skill;
		}

		usort( $ranked, static function ( array $a, array $b ): int {
			$left = (int) ( $a['retrievalScore'] ?? 0 );
			$right = (int) ( $b['retrievalScore'] ?? 0 );
			if ( $left !== $right ) { return $right <=> $left; }
			return strcasecmp( (string) ( $a['displayLabel'] ?? $a['label'] ?? '' ), (string) ( $b['displayLabel'] ?? $b['label'] ?? '' ) );
		} );

		$all_count = count( $ranked );
		$selected = array_slice( $ranked, 0, $limit );
		if ( count( $selected ) < min( 10, $all_count ) ) { $selected = array_slice( $ranked, 0, min( 10, $all_count ) ); }
		$top = (int) ( $selected[0]['retrievalScore'] ?? 0 );
		$score_sum = 0.0;
		foreach ( $selected as $skill ) { $score_sum += min( 1.0, max( 0.0, (float) ( $skill['retrievalScore'] ?? 0 ) / max( 1, $top ) ) ); }
		$coverage = $selected ? $score_sum / count( $selected ) : 0.0;

		return [
			'version' => self::VERSION,
			'totalExecutableCandidates' => $all_count,
			'returned' => count( $selected ),
			'dropped' => max( 0, $all_count - count( $selected ) ),
			'limit' => $limit,
			'coverage' => round( $coverage, 4 ),
			'domainHints' => array_keys( $domain_hints ),
			'skills' => $selected,
		];
	}

	private function semantic_bonus( string $task, string $role, string $haystack ): int {
		$rules = [
			'/padding|khoang trong|dem/' => [ 'spacing.padding', 32 ],
			'/margin|khoang ngoai/' => [ 'spacing.margin', 32 ],
			'/gap|khoang cach/' => [ 'layout.gap', 32 ],
			'/width|chieu rong/' => [ 'layout.width', 28 ],
			'/height|chieu cao/' => [ 'layout.min-height', 24 ],
			'/stack|xep doc|cot|column/' => [ 'layout.direction', 34 ],
			'/align|can le|can giua/' => [ 'layout.align', 24 ],
			'/font|chu|typography/' => [ 'typography.', 24 ],
			'/background|nen/' => [ 'style.background', 24 ],
			'/radius|bo goc/' => [ 'style.border-radius', 28 ],
			'/color|mau/' => [ '.color', 20 ],
		];
		foreach ( $rules as $pattern => [ $needle, $bonus ] ) {
			if ( preg_match( $pattern, $task ) && ( str_contains( $role, $needle ) || str_contains( $haystack, $needle ) ) ) { return $bonus; }
		}
		return 0;
	}

	private function domain_hints( string $task ): array {
		$map = [
			'layout' => '/layout|container|section|column|grid|flex|stack|align|width|height|gap|mobile|responsive|bo cuc|cot|chieu rong|chieu cao/',
			'spacing' => '/spacing|padding|margin|space|khoang cach|khoang trong|khoang ngoai/',
			'typography' => '/font|text|heading|title|typography|chu|tieu de|line height|weight/',
			'style' => '/color|background|border|radius|shadow|opacity|mau|nen|bo goc/',
			'content' => '/content|text|label|link|noi dung|van ban/',
			'responsive' => '/mobile|tablet|responsive|phone|dien thoai|may tinh bang/',
		];
		$out = [];
		foreach ( $map as $domain => $pattern ) { if ( preg_match( $pattern, $task ) ) { $out[ $domain ] = true; } }
		return $out;
	}

	private function normalize( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( function_exists( 'remove_accents' ) ) { $value = remove_accents( $value ); }
		$value = strtr( $value, [ 'đ' => 'd' ] );
		return preg_replace( '/\s+/', ' ', $value ) ?? $value;
	}

	private function tokens( string $value ): array {
		$tokens = preg_split( '/[^a-z0-9]+/u', $value ) ?: [];
		$stop = [ 'this', 'that', 'the', 'and', 'for', 'with', 'lam', 'cho', 'nay', 'hon', 'mot', 'cua', 'toi', 'giup' ];
		return array_values( array_unique( array_filter( $tokens, static fn( string $token ): bool => '' !== $token && ! in_array( $token, $stop, true ) ) ) );
	}
}
