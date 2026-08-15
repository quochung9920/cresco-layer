<?php
namespace CrescoLayer\AI;

/**
 * Turns whatever an AI actually returned into one internal representation.
 *
 * Chat models wrap their output: a markdown fence, a `result` or `data` envelope, a preamble
 * sentence before the JSON. None of that changes what the user meant, so rejecting it with
 * "Unsupported JSON" makes the user hand-edit a file to satisfy a parser — the exact manual work
 * this workflow exists to remove.
 *
 * Tolerance stops at recognition. Unrelated JSON is still refused, and the refusal says what was
 * found and what was expected so the user can see whether they pasted the wrong thing.
 */
final class AIResultNormalizer {
	public const SCHEMA = 'cresco-layer-ai-result/v1';
	public const LEGACY_PATCH_SCHEMA = 'cresco-layer-patch/v1';

	/** Envelope keys a model commonly wraps its answer in. */
	private const WRAPPERS = [ 'result', 'data', 'output', 'response', 'payload', 'aiResult', 'ai_result', 'json' ];
	private const MAX_UNWRAP_DEPTH = 6;

	/**
	 * @return array{kind:string,result:array,raw:array}
	 *   kind: 'ai-result' | 'legacy-patch'
	 * @throws \InvalidArgumentException when the payload cannot be recognised.
	 */
	public function normalize( string $input ): array {
		$decoded = $this->decode( $input );
		$candidate = $this->unwrap( $decoded );

		$schema = is_array( $candidate ) ? (string) ( $candidate['schema'] ?? '' ) : '';

		if ( self::SCHEMA === $schema ) {
			return [ 'kind' => 'ai-result', 'result' => $this->shape_result( $candidate ), 'raw' => $candidate ];
		}
		if ( self::LEGACY_PATCH_SCHEMA === $schema ) {
			return [ 'kind' => 'legacy-patch', 'result' => $candidate, 'raw' => $candidate ];
		}

		// A model that omits the schema line but produced the right shape is still understood; the
		// shape is the meaningful part, and demanding the marker helps nobody.
		if ( is_array( $candidate ) && $this->looks_like_result( $candidate ) ) {
			$candidate['schema'] = self::SCHEMA;
			return [ 'kind' => 'ai-result', 'result' => $this->shape_result( $candidate ), 'raw' => $candidate ];
		}

		throw new \InvalidArgumentException( $this->describe_failure( $decoded, $candidate ) );
	}

	/** Strip prose and markdown fences, then parse. */
	private function decode( string $input ): array {
		$text = trim( $input );
		if ( '' === $text ) { throw new \InvalidArgumentException( 'Nothing to import: the AI result is empty.' ); }

		$text = $this->strip_fences( $text );

		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) { return $decoded; }

		// A model sometimes writes a sentence before or after the JSON; take the outermost object.
		$extracted = $this->extract_object( $text );
		if ( null !== $extracted ) {
			$decoded = json_decode( $extracted, true );
			if ( is_array( $decoded ) ) { return $decoded; }
		}

		throw new \InvalidArgumentException( 'The AI result is not valid JSON. ' . ( json_last_error_msg() ?: '' ) );
	}

	private function strip_fences( string $text ): string {
		if ( preg_match( '/```(?:json|JSON)?\s*(.+?)\s*```/s', $text, $match ) ) {
			return trim( $match[1] );
		}
		return $text;
	}

	/** The substring from the first { to its matching }, ignoring braces inside strings. */
	private function extract_object( string $text ): ?string {
		$start = strpos( $text, '{' );
		if ( false === $start ) { return null; }
		$depth = 0;
		$in_string = false;
		$escaped = false;
		for ( $i = $start, $length = strlen( $text ); $i < $length; $i++ ) {
			$char = $text[ $i ];
			if ( $escaped ) { $escaped = false; continue; }
			if ( '\\' === $char ) { $escaped = true; continue; }
			if ( '"' === $char ) { $in_string = ! $in_string; continue; }
			if ( $in_string ) { continue; }
			if ( '{' === $char ) { $depth++; }
			if ( '}' === $char ) {
				$depth--;
				if ( 0 === $depth ) { return substr( $text, $start, $i - $start + 1 ); }
			}
		}
		return null;
	}

	/** Follow known envelope keys inward until the payload itself is reached. */
	private function unwrap( array $value ): array {
		for ( $depth = 0; $depth < self::MAX_UNWRAP_DEPTH; $depth++ ) {
			if ( isset( $value['schema'] ) ) { return $value; }

			$next = null;
			foreach ( self::WRAPPERS as $key ) {
				if ( isset( $value[ $key ] ) && is_array( $value[ $key ] ) ) { $next = $value[ $key ]; break; }
			}
			// The legacy envelope the previous workflow used.
			if ( null === $next && isset( $value['patch'] ) && is_array( $value['patch'] ) ) { $next = $value['patch']; }

			if ( null === $next ) { return $value; }
			$value = $next;
		}
		return $value;
	}

	/** Recognised by shape: a target plus an Elementor element tree. */
	private function looks_like_result( array $value ): bool {
		if ( ! isset( $value['element'] ) || ! is_array( $value['element'] ) ) { return false; }
		$element = $value['element'];
		return isset( $element['elType'] ) || isset( $element['widgetType'] );
	}

	private function shape_result( array $value ): array {
		$target = is_array( $value['target'] ?? null ) ? $value['target'] : [];
		return [
			'schema' => self::SCHEMA,
			'target' => [
				'postId' => absint( $target['postId'] ?? 0 ),
				'id' => trim( (string) ( $target['id'] ?? '' ) ),
			],
			'element' => is_array( $value['element'] ?? null ) ? $value['element'] : [],
			'label' => sanitize_text_field( (string) ( $value['label'] ?? 'AI design import' ) ),
		];
	}

	/** A refusal has to show what arrived, or the user cannot tell what went wrong. */
	private function describe_failure( array $decoded, array $candidate ): string {
		$keys = array_slice( array_map( 'strval', array_keys( $decoded ) ), 0, 8 );
		$schema = (string) ( $candidate['schema'] ?? '' );
		$found = '' !== $schema ? sprintf( 'Detected schema: %s.', $schema ) : 'No schema field was found.';
		return sprintf(
			"Unsupported AI result. %s Detected top-level keys: %s. Expected %s (an Elementor element tree) or legacy %s.",
			$found,
			$keys ? implode( ', ', $keys ) : '(none)',
			self::SCHEMA,
			self::LEGACY_PATCH_SCHEMA
		);
	}
}
