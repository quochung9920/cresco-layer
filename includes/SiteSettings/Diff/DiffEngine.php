<?php
namespace CrescoLayer\SiteSettings\Diff;

/**
 * Semantic comparison of desired Site Settings against what the Kit already holds.
 *
 * The engine writes only when this reports a change. Re-saving a Kit that already matches would
 * bump its revision and force Elementor to regenerate every stylesheet for no visible difference,
 * so "nothing to do" has to be a first-class outcome rather than a harmless extra write.
 *
 * Comparison is value-semantic, not string-identical: Elementor stores `16`, `"16"` and `16.0`
 * interchangeably depending on which code path last touched a control, and treating those as
 * different would make every run report changes.
 */
final class DiffEngine {
	/**
	 * @param array $current Settings currently on the Kit.
	 * @param array $desired Settings the adapter produced.
	 * @return array{changed:bool,created:array,updated:array,unchanged:array,merged:array}
	 */
	public function compare( array $current, array $desired ): array {
		$created = [];
		$updated = [];
		$unchanged = [];
		// Start from current so every key Cresco does not manage survives untouched.
		$merged = $current;

		foreach ( $desired as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) ) {
				$created[] = $key;
				$merged[ $key ] = $value;
				continue;
			}
			if ( $this->equivalent( $current[ $key ], $value ) ) {
				$unchanged[] = $key;
				continue;
			}
			$updated[] = $key;
			$merged[ $key ] = $value;
		}

		return [
			'changed' => ( [] !== $created || [] !== $updated ),
			'created' => $created,
			'updated' => $updated,
			'unchanged' => $unchanged,
			'merged' => $merged,
		];
	}

	/** A stable fingerprint of the managed subset, used to short-circuit an unchanged re-run. */
	public function hash( array $desired ): string {
		return hash( 'sha256', (string) wp_json_encode( $this->canonical( $desired ) ) );
	}

	/**
	 * Values are equivalent when their canonical forms match. Repeater rows are compared by `_id`
	 * rather than position, because Elementor reorders rows and a reorder is not a style change.
	 */
	public function equivalent( $a, $b ): bool {
		return $this->canonical( $a ) === $this->canonical( $b );
	}

	private function canonical( $value ) {
		if ( is_array( $value ) ) {
			if ( $this->is_repeater( $value ) ) { return $this->canonical_repeater( $value ); }
			$out = [];
			foreach ( $value as $key => $item ) { $out[ (string) $key ] = $this->canonical( $item ); }
			ksort( $out, SORT_STRING );
			return $out;
		}
		if ( is_bool( $value ) ) { return $value ? '1' : ''; }
		if ( null === $value ) { return ''; }
		if ( is_int( $value ) || is_float( $value ) ) { return $this->number( (float) $value ); }
		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			// "16" and 16 arrive from different Elementor code paths for the same control.
			if ( is_numeric( $trimmed ) ) { return $this->number( (float) $trimmed ); }
			return $trimmed;
		}
		return '';
	}

	/** Trailing-zero-insensitive so 16, 16.0 and "16.00" all agree. */
	private function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' ) ?: '0';
	}

	private function is_repeater( array $value ): bool {
		if ( ! $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { return false; }
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['_id'] ) ) { return false; }
		}
		return true;
	}

	private function canonical_repeater( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$id = (string) $row['_id'];
			$fields = [];
			foreach ( $row as $key => $item ) { $fields[ (string) $key ] = $this->canonical( $item ); }
			ksort( $fields, SORT_STRING );
			$out[ $id ] = $fields;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}
}
