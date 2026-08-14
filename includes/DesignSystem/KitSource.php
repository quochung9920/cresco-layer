<?php
namespace CrescoLayer\DesignSystem;

/**
 * Everything the design standard needs to know about a Kit, derived from a single read().
 *
 * Splitting the accessors from the Elementor lookup keeps the rules testable against fixture data
 * while leaving the runtime reader final. Consumers depend on this type, never on the reader.
 */
abstract class KitSource {
	/** @return array{available:bool,postId:int,settings:array,controls:array,breakpoints:array,errors:array} */
	abstract public function read(): array;

	/** True when the Kit actually registers this setting, so a proposal can never invent a key. */
	public function has_control( string $name ): bool {
		return isset( $this->read()['controls'][ $name ] );
	}

	public function control( string $name ): array {
		$control = $this->read()['controls'][ $name ] ?? null;
		return is_array( $control ) ? $control : [];
	}

	public function setting( string $name, $default = null ) {
		return $this->read()['settings'][ $name ] ?? $default;
	}

	/**
	 * Global colours as [ id => [ id, bucket, title, color ] ]. Elementor keeps system and custom
	 * colours in two lists; both are real global colours to a reviewer.
	 */
	public function global_colors(): array {
		$settings = $this->read()['settings'];
		$out = [];
		foreach ( [ 'system_colors', 'custom_colors' ] as $bucket ) {
			foreach ( (array) ( $settings[ $bucket ] ?? [] ) as $entry ) {
				if ( ! is_array( $entry ) ) { continue; }
				$id = (string) ( $entry['_id'] ?? '' );
				if ( '' === $id ) { continue; }
				$out[ $id ] = [
					'id' => $id,
					'bucket' => $bucket,
					'title' => (string) ( $entry['title'] ?? $id ),
					'color' => (string) ( $entry['color'] ?? '' ),
				];
			}
		}
		return $out;
	}

	/** Global typography entries as [ id => [ id, bucket, title, fontFamily, fontWeight, fontSize ] ]. */
	public function global_typography(): array {
		$settings = $this->read()['settings'];
		$out = [];
		foreach ( [ 'system_typography', 'custom_typography' ] as $bucket ) {
			foreach ( (array) ( $settings[ $bucket ] ?? [] ) as $entry ) {
				if ( ! is_array( $entry ) ) { continue; }
				$id = (string) ( $entry['_id'] ?? '' );
				if ( '' === $id ) { continue; }
				$out[ $id ] = [
					'id' => $id,
					'bucket' => $bucket,
					'title' => (string) ( $entry['title'] ?? $id ),
					'fontFamily' => (string) ( $entry['typography_font_family'] ?? '' ),
					'fontWeight' => (string) ( $entry['typography_font_weight'] ?? '' ),
					'fontSize' => $entry['typography_font_size'] ?? null,
				];
			}
		}
		return $out;
	}

	/**
	 * Every Kit control that looks like a font size, keyed by control name.
	 *
	 * Discovered from the runtime rather than assumed, because Elementor moves these between versions
	 * and the plugin never hardcodes a setting key.
	 *
	 * @return array<string,array>
	 */
	public function font_size_controls(): array {
		$out = [];
		foreach ( $this->read()['controls'] as $name => $control ) {
			if ( ! is_array( $control ) || ! str_contains( (string) $name, 'font_size' ) ) { continue; }
			$out[ (string) $name ] = $control;
		}
		return $out;
	}

	/** Does this control accept Elementor's `custom` unit, which is what carries a clamp() string? */
	public function supports_custom_unit( string $name ): bool {
		$units = $this->control( $name )['size_units'] ?? [];
		return is_array( $units ) && in_array( 'custom', array_map( 'strval', $units ), true );
	}
}
