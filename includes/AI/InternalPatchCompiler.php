<?php
namespace CrescoLayer\AI;

/**
 * Compiles AI-facing results into the internal `cresco-layer-patch/v1` representation.
 *
 * Full-tree AI results are allowed only for empty targets or explicit rebuilds. Incremental work
 * stays delta-first. For legacy/delta patches, Cresco also owns IDs for inserted subtrees so the AI
 * can focus on Elementor structure and native controls instead of database identity.
 */
final class InternalPatchCompiler {
	private ElementLocator $locator;
	private AIResultNormalizer $normalizer;

	public function __construct( ?ElementLocator $locator = null, ?AIResultNormalizer $normalizer = null ) {
		$this->locator = $locator ?? new ElementLocator();
		$this->normalizer = $normalizer ?? new AIResultNormalizer();
	}

	/**
	 * @param string $raw       Whatever the user pasted, dropped or uploaded.
	 * @param int    $post_id   The document currently open.
	 * @param array  $elements  Current document elements, for target resolution and ID collision checks.
	 * @param string $selected  The element the user has selected, when the caller knows it.
	 * @return array{patch:array,report:array}
	 */
	public function compile( string $raw, int $post_id, array $elements, string $selected = '' ): array {
		$normalized = $this->normalizer->normalize( $raw );

		// Delta patches remain a supported AI output because they are the safest representation for
		// add/edit/move work. Normalize inserted subtree IDs here so models do not need to invent them.
		if ( 'legacy-patch' === $normalized['kind'] ) {
			return $this->normalize_delta_patch( $normalized['result'], $elements );
		}

		$result = $normalized['result'];
		$target_id = $this->resolve_target( $result, $post_id, $elements, $selected );
		$live_target = $this->locator->find( $elements, $target_id );

		// A full-tree AI result necessarily means replace-element. That is safe by default for an
		// empty construction target, but destructive for a populated target. Require explicit intent
		// so an incremental request can never echo read-only source context over live Elementor data.
		if ( $this->has_live_content( $live_target ) && ! $this->explicit_replace_intent( $normalized['raw'] ) ) {
			throw new \InvalidArgumentException(
				'This target already contains live Elementor data, so Cresco will not compile a full-tree AI result into replace-element automatically. Return only the intended delta change using insert-element/update-setting, or set intent to "replace-target" only when the user explicitly requested a complete rebuild of this target.'
			);
		}

		$generator = new ElementorIdGenerator( $elements );
		$normalized_tree = $generator->normalize( $result['element'], $target_id );

		$patch = [
			'schema' => 'cresco-layer-patch/v1',
			'base' => [ 'postId' => $post_id ],
			'scope' => [ 'mode' => 'subtree', 'rootElementId' => $target_id, 'elementIds' => [ $target_id ] ],
			'label' => (string) ( $result['label'] ?? 'AI design import' ),
			'operations' => [
				[
					'operation' => 'replace-element',
					'elementId' => $target_id,
					'element' => $normalized_tree['element'],
				],
			],
		];

		return [
			'patch' => $patch,
			'report' => [
				'source' => 'ai-result',
				'targetId' => $target_id,
				'generatedIds' => $normalized_tree['generated'],
				'reusedIds' => $normalized_tree['reused'],
				'elementCount' => $this->count_elements( $normalized_tree['element'] ),
				'destructiveReplace' => $this->has_live_content( $live_target ),
			],
		];
	}

