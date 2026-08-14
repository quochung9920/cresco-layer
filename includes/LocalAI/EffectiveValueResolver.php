<?php
namespace CrescoLayer\LocalAI;

final class EffectiveValueResolver {
	public function describe( array $skill, array $current_settings ): array {
		$setting = trim( (string) ( $skill['setting'] ?? '' ) );
		$devices = array_values( array_unique( array_map( 'strval', (array) ( $skill['devices'] ?? [ 'desktop' ] ) ) ) );
		if ( ! in_array( 'desktop', $devices, true ) ) { array_unshift( $devices, 'desktop' ); }
		$runtime_values = is_array( $skill['current']['devices'] ?? null ) ? $skill['current']['devices'] : [];
		$out = [];

		foreach ( $devices as $index => $device ) {
			$key = 'desktop' === $device ? $setting : $setting . '_' . $device;
			$explicit = '' !== $setting && array_key_exists( $key, $current_settings );
			$value = $explicit ? $current_settings[ $key ] : null;
			$source = $explicit ? 'explicit' : 'unset';
			$inherited_from = null;

			if ( ! $explicit && '' !== $setting ) {
				for ( $cursor = $index - 1; $cursor >= 0; $cursor-- ) {
					$parent_device = $devices[ $cursor ];
					$parent_key = 'desktop' === $parent_device ? $setting : $setting . '_' . $parent_device;
					if ( array_key_exists( $parent_key, $current_settings ) ) {
						$value = $current_settings[ $parent_key ];
						$source = 'inherited';
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
			];
		}

		return $out;
	}
}
