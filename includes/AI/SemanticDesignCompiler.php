<?php
namespace CrescoLayer\AI;

/**
 * Lowers cresco-ai-mutation/v3 semantic design intent to the v2 semantic mutation contract.
 *
 * Every generated setting is resolved against the active Elementor runtime. The compiler refuses
 * ambiguous or unsupported intents rather than guessing a control name, responsive suffix or value
 * shape. Explicit `settings` remain an expert escape hatch and are validated later by
 * SemanticPatchGuard.
 */
final class SemanticDesignCompiler {
	public const SCHEMA = 'cresco-ai-mutation/v3';
	public const LOWERED_SCHEMA = 'cresco-ai-mutation/v2';

	private CapabilityScanner $scanner;
	private array $report = [];

	public function __construct( ?CapabilityScanner $scanner = null ) {
		$this->scanner = $scanner ?? new CapabilityScanner();
	}

	/** @return array{mutation:array,report:array} */
	public function lower( array $mutation ): array {
		if ( self::SCHEMA !== (string) ( $mutation['schema'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'SemanticDesignCompiler expects cresco-ai-mutation/v3.' );
		}
		$this->report = [
			'source' => 'semantic-design-v3',
			'compiledIntentCount' => 0,
			'compiledIntents' => [],
			'activeResponsiveDevices' => $this->active_responsive_devices(),
		];
		$catalog = $this->scanner->catalog();
		$lowered = $mutation;
		$lowered['schema'] = self::LOWERED_SCHEMA;
		$lowered['nodes'] = $this->compile_nodes( is_array( $mutation['nodes'] ?? null ) ? $mutation['nodes'] : [], $catalog, '$.nodes' );
		$lowered['compiler'] = [
			'sourceSchema' => self::SCHEMA,
			'loweredSchema' => self::LOWERED_SCHEMA,
			'mode' => 'runtime-exact-fail-closed',
		];
		return [ 'mutation' => $lowered, 'report' => $this->report ];
	}

	private function compile_nodes( array $nodes, array $catalog, string $path ): array {
		$out = [];
		foreach ( array_values( $nodes ) as $index => $node ) {
			if ( ! is_array( $node ) ) { continue; }
			$out[] = $this->compile_node( $node, $catalog, $path . '[' . $index . ']' );
		}
		return $out;
	}

	private function compile_node( array $node, array $catalog, string $path ): array {
		$intent = trim( (string) ( $node['widgetIntent'] ?? $node['widgetType'] ?? $node['elType'] ?? '' ) );
		if ( '' === $intent ) { throw new \InvalidArgumentException( 'Semantic design node is missing widgetIntent at ' . $path . '.' ); }
		$is_layout = in_array( $intent, [ 'container', 'section', 'column', 'e-div-block', 'e-flexbox', 'e-grid' ], true )
			|| ( isset( $node['elType'] ) && 'widget' !== (string) $node['elType'] );
		$name = $is_layout ? (string) ( $node['elType'] ?? $intent ) : $intent;
		$group = $is_layout ? 'elements' : 'widgets';
		$entry = $catalog[ $group ][ $name ] ?? null;
		if ( ! is_array( $entry ) ) {
			throw new \InvalidArgumentException( 'Semantic design intent references a type absent from the active Elementor runtime: ' . $name );
		}

		$compiled = [];
		$layout = is_array( $node['layoutIntent'] ?? null ) ? $node['layoutIntent'] : [];
		$style = is_array( $node['styleIntent'] ?? null ) ? $node['styleIntent'] : [];
		$responsive = is_array( $node['responsiveIntent'] ?? null ) ? $node['responsiveIntent'] : [];
		$accessibility = is_array( $node['accessibilityIntent'] ?? null ) ? $node['accessibilityIntent'] : [];

		$compiled = array_replace( $compiled, $this->compile_layout( $entry, $layout, $path ) );
		$compiled = array_replace( $compiled, $this->compile_style( $entry, $style, $path ) );
		$compiled = array_replace( $compiled, $this->compile_accessibility( $entry, $accessibility, $path ) );
		$compiled = array_replace( $compiled, $this->compile_responsive( $entry, $responsive, $path ) );

		$explicit = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];
		$node['settings'] = array_replace( $compiled, $explicit );
		if ( isset( $accessibility['semanticLevel'] ) && ! isset( $node['content']['semanticLevel'] ) ) {
			$node['content'] = is_array( $node['content'] ?? null ) ? $node['content'] : [];
			$node['content']['semanticLevel'] = (string) $accessibility['semanticLevel'];
		}
		$children = is_array( $node['children'] ?? null ) ? $node['children'] : ( is_array( $node['elements'] ?? null ) ? $node['elements'] : [] );
		if ( $children ) {
			if ( ! $is_layout ) {
				throw new \InvalidArgumentException( 'Semantic design v3 does not place arbitrary Elementor child nodes inside a widget at ' . $path . '. Use a Container or widget-native repeater/content controls.' );
			}
			$node['children'] = $this->compile_nodes( $children, $catalog, $path . '.children' );
			unset( $node['elements'] );
		}
		return $node;
	}

