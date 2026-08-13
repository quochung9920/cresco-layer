<?php
namespace CrescoLayer\Skills;

final class SkillCompiler {
	public const SCHEMA = 'cresco-layer-widget-skills/v1';
	public const RESOLUTION_SCHEMA = 'cresco-layer-skill-resolution/v1';
	private const RESPONSIVE_SUFFIXES = [ 'tablet', 'mobile', 'widescreen', 'laptop', 'tablet_extra', 'mobile_extra' ];
	private const NON_VALUE_TYPES = [ 'section', 'tab', 'heading', 'raw_html', 'divider', 'button' ];
	private const COMPLEX_TYPES = [ 'repeater', 'gallery', 'structure', 'code', 'wysiwyg' ];

	public function compile( array $entry, array $current_settings = [], array $breakpoints = [], array $knowledge = [] ): array {
		$controls = is_array( $entry['controls'] ?? null ) ? $entry['controls'] : [];
		$defaults = is_array( $entry['defaultSettings'] ?? null ) ? $entry['defaultSettings'] : [];
		$skills = [];
		$categories = [];
		$roles = [];

		foreach ( $controls as $control_name => $control ) {
			if ( ! is_array( $control ) ) { continue; }
			$skill = $this->compile_control( (string) $control_name, $control, $current_settings, $defaults, $breakpoints );
			$skills[] = $skill;
			$categories[ $skill['category'] ] = ( $categories[ $skill['category'] ] ?? 0 ) + 1;
			if ( '' !== $skill['role'] ) { $roles[ $skill['role'] ][] = $skill['id']; }
		}

		usort( $skills, static function ( array $a, array $b ): int {
			$mode = [ 'direct' => 0, 'expert' => 1, 'read-only' => 2 ];
			$left = $mode[ $a['mode'] ] ?? 9;
			$right = $mode[ $b['mode'] ] ?? 9;
			return $left === $right ? strcasecmp( (string) $a['label'], (string) $b['label'] ) : $left <=> $right;
		} );

		return [
			'schema' => self::SCHEMA,
			'compilerVersion' => 1,
			'capabilitySource' => (string) ( $entry['capabilitySource'] ?? '' ),
			'isAtomic' => ! empty( $entry['isAtomic'] ),
			'controlCount' => count( $controls ),
			'skillCount' => count( $skills ),
			'executableSkillCount' => count( array_filter( $skills, static fn( array $skill ): bool => 'read-only' !== $skill['mode'] ) ),
			'categories' => $categories,
			'roles' => $roles,
			'skills' => $skills,
			'knowledge' => $knowledge,
		];
	}

