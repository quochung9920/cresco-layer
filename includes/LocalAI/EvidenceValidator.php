<?php
namespace CrescoLayer\LocalAI;

final class EvidenceValidator {
	public const VERSION = 1;
	private const OPERATORS = [ 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'exists' ];

	public function validate( array $evidence, array $context ): array {
		$facts = is_array( $context['facts'] ?? null ) ? $context['facts'] : [];
		$results = [];
		$passed = 0;
		foreach ( $evidence as $index => $item ) {
			if ( ! is_array( $item ) ) { throw new \InvalidArgumentException( 'Local AI evidence item ' . ( $index + 1 ) . ' must be an object.' ); }
			$fact_id = trim( (string) ( $item['factId'] ?? '' ) );
			$operator = strtolower( trim( (string) ( $item['operator'] ?? 'eq' ) ) );
			if ( '' === $fact_id || ! array_key_exists( $fact_id, $facts ) ) { throw new \InvalidArgumentException( 'Local AI referenced an evidence fact that is not present in the semantic context.' ); }
			if ( ! in_array( $operator, self::OPERATORS, true ) ) { throw new \InvalidArgumentException( 'Local AI evidence operator is unsupported.' ); }
			$actual_record = is_array( $facts[ $fact_id ] ) && array_key_exists( 'value', $facts[ $fact_id ] ) ? $facts[ $fact_id ] : [ 'value' => $facts[ $fact_id ] ];
			$actual = $actual_record['value'] ?? null;
			$expected = $item['value'] ?? null;
			if ( 'exists' === $operator && ! is_bool( $expected ) ) { throw new \InvalidArgumentException( 'Local AI exists evidence requires a boolean value.' ); }
			$ok = $this->compare( $actual, $expected, $operator );
			if ( $ok ) { $passed++; }
			$results[] = [
				'factId' => $fact_id,
				'operator' => $operator,
				'expected' => $expected,
				'actual' => $actual,
				'valid' => $ok,
				'statement' => sanitize_textarea_field( (string) ( $item['statement'] ?? '' ) ),
			];
		}
		$total = count( $results );
		$score = $total ? $passed / $total : 0.0;
		return [
			'version' => self::VERSION,
			'valid' => $total > 0 && $passed === $total,
			'passed' => $passed,
			'total' => $total,
			'score' => round( $score, 4 ),
			'items' => $results,
		];
	}

	private function compare( $actual, $expected, string $operator ): bool {
		if ( 'exists' === $operator ) { return $expected === ( null !== $actual ); }
		if ( 'contains' === $operator ) {
			if ( is_array( $actual ) ) { return in_array( $expected, $actual, true ); }
			return str_contains( strtolower( (string) $actual ), strtolower( (string) $expected ) );
		}
		if ( in_array( $operator, [ 'gt', 'gte', 'lt', 'lte' ], true ) ) {
			if ( ! is_numeric( $actual ) || ! is_numeric( $expected ) ) { return false; }
			$a = (float) $actual; $b = (float) $expected;
			if ( 'gt' === $operator ) { return $a > $b; }
			if ( 'gte' === $operator ) { return $a >= $b; }
			if ( 'lt' === $operator ) { return $a < $b; }
			return $a <= $b;
		}
		$equal = $this->equivalent( $actual, $expected );
		return 'neq' === $operator ? ! $equal : $equal;
	}

	private function equivalent( $left, $right ): bool {
		if ( is_numeric( $left ) && is_numeric( $right ) ) { return abs( (float) $left - (float) $right ) < 0.000001; }
		if ( is_bool( $left ) || is_bool( $right ) ) { return (bool) $left === (bool) $right; }
		if ( is_array( $left ) || is_array( $right ) ) {
			return wp_json_encode( $left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) === wp_json_encode( $right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return trim( (string) $left ) === trim( (string) $right );
	}
}
