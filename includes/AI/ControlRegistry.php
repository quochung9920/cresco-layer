<?php
namespace CrescoLayer\AI;

final class ControlRegistry {
	public const SCHEMA = 'cresco-control-registry/v1';
	public const RESPONSIVE_SUFFIXES = [ 'tablet', 'mobile', 'widescreen', 'laptop', 'tablet_extra', 'mobile_extra' ];

	public function build( array $catalog ): array {
		return [
			'schema' => self::SCHEMA,
			'controlMetadataVersion' => (int) ( $catalog['controlMetadataVersion'] ?? 0 ),
			'widgets' => $this->normalize_entries( (array) ( $catalog['widgets'] ?? [] ) ),
			'elements' => $this->normalize_entries( (array) ( $catalog['elements'] ?? [] ) ),
			'responsiveSuffixes' => self::RESPONSIVE_SUFFIXES,
		];
	}

	public function normalize_entry( array $entry ): array {
		$controls = [];
		foreach ( (array) ( $entry['controls'] ?? [] ) as $name => $control ) {
			if ( ! is_array( $control ) ) { continue; }
			$controls[ (string) $name ] = $this->normalize_control( (string) $name, $control );
		}
		return [
			'name' => (string) ( $entry['name'] ?? '' ),
			'title' => (string) ( $entry['title'] ?? '' ),
			'className' => (string) ( $entry['className'] ?? '' ),
			'isAtomic' => ! empty( $entry['isAtomic'] ),
			'capabilitySource' => (string) ( $entry['capabilitySource'] ?? '' ),
			'controlCount' => count( $controls ),
			'controls' => $controls,
		];
	}

	public function resolve( array $entry, string $setting ): ?array {
		$controls = (array) ( $entry['controls'] ?? [] );
		if ( isset( $controls[ $setting ] ) && is_array( $controls[ $setting ] ) ) {
			$contract = $this->normalize_control( $setting, $controls[ $setting ] );
			$contract['setting'] = $setting;
			$contract['baseSetting'] = $setting;
			$contract['device'] = '';
			return $contract;
		}

		if ( ! preg_match( '/^(.+?)_(' . implode( '|', array_map( 'preg_quote', self::RESPONSIVE_SUFFIXES ) ) . ')$/', $setting, $matches ) ) {
			return null;
		}
		$base = (string) $matches[1];
		$device = (string) $matches[2];
		if ( ! isset( $controls[ $base ] ) || ! is_array( $controls[ $base ] ) ) { return null; }
		$contract = $this->normalize_control( $base, $controls[ $base ] );
		if ( empty( $contract['responsive'] ) ) { return null; }
		$contract['setting'] = $setting;
		$contract['baseSetting'] = $base;
		$contract['device'] = $device;
		return $contract;
	}

	private function normalize_entries( array $entries ): array {
		$out = [];
		foreach ( $entries as $name => $entry ) {
			if ( ! is_array( $entry ) ) { continue; }
			$normalized = $this->normalize_entry( $entry );
			$key = '' !== $normalized['name'] ? $normalized['name'] : (string) $name;
			$out[ $key ] = $normalized;
		}
		return $out;
	}

	private function normalize_control( string $name, array $control ): array {
		$options = [];
		foreach ( (array) ( $control['options'] ?? [] ) as $key => $label ) {
			$options[] = (string) $key;
		}
		$units = array_values( array_unique( array_map( 'strval', (array) ( $control['size_units'] ?? [] ) ) ) );
		return [
			'name' => $name,
			'type' => (string) ( $control['type'] ?? '' ),
			'source' => (string) ( $control['source'] ?? '' ),
			'label' => (string) ( $control['label'] ?? $name ),
			'responsive' => ! empty( $control['responsive'] ),
			'dynamic' => ! empty( $control['dynamic'] ),
			'units' => $units,
			'options' => $options,
			'range' => is_array( $control['range'] ?? null ) ? $control['range'] : [],
			'min' => $control['min'] ?? null,
			'max' => $control['max'] ?? null,
			'step' => $control['step'] ?? null,
			'condition' => is_array( $control['condition'] ?? null ) ? $control['condition'] : [],
			'conditions' => is_array( $control['conditions'] ?? null ) ? $control['conditions'] : [],
			'selectors' => is_array( $control['selectors'] ?? null ) ? array_keys( $control['selectors'] ) : [],
			'bind' => (string) ( $control['bind'] ?? '' ),
			'propType' => (string) ( $control['propType'] ?? '' ),
		];
	}
}
