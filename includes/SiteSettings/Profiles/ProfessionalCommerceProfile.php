<?php
namespace CrescoLayer\SiteSettings\Profiles;

use CrescoLayer\SiteSettings\Contract\Spec;

/**
 * The `professional-commerce` baseline expressed as semantic intent.
 *
 * Fluid typography and spacing scale continuously, while layout uses five device contexts:
 * Mobile, Tablet, Laptop 13–14 inch, Desktop (base), and Widescreen / 4K.
 */
final class ProfessionalCommerceProfile {
	public const ID = 'professional-commerce';

	/** Fluid type/spacing range. Layout breakpoints continue beyond this range. */
	private const VIEWPORT_MIN = 320;
	private const VIEWPORT_MAX = 1440;

	public function spec(): array {
		return array_replace_recursive( Spec::skeleton(), [
			'profile' => self::ID,
			'mode' => Spec::MODE_MERGE,
			'fluid' => [
				'viewportMin' => self::VIEWPORT_MIN,
				'viewportMax' => self::VIEWPORT_MAX,
				'tokens' => $this->tokens(),
			],
			'designSystem' => [
				'colors' => $this->colors(),
				'typography' => $this->typography(),
			],
			'themeStyle' => [
				'typography' => $this->theme_typography(),
				'buttons' => $this->buttons(),
				'images' => $this->images(),
				'formFields' => $this->form_fields(),
				'helloHeader' => $this->hello_header(),
				'helloFooter' => $this->hello_footer(),
			],
			'settings' => [
				'siteIdentity' => [ 'preserve' => true ],
				'background' => [ 'bodyBackground' => 'surface', 'mobileBrowserBackground' => '#FFFFFF', 'overscroll' => 'auto' ],
				'layout' => $this->layout(),
				'lightbox' => $this->lightbox(),
				'pageTransitions' => [ 'preserve' => true ],
				'customCss' => [ 'manageFluidTokens' => true ],
			],
		] );
	}

	private function colors(): array {
		return [
			'system' => [
				'primary' => '#0F172A',
				'secondary' => '#475569',
				'text' => '#334155',
				'accent' => '#2563EB',
			],
			'custom' => [
				'surface' => [ 'title' => 'Surface', 'color' => '#FFFFFF' ],
				'surface-muted' => [ 'title' => 'Surface Muted', 'color' => '#F8FAFC' ],
				'muted' => [ 'title' => 'Muted', 'color' => '#64748B' ],
				'border' => [ 'title' => 'Border', 'color' => '#E2E8F0' ],
				'border-strong' => [ 'title' => 'Border Strong', 'color' => '#CBD5E1' ],
				'accent-hover' => [ 'title' => 'Accent Hover', 'color' => '#1D4ED8' ],
				'success' => [ 'title' => 'Success', 'color' => '#15803D' ],
				'warning' => [ 'title' => 'Warning', 'color' => '#B45309' ],
				'danger' => [ 'title' => 'Danger', 'color' => '#B91C1C' ],
				'on-dark' => [ 'title' => 'On Dark', 'color' => '#FFFFFF' ],
			],
		];
	}

	private function typography(): array {
		$family = 'Inter';
		return [
			'system' => [
				'primary' => [ 'font_family' => $family, 'font_weight' => '700' ],
				'secondary' => [ 'font_family' => $family, 'font_weight' => '600' ],
				'text' => [ 'font_family' => $family, 'font_weight' => '400' ],
				'accent' => [ 'font_family' => $family, 'font_weight' => '600' ],
			],
			'genericFonts' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif',
		];
	}