	private function compile_layout( array $entry, array $intent, string $path ): array {
		if ( ! $intent ) { return []; }
		$map = [
			'direction' => [ 'flex_direction', 'direction' ],
			'justify' => [ 'flex_justify_content', 'justify_content', 'justify' ],
			'align' => [ 'flex_align_items', 'align_items', 'align' ],
			'wrap' => [ 'flex_wrap', 'wrap' ],
			'gap' => [ 'gap', 'flex_gap', 'grid_gap' ],
			'width' => [ 'width' ],
			'minHeight' => [ 'min_height' ],
			'maxWidth' => [ 'max_width' ],
			'padding' => [ 'padding', '_padding' ],
			'margin' => [ 'margin', '_margin' ],
			'overflow' => [ 'overflow' ],
		];
		return $this->compile_property_map( $entry, $intent, $map, $path . '.layoutIntent', false );
	}

	private function compile_style( array $entry, array $intent, string $path ): array {
		if ( ! $intent ) { return []; }
		$map = [
			'backgroundColor' => [ 'background_color', '_background_color' ],
			'textColor' => [ 'text_color', 'title_color', 'color' ],
			'borderRadius' => [ 'border_radius', '_border_radius' ],
			'opacity' => [ 'opacity', '_opacity' ],
			'textAlign' => [ 'align', 'text_align', 'alignment' ],
			'fontSize' => [ 'typography_font_size', 'title_typography_font_size', 'text_typography_font_size', 'button_typography_font_size' ],
			'lineHeight' => [ 'typography_line_height', 'title_typography_line_height', 'text_typography_line_height', 'button_typography_line_height' ],
			'letterSpacing' => [ 'typography_letter_spacing', 'title_typography_letter_spacing', 'text_typography_letter_spacing', 'button_typography_letter_spacing' ],
			'fontWeight' => [ 'typography_font_weight', 'title_typography_font_weight', 'text_typography_font_weight', 'button_typography_font_weight' ],
		];
		$out = $this->compile_property_map( $entry, $intent, $map, $path . '.styleIntent', false );
		if ( array_key_exists( 'backgroundColor', $intent ) ) {
			$controls = (array) ( $entry['controls'] ?? [] );
			if ( isset( $controls['background_background'] ) && ! isset( $out['background_background'] ) ) {
				$options = (array) ( $controls['background_background']['options'] ?? [] );
				if ( ! $options || array_key_exists( 'classic', $options ) ) { $out['background_background'] = 'classic'; }
			}
		}
		return $out;
	}

	private function compile_accessibility( array $entry, array $intent, string $path ): array {
		if ( ! $intent || ! array_key_exists( 'ariaLabel', $intent ) ) { return []; }
		$controls = (array) ( $entry['controls'] ?? [] );
		$key = $this->first_control( $controls, [ 'aria_label', '_aria_label', 'accessible_name' ] );
		if ( '' === $key ) {
			throw new \InvalidArgumentException( 'Cannot compile ' . $path . '.accessibilityIntent.ariaLabel because the active runtime exposes no unambiguous ARIA-label control for this type.' );
		}
		$this->record( $path . '.accessibilityIntent.ariaLabel', $key );
		return [ $key => (string) $intent['ariaLabel'] ];
	}

