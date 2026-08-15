<?php
namespace CrescoLayer\AI;

/**
 * Deterministic, semantics-preserving repair before SemanticPatchGuard.
 *
 * This layer never clamps, guesses or converts between unlike units. It only performs repairs that
 * preserve the requested CSS meaning exactly and that the active Elementor runtime explicitly
 * proves are supported.
 */
final class MutationNormalizer {
	private ElementLocator $locator;
	private CapabilityScanner $scanner;

	public function __construct( ?ElementLocator $locator = null, ?CapabilityScanner $scanner = null ) {
		$this->locator = $locator ?? new ElementLocator();
		$this->scanner = $scanner ?? new CapabilityScanner();
	}

	/** @return array{patch:array,repairs:array} */
	public function normalize( array $patch, array $elements ): array {
		$catalog = $this->scanner->catalog();
		$repairs = [];
		$operations = is_array( $patch['operations'] ?? null ) ? $patch['operations'] : [];

		foreach ( $operations as $index => $operation ) {
			if ( ! is_array( $operation ) ) { continue; }
			$type = (string) ( $operation['operation'] ?? '' );
			if ( in_array( $type, [ 'insert-element', 'replace-element' ], true ) && is_array( $operation['element'] ?? null ) ) {
				$element = $operation['element'];
				$this->normalize_tree( $element, $catalog, $repairs, $index );
				$operations[ $index ]['element'] = $element;
				continue;
			}
			if ( 'update-setting' === $type ) {
				$element_id = (string) ( $operation['elementId'] ?? '' );
				$element = $this->locator->find( $elements, $element_id );
				if ( ! is_array( $element ) ) { continue; }
				$setting = (string) ( $operation['setting'] ?? '' );
				$control = $this->control_for( $element, $setting, $catalog );
				if ( ! $control ) { continue; }
				$value = $operation['value'] ?? null;
				$next = $this->normalize_value( $value, $control, $element_id, $setting, $repairs, $index );
				$operations[ $index ]['value'] = $next;
			}
		}

		$patch['operations'] = $operations;
		return [ 'patch' => $patch, 'repairs' => $repairs ];
	}

	private function normalize_tree( array &$element, array $catalog, array &$repairs, int $operation_index ): void {
		$entry = $this->catalog_entry( $element, $catalog );
		$controls = is_array( $entry['controls'] ?? null ) ? $entry['controls'] : [];
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		foreach ( $settings as $setting => $value ) {
			$control = $this->control_from_map( (string) $setting, $controls );
			if ( ! $control ) { continue; }
			$settings[ $setting ] = $this->normalize_value(
				$value,
				$control,
				(string) ( $element['id'] ?? $element['ref'] ?? '' ),
				(string) $setting,
				$repairs,
				$operation_index
			);
		}
		$element['settings'] = $settings;
		foreach ( (array) ( $element['elements'] ?? [] ) as $child_index => $child ) {
			if ( ! is_array( $child ) ) { continue; }
			$this->normalize_tree( $child, $catalog, $repairs, $operation_index );
			$element['elements'][ $child_index ] = $child;
		}
	}

	private function normalize_value( $value, array $control, string $element_id, string $setting, array &$repairs, int $operation_index ) {
		if ( ! is_array( $value ) || 'px' !== (string) ( $value['unit'] ?? '' ) || ! isset( $value['size'] ) || ! is_numeric( $value['size'] ) ) {
			return $value;
		}
		$units = array_map( 'strval', (array) ( $control['size_units'] ?? [] ) );
		if ( ! in_array( 'custom', $units, true ) ) { return $value; }
		[ $min, $max ] = $this->bounds_for_unit( $control, 'px' );
		$number = (float) $value['size'];
		$outside = ( is_numeric( $min ) && $number < (float) $min ) || ( is_numeric( $max ) && $number > (float) $max );
		if ( ! $outside ) { return $value; }

		$next = $value;
		$next['unit'] = 'custom';
		$next['size'] = $this->format_number( $number ) . 'px';
		if ( ! array_key_exists( 'sizes', $next ) ) { $next['sizes'] = []; }
		$repairs[] = [
			'operationIndex' => $operation_index,
			'elementId' => $element_id,
			'setting' => $setting,
			'from' => $value,
			'to' => $next,
			'reason' => 'Requested px value is outside the native px slider range; the same Elementor control explicitly supports Custom Unit, so the CSS length is preserved exactly.',
		];
		return $next;
	}

	private function control_for( array $element, string $setting, array $catalog ): array {
		$entry = $this->catalog_entry( $element, $catalog );
		return $this->control_from_map( $setting, is_array( $entry['controls'] ?? null ) ? $entry['controls'] : [] );
	}

	private function control_from_map( string $setting, array $controls ): array {
		if ( isset( $controls[ $setting ] ) && is_array( $controls[ $setting ] ) ) { return $controls[ $setting ]; }
		$base = preg_replace( '/_(?:tablet|mobile|widescreen|laptop|tablet_extra|mobile_extra)$/', '', $setting );
		if ( isset( $controls[ $base ] ) && is_array( $controls[ $base ] ) && ! empty( $controls[ $base ]['responsive'] ) ) { return $controls[ $base ]; }
		return [];
	}

	private function catalog_entry( array $element, array $catalog ): array {
		$widget = (string) ( $element['widgetType'] ?? '' );
		$type = (string) ( $element['elType'] ?? '' );
		if ( '' !== $widget && isset( $catalog['widgets'][ $widget ] ) && is_array( $catalog['widgets'][ $widget ] ) ) { return $catalog['widgets'][ $widget ]; }
		if ( '' !== $type && isset( $catalog['elements'][ $type ] ) && is_array( $catalog['elements'][ $type ] ) ) { return $catalog['elements'][ $type ]; }
		return [];
	}

	private function bounds_for_unit( array $control, string $unit ): array {
		$range = is_array( $control['range'] ?? null ) ? $control['range'] : [];
		if ( isset( $range[ $unit ] ) && is_array( $range[ $unit ] ) ) {
			return [ $range[ $unit ]['min'] ?? null, $range[ $unit ]['max'] ?? null ];
		}
		$has_per_unit = false;
		foreach ( $range as $key => $candidate ) {
			if ( is_string( $key ) && is_array( $candidate ) && ( isset( $candidate['min'] ) || isset( $candidate['max'] ) ) ) { $has_per_unit = true; break; }
		}
		if ( $has_per_unit ) { return [ null, null ]; }
		if ( isset( $range['min'] ) || isset( $range['max'] ) ) { return [ $range['min'] ?? null, $range['max'] ?? null ]; }
		return [ $control['min'] ?? null, $control['max'] ?? null ];
	}

	private function format_number( float $number ): string {
		if ( floor( $number ) === $number ) { return (string) (int) $number; }
		return rtrim( rtrim( sprintf( '%.6F', $number ), '0' ), '.' );
	}
}