	public function resolve( array $compiled, string $skill_id, array $params, array $current_settings, string $element_id ): array {
		$skill = $this->find_skill( $compiled, $skill_id );
		if ( ! $skill ) { throw new \InvalidArgumentException( 'Requested Cresco skill is not available for this Elementor element.' ); }
		if ( 'read-only' === ( $skill['mode'] ?? '' ) ) { throw new \InvalidArgumentException( 'This runtime control is descriptive/action-only or lacks a proven Elementor binding.' ); }
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $element_id ) ) { throw new \InvalidArgumentException( 'Element ID is invalid.' ); }

		$device = strtolower( trim( (string) ( $params['device'] ?? 'desktop' ) ) );
		$device = '' === $device ? 'desktop' : $device;
		$devices = (array) ( $skill['devices'] ?? [ 'desktop' ] );
		if ( ! in_array( $device, $devices, true ) ) { throw new \InvalidArgumentException( 'This skill is not available for the requested responsive device.' ); }

		$setting = (string) ( $skill['setting'] ?? '' );
		if ( '' === $setting ) { throw new \InvalidArgumentException( 'Skill does not expose a proven Elementor setting/prop binding.' ); }
		if ( 'desktop' !== $device ) {
			if ( empty( $skill['responsive'] ) ) { throw new \InvalidArgumentException( 'This Elementor control is not responsive.' ); }
			$setting .= '_' . $device;
		}

		$value = $this->normalize_value( $skill, $params );
		$operations = $this->safe_prerequisite_operations( $compiled, $skill, $current_settings, $element_id );
		$operations[] = [ 'operation' => 'update-setting', 'elementId' => $element_id, 'setting' => $setting, 'value' => $value ];
		$before = array_key_exists( $setting, $current_settings ) ? $current_settings[ $setting ] : ( $skill['current']['devices'][ $device ] ?? null );

		return [
			'schema' => self::RESOLUTION_SCHEMA,
			'elementId' => $element_id,
			'skillId' => $skill_id,
			'role' => (string) ( $skill['role'] ?? '' ),
			'label' => (string) ( $skill['label'] ?? $skill_id ),
			'device' => $device,
			'risk' => (string) ( $skill['risk'] ?? 'safe' ),
			'historyLabel' => 'Cresco Skill · ' . (string) ( $skill['label'] ?? $skill_id ),
			'operations' => $operations,
			'preview' => [ 'setting' => $setting, 'before' => $before, 'after' => $value, 'prerequisites' => array_slice( $operations, 0, -1 ) ],
		];
	}

	public function command( array $compiled, string $command, array $current_settings, string $element_id ): array {
		$raw = trim( $command );
		if ( '' === $raw ) { throw new \InvalidArgumentException( 'Enter a Cresco skill command.' ); }
		$normalized = $this->normalize_command_text( $raw );
		$device = 'desktop';
		if ( preg_match( '/\b(mobile|phone|dien thoai)\b/u', $normalized ) ) { $device = 'mobile'; }
		elseif ( preg_match( '/\b(tablet|may tinh bang)\b/u', $normalized ) ) { $device = 'tablet'; }

		if ( preg_match( '/\b(an|hide)\s+(mobile|phone|tablet|desktop)\b/u', $normalized, $match ) ) {
			$target = 'phone' === $match[2] ? 'mobile' : $match[2];
			$skill = $this->find_setting_skill( $compiled, 'hide_' . $target );
			if ( ! $skill ) { throw new \InvalidArgumentException( 'Selected widget does not expose native visibility for ' . $target . '.' ); }
			return $this->resolve( $compiled, $skill['id'], [ 'value' => 'yes' ], $current_settings, $element_id );
		}
		if ( preg_match( '/\b(hien|show)\s+(mobile|phone|tablet|desktop)\b/u', $normalized, $match ) ) {
			$target = 'phone' === $match[2] ? 'mobile' : $match[2];
			$skill = $this->find_setting_skill( $compiled, 'hide_' . $target );
			if ( ! $skill ) { throw new \InvalidArgumentException( 'Selected widget does not expose native visibility for ' . $target . '.' ); }
			return $this->resolve( $compiled, $skill['id'], [ 'value' => '' ], $current_settings, $element_id );
		}

		$patterns = [
			[ '/(?:padding|khoang trong|dem)\b/u', 'spacing.padding' ],
			[ '/(?:margin|khoang ngoai)\b/u', 'spacing.margin' ],
			[ '/(?:min height|min-height|chieu cao toi thieu)\b/u', 'layout.min-height' ],
			[ '/(?:width|chieu rong)\b/u', 'layout.width' ],
			[ '/(?:gap|khoang cach)\b/u', 'layout.gap' ],
			[ '/(?:background|nen)\b/u', 'style.background-color' ],
			[ '/(?:radius|bo goc|rounded)\b/u', 'style.border-radius' ],
			[ '/(?:font size|co chu)\b/u', 'typography.font-size' ],
			[ '/(?:font weight|do dam)\b/u', 'typography.font-weight' ],
			[ '/(?:text color|mau chu)\b/u', 'typography.color' ],
			[ '/(?:opacity|do mo)\b/u', 'style.opacity' ],
			[ '/(?:align|can chu|can le)\b/u', 'typography.align' ],
		];
		$role = '';
		foreach ( $patterns as [ $pattern, $candidate ] ) {
			if ( preg_match( $pattern, $normalized ) ) { $role = $candidate; break; }
		}
		if ( '' === $role ) { throw new \InvalidArgumentException( 'Command did not match a deterministic skill. Choose a skill from the list for this widget.' ); }
		$skill = $this->find_role_skill( $compiled, $role );
		if ( ! $skill ) { throw new \InvalidArgumentException( 'Selected widget does not expose a native control for ' . $role . '.' ); }

		$value = null;
		if ( 'typography.align' === $role ) {
			if ( preg_match( '/\b(center|giua)\b/u', $normalized ) ) { $value = 'center'; }
			elseif ( preg_match( '/\b(right|phai)\b/u', $normalized ) ) { $value = 'right'; }
			elseif ( preg_match( '/\b(left|trai)\b/u', $normalized ) ) { $value = 'left'; }
			elseif ( preg_match( '/\b(justify|deu)\b/u', $normalized ) ) { $value = 'justify'; }
		}
		if ( null === $value && preg_match( '/#(?:[0-9a-f]{3,8})\b/i', $raw, $match ) ) { $value = $match[0]; }
		if ( null === $value && preg_match( '/(-?\d+(?:\.\d+)?)\s*(px|%|em|rem|vh|vw|deg|s|ms)?/i', $raw, $match ) ) { $value = $match[1] . ( $match[2] ?? '' ); }
		if ( null === $value ) { throw new \InvalidArgumentException( 'Command matched a skill but no value could be parsed.' ); }
		return $this->resolve( $compiled, $skill['id'], [ 'device' => $device, 'value' => $value ], $current_settings, $element_id );
	}

	private function compile_control( string $name, array $control, array $current, array $defaults, array $breakpoints ): array {
		$type = strtolower( (string) ( $control['type'] ?? 'unknown' ) );
		$source = (string) ( $control['source'] ?? 'classic-control' );
		$bind = trim( (string) ( $control['bind'] ?? '' ) );
		$is_atomic_source = str_starts_with( $source, 'atomic' );
		$setting = $is_atomic_source ? $bind : $name;
		$responsive = ! empty( $control['responsive'] );
		$devices = [ 'desktop' ];
		if ( $responsive ) {
			$available = array_keys( $breakpoints );
			if ( ! $available ) { $available = [ 'tablet', 'mobile' ]; }
			foreach ( $available as $device ) {
				$device = (string) $device;
				if ( in_array( $device, self::RESPONSIVE_SUFFIXES, true ) && ! in_array( $device, $devices, true ) ) { $devices[] = $device; }
			}
		}
		$mode = in_array( $type, self::NON_VALUE_TYPES, true ) || ( $is_atomic_source && '' === $setting ) ? 'read-only' : ( in_array( $type, self::COMPLEX_TYPES, true ) ? 'expert' : 'direct' );
		$role = '' === $setting ? '' : $this->infer_role( $setting, $type );
		$category = $this->infer_category( '' === $setting ? $name : $setting, $type, $role );
		$device_values = [];
		foreach ( $devices as $device ) {
			$key = 'desktop' === $device ? $setting : $setting . '_' . $device;
			$device_values[ $device ] = '' === $setting ? null : ( array_key_exists( $key, $current ) ? $current[ $key ] : ( $defaults[ $key ] ?? null ) );
		}
		$conditions = [];
		if ( isset( $control['condition'] ) ) { $conditions['condition'] = $control['condition']; }
		if ( isset( $control['conditions'] ) ) { $conditions['conditions'] = $control['conditions']; }
		$label = trim( (string) ( $control['label'] ?? '' ) );
		if ( '' === $label ) { $label = ucwords( str_replace( [ '_', '-' ], ' ', '' === $setting ? $name : $setting ) ); }

		return [
			'id' => 'control.' . $name,
			'control' => $name,
			'setting' => $setting,
			'bind' => $bind,
			'source' => $source,
			'type' => $type,
			'label' => $label,
			'description' => trim( (string) ( $control['description'] ?? '' ) ),
			'category' => $category,
			'role' => $role,
			'mode' => $mode,
			'risk' => $this->risk( '' === $setting ? $name : $setting, $type, $conditions ),
			'responsive' => $responsive,
			'devices' => $devices,
			'dynamic' => ! empty( $control['dynamic'] ),
			'frontendAvailable' => $control['frontend_available'] ?? null,
			'input' => $this->input_schema( $control, $type ),
			'conditions' => $conditions,
			'group' => [ 'type' => (string) ( $control['group_type'] ?? '' ), 'prefix' => (string) ( $control['group_prefix'] ?? '' ) ],
			'renderType' => (string) ( $control['render_type'] ?? '' ),
			'selectors' => $control['selectors'] ?? [],
			'selectorsDictionary' => $control['selectors_dictionary'] ?? [],
			'current' => [ 'devices' => $device_values ],
			'metadata' => [
				'default' => $control['default'] ?? null,
				'tabletDefault' => $control['tablet_default'] ?? null,
				'mobileDefault' => $control['mobile_default'] ?? null,
				'placeholder' => $control['placeholder'] ?? null,
				'classes' => $control['classes'] ?? null,
				'prefixClass' => $control['prefix_class'] ?? null,
				'propSchema' => $control['propSchema'] ?? null,
				'atomicProps' => $control['props'] ?? null,
				'atomicMeta' => $control['meta'] ?? null,
				'rawMetadata' => $control['rawMetadata'] ?? null,
			],
			'searchTerms' => array_values( array_unique( array_filter( [ $name, $setting, $label, $role, $category, $type ] ) ) ),
		];
	}

	private function input_schema( array $control, string $type ): array {
		$raw = is_array( $control['rawMetadata'] ?? null ) ? $control['rawMetadata'] : [];
		return [
			'kind' => $type,
			'options' => is_array( $control['options'] ?? null ) ? $control['options'] : [],
			'units' => array_values( array_map( 'strval', (array) ( $control['size_units'] ?? [] ) ) ),
			'range' => is_array( $control['range'] ?? null ) ? $control['range'] : [],
			'min' => $control['min'] ?? null,
			'max' => $control['max'] ?? null,
			'step' => $control['step'] ?? null,
			'multiple' => ! empty( $control['multiple'] ),
			'returnValue' => $control['return_value'] ?? ( $raw['return_value'] ?? 'yes' ),
		];
	}

	private function normalize_value( array $skill, array $params ) {
		if ( ! array_key_exists( 'value', $params ) && ! array_key_exists( 'all', $params ) && ! array_intersect( [ 'top', 'right', 'bottom', 'left' ], array_keys( $params ) ) ) { throw new \InvalidArgumentException( 'Skill value is required.' ); }
		$type = (string) ( $skill['type'] ?? '' );
		$input = is_array( $skill['input'] ?? null ) ? $skill['input'] : [];
		$value = $params['value'] ?? ( $params['all'] ?? null );
		if ( 'dimensions' === $type ) { return $this->dimensions_value( $params, $input ); }
		if ( in_array( $type, [ 'slider', 'size' ], true ) ) { return $this->slider_value( $value, $input ); }
		if ( 'number' === $type ) {
			if ( ! is_numeric( $value ) ) { throw new \InvalidArgumentException( 'Numeric skill requires a number.' ); }
			$number = 0 + $value; $this->assert_numeric_range( $number, $input ); return $number;
		}
		if ( 'switcher' === $type ) {
			if ( is_bool( $value ) ) { return $value ? (string) ( $input['returnValue'] ?? 'yes' ) : ''; }
			$normalized = strtolower( trim( (string) $value ) );
			if ( in_array( $normalized, [ '1', 'true', 'yes', 'on', 'show', 'enable', 'enabled' ], true ) ) { return (string) ( $input['returnValue'] ?? 'yes' ); }
			if ( in_array( $normalized, [ '0', 'false', 'no', 'off', 'hide', 'disable', 'disabled', '' ], true ) ) { return ''; }
			return (string) $value;
		}
		if ( in_array( $type, [ 'select', 'choose', 'select2' ], true ) ) {
			$options = is_array( $input['options'] ?? null ) ? $input['options'] : [];
			if ( ! empty( $input['multiple'] ) ) { $values = is_array( $value ) ? $value : [ $value ]; foreach ( $values as $item ) { $this->assert_option( $item, $options ); } return array_values( $values ); }
			$this->assert_option( $value, $options ); return $value;
		}
		if ( 'url' === $type && is_string( $value ) ) { return [ 'url' => trim( $value ), 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ]; }
		if ( in_array( $type, [ 'repeater', 'gallery', 'structure' ], true ) && ! is_array( $value ) ) { throw new \InvalidArgumentException( 'Structured Elementor skill requires an array/object value.' ); }
		if ( is_scalar( $value ) || is_array( $value ) || null === $value ) { return $value; }
		throw new \InvalidArgumentException( 'Skill value type is not supported.' );
	}

	private function dimensions_value( array $params, array $input ): array {
		$seed = $params['value'] ?? ( $params['all'] ?? null );
		if ( null === $seed || '' === (string) $seed ) { foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) { if ( array_key_exists( $side, $params ) && '' !== (string) $params[ $side ] ) { $seed = $params[ $side ]; break; } } }
		if ( null === $seed || '' === (string) $seed ) { $seed = 0; }
		[ $size, $inferred_unit ] = $this->parse_size( $seed, $input );
		$unit = (string) ( $params['unit'] ?? $inferred_unit ); $this->assert_unit( $unit, $input ); $all = (string) $size;
		return [
			'unit' => $unit,
			'top' => array_key_exists( 'top', $params ) ? $this->dimension_side( $params['top'], $unit, $input ) : $all,
			'right' => array_key_exists( 'right', $params ) ? $this->dimension_side( $params['right'], $unit, $input ) : $all,
			'bottom' => array_key_exists( 'bottom', $params ) ? $this->dimension_side( $params['bottom'], $unit, $input ) : $all,
			'left' => array_key_exists( 'left', $params ) ? $this->dimension_side( $params['left'], $unit, $input ) : $all,
			'isLinked' => ! array_intersect( [ 'top', 'right', 'bottom', 'left' ], array_keys( $params ) ),
		];
	}

	private function dimension_side( $value, string $unit, array $input ): string {
		if ( '' === (string) $value ) { return ''; }
		if ( is_numeric( $value ) ) { return (string) ( 0 + $value ); }
		[ $size, $parsed_unit ] = $this->parse_size( $value, $input );
		if ( $parsed_unit !== $unit ) { throw new \InvalidArgumentException( 'All dimension sides must use the selected Elementor unit.' ); }
		return (string) $size;
	}

	private function slider_value( $value, array $input ): array {
		if ( is_array( $value ) && array_key_exists( 'size', $value ) ) {
			$unit = (string) ( $value['unit'] ?? $this->preferred_unit( $input ) ); $this->assert_unit( $unit, $input );
			if ( ! is_numeric( $value['size'] ) && '' !== (string) $value['size'] ) { throw new \InvalidArgumentException( 'Slider size must be numeric.' ); }
			if ( is_numeric( $value['size'] ) ) { $this->assert_numeric_range( 0 + $value['size'], $input, $unit ); }
			return [ 'unit' => $unit, 'size' => '' === (string) $value['size'] ? '' : 0 + $value['size'], 'sizes' => is_array( $value['sizes'] ?? null ) ? $value['sizes'] : [] ];
		}
		[ $size, $unit ] = $this->parse_size( $value, $input ); $this->assert_numeric_range( 0 + $size, $input, $unit );
		return [ 'unit' => $unit, 'size' => 0 + $size, 'sizes' => [] ];
	}

	private function parse_size( $value, array $input ): array {
		if ( is_numeric( $value ) ) { return [ 0 + $value, $this->preferred_unit( $input ) ]; }
		$string = trim( (string) $value );
		if ( ! preg_match( '/^(-?\d+(?:\.\d+)?)\s*(px|%|em|rem|vh|vw|deg|s|ms|fr)?$/i', $string, $match ) ) { throw new \InvalidArgumentException( 'Expected a numeric value with an optional Elementor unit.' ); }
		$unit = '' !== ( $match[2] ?? '' ) ? strtolower( $match[2] ) : $this->preferred_unit( $input ); $this->assert_unit( $unit, $input );
		return [ 0 + $match[1], $unit ];
	}

	private function preferred_unit( array $input ): string { $units = (array) ( $input['units'] ?? [] ); return $units ? (string) $units[0] : 'px'; }
	private function assert_unit( string $unit, array $input ): void { $units = array_values( array_map( 'strval', (array) ( $input['units'] ?? [] ) ) ); if ( $units && ! in_array( $unit, $units, true ) ) { throw new \InvalidArgumentException( 'Unit is not supported by this Elementor control.' ); } }
	private function assert_numeric_range( $number, array $input, string $unit = '' ): void {
		$range = is_array( $input['range'] ?? null ) ? $input['range'] : []; $bounds = $unit && is_array( $range[ $unit ] ?? null ) ? $range[ $unit ] : [];
		$min = $bounds['min'] ?? ( $input['min'] ?? null ); $max = $bounds['max'] ?? ( $input['max'] ?? null );
		if ( is_numeric( $min ) && $number < $min ) { throw new \InvalidArgumentException( 'Value is below the Elementor control minimum.' ); }
		if ( is_numeric( $max ) && $number > $max ) { throw new \InvalidArgumentException( 'Value is above the Elementor control maximum.' ); }
	}
	private function assert_option( $value, array $options ): void { if ( ! $options ) { return; } $key = (string) $value; if ( ! array_key_exists( $key, $options ) && ! array_key_exists( $value, $options ) ) { throw new \InvalidArgumentException( 'Value is not one of the Elementor control options.' ); } }

	private function safe_prerequisite_operations( array $compiled, array $skill, array $current, string $element_id ): array {
		$condition = is_array( $skill['conditions']['condition'] ?? null ) ? $skill['conditions']['condition'] : []; $operations = [];
		foreach ( $condition as $key => $expected ) {
			$key = (string) $key;
			if ( str_ends_with( $key, '!' ) || is_array( $expected ) || ! $this->safe_enabler( $key ) ) { continue; }
			if ( array_key_exists( $key, $current ) && (string) $current[ $key ] === (string) $expected ) { continue; }
			if ( ! $this->find_setting_skill( $compiled, $key ) ) { continue; }
			$operations[] = [ 'operation' => 'update-setting', 'elementId' => $element_id, 'setting' => $key, 'value' => $expected ];
		}
		return $operations;
	}
	private function safe_enabler( string $key ): bool { return (bool) preg_match( '/(?:_background|_border|_typography|_box_shadow_type|_popover|_css_filter)$/', $key ); }
	private function find_skill( array $compiled, string $id ): ?array { foreach ( (array) ( $compiled['skills'] ?? [] ) as $skill ) { if ( is_array( $skill ) && $id === ( $skill['id'] ?? '' ) ) { return $skill; } } return null; }
	private function find_role_skill( array $compiled, string $role ): ?array { foreach ( (array) ( $compiled['skills'] ?? [] ) as $skill ) { if ( is_array( $skill ) && $role === ( $skill['role'] ?? '' ) && 'read-only' !== ( $skill['mode'] ?? '' ) ) { return $skill; } } return null; }
	private function find_setting_skill( array $compiled, string $setting ): ?array { foreach ( (array) ( $compiled['skills'] ?? [] ) as $skill ) { if ( is_array( $skill ) && $setting === ( $skill['setting'] ?? '' ) && 'read-only' !== ( $skill['mode'] ?? '' ) ) { return $skill; } } return null; }

	private function infer_role( string $setting, string $type ): string {
		$key = strtolower( $setting );
		$map = [
			'/^padding$|_padding$/' => 'spacing.padding', '/^margin$|_margin$/' => 'spacing.margin', '/(?:^|_)min_height$/' => 'layout.min-height',
			'/(?:^|_)(?:content_)?width$|_width$/' => 'layout.width', '/(?:flex_)?gap|grid_gaps|space_between/' => 'layout.gap', '/flex_direction|direction$/' => 'layout.direction',
			'/justify_content|justify$/' => 'layout.justify', '/align_items|align_self/' => 'layout.align', '/background_color$|^background_color$/' => 'style.background-color',
			'/border_radius|radius$/' => 'style.border-radius', '/border_color$/' => 'style.border-color', '/border_width$/' => 'style.border-width', '/box_shadow/' => 'style.box-shadow',
			'/opacity$/' => 'style.opacity', '/font_size$/' => 'typography.font-size', '/font_weight$/' => 'typography.font-weight', '/line_height$/' => 'typography.line-height',
			'/letter_spacing$/' => 'typography.letter-spacing', '/(?:text|title|heading|typography)_color$|^color$/' => 'typography.color', '/(?:text_)?align(?:ment)?$/' => 'typography.align',
			'/html_tag$/' => 'content.html-tag', '/(?:^|_)(?:title|text|editor|description|caption|button_text)$/' => 'content.text', '/(?:^|_)(?:link|url)$/' => 'content.link',
			'/^hide_(?:desktop|tablet|mobile)$/' => 'responsive.visibility', '/(?:image|media)$/' => 'media.source', '/object_fit/' => 'media.object-fit', '/autoplay/' => 'media.autoplay',
			'/(?:slides_per_view|slides_to_show|slides_count)/' => 'carousel.columns', '/(?:columns|column_count)$/' => 'layout.columns', '/(?:query|posts|source)$/' => 'query.source',
			'/(?:form_fields|fields)$/' => 'form.fields', '/(?:actions_after_submit|submit_actions)/' => 'form.actions',
		];
		foreach ( $map as $pattern => $role ) { if ( preg_match( $pattern, $key ) ) { return $role; } }
		if ( 'color' === $type ) { return 'style.color'; }
		return '';
	}

	private function infer_category( string $setting, string $type, string $role ): string {
		if ( str_starts_with( $role, 'spacing.' ) ) { return 'Spacing'; } if ( str_starts_with( $role, 'layout.' ) ) { return 'Layout'; }
		if ( str_starts_with( $role, 'typography.' ) ) { return 'Typography'; } if ( str_starts_with( $role, 'style.' ) ) { return 'Style'; }
		if ( str_starts_with( $role, 'content.' ) ) { return 'Content'; } if ( str_starts_with( $role, 'responsive.' ) ) { return 'Responsive'; }
		if ( str_starts_with( $role, 'media.' ) ) { return 'Media'; } if ( str_starts_with( $role, 'form.' ) ) { return 'Form'; }
		if ( str_starts_with( $role, 'query.' ) ) { return 'Query'; } if ( str_starts_with( $role, 'carousel.' ) ) { return 'Carousel'; }
		$key = strtolower( $setting . ' ' . $type );
		if ( preg_match( '/motion|animation|transform|sticky/', $key ) ) { return 'Motion'; } if ( preg_match( '/background|border|shadow|color|filter|opacity/', $key ) ) { return 'Style'; }
		if ( preg_match( '/font|typography|letter|line_height/', $key ) ) { return 'Typography'; } if ( preg_match( '/padding|margin/', $key ) ) { return 'Spacing'; }
		if ( preg_match( '/width|height|flex|grid|align|justify|gap|position|offset|order/', $key ) ) { return 'Layout'; } if ( preg_match( '/image|media|video|gallery/', $key ) ) { return 'Media'; }
		if ( preg_match( '/form|field|submit|message|email|webhook/', $key ) ) { return 'Form'; } if ( preg_match( '/query|taxonomy|pagination|posts|products/', $key ) ) { return 'Query'; }
		return 'Advanced';
	}

	private function risk( string $setting, string $type, array $conditions ): string {
		$key = strtolower( $setting );
		if ( preg_match( '/password|secret|token|api[_-]?key|webhook|email|smtp|redirect|payment|stripe|paypal/', $key ) ) { return 'external'; }
		if ( in_array( $type, [ 'repeater', 'gallery', 'structure' ], true ) ) { return 'structural'; }
		if ( 'code' === $type || preg_match( '/custom_css|html|shortcode|code/', $key ) ) { return 'expert'; }
		if ( $conditions ) { return 'conditional'; }
		return 'safe';
	}

	private function normalize_command_text( string $value ): string {
		$value = strtolower( $value ); $ascii = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );
		if ( is_string( $ascii ) && '' !== $ascii ) { $value = strtolower( $ascii ); }
		$value = preg_replace( '/[^a-z0-9#.%_\-\s]+/', ' ', $value );
		return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
	}
}
