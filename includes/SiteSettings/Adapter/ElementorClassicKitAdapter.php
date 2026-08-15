<?php
namespace CrescoLayer\SiteSettings\Adapter;

use CrescoLayer\SiteSettings\Contract\Spec;
use CrescoLayer\SiteSettings\Discovery\CapabilityReport;
use CrescoLayer\SiteSettings\Gateway\KitGateway;
use CrescoLayer\SiteSettings\Registry\OwnershipRegistry;
use CrescoLayer\SiteSettings\Support\ManagedCssBlock;
use CrescoLayer\SiteSettings\Support\ValueFactory;

/**
 * Maps a Cresco spec onto the Classic (Kit-based) Elementor Site Settings.
 *
 * Every write is gated on capability discovery: a control that the running Elementor does not
 * register is recorded as skipped rather than written under a key nothing reads. Optional surfaces
 * — Hello Theme header/footer, Elementor Pro custom CSS, page transitions — are detected the same
 * way, so a Free install on a non-Hello theme still gets a complete Site Settings pass.
 *
 * Colours resolve semantically: the spec says `accent`, and this decides whether that means a
 * system slot, a Cresco-owned custom global, or a literal value.
 */
final class ElementorClassicKitAdapter implements SiteSettingsAdapter {
	private CapabilityReport $capabilities;
	private array $current;
	private array $skipped = [];
	private array $notes = [];
	private array $preserved = [];
	/** Elementor control name => Cresco semantic path, so a mismatch can be reported in profile terms. */
	private array $paths = [];
	/** Semantic colour key => resolved literal, built while mapping the palette. */
	private array $palette = [];

	/**
	 * Semantic names for controls written directly rather than through a put_* helper. A control with
	 * no entry falls back to its own name, so the plan is never incomplete — verification scope is
	 * derived from it, and a missing entry would silently drop a control from being checked.
	 */
	private const DIRECT_PATHS = [
		'system_colors' => 'designSystem.colors.system',
		'custom_colors' => 'designSystem.colors.custom',
		'system_typography' => 'designSystem.typography.system',
		'default_generic_fonts' => 'designSystem.typography.genericFonts',
		'body_background_background' => 'settings.background.bodyBackground',
		'body_background_color' => 'settings.background.bodyBackground',
		'container_width' => 'settings.layout.contentWidth',
		'container_padding' => 'settings.layout.containerPadding',
		'active_breakpoints' => 'settings.layout.breakpoints.active',
		'viewport_mobile' => 'settings.layout.breakpoints.mobile',
		'viewport_tablet' => 'settings.layout.breakpoints.tablet',
		'custom_css' => 'settings.customCss.fluidTokens',
	];

	public function __construct(
		private KitGateway $gateway,
		private OwnershipRegistry $registry,
		private ValueFactory $factory,
		private ManagedCssBlock $css
	) {
		$this->capabilities = new CapabilityReport( $gateway );
		$this->current = $gateway->settings();
	}

	public function id(): string { return 'elementor-classic'; }

	public function supports(): bool {
		// Global colours are the one surface every Classic Kit registers; without them this is not a
		// Classic Kit and a different adapter is needed.
		return $this->capabilities->has( 'system_colors' );
	}

	public function build( array $spec ): array {
		$this->skipped = [];
		$this->notes = [];
		$this->preserved = [];
		$this->paths = [];
		$settings = [];

		$this->map_colors( $spec, $settings );
		$this->map_global_typography( $spec, $settings );
		$this->map_theme_typography( $spec, $settings );
		$this->map_buttons( $spec, $settings );
		$this->map_images( $spec, $settings );
		$this->map_form_fields( $spec, $settings );
		$this->map_background( $spec, $settings );
		$this->map_layout( $spec, $settings );
		$this->map_lightbox( $spec, $settings );
		$this->map_hello( $spec, $settings );
		$this->map_custom_css( $spec, $settings );

		return [
			'settings' => $settings,
			'plan' => $this->plan( $settings ),
			'skipped' => $this->skipped,
			'preserved' => $this->preserved,
			'notes' => $this->notes,
		];
	}