	private function compile_responsive( array $entry, array $responsive, string $path ): array {
		if ( ! $responsive ) { return []; }
		$out = [];
		$active = $this->active_responsive_devices();
		foreach ( $responsive as $device => $device_intent ) {
			$device = sanitize_key( (string) $device );
			if ( ! is_array( $device_intent ) ) { continue; }
			if ( 'desktop' !== $device && ! in_array( $device, $active, true ) ) {
				throw new \InvalidArgumentException( 'Responsive device "' . $device . '" is not active in the current Elementor installation.' );
			}
			$layout = is_array( $device_intent['layout'] ?? null ) ? $device_intent['layout'] : $device_intent;
			$style = is_array( $device_intent['style'] ?? null ) ? $device_intent['style'] : [];
			$out = array_replace( $out, $this->compile_responsive_map( $entry, $layout, [
				'direction' => [ 'flex_direction', 'direction' ], 'justify' => [ 'flex_justify_content', 'justify_content', 'justify' ],
				'align' => [ 'flex_align_items', 'align_items', 'align' ], 'wrap' => [ 'flex_wrap', 'wrap' ],
				'gap' => [ 'gap', 'flex_gap', 'grid_gap' ], 'width' => [ 'width' ], 'minHeight' => [ 'min_height' ],
				'padding' => [ 'padding', '_padding' ], 'margin' => [ 'margin', '_margin' ], 'overflow' => [ 'overflow' ],
			], $device, $path . '.responsiveIntent.' . $device . '.layout' ) );
			$out = array_replace( $out, $this->compile_responsive_map( $entry, $style, [
				'backgroundColor' => [ 'background_color', '_background_color' ], 'textColor' => [ 'text_color', 'title_color', 'color' ],
				'borderRadius' => [ 'border_radius', '_border_radius' ], 'opacity' => [ 'opacity', '_opacity' ],
				'textAlign' => [ 'align', 'text_align', 'alignment' ], 'fontSize' => [ 'typography_font_size', 'title_typography_font_size', 'text_typography_font_size', 'button_typography_font_size' ],
				'lineHeight' => [ 'typography_line_height', 'title_typography_line_height', 'text_typography_line_height', 'button_typography_line_height' ],
				'letterSpacing' => [ 'typography_letter_spacing', 'title_typography_letter_spacing', 'text_typography_letter_spacing', 'button_typography_letter_spacing' ],
			], $device, $path . '.responsiveIntent.' . $device . '.style' ) );
		}
		return $out;
	}

	private function compile_property_map( array $entry, array $intent, array $map, string $path, bool $responsive ): array {
		$controls = (array) ( $entry['controls'] ?? [] );
		$out = [];
		foreach ( $map as $property => $candidates ) {
			if ( ! array_key_exists( $property, $intent ) ) { continue; }
			$key = $this->first_control( $controls, $candidates );
			if ( '' === $key ) {
				throw new \InvalidArgumentException( 'Cannot compile ' . $path . '.' . $property . ' because no exact active-runtime control matches this semantic intent.' );
			}
			$control = (array) $controls[ $key ];
			if ( $responsive && ! $this->is_responsive_control( $control ) ) {
				throw new \InvalidArgumentException( 'Control ' . $key . ' is not responsive in the active Elementor runtime.' );
			}
			$out[ $key ] = $this->shape_value( $control, $intent[ $property ], $path . '.' . $property );
			$this->record( $path . '.' . $property, $key );
		}
		return $out;
	}

	private function compile_responsive_map( array $entry, array $intent, array $map, string $device, string $path ): array {
		$controls = (array) ( $entry['controls'] ?? [] );
		$out = [];
		foreach ( $map as $property => $candidates ) {
			if ( ! array_key_exists( $property, $intent ) ) { continue; }
			$key = $this->first_control( $controls, $candidates );
			if ( '' === $key ) { throw new \InvalidArgumentException( 'Cannot compile ' . $path . '.' . $property . ' because no exact active-runtime control matches it.' ); }
			$control = (array) $controls[ $key ];
			if ( 'desktop' !== $device && ! $this->is_responsive_control( $control ) ) {
				throw new \InvalidArgumentException( 'Control ' . $key . ' cannot emit a ' . $device . ' responsive value.' );
			}
			$emitted = 'desktop' === $device ? $key : $key . '_' . $device;
			$out[ $emitted ] = $this->shape_value( $control, $intent[ $property ], $path . '.' . $property );
			$this->record( $path . '.' . $property, $emitted );
		}
		return $out;
	}

