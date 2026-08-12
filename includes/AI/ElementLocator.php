<?php
namespace CrescoLayer\AI;

use CrescoLayer\Support\DocumentChecksum;

final class ElementLocator {
	public const SCOPES = [ 'document', 'widget', 'selection', 'subtree' ];

	public function normalize_ids( array $ids ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$id = trim( (string) $id );
			if ( preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	public function find( array $elements, string $id ): ?array {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			if ( (string) ( $element['id'] ?? '' ) === $id ) { return $element; }
			$found = $this->find( (array) ( $element['elements'] ?? [] ), $id );
			if ( null !== $found ) { return $found; }
		}
		return null;
	}

	public function scope_elements( array $elements, string $scope, array $selected_ids ): array {
		$scope = in_array( $scope, self::SCOPES, true ) ? $scope : 'document';
		if ( 'document' === $scope ) { return $elements; }

		$selected_ids = $this->normalize_ids( $selected_ids );
		if ( 'widget' === $scope && $selected_ids ) { $selected_ids = [ $selected_ids[0] ]; }
		if ( ! $selected_ids ) { return []; }

		$result = [];
		foreach ( $selected_ids as $id ) {
			$element = $this->find( $elements, $id );
			if ( null === $element ) { continue; }
			if ( in_array( $scope, [ 'widget', 'selection' ], true ) ) {
				$element['elements'] = [];
			}
			$result[] = $element;
		}
		return $result;
	}

	public function scope_ids( array $elements, string $scope, array $selected_ids ): array {
		if ( 'document' === $scope ) { return $this->collect_ids( $elements ); }
		$roots = $this->scope_elements( $elements, $scope, $selected_ids );
		if ( in_array( $scope, [ 'widget', 'selection' ], true ) ) {
			return $this->normalize_ids( array_map( static fn( array $element ): string => (string) ( $element['id'] ?? '' ), $roots ) );
		}
		return $this->collect_ids( $roots );
	}

	public function collect_ids( array $elements ): array {
		$ids = [];
		$this->walk_ids( $elements, $ids );
		return array_values( array_unique( $ids ) );
	}

	public function scope_checksum( array $elements, string $scope, array $selected_ids ): string {
		return DocumentChecksum::hash( $this->scope_elements( $elements, $scope, $selected_ids ), [] );
	}

	public function context( array $elements, array $selected_ids ): array {
		$out = [];
		foreach ( $this->normalize_ids( $selected_ids ) as $id ) {
			$context = $this->find_context( $elements, $id );
			if ( null === $context ) { continue; }
			$out[] = $context;
		}
		return $out;
	}

	private function find_context( array $elements, string $id, ?array $parent = null ): ?array {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) { continue; }
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				$siblings = [];
				foreach ( $elements as $sibling ) {
					if ( ! is_array( $sibling ) ) { continue; }
					$siblings[] = $this->summary( $sibling );
				}
				return [
					'target' => $this->summary( $element ),
					'parent' => null === $parent ? null : $this->summary( $parent ),
					'index' => $index,
					'siblings' => $siblings,
				];
			}
			$found = $this->find_context( (array) ( $element['elements'] ?? [] ), $id, $element );
			if ( null !== $found ) { return $found; }
		}
		return null;
	}

	private function summary( array $element ): array {
		return [
			'id' => (string) ( $element['id'] ?? '' ),
			'elType' => (string) ( $element['elType'] ?? '' ),
			'widgetType' => (string) ( $element['widgetType'] ?? '' ),
			'isInner' => (bool) ( $element['isInner'] ?? false ),
			'settings' => is_array( $element['settings'] ?? null ) ? $element['settings'] : [],
			'childCount' => count( (array) ( $element['elements'] ?? [] ) ),
		];
	}

	private function walk_ids( array $elements, array &$ids ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$id = (string) ( $element['id'] ?? '' );
			if ( '' !== $id ) { $ids[] = $id; }
			$this->walk_ids( (array) ( $element['elements'] ?? [] ), $ids );
		}
	}
}