	/**
	 * Normalize IDs only for new inserted subtrees. Existing element IDs in update/move/remove
	 * operations are never rewritten because they identify live Elementor data.
	 */
	private function normalize_delta_patch( array $patch, array $elements ): array {
		$generator = new ElementorIdGenerator( $elements );
		$generated = [];
		$reused = [];
		$count = 0;
		$operations = is_array( $patch['operations'] ?? null ) ? $patch['operations'] : [];

		foreach ( $operations as $index => $operation ) {
			if ( ! is_array( $operation ) || 'insert-element' !== (string) ( $operation['operation'] ?? '' ) || ! is_array( $operation['element'] ?? null ) ) { continue; }
			$element = $operation['element'];
			$supplied_id = trim( (string) ( $element['id'] ?? '' ) );
			$taken = array_flip( $generator->taken() );
			$usable = 1 === preg_match( '/^[a-f0-9]{7}$/', $supplied_id ) && ! isset( $taken[ $supplied_id ] );
			$root_id = $usable ? $supplied_id : $generator->generate();
			if ( ! $usable ) { $generated[] = $root_id; }
			else { $reused[] = $root_id; }

			$tree = $generator->normalize( $element, $root_id );
			$operations[ $index ]['element'] = $tree['element'];
			$generated = array_merge( $generated, $tree['generated'] );
			$reused = array_merge( $reused, $tree['reused'] );
			$count += $this->count_elements( $tree['element'] );
		}

		$patch['operations'] = $operations;
		return [
			'patch' => $patch,
			'report' => [
				'source' => 'legacy-patch',
				'generatedIds' => array_values( array_unique( $generated ) ),
				'reusedIds' => array_values( array_unique( $reused ) ),
				'targetId' => (string) ( $patch['scope']['rootElementId'] ?? '' ),
				'elementCount' => $count,
				'deltaNormalized' => true,
			],
		];
	}

	/**
	 * Decide which element the result applies to, and refuse when that is ambiguous.
	 * Silently applying to the current selection when the result names a different element would
	 * rewrite something the user never asked to change.
	 */
	private function resolve_target( array $result, int $post_id, array $elements, string $selected ): string {
		$declared_post = absint( $result['target']['postId'] ?? 0 );
		if ( $declared_post && $declared_post !== $post_id ) {
			throw new \InvalidArgumentException( sprintf(
				'This AI result was generated for document #%d but the open document is #%d.',
				$declared_post,
				$post_id
			) );
		}

		$declared = trim( (string) ( $result['target']['id'] ?? '' ) );
		$root = trim( (string) ( $result['element']['id'] ?? '' ) );
		$target = '' !== $declared ? $declared : $root;

		if ( '' === $target ) {
			if ( '' === $selected ) {
				throw new \InvalidArgumentException( 'The AI result does not say which element it belongs to, and nothing is selected in Elementor.' );
			}
			$target = $selected;
		}

		if ( '' !== $selected && $target !== $selected ) {
			throw new \InvalidArgumentException( sprintf(
				'This AI result targets element %s but %s is selected in Elementor. Select the matching element, or export again for the current selection.',
				$target,
				$selected
			) );
		}

		if ( '' !== $root && '' !== $declared && $root !== $declared ) {
			throw new \InvalidArgumentException( sprintf(
				'The AI result is inconsistent: target is %s but the returned element root is %s.',
				$declared,
				$root
			) );
		}

		if ( null === $this->locator->find( $elements, $target ) ) {
			throw new \InvalidArgumentException( sprintf(
				'Element %s no longer exists in this document. It may have been deleted since the export.',
				$target
			) );
		}

		return $target;
	}

	private function has_live_content( ?array $target ): bool {
		if ( ! is_array( $target ) ) { return false; }
		if ( ! empty( $target['settings'] ) ) { return true; }
		if ( ! empty( $target['elements'] ) ) { return true; }

		foreach ( $target as $key => $value ) {
			if ( in_array( (string) $key, [ 'id', 'elType', 'widgetType', 'isInner', 'settings', 'elements' ], true ) ) { continue; }
			if ( null !== $value && '' !== $value && [] !== $value ) { return true; }
		}
		return false;
	}

	private function explicit_replace_intent( array $raw ): bool {
		if ( true === ( $raw['replaceTarget'] ?? false ) ) { return true; }
		$intent = strtolower( trim( (string) ( $raw['intent'] ?? '' ) ) );
		return in_array( $intent, [ 'replace', 'replace-target', 'full-rebuild', 'rebuild-target' ], true );
	}

	private function count_elements( array $element ): int {
		$count = 1;
		foreach ( (array) ( $element['elements'] ?? [] ) as $child ) {
			if ( is_array( $child ) ) { $count += $this->count_elements( $child ); }
		}
		return $count;
	}
}