	/**
	 * One entry per control Cresco is actually writing, carrying the semantic name and the runtime
	 * control type. This is the verification scope: built from what was written rather than from what
	 * the profile hoped to write, so an unsupported or preserved control can never enter it.
	 */
	private function plan( array $settings ): array {
		$plan = [];
		foreach ( $settings as $control => $value ) {
			$control = (string) $control;
			$plan[] = [
				'semanticPath' => $this->paths[ $control ] ?? self::DIRECT_PATHS[ $control ] ?? $control,
				'control' => $control,
				'controlType' => (string) ( $this->capabilities->control( $control )['type'] ?? '' ),
				'value' => $value,
			];
		}
		return $plan;
	}

	private function path( string $control, string $semantic ): void {
		if ( '' === $semantic ) { return; }
		$this->paths[ $control ] = $semantic;
	}

	public function capabilities(): CapabilityReport { return $this->capabilities; }

	/* ---------------------------------------------------------------- colours */

	private function map_colors( array $spec, array &$settings ): void {
		$colors = $spec['designSystem']['colors'] ?? [];
		if ( ! $colors ) { return; }

		if ( $this->capabilities->has( 'system_colors' ) ) {
			$rows = $this->rows( 'system_colors' );
			foreach ( (array) ( $colors['system'] ?? [] ) as $id => $value ) {
				if ( ! in_array( $id, Spec::SYSTEM_COLOR_IDS, true ) ) { continue; }
				$rows = $this->upsert_row( $rows, (string) $id, [ 'color' => strtoupper( (string) $value ) ], ucfirst( (string) $id ) );
				$this->palette[ $id ] = strtoupper( (string) $value );
			}
			$settings['system_colors'] = $rows;
		} else {
			$this->skip( 'designSystem.colors.system', 'unsupported_control' );
		}

		if ( ! $this->capabilities->has( 'custom_colors' ) ) {
			if ( ! empty( $colors['custom'] ) ) { $this->skip( 'designSystem.colors.custom', 'unsupported_control' ); }
			return;
		}

		$rows = $this->rows( 'custom_colors' );
		foreach ( (array) ( $colors['custom'] ?? [] ) as $key => $definition ) {
			$key = (string) $key;
			$value = strtoupper( (string) ( $definition['color'] ?? '' ) );
			$title = (string) ( $definition['title'] ?? ucfirst( $key ) );
			if ( '' === $value ) { continue; }

			$id = $this->stable_custom_id( 'colors', $key, $rows, $title );
			$rows = $this->upsert_row( $rows, $id, [ 'color' => $value ], $title );
			$this->palette[ $key ] = $value;
		}
		$settings['custom_colors'] = $rows;
	}

