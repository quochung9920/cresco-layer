<?php
namespace CrescoLayer\LocalAI;

final class EffectiveValueResolver {
	public function describe( array $skill, array $current_settings ): array {
		$setting = trim( (string) ( $skill['setting'] ?? '' ) );
		$devices = array_values( array_unique( array_map( 'strval', (array) ( $skill['devices'] ?? [ 'desktop' ] ) ) ) );
		if ( ! in_array( 'desktop', $devices, true ) ) { array_unshift( $devices, 'desktop' ); }
		$runtime_values = is_array( $skill['current']['devices'] ?? null ) ? $skill['current']['devices'] : [];
		$globals = is_array( $current_settings['__globals__'] ?? null ) ? $current_settings['__globals__'] : [];
		$dynamic = is_array( $current_settings['__dynamic__'] ?? null ) ? $current_settings['__dynamic__'] : [];
		$out = [];

		foreach ( $devices as $index => $device ) {
			$key = 'desktop' === $device ? $setting : $setting . '_' . $device;
			$explicit = '' !== $setting && array_key_exists( $key, $current_settings );
			$value = $explicit ? $current_settings[ $key ] : null;
			$source = $explicit ? 'explicit-local' : 'unset';
			$inherited_from = null;
			$binding = $this->binding( $key, $setting, $globals, $dynamic );
			if ( $binding ) { $source = $binding['type']; }

			if ( ! $explicit && ! $binding && '' !== $setting ) {
				for ( $cursor = $index - 1; $cursor >= 0; $cursor-- ) {
					$parent_device = $devices[ $cursor ];
					$parent_key = 'desktop' === $parent_device ? $setting : $setting . '_' . $parent_device;
					$parent_binding = $this->binding( $parent_key, $setting, $globals, $dynamic );
					if ( array_key_exists( $parent_key, $current_settings ) || $parent_binding ) {
						$value = array_key_exists( $parent_key, $current_settings ) ? $current_settings[ $parent_key ] : null;
						$source = $parent_binding ? 'inherited-' . $parent_binding['type'] : 'inherited';
						$binding = $parent_binding;
						$inherited_from = $parent_device;
						break;
					}
				}
			}

			if ( 'unset' === $source && array_key_exists( $device, $runtime_values ) && null !== $runtime_values[ $device ] ) {
				$value = $runtime_values[ $device ];
				$source = 'runtime-default';
			}

			$out[ $device ] = [
				'explicit' => $explicit,
				'explicitValue' => $explicit ? $current_settings[ $key ] : null,
				'effectiveValue' => $value,
				'source' => $source,
				'inheritedFrom' => $inherited_from,
				'binding' => $binding,
				'protectedReference' => ! empty( $binding ),
			];
		}

		return $out;
	}

	private function binding( string $key, string $base, array $globals, array $dynamic ): ?array {
		foreach ( [ [ 'map' => $dynamic, 'type' => 'dynamic-tag' ], [ 'map' => $globals, 'type' => 'global-reference' ] ] as $candidate ) {
			$map = $candidate['map'];
			$binding_key = array_key_exists( $key, $map ) ? $key : ( array_key_exists( $base, $map ) ? $base : '' );
			if ( '' === $binding_key ) { continue; }
			$value = $map[ $binding_key ];
			if ( null === $value || '' === (string) $value ) { continue; }
			return [ 'type' => $candidate['type'], 'key' => $binding_key, 'reference' => is_scalar( $value ) ? (string) $value : '[structured-reference]' ];
		}
		return null;
	}
}