	/** Fluid custom properties for sites where Global Custom CSS is available. */
	private function tokens(): array {
		return [
			'--cresco-container-max' => '1400px',
			'--cresco-container-max-mobile' => '767px',
			'--cresco-container-max-tablet' => '960px',
			'--cresco-container-max-laptop' => '1200px',
			'--cresco-container-max-desktop' => '1400px',
			'--cresco-container-max-widescreen' => '1500px',
			'--cresco-gutter' => 'clamp(32px, 2.5vw, 48px)',
			'--cresco-gutter-mobile' => 'clamp(16px, 4vw, 20px)',
			'--cresco-gutter-tablet' => 'clamp(20px, 2.5vw, 28px)',
			'--cresco-gutter-laptop' => 'clamp(24px, 2.2vw, 32px)',
			'--cresco-gutter-desktop' => 'clamp(32px, 2.5vw, 48px)',
			'--cresco-gutter-widescreen' => 'clamp(48px, 3vw, 80px)',
			'--cresco-fs-display' => 'clamp(2.75rem, 1.95rem + 4vw, 5.5rem)',
			'--cresco-fs-h1' => 'clamp(2.25rem, 1.6rem + 2.6vw, 4rem)',
			'--cresco-fs-h2' => 'clamp(1.875rem, 1.45rem + 1.5vw, 2.75rem)',
			'--cresco-fs-h3' => 'clamp(1.5rem, 1.28rem + 0.85vw, 2rem)',
			'--cresco-fs-h4' => 'clamp(1.25rem, 1.13rem + 0.48vw, 1.5rem)',
			'--cresco-fs-h5' => 'clamp(1.125rem, 1.07rem + 0.22vw, 1.25rem)',
			'--cresco-fs-h6' => 'clamp(1rem, 0.95rem + 0.22vw, 1.125rem)',
			'--cresco-fs-body' => 'clamp(1rem, 0.96rem + 0.18vw, 1.125rem)',
			'--cresco-fs-small' => 'clamp(0.875rem, 0.85rem + 0.1vw, 0.9375rem)',
			'--cresco-space-2xs' => 'clamp(0.375rem, 0.34rem + 0.15vw, 0.5rem)',
			'--cresco-space-xs' => 'clamp(0.5rem, 0.43rem + 0.27vw, 0.75rem)',
			'--cresco-space-sm' => 'clamp(0.75rem, 0.68rem + 0.27vw, 1rem)',
			'--cresco-space-md' => 'clamp(1rem, 0.86rem + 0.71vw, 1.5rem)',
			'--cresco-space-lg' => 'clamp(1.5rem, 1.36rem + 0.71vw, 2rem)',
			'--cresco-space-xl' => 'clamp(2rem, 1.71rem + 1.43vw, 3rem)',
			'--cresco-space-2xl' => 'clamp(3rem, 2.57rem + 2.14vw, 4.5rem)',
			'--cresco-section-sm' => 'clamp(2.5rem, 2.07rem + 2.14vw, 4rem)',
			'--cresco-section' => 'clamp(3.5rem, 2.64rem + 4.29vw, 6.5rem)',
			'--cresco-section-lg' => 'clamp(4.5rem, 3.21rem + 6.43vw, 9rem)',
			'--cresco-control-py' => 'clamp(0.75rem, 0.71rem + 0.18vw, 0.875rem)',
			'--cresco-control-px' => 'clamp(1.125rem, 1.02rem + 0.54vw, 1.5rem)',
			'--cresco-card-padding' => 'clamp(1.25rem, 1.04rem + 1.07vw, 2rem)',
		];
	}

	private function theme_typography(): array {
		return [
			'body' => [
				'color' => 'text',
				'fluid' => 'clamp(1rem, 0.96rem + 0.18vw, 1.125rem)',
				'fallbackPx' => 17,
				'font_weight' => '400',
				'line_height' => 1.65,
				'letter_spacing' => 0,
			],
			'paragraphSpacing' => [ 'fluid' => 'clamp(0.875rem, 0.8rem + 0.3vw, 1.25rem)', 'fallbackPx' => 16 ],
			'link' => [ 'normalColor' => 'accent', 'hoverColor' => 'accent-hover' ],
			'headings' => [
				'h1' => [ 'color' => 'primary', 'fluid' => 'clamp(2.25rem, 1.6rem + 2.6vw, 4rem)', 'fallbackPx' => 48, 'font_weight' => '700', 'line_height' => 1.05, 'letter_spacing' => -0.02 ],
				'h2' => [ 'fluid' => 'clamp(1.875rem, 1.45rem + 1.5vw, 2.75rem)', 'fallbackPx' => 36, 'font_weight' => '700', 'line_height' => 1.10, 'letter_spacing' => -0.02 ],
				'h3' => [ 'fluid' => 'clamp(1.5rem, 1.28rem + 0.85vw, 2rem)', 'fallbackPx' => 28, 'font_weight' => '700', 'line_height' => 1.20, 'letter_spacing' => -0.015 ],
				'h4' => [ 'fluid' => 'clamp(1.25rem, 1.13rem + 0.48vw, 1.5rem)', 'fallbackPx' => 22, 'font_weight' => '600', 'line_height' => 1.25, 'letter_spacing' => -0.01 ],
				'h5' => [ 'fluid' => 'clamp(1.125rem, 1.07rem + 0.22vw, 1.25rem)', 'fallbackPx' => 19, 'font_weight' => '600', 'line_height' => 1.30 ],
				'h6' => [ 'fluid' => 'clamp(1rem, 0.95rem + 0.22vw, 1.125rem)', 'fallbackPx' => 17, 'font_weight' => '600', 'line_height' => 1.35 ],
			],
		];
	}

	private function buttons(): array {
		return [
			'typography' => [ 'fluid' => 'clamp(0.9375rem, 0.9rem + 0.15vw, 1rem)', 'fallbackPx' => 16, 'font_weight' => '600', 'line_height' => 1.2 ],
			'textColor' => '#FFFFFF',
			'background' => 'accent',
			'borderRadiusRem' => 0.625,
			'padding' => [
				'y' => 'clamp(0.75rem, 0.71rem + 0.18vw, 0.875rem)',
				'x' => 'clamp(1.125rem, 1.02rem + 0.54vw, 1.5rem)',
				'fallbackY' => 14,
				'fallbackX' => 22,
			],
			'hover' => [ 'textColor' => '#FFFFFF', 'background' => 'accent-hover' ],
		];
	}