	/**
	 * Reuse the ID Cresco created before; otherwise adopt an existing row with the same title so a
	 * palette that already contains "Surface" is updated rather than duplicated; otherwise mint one.
	 */
	private function stable_custom_id( string $bucket, string $key, array $rows, string $title ): string {
		$known = $this->registry->id_for( $bucket, $key );
		if ( $known && $this->row_index( $rows, $known ) !== null ) { return $known; }

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			if ( strcasecmp( (string) ( $row['title'] ?? '' ), $title ) === 0 ) {
				$id = (string) ( $row['_id'] ?? '' );
				if ( '' !== $id ) {
					$this->notes[] = [ 'key' => $bucket . '.' . $key, 'note' => 'adopted_existing_global', 'id' => $id ];
					return $id;
				}
			}
		}
		return $known ?: $this->registry->generate_id();
	}

	/* ------------------------------------------------------------ typography */

	private function map_global_typography( array $spec, array &$settings ): void {
		$typography = $spec['designSystem']['typography'] ?? [];
		if ( ! $typography ) { return; }

		if ( $this->capabilities->has( 'system_typography' ) ) {
			$rows = $this->rows( 'system_typography' );
			foreach ( (array) ( $typography['system'] ?? [] ) as $id => $properties ) {
				if ( ! in_array( $id, Spec::SYSTEM_TYPOGRAPHY_IDS, true ) ) { continue; }
				$patch = [ 'typography_typography' => 'custom' ];
				foreach ( (array) $properties as $property => $value ) {
					$patch[ 'typography_' . $property ] = $value;
				}
				$rows = $this->upsert_row( $rows, (string) $id, $patch, ucfirst( (string) $id ) );
			}
			$settings['system_typography'] = $rows;
		} else {
			$this->skip( 'designSystem.typography.system', 'unsupported_control' );
		}

		$generic = (string) ( $typography['genericFonts'] ?? '' );
		if ( '' !== $generic && $this->capabilities->has( 'default_generic_fonts' ) ) {
			$settings['default_generic_fonts'] = $generic;
		}
	}

	private function map_theme_typography( array $spec, array &$settings ): void {
		$typography = $spec['themeStyle']['typography'] ?? [];
		if ( ! $typography ) { return; }

		$body = $typography['body'] ?? [];
		if ( $body ) {
			$this->put_color( $settings, 'body_color', $body['color'] ?? null, 'themeStyle.typography.body.color' );
			$this->put_font_size( $settings, 'body_typography_font_size', $body, 'themeStyle.typography.body' );
			$this->put_scalar( $settings, 'body_typography_font_weight', $body['font_weight'] ?? null );
			$this->put_number_unit( $settings, 'body_typography_line_height', $body['line_height'] ?? null, 'em' );
			$this->put_number_unit( $settings, 'body_typography_letter_spacing', $body['letter_spacing'] ?? null, 'px' );
			if ( isset( $settings['body_typography_font_size'] ) || isset( $settings['body_typography_font_weight'] ) ) {
				$settings['body_typography_typography'] = 'custom';
			}
		}

		$spacing = $typography['paragraphSpacing'] ?? [];
		if ( $spacing ) { $this->put_font_size( $settings, 'paragraph_spacing', $spacing, 'themeStyle.typography.paragraphSpacing' ); }

		$link = $typography['link'] ?? [];
		if ( $link ) {
			$this->put_color( $settings, 'link_normal_color', $link['normalColor'] ?? null, 'themeStyle.typography.link.normal' );
			$this->put_color( $settings, 'link_hover_color', $link['hoverColor'] ?? null, 'themeStyle.typography.link.hover' );
		}

		foreach ( (array) ( $typography['headings'] ?? [] ) as $tag => $definition ) {
			$prefix = (string) $tag;
			$this->put_color( $settings, $prefix . '_color', $definition['color'] ?? null, 'themeStyle.typography.' . $prefix . '.color' );
			$this->put_font_size( $settings, $prefix . '_typography_font_size', $definition, 'themeStyle.typography.' . $prefix );
			$this->put_scalar( $settings, $prefix . '_typography_font_weight', $definition['font_weight'] ?? null );
			$this->put_number_unit( $settings, $prefix . '_typography_line_height', $definition['line_height'] ?? null, 'em' );
			$this->put_number_unit( $settings, $prefix . '_typography_letter_spacing', $definition['letter_spacing'] ?? null, 'em' );
			if ( isset( $settings[ $prefix . '_typography_font_size' ] ) || isset( $settings[ $prefix . '_typography_font_weight' ] ) ) {
				$settings[ $prefix . '_typography_typography' ] = 'custom';
			}
		}
	}

	/* --------------------------------------------------------------- buttons */

	private function map_buttons( array $spec, array &$settings ): void {
		$buttons = $spec['themeStyle']['buttons'] ?? [];
		if ( ! $buttons ) { return; }

		$typography = $buttons['typography'] ?? [];
		if ( $typography ) {
			$this->put_font_size( $settings, 'button_typography_font_size', $typography, 'themeStyle.buttons.typography' );
			$this->put_scalar( $settings, 'button_typography_font_weight', $typography['font_weight'] ?? null );
			$this->put_number_unit( $settings, 'button_typography_line_height', $typography['line_height'] ?? null, 'em' );
			if ( isset( $settings['button_typography_font_size'] ) ) { $settings['button_typography_typography'] = 'custom'; }
		}

		$this->put_color( $settings, 'button_text_color', $buttons['textColor'] ?? null, 'themeStyle.buttons.textColor' );
		$this->put_color( $settings, 'button_background_color', $buttons['background'] ?? null, 'themeStyle.buttons.background' );
		$this->put_color( $settings, 'button_hover_text_color', $buttons['hover']['textColor'] ?? null, 'themeStyle.buttons.hover.textColor' );
		$this->put_color( $settings, 'button_hover_background_color', $buttons['hover']['background'] ?? null, 'themeStyle.buttons.hover.background' );

		if ( isset( $buttons['borderRadiusRem'] ) ) {
			$this->put_radius( $settings, 'button_border_radius', (float) $buttons['borderRadiusRem'], 'themeStyle.buttons.borderRadius' );
		}

		$padding = $buttons['padding'] ?? [];
		if ( $padding ) { $this->put_padding( $settings, 'button_padding', $padding, 'themeStyle.buttons.padding' ); }
	}

	/* ---------------------------------------------------------------- images */

	private function map_images( array $spec, array &$settings ): void {
		$images = $spec['themeStyle']['images'] ?? [];
		if ( ! $images ) { return; }
		if ( isset( $images['borderRadiusPx'] ) ) {
			$this->put_radius_px( $settings, 'image_border_radius', (float) $images['borderRadiusPx'], 'themeStyle.images.borderRadius' );
		}
		$this->put_number_unit( $settings, 'image_opacity', $images['opacity'] ?? null, '' );
		$this->put_number_unit( $settings, 'image_hover_opacity', $images['hoverOpacity'] ?? null, '' );
	}

	/* ----------------------------------------------------------- form fields */

	private function map_form_fields( array $spec, array &$settings ): void {
		$form = $spec['themeStyle']['formFields'] ?? [];
		if ( ! $form ) { return; }

		$label = $form['label'] ?? [];
		if ( $label ) {
			$this->put_color( $settings, 'form_label_color', $label['color'] ?? null, 'themeStyle.formFields.label.color' );
			$this->put_font_size( $settings, 'form_label_typography_font_size', $label, 'themeStyle.formFields.label' );
			$this->put_scalar( $settings, 'form_label_typography_font_weight', $label['font_weight'] ?? null );
			if ( isset( $settings['form_label_typography_font_size'] ) ) { $settings['form_label_typography_typography'] = 'custom'; }
		}

		$field = $form['field'] ?? [];
		if ( $field ) {
			// A fixed 16px floor, never fluid: below it iOS zooms the viewport when a field is focused.
			$this->put_size_px( $settings, 'form_field_typography_font_size', (float) ( $field['fontSizePx'] ?? 16 ) );
			$this->put_number_unit( $settings, 'form_field_typography_line_height', $field['line_height'] ?? null, 'em' );
			if ( isset( $settings['form_field_typography_font_size'] ) ) { $settings['form_field_typography_typography'] = 'custom'; }

			$this->put_color( $settings, 'form_field_text_color', $field['textColor'] ?? null, 'themeStyle.formFields.field.textColor' );
			$this->put_color( $settings, 'form_field_background_color', $field['background'] ?? null, 'themeStyle.formFields.field.background' );
			if ( isset( $field['borderRadiusRem'] ) ) {
				$this->put_radius( $settings, 'form_field_border_radius', (float) $field['borderRadiusRem'], 'themeStyle.formFields.field.borderRadius' );
			}
		}

		$padding = $form['padding'] ?? [];
		if ( $padding ) { $this->put_padding( $settings, 'form_field_padding', $padding, 'themeStyle.formFields.padding' ); }

		$focus = $form['focus'] ?? [];
		if ( $focus ) {
			$this->put_color( $settings, 'form_field_focus_text_color', $focus['textColor'] ?? null, 'themeStyle.formFields.focus.textColor' );
			$this->put_color( $settings, 'form_field_focus_background_color', $focus['background'] ?? null, 'themeStyle.formFields.focus.background' );
			$this->put_color( $settings, 'form_field_focus_accent_color', $focus['accentColor'] ?? null, 'themeStyle.formFields.focus.accentColor' );
			if ( isset( $focus['transitionMs'] ) ) {
				$this->put_number_unit( $settings, 'form_field_focus_transition_duration', (int) $focus['transitionMs'], 'px' );
			}
		}
	}

	/* ------------------------------------------------------------ background */

	private function map_background( array $spec, array &$settings ): void {
		$background = $spec['settings']['background'] ?? [];
		if ( ! $background ) { return; }
		// Classic uses a background group control, so the type must be set alongside the colour.
		if ( $this->capabilities->has( 'body_background_color' ) ) {
			$value = $this->resolve_color( $background['bodyBackground'] ?? null );
			if ( null !== $value ) {
				$settings['body_background_background'] = 'classic';
				$settings['body_background_color'] = $value;
			}
		} else {
			$this->skip( 'settings.background.bodyBackground', 'unsupported_control' );
		}
		$this->put_scalar( $settings, 'mobile_browser_background', $background['mobileBrowserBackground'] ?? null );
		$this->put_scalar( $settings, 'body_overscroll_behavior', $background['overscroll'] ?? null );
	}

	/* ---------------------------------------------------------------- layout */

	private function map_layout( array $spec, array &$settings ): void {
		$layout = $spec['settings']['layout'] ?? [];
		if ( ! $layout ) { return; }

		if ( $this->capabilities->has( 'container_width' ) ) {
			if ( $this->capabilities->supports_unit( 'container_width', 'rem' ) ) {
				$settings['container_width'] = $this->factory->slider_shape( 'rem', (float) ( $layout['contentWidthRem'] ?? 82 ) );
			} else {
				$settings['container_width'] = $this->factory->slider_shape( 'px', (float) ( $layout['contentWidthPxFallback'] ?? 1312 ) );
				$this->notes[] = [ 'key' => 'settings.layout.contentWidth', 'note' => 'rem_unsupported_used_px' ];
			}
		} else {
			$this->skip( 'settings.layout.contentWidth', 'unsupported_control' );
		}

		if ( isset( $layout['containerPaddingPx'] ) && $this->capabilities->has( 'container_padding' ) ) {
			$padding = (float) $layout['containerPaddingPx'];
			$settings['container_padding'] = [
				'unit' => 'px', 'top' => (string) $padding, 'right' => (string) $padding,
				'bottom' => (string) $padding, 'left' => (string) $padding, 'isLinked' => true,
			];
		}

		$gap = $layout['widgetGap'] ?? [];
		if ( $gap ) { $this->put_font_size( $settings, 'space_between_widgets', $gap, 'settings.layout.widgetGap' ); }

		$this->map_breakpoints( $layout, $settings );

		// Page title selector, stretched container and default page template are preserve-by-default;
		// forcing a template can strip a theme's header/footer or break WooCommerce templates.
		foreach ( [ 'pageTitleSelector', 'stretchedSectionContainer', 'defaultPageTemplate' ] as $key ) {
			if ( ! empty( $layout[ $key ]['preserve'] ) ) {
				// Preserved values were never requested, so they must not enter the verification scope.
				$this->preserved[] = [ 'key' => 'settings.layout.' . $key, 'reason' => 'preserved_by_profile' ];
			}
		}
	}

	/**
	 * Breakpoints are structural, so the profile only guarantees mobile and tablet exist with sane
	 * values. Any breakpoint the site already activated is kept: turning one off would silently drop
	 * every responsive override authored against it.
	 */
	private function map_breakpoints( array $layout, array &$settings ): void {
		if ( empty( $layout['breakpoints'] ) ) { return; }

		if ( $this->capabilities->has( 'active_breakpoints' ) && ! empty( $layout['preserveExistingBreakpoints'] ) ) {
			$active = array_values( array_unique( array_map( 'strval', (array) ( $this->current['active_breakpoints'] ?? [] ) ) ) );
			foreach ( array_keys( $layout['breakpoints'] ) as $device ) {
				$key = 'viewport_' . $device;
				if ( ! in_array( $key, $active, true ) ) { $active[] = $key; }
			}
			$settings['active_breakpoints'] = $active;
		}

		foreach ( $layout['breakpoints'] as $device => $value ) {
			$control = 'viewport_' . $device;
			if ( ! $this->capabilities->has( $control ) ) { $this->skip( 'settings.layout.breakpoints.' . $device, 'unsupported_control' ); continue; }
			$settings[ $control ] = (int) $value;
		}
	}

	/* -------------------------------------------------------------- lightbox */

	private function map_lightbox( array $spec, array &$settings ): void {
		$lightbox = $spec['settings']['lightbox'] ?? [];
		if ( ! $lightbox ) { return; }
		if ( ! $this->capabilities->has( 'global_image_lightbox' ) ) { $this->skip( 'settings.lightbox', 'unsupported_control' ); return; }

		$map = [
			'global_image_lightbox' => 'enabled',
			'lightbox_enable_counter' => 'counter',
			'lightbox_enable_fullscreen' => 'fullscreen',
			'lightbox_enable_zoom' => 'zoom',
			'lightbox_enable_share' => 'share',
			'lightbox_title_src' => 'titleSrc',
			'lightbox_description_src' => 'descriptionSrc',
			'lightbox_color' => 'background',
			'lightbox_ui_color' => 'uiColor',
			'lightbox_ui_color_hover' => 'uiHoverColor',
			'lightbox_text_color' => 'textColor',
		];
		foreach ( $map as $control => $key ) {
			if ( ! array_key_exists( $key, $lightbox ) ) { continue; }
			$this->put_scalar( $settings, $control, $lightbox[ $key ] );
		}

		foreach ( [ 'lightbox_icons_size' => 'toolbarIcon', 'lightbox_slider_icons_size' => 'navigationIcon' ] as $control => $key ) {
			if ( empty( $lightbox[ $key ] ) ) { continue; }
			$this->put_font_size( $settings, $control, $lightbox[ $key ], 'settings.lightbox.' . $key, 'em' );
		}
	}

	/* ----------------------------------------------------------- hello theme */

	private function map_hello( array $spec, array &$settings ): void {
		foreach ( [ 'helloHeader' => 'hello_header', 'helloFooter' => 'hello_footer' ] as $section => $prefix ) {
			$definition = $spec['themeStyle'][ $section ] ?? [];
			if ( ! $definition ) { continue; }

			// Hello header/footer controls exist only when the Hello theme registers that tab.
			if ( ! $this->capabilities->has( $prefix . '_layout' ) && ! $this->capabilities->has( $prefix . '_logo_display' ) ) {
				$this->skip( 'themeStyle.' . $section, 'tab_not_registered' );
				continue;
			}

			$scalars = [
				'logoDisplay' => '_logo_display', 'taglineDisplay' => '_tagline_display',
				'menuDisplay' => '_menu_display', 'copyrightDisplay' => '_copyright_display',
				'width' => '_width', 'menuLayout' => '_menu_layout', 'menuDropdown' => '_menu_dropdown',
			];
			foreach ( $scalars as $key => $suffix ) {
				if ( ! array_key_exists( $key, $definition ) ) { continue; }
				$this->put_scalar( $settings, $prefix . $suffix, $definition[ $key ] );
			}

			if ( isset( $definition['contentWidthRem'] ) ) {
				$control = $prefix . '_custom_width';
				if ( $this->capabilities->has( $control ) ) {
					$unit = $this->capabilities->supports_unit( $control, 'rem' ) ? 'rem' : 'px';
					$size = 'rem' === $unit ? (float) $definition['contentWidthRem'] : (float) $definition['contentWidthRem'] * 16;
					$settings[ $control ] = $this->factory->slider_shape( $unit, $size );
				}
			}
			if ( ! empty( $definition['logoWidth'] ) ) {
				$this->put_font_size( $settings, $prefix . '_logo_width', $definition['logoWidth'], 'themeStyle.' . $section . '.logoWidth' );
			}
			$this->put_color( $settings, $prefix . '_menu_color', $definition['menuColor'] ?? null, 'themeStyle.' . $section . '.menuColor' );
			$this->put_color( $settings, $prefix . '_copyright_color', $definition['copyrightColor'] ?? null, 'themeStyle.' . $section . '.copyrightColor' );

			if ( ! empty( $definition['background'] ) && $this->capabilities->has( $prefix . '_background_color' ) ) {
				$value = $this->resolve_color( $definition['background'] );
				if ( null !== $value ) {
					$settings[ $prefix . '_background_background' ] = 'classic';
					$settings[ $prefix . '_background_color' ] = $value;
				}
			}
			if ( ! empty( $definition['menuTypography'] ) ) {
				$this->put_font_size( $settings, $prefix . '_menu_typography_font_size', $definition['menuTypography'], 'themeStyle.' . $section . '.menuTypography' );
				$this->put_scalar( $settings, $prefix . '_menu_typography_font_weight', $definition['menuTypography']['font_weight'] ?? null );
			}
			if ( ! empty( $definition['copyrightTypography'] ) ) {
				$this->put_font_size( $settings, $prefix . '_copyright_typography_font_size', $definition['copyrightTypography'], 'themeStyle.' . $section . '.copyrightTypography' );
			}
			// Copyright text is site content, never a generic preset value.
		}
	}

	/* ------------------------------------------------------------ custom css */

	private function map_custom_css( array $spec, array &$settings ): void {
		if ( empty( $spec['settings']['customCss']['manageFluidTokens'] ) ) { return; }
		$tokens = (array) ( $spec['fluid']['tokens'] ?? [] );
		if ( ! $tokens ) { return; }

		// Custom CSS is an Elementor Pro control; without it the tokens simply are not published and
		// every mapped value falls back to its own inline clamp() instead.
		if ( ! $this->capabilities->has( 'custom_css' ) ) {
			$this->skip( 'settings.customCss', 'unsupported_control' );
			return;
		}

		$safe = [];
		foreach ( $tokens as $name => $value ) {
			$rejection = $this->factory->clamp_rejection( (string) $value );
			if ( null !== $rejection ) {
				$this->skip( 'fluid.tokens.' . $name, 'invalid_value:' . $rejection );
				continue;
			}
			$safe[ (string) $name ] = (string) $value;
		}
		if ( ! $safe ) { return; }

		$existing = (string) ( $this->current['custom_css'] ?? '' );
		$settings['custom_css'] = $this->css->write( $existing, $this->css->render_tokens( $safe ) );
	}

	/* ----------------------------------------------------------------- utils */

	private function rows( string $control ): array {
		$rows = $this->current[ $control ] ?? [];
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : [];
	}

	private function row_index( array $rows, string $id ): ?int {
		foreach ( $rows as $index => $row ) {
			if ( is_array( $row ) && (string) ( $row['_id'] ?? '' ) === $id ) { return (int) $index; }
		}
		return null;
	}

	/**
	 * Update a repeater row in place, or append it. Unknown keys on the existing row are preserved,
	 * because a third-party addon may have added fields Cresco knows nothing about.
	 */
	private function upsert_row( array $rows, string $id, array $patch, string $title ): array {
		$index = $this->row_index( $rows, $id );
		if ( null === $index ) {
			$rows[] = array_merge( [ '_id' => $id, 'title' => $title ], $patch );
			return $rows;
		}
		$rows[ $index ] = array_merge( $rows[ $index ], $patch );
		if ( '' === (string) ( $rows[ $index ]['title'] ?? '' ) ) { $rows[ $index ]['title'] = $title; }
		return $rows;
	}

	/** A semantic colour key resolves through the palette; a literal passes through unchanged. */
	private function resolve_color( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) { return null; }
		if ( isset( $this->palette[ $value ] ) ) { return $this->palette[ $value ]; }
		if ( preg_match( '/^(#|rgb|hsl)/i', $value ) ) { return $value; }
		return null;
	}

	private function put_color( array &$settings, string $control, $value, string $key ): void {
		if ( null === $value || '' === $value ) { return; }
		if ( ! $this->capabilities->has( $control ) ) { if ( '' !== $key ) { $this->skip( $key, 'unsupported_control' ); } return; }
		$resolved = $this->resolve_color( $value );
		if ( null === $resolved ) { $this->skip( $key, 'unresolved_color' ); return; }
		$this->path( $control, $key );
		$settings[ $control ] = $resolved;
	}

	private function put_scalar( array &$settings, string $control, $value ): void {
		if ( null === $value ) { return; }
		if ( ! $this->capabilities->has( $control ) ) { return; }
		if ( is_string( $value ) && ! $this->capabilities->allows_option( $control, $value ) ) {
			$this->skip( $control, 'invalid_option' );
			return;
		}
		$settings[ $control ] = $value;
	}

	/** An empty $unit means the control has no unit switcher; leave the key to Elementor's default. */
	private function put_number_unit( array &$settings, string $control, $value, string $unit ): void {
		if ( null === $value ) { return; }
		if ( ! $this->capabilities->has( $control ) ) { return; }
		$shape = $this->factory->slider_shape( $unit, (float) $value );
		if ( '' === $unit ) { unset( $shape['unit'] ); }
		$settings[ $control ] = $shape;
	}

	private function put_size_px( array &$settings, string $control, float $px ): void {
		if ( ! $this->capabilities->has( $control ) ) { return; }
		$settings[ $control ] = $this->factory->slider_shape( 'px', $px );
	}

	/**
	 * A size that should be fluid when possible. `fluid` is used with the custom unit; `fallbackPx`
	 * is written when the control has no custom unit, so the value is never simply lost.
	 */
	private function put_font_size( array &$settings, string $control, array $definition, string $key, string $fallback_unit = 'px' ): void {
		if ( ! isset( $definition['fluid'] ) && ! isset( $definition['fallbackPx'] ) ) { return; }
		if ( ! $this->capabilities->has( $control ) ) { $this->skip( $key, 'unsupported_control' ); return; }

		$result = $this->factory->slider(
			(string) ( $definition['fluid'] ?? '' ),
			(float) ( $definition['fallbackPx'] ?? 16 ),
			$this->capabilities->supports_custom_unit( $control ),
			$fallback_unit
		);
		if ( ! $result['fluid'] ) { $this->notes[] = [ 'key' => $key, 'note' => $result['reason'] ]; }
		$this->path( $control, $key );
		$settings[ $control ] = $result['value'];
	}

	private function put_padding( array &$settings, string $control, array $padding, string $key ): void {
		if ( ! $this->capabilities->has( $control ) ) { $this->skip( $key, 'unsupported_control' ); return; }
		$result = $this->factory->dimensions(
			[ 'top' => (string) ( $padding['y'] ?? '' ), 'right' => (string) ( $padding['x'] ?? '' ), 'bottom' => (string) ( $padding['y'] ?? '' ), 'left' => (string) ( $padding['x'] ?? '' ) ],
			[ 'top' => (float) ( $padding['fallbackY'] ?? 0 ), 'right' => (float) ( $padding['fallbackX'] ?? 0 ), 'bottom' => (float) ( $padding['fallbackY'] ?? 0 ), 'left' => (float) ( $padding['fallbackX'] ?? 0 ) ],
			$this->capabilities->supports_custom_unit( $control ),
			false
		);
		if ( ! $result['fluid'] ) { $this->notes[] = [ 'key' => $key, 'note' => $result['reason'] ]; }
		$this->path( $control, $key );
		$settings[ $control ] = $result['value'];
	}

	/** Component radius stays a fixed value; a fluid radius shifts a component's identity as it scales. */
	private function put_radius( array &$settings, string $control, float $rem, string $key ): void {
		if ( ! $this->capabilities->has( $control ) ) { $this->skip( $key, 'unsupported_control' ); return; }
		$unit = $this->capabilities->supports_unit( $control, 'rem' ) ? 'rem' : 'px';
		$size = 'rem' === $unit ? $rem : $rem * 16;
		$this->path( $control, $key );
		$settings[ $control ] = [
			'unit' => $unit, 'top' => (string) $size, 'right' => (string) $size,
			'bottom' => (string) $size, 'left' => (string) $size, 'isLinked' => true,
		];
	}

	private function put_radius_px( array &$settings, string $control, float $px, string $key ): void {
		if ( ! $this->capabilities->has( $control ) ) { $this->skip( $key, 'unsupported_control' ); return; }
		$this->path( $control, $key );
		$settings[ $control ] = [
			'unit' => 'px', 'top' => (string) $px, 'right' => (string) $px,
			'bottom' => (string) $px, 'left' => (string) $px, 'isLinked' => true,
		];
	}

	private function skip( string $key, string $reason ): void {
		if ( '' === $key ) { return; }
		$this->skipped[] = [ 'key' => $key, 'reason' => $reason ];
	}
}