	private function first_control( array $controls, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( isset( $controls[ $candidate ] ) && is_array( $controls[ $candidate ] ) ) { return $candidate; }
		}
		return '';
	}

	private function shape_value( array $control, mixed $value, string $path ): mixed {
		if ( is_array( $value ) ) { return $value; }
		$type = strtolower( (string) ( $control['type'] ?? '' ) );
		if ( 'switcher' === $type ) { return $value ? 'yes' : ''; }
		if ( in_array( $type, [ 'select', 'choose', 'select2' ], true ) ) {
			$options = (array) ( $control['options'] ?? [] );
			if ( $options && ! array_key_exists( (string) $value, $options ) ) {
				throw new \InvalidArgumentException( $path . ' requests option "' . (string) $value . '" which the active runtime control does not expose.' );
			}
			return (string) $value;
		}
		if ( 'dimensions' === $type || $this->looks_like_dimensions_default( $control ) ) {
			return $this->dimension_value( $control, $value, $path );
		}
		if ( 'slider' === $type || ! empty( $control['size_units'] ) ) {
			return $this->slider_value( $control, $value, $path );
		}
		return $value;
	}

	private function slider_value( array $control, mixed $value, string $path ): array|string|int|float {
		if ( is_int( $value ) || is_float( $value ) ) {
			$unit = in_array( 'px', (array) ( $control['size_units'] ?? [] ), true ) ? 'px' : '';
			return '' !== $unit ? [ 'unit' => $unit, 'size' => $value, 'sizes' => [] ] : $value;
		}
		$raw = trim( (string) $value );
		if ( preg_match( '/^(-?[0-9]*\.?[0-9]+)(px|%|vw|vh|em|rem|vmin|vmax)?$/i', $raw, $match ) ) {
			$unit = strtolower( (string) ( $match[2] ?? '' ) );
			$units = array_values( array_map( 'strval', (array) ( $control['size_units'] ?? [] ) ) );
			if ( '' === $unit ) { $unit = in_array( 'px', $units, true ) ? 'px' : ( $units[0] ?? '' ); }
			if ( $units && ! in_array( $unit, $units, true ) ) { throw new \InvalidArgumentException( $path . ' uses unsupported unit ' . $unit . '.' ); }
			return [ 'unit' => $unit, 'size' => (float) $match[1], 'sizes' => [] ];
		}
		$units = array_values( array_map( 'strval', (array) ( $control['size_units'] ?? [] ) ) );
		if ( in_array( 'custom', $units, true ) && preg_match( '/^(?:clamp|min|max|calc|var)\([^;{}]+\)$/i', $raw ) ) {
			return [ 'unit' => 'custom', 'size' => $raw, 'sizes' => [] ];
		}
		throw new \InvalidArgumentException( $path . ' cannot be represented safely by the active runtime slider/control shape.' );
	}

	private function dimension_value( array $control, mixed $value, string $path ): array {
		$units = array_values( array_map( 'strval', (array) ( $control['size_units'] ?? [] ) ) );
		$raw = trim( (string) $value );
		if ( ! preg_match( '/^(-?[0-9]*\.?[0-9]+)(px|%|vw|vh|em|rem)?$/i', $raw, $match ) ) {
			throw new \InvalidArgumentException( $path . ' dimensions require a scalar CSS length or an explicit Elementor dimensions object.' );
		}
		$unit = strtolower( (string) ( $match[2] ?? '' ) );
		if ( '' === $unit ) { $unit = in_array( 'px', $units, true ) ? 'px' : ( $units[0] ?? 'px' ); }
		if ( $units && ! in_array( $unit, $units, true ) ) { throw new \InvalidArgumentException( $path . ' uses unsupported dimensions unit ' . $unit . '.' ); }
		$size = (string) (float) $match[1];
		$default = is_array( $control['default'] ?? null ) ? $control['default'] : [];
		if ( array_key_exists( 'row', $default ) || array_key_exists( 'column', $default ) ) {
			return [ 'unit' => $unit, 'row' => $size, 'column' => $size, 'isLinked' => true ];
		}
		return [ 'unit' => $unit, 'top' => $size, 'right' => $size, 'bottom' => $size, 'left' => $size, 'isLinked' => true ];
	}

	private function looks_like_dimensions_default( array $control ): bool {
		$default = $control['default'] ?? null;
		return is_array( $default ) && ( array_key_exists( 'top', $default ) || array_key_exists( 'row', $default ) );
	}

	private function is_responsive_control( array $control ): bool {
		return true === ( $control['responsive'] ?? false ) || 'yes' === ( $control['responsive'] ?? null ) || 1 === ( $control['responsive'] ?? null );
	}

	private function active_responsive_devices(): array {
		$devices = [];
		try {
			$plugin = \Elementor\Plugin::instance();
			$manager = $plugin->breakpoints ?? null;
			if ( $manager && method_exists( $manager, 'get_active_breakpoints' ) ) {
				foreach ( (array) $manager->get_active_breakpoints() as $key => $breakpoint ) {
					$name = is_string( $key ) ? $key : ( is_object( $breakpoint ) && method_exists( $breakpoint, 'get_name' ) ? (string) $breakpoint->get_name() : '' );
					$name = sanitize_key( $name );
					if ( $name && 'desktop' !== $name ) { $devices[] = $name; }
				}
			}
		} catch ( \Throwable $error ) {
			return [];
		}
		return array_values( array_unique( $devices ) );
	}

	private function record( string $intent_path, string $control ): void {
		$this->report['compiledIntentCount']++;
		$this->report['compiledIntents'][] = [ 'intent' => $intent_path, 'control' => $control ];
	}
}
