<?php
namespace CrescoLayer\AI;

/**
 * Assigns Elementor-safe element IDs to an AI-authored subtree.
 *
 * An AI should describe the interface it wants, not invent Elementor's internal identifiers. Asking
 * it to mint unique 7-character hex IDs is a task it has no way to do safely — it cannot see the
 * rest of the document, so any ID it invents may already exist elsewhere and silently collide.
 *
 * The root is never renamed: it identifies the element the user selected, and changing it would
 * point the write at something other than what they chose.
 */
final class ElementorIdGenerator {
	/** Elementor uses 7 lowercase hex characters for element IDs. */
	private const PATTERN = '/^[a-f0-9]{7}$/';
	private const MAX_ATTEMPTS = 50;

	/** IDs already present in the document, so a generated one cannot collide with them. */
	private array $taken = [];
	private array $generated = [];
	private array $reused = [];
	private array $duplicateRefs = [];

	/** @param array $document Existing document elements, used to collect IDs already in use. */
	public function __construct( array $document = [] ) {
		$this->collect( $document );
	}

	/**
	 * Normalize a subtree: keep the root ID, keep valid unique descendant IDs, generate the rest.
	 *
	 * @param array  $element AI-authored element tree.
	 * @param string $root_id The target element ID the result must keep.
	 * @return array{element:array,generated:string[],reused:string[]}
	 */
	public function normalize( array $element, string $root_id ): array {
		$this->generated = [];
		$this->reused = [];
		$this->duplicateRefs = [];

		// The root keeps the target ID; it is the anchor the patch is applied against.
		$element['id'] = $root_id;
		$this->taken[ $root_id ] = true;

		if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$element['elements'] = $this->normalize_children( $element['elements'] );
		} else {
			$element['elements'] = [];
		}

		return [
			'element' => $element,
			'generated' => $this->generated,
			'reused' => $this->reused,
			'refs' => $this->refs,
			'duplicateRefs' => array_values( array_unique( $this->duplicateRefs ) ),
		];
	}

	private function normalize_children( array $children ): array {
		$out = [];
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) { continue; }
			$id = isset( $child['id'] ) ? (string) $child['id'] : '';
			$ref = isset( $child['ref'] ) ? (string) $child['ref'] : '';

			if ( '' === $id && self::is_ref( $ref ) ) {
				// A repeated ref would silently merge two nodes into one, so it is recorded rather
				// than quietly reused.
				if ( $this->has_ref( $ref ) ) { $this->duplicateRefs[] = $ref; }
				$id = $this->resolve_ref( $ref );
				$this->generated[] = $id;
			} elseif ( $this->is_usable( $id ) ) {
				$this->taken[ $id ] = true;
				$this->reused[] = $id;
			} else {
				$id = $this->generate();
				$this->generated[] = $id;
			}
			$child['id'] = $id;
			unset( $child['ref'] );

			if ( isset( $child['elements'] ) && is_array( $child['elements'] ) ) {
				$child['elements'] = $this->normalize_children( $child['elements'] );
			} else {
				$child['elements'] = [];
			}
			$out[] = $child;
		}
		return $out;
	}

	/** A supplied ID is kept only when it is Elementor-shaped and not already used anywhere. */
	private function is_usable( string $id ): bool {
		return '' !== $id && preg_match( self::PATTERN, $id ) && ! isset( $this->taken[ $id ] );
	}

	/**
	 * Temporary references an external AI may use instead of inventing final Elementor IDs.
	 *
	 * A model cannot see the rest of the document, so any ID it mints may collide with an element it
	 * never knew about — the "Inserted element ID already exists" failure. Letting it name nodes
	 * symbolically ($new:hero) moves the one decision it cannot make safely back to Cresco, while
	 * still letting it refer to its own nodes.
	 */
	public const REF_PREFIX = '$new:';

	/** Resolved ref => allocated Elementor ID, so sibling references stay consistent. */
	private array $refs = [];

	public static function is_ref( string $value ): bool {
		return str_starts_with( $value, self::REF_PREFIX ) && strlen( $value ) > strlen( self::REF_PREFIX );
	}

	/**
	 * Allocate the final ID for a temporary reference, reusing it when the same ref appears again.
	 *
	 * Two nodes carrying the same ref is a mistake in the answer, not something to paper over: they
	 * would collapse into one element. The caller decides how to report it via duplicate_refs().
	 */
	public function resolve_ref( string $ref ): string {
		if ( ! self::is_ref( $ref ) ) {
			throw new \InvalidArgumentException( 'Not a Cresco temporary element reference: ' . $ref );
		}
		if ( isset( $this->refs[ $ref ] ) ) { return $this->refs[ $ref ]; }
		$this->refs[ $ref ] = $this->generate();
		return $this->refs[ $ref ];
	}

	public function resolved_refs(): array { return $this->refs; }

	/** True when this ref was never allocated, so a pointer to it cannot be honoured. */
	public function has_ref( string $ref ): bool { return isset( $this->refs[ $ref ] ); }

	public function generate(): string {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$candidate = substr( md5( uniqid( 'cresco', true ) . $attempt ), 0, 7 );
			if ( ! isset( $this->taken[ $candidate ] ) ) {
				$this->taken[ $candidate ] = true;
				return $candidate;
			}
		}
		// Practically unreachable; fail loudly rather than return a colliding ID.
		throw new \RuntimeException( 'Could not generate a unique Elementor element ID.' );
	}

	/** @return string[] Every ID currently known to be in use. */
	public function taken(): array {
		return array_keys( $this->taken );
	}

	private function collect( array $elements ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$id = isset( $element['id'] ) ? (string) $element['id'] : '';
			if ( '' !== $id ) { $this->taken[ $id ] = true; }
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->collect( $element['elements'] );
			}
		}
	}
}
