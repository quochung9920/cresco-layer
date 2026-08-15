<?php
namespace CrescoLayer\SiteSettings\Support;

/**
 * Bridges Hello Theme's conditional Site Settings controls with Cresco's Kit pipeline.
 *
 * Hello registers controls that can be present in the Kit stack but inactive for the current
 * settings. Two cases matter to a round-trip verifier:
 *
 * - logo width only renders while the matching logo type is `logo`;
 * - typography children only render while their group starter is `custom`.
 *
 * Capability discovery must therefore hide a logo-width control whose unmanaged logo type makes it
 * inactive, while the save path must activate a Hello typography group whenever Cresco actually
 * writes one of that group's child controls.
 */
final class HelloControlBridge {
	/**
	 * Remove registered-but-inactive Hello logo width controls from the capability surface.
	 *
	 * Cresco intentionally does not own `*_logo_type`, so it must not force `title` to `logo` just to
	 * make a width setting writable. When the type is not `logo`, the correct operation is to skip the
	 * width and preserve the user's current branding mode.
	 */
	public static function filter_controls( array $controls, array $settings ): array {
		foreach ( [ 'hello_header', 'hello_footer' ] as $prefix ) {
			$width = $prefix . '_logo_width';
			$type = $prefix . '_logo_type';
			if ( ! isset( $controls[ $width ], $controls[ $type ] ) ) { continue; }

			$effective = self::effective_value( $type, $settings, $controls );
			if ( 'logo' !== (string) $effective ) {
				unset( $controls[ $width ] );
			}
		}
		return $controls;
	}

	/**
	 * Ensure Hello typography group starters are enabled before the Kit document sanitises the save.
	 *
	 * Elementor's Typography group makes every child conditional on `<group>_typography != ''`.
	 * Writing only `<group>_font_size` is therefore accepted into the payload but read back as inactive.
	 */
	public static function prepare_for_save( array $settings, array $controls ): array {
		$children = '(?:font_family|font_size|font_weight|text_transform|font_style|text_decoration|line_height|letter_spacing|word_spacing|weight|width)';
		foreach ( array_keys( $settings ) as $control ) {
			if ( ! is_string( $control ) ) { continue; }
			if ( ! preg_match( '/^(hello_(?:header|footer)_.+_typography)_' . $children . '$/', $control, $match ) ) { continue; }

			$starter = $match[1] . '_typography';
			if ( ! isset( $controls[ $starter ] ) ) { continue; }
			if ( '' === (string) ( $settings[ $starter ] ?? '' ) ) {
				$settings[ $starter ] = 'custom';
			}
		}
		return $settings;
	}

	private static function effective_value( string $control, array $settings, array $controls ) {
		if ( array_key_exists( $control, $settings ) ) { return $settings[ $control ]; }
		return $controls[ $control ]['default'] ?? null;
	}
}
