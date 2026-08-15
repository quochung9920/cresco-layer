<?php
namespace CrescoLayer\SiteSettings\Layout;

/**
 * Single source of truth for Cresco's five-context responsive layout foundation.
 *
 * Elementor Site Settings own structural breakpoints and content max-widths. The page-level gutter
 * is intentionally NOT stored as the global Container Padding because Elementor applies that value
 * to every `.e-con`, including nested containers. Cresco therefore keeps the global default at zero
 * and exposes the gutter as semantic policy for page/section shells managed by Layer patches.
 */
final class ResponsiveLayoutPolicy {
	public const ID = 'cresco-responsive-foundation/v2';
	public const ROLE_POLICY = 'cresco-container-role-policy/v1';
	public const MIGRATION_POLICY = 'block-if-used';

	/** @return string[] Layout contexts in visual order from narrow to wide. */
	public static function devices(): array {
		return [ 'mobile', 'tablet', 'laptop', 'desktop', 'widescreen' ];
	}

	/**
	 * CSS-pixel contexts. Desktop is Elementor's implicit/base context; Widescreen is min-width.
	 *
	 * @return array<string,array{min:int,max:?int,direction:string,breakpoint:?int}>
	 */
	public static function contexts(): array {
		return [
			'mobile' => [ 'min' => 320, 'max' => 767, 'direction' => 'max', 'breakpoint' => 767 ],
			'tablet' => [ 'min' => 768, 'max' => 1024, 'direction' => 'max', 'breakpoint' => 1024 ],
			'laptop' => [ 'min' => 1025, 'max' => 1440, 'direction' => 'max', 'breakpoint' => 1440 ],
			'desktop' => [ 'min' => 1441, 'max' => 1919, 'direction' => 'base', 'breakpoint' => null ],
			'widescreen' => [ 'min' => 1920, 'max' => null, 'direction' => 'min', 'breakpoint' => 1920 ],
		];
	}

	/** @return array<string,int> Elementor breakpoint keys excluding implicit Desktop. */
	public static function breakpoints(): array {
		return [
			'mobile' => 767,
			'tablet' => 1024,
			'laptop' => 1440,
			'widescreen' => 1920,
		];
	}

	/** @return string[] Elementor Kit values for active_breakpoints. */
	public static function active_breakpoint_controls(): array {
		return [ 'viewport_mobile', 'viewport_tablet', 'viewport_laptop', 'viewport_widescreen' ];
	}

	/** @return array<string,int> Native px content max-width per responsive context. */
	public static function content_widths(): array {
		return [
			'mobile' => 767,
			'tablet' => 960,
			'laptop' => 1180,
			'desktop' => 1320,
			'widescreen' => 1500,
		];
	}

	/**
	 * Horizontal page/section-shell gutter. This is page-level policy, not global Kit padding.
	 *
	 * @return array<string,array{fluid:string,fallbackPx:int}>
	 */
	public static function page_gutters(): array {
		return [
			'mobile' => [ 'fluid' => 'clamp(16px, 4vw, 20px)', 'fallbackPx' => 18 ],
			'tablet' => [ 'fluid' => 'clamp(20px, 2.5vw, 28px)', 'fallbackPx' => 24 ],
			'laptop' => [ 'fluid' => 'clamp(24px, 2.2vw, 32px)', 'fallbackPx' => 28 ],
			'desktop' => [ 'fluid' => 'clamp(32px, 2.5vw, 48px)', 'fallbackPx' => 40 ],
			'widescreen' => [ 'fluid' => 'clamp(48px, 3vw, 80px)', 'fallbackPx' => 64 ],
		];
	}

	/**
	 * Elementor's global Container Padding must stay explicit zero on every context. Without a stored
	 * value Elementor falls back to 10px; with a non-zero value every nested `.e-con` receives it.
	 *
	 * @return array<string,array{fixedPx:int}>
	 */
	public static function global_container_padding(): array {
		$out = [];
		foreach ( self::devices() as $device ) {
			$out[ $device ] = [ 'fixedPx' => 0 ];
		}
		return $out;
	}

	/** Human/machine contract consumed by Site Settings and Layer patch tooling. */
	public static function layout_contract(): array {
		return [
			'policy' => self::ID,
			'requiredDevices' => self::devices(),
			'contexts' => self::contexts(),
			'breakpoints' => self::breakpoints(),
			'contentWidthPx' => self::content_widths(),
			'containerPadding' => self::global_container_padding(),
			'pageGutter' => self::page_gutters(),
			'containerRolePolicy' => self::ROLE_POLICY,
			'breakpointMigrationPolicy' => self::MIGRATION_POLICY,
			'preserveExistingBreakpoints' => false,
		];
	}

	/** Upgrade an existing semantic profile spec without discarding unrelated layout/theme settings. */
	public static function apply_to_spec( array $spec ): array {
		$existing_layout = is_array( $spec['settings']['layout'] ?? null ) ? $spec['settings']['layout'] : [];
		$spec['settings']['layout'] = array_replace( $existing_layout, self::layout_contract() );

		foreach ( [ 'helloHeader', 'helloFooter' ] as $section ) {
			if ( ! isset( $spec['themeStyle'][ $section ] ) || ! is_array( $spec['themeStyle'][ $section ] ) ) { continue; }
			$spec['themeStyle'][ $section ]['contentWidthPx'] = self::content_widths();
		}

		$tokens = is_array( $spec['fluid']['tokens'] ?? null ) ? $spec['fluid']['tokens'] : [];
		$spec['fluid']['tokens'] = array_replace( $tokens, self::token_map() );
		return $spec;
	}

	/** CSS variables are convenience tokens only; native Elementor controls remain authoritative. */
	public static function token_map(): array {
		$tokens = [ '--cresco-container-max' => '1320px', '--cresco-gutter' => self::page_gutters()['desktop']['fluid'] ];
		foreach ( self::content_widths() as $device => $width ) {
			$tokens[ '--cresco-container-max-' . $device ] = $width . 'px';
		}
		foreach ( self::page_gutters() as $device => $gutter ) {
			$tokens[ '--cresco-gutter-' . $device ] = $gutter['fluid'];
		}
		return $tokens;
	}
}