	private function images(): array {
		return [ 'borderRadiusPx' => 0, 'opacity' => 1, 'hoverOpacity' => 1 ];
	}

	private function form_fields(): array {
		return [
			'label' => [ 'color' => 'primary', 'fluid' => 'clamp(0.875rem, 0.85rem + 0.1vw, 0.9375rem)', 'fallbackPx' => 15, 'font_weight' => '600' ],
			'field' => [ 'fontSizePx' => 16, 'line_height' => 1.5, 'textColor' => 'text', 'background' => 'surface', 'borderColor' => 'border-strong', 'borderWidthPx' => 1, 'borderRadiusRem' => 0.625 ],
			'padding' => [
				'y' => 'clamp(0.75rem, 0.71rem + 0.18vw, 0.875rem)',
				'x' => 'clamp(0.875rem, 0.78rem + 0.36vw, 1.125rem)',
				'fallbackY' => 14,
				'fallbackX' => 18,
			],
			'focus' => [ 'textColor' => 'text', 'background' => 'surface', 'borderColor' => 'accent', 'accentColor' => 'accent', 'transitionMs' => 160 ],
		];
	}

	private function content_widths(): array {
		return [
			'mobile' => 767,
			'tablet' => 960,
			'laptop' => 1200,
			'desktop' => 1400,
			'widescreen' => 1500,
		];
	}

	private function hello_header(): array {
		return [
			'logoDisplay' => 'yes',
			'taglineDisplay' => '',
			'menuDisplay' => 'yes',
			'width' => 'boxed',
			'contentWidthPx' => $this->content_widths(),
			'logoWidth' => [ 'fluid' => 'clamp(7.5rem, 6.65rem + 2.7vw, 10.5rem)', 'fallbackPx' => 160 ],
			'background' => 'surface',
			'menuLayout' => 'horizontal',
			'menuDropdown' => 'tablet',
			'menuColor' => 'primary',
			'menuTypography' => [ 'fluid' => 'clamp(0.9375rem, 0.9rem + 0.15vw, 1rem)', 'fallbackPx' => 16, 'font_weight' => '600' ],
		];
	}

	private function hello_footer(): array {
		return [
			'logoDisplay' => 'yes',
			'taglineDisplay' => '',
			'menuDisplay' => 'yes',
			'copyrightDisplay' => 'yes',
			'width' => 'boxed',
			'contentWidthPx' => $this->content_widths(),
			'logoWidth' => [ 'fluid' => 'clamp(7.5rem, 7rem + 1.8vw, 9.5rem)', 'fallbackPx' => 144 ],
			'background' => 'primary',
			'menuColor' => '#E2E8F0',
			'copyrightColor' => '#CBD5E1',
			'copyrightTypography' => [ 'fluid' => 'clamp(0.875rem, 0.85rem + 0.1vw, 0.9375rem)', 'fallbackPx' => 15 ],
		];
	}

	private function layout(): array {
		return [
			'contentWidthPx' => $this->content_widths(),
			'containerPadding' => [
				'mobile' => [ 'fluid' => 'clamp(16px, 4vw, 20px)', 'fallbackPx' => 18 ],
				'tablet' => [ 'fluid' => 'clamp(20px, 2.5vw, 28px)', 'fallbackPx' => 24 ],
				'laptop' => [ 'fluid' => 'clamp(24px, 2.2vw, 32px)', 'fallbackPx' => 28 ],
				'desktop' => [ 'fluid' => 'clamp(32px, 2.5vw, 48px)', 'fallbackPx' => 40 ],
				'widescreen' => [ 'fluid' => 'clamp(48px, 3vw, 80px)', 'fallbackPx' => 64 ],
			],
			'widgetGap' => [ 'fluid' => 'clamp(1rem, 0.86rem + 0.71vw, 1.5rem)', 'fallbackPx' => 20 ],
			'pageTitleSelector' => [ 'preserve' => true ],
			'stretchedSectionContainer' => [ 'preserve' => true ],
			'defaultPageTemplate' => [ 'preserve' => true ],
			'breakpoints' => [
				'mobile' => 767,
				'tablet' => 1024,
				'laptop' => 1440,
				'widescreen' => 1920,
			],
			// This profile intentionally standardises exactly five contexts. Desktop is implicit/base.
			'preserveExistingBreakpoints' => false,
		];
	}

	private function lightbox(): array {
		return [
			'enabled' => 'yes',
			'counter' => 'yes',
			'fullscreen' => 'yes',
			'zoom' => 'yes',
			'share' => '',
			'titleSrc' => '',
			'descriptionSrc' => '',
			'background' => 'rgba(15,23,42,.96)',
			'uiColor' => '#FFFFFF',
			'uiHoverColor' => '#CBD5E1',
			'textColor' => '#FFFFFF',
			'toolbarIcon' => [ 'fluid' => 'clamp(1.5rem, 1.35rem + 0.55vw, 2rem)', 'fallbackPx' => 26 ],
			'navigationIcon' => [ 'fluid' => 'clamp(2rem, 1.7rem + 1vw, 3rem)', 'fallbackPx' => 40 ],
		];
	}
}
