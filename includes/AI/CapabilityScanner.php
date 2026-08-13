<?php
namespace CrescoLayer\AI;

use Elementor\Plugin as ElementorPlugin;

final class CapabilityScanner {
	private const MAX_DEPTH = 10;
	private const MAX_STRING = 32768;
	private const MAX_ARRAY_ITEMS = 5000;
	private const CONTROL_KEYS = [
		'type', 'label', 'description', 'default', 'options', 'placeholder', 'separator', 'show_label', 'label_block',
		'responsive', 'dynamic', 'frontend_available', 'size_units', 'range', 'min', 'max', 'step', 'selectors',
		'selectors_dictionary', 'condition', 'conditions', 'render_type', 'prefix_class', 'classes', 'devices',
		'tablet_default', 'mobile_default', 'widescreen_default', 'laptop_default', 'tablet_extra_default',
		'mobile_extra_default', 'group_prefix', 'group_type', 'ai', 'multiple', 'toggle', 'rows', 'language',
	];
	private const RESPONSIVE_DEFAULTS = [
		'tablet_default' => 'tablet',
		'mobile_default' => 'mobile',
		'widescreen_default' => 'widescreen',
		'laptop_default' => 'laptop',
		'tablet_extra_default' => 'tablet_extra',
		'mobile_extra_default' => 'mobile_extra',
	];

	public function catalog( bool $include_raw_metadata = false ): array {
		$widgets = [];
		$elements = [];
		$errors = [];

		foreach ( $this->instances( 'widget', $errors ) as $name => $widget ) {
			if ( ! is_object( $widget ) ) { continue; }
			try {
				$entry = $this->describe_instance( $widget, (string) $name, $include_raw_metadata, 'widget' );
				$widgets[ $entry['name'] ] = $entry;
			} catch ( \Throwable $error ) {
				$errors[] = $this->scan_error( 'widget', (string) $name, 'describe', $error );
			}
		}

		foreach ( $this->instances( 'element', $errors ) as $name => $element ) {
			if ( ! is_object( $element ) ) { continue; }
			try {
				$entry = $this->describe_instance( $element, (string) $name, $include_raw_metadata, 'element' );
				$elements[ $entry['name'] ] = $entry;
			} catch ( \Throwable $error ) {
				$errors[] = $this->scan_error( 'element', (string) $name, 'describe', $error );
			}
		}

		return [
			'widgets' => $widgets,
			'elements' => $elements,
			'controlMetadataVersion' => $include_raw_metadata ? 5 : 3,
			'notes' => $this->notes( $include_raw_metadata ),
			'scanErrors' => $errors,
		];
	}

	public function catalog_index(): array {
		$widgets = [];
		$elements = [];
		$errors = [];

		foreach ( $this->instances( 'widget', $errors ) as $name => $widget ) {
			if ( ! is_object( $widget ) ) { continue; }
			$entry = $this->summarize_instance( $widget, (string) $name, 'widget' );
			$widgets[ $entry['name'] ] = $entry;
			if ( ! empty( $entry['scanErrors'] ) ) { array_push( $errors, ...$entry['scanErrors'] ); }
		}

		foreach ( $this->instances( 'element', $errors ) as $name => $element ) {
			if ( ! is_object( $element ) ) { continue; }
			$entry = $this->summarize_instance( $element, (string) $name, 'element' );
			$elements[ $entry['name'] ] = $entry;
			if ( ! empty( $entry['scanErrors'] ) ) { array_push( $errors, ...$entry['scanErrors'] ); }
		}

		return [
			'widgets' => $widgets,
			'elements' => $elements,
			'controlMetadataVersion' => 5,
			'notes' => $this->notes( true ),
			'scanErrors' => $errors,
		];
	}

	public function catalog_entry( string $kind, string $name, bool $include_raw_metadata = true ): array {
		if ( ! in_array( $kind, [ 'widget', 'element' ], true ) ) {
			throw new \InvalidArgumentException( 'Elementor catalog kind must be widget or element.' );
		}
		$errors = [];
		$instances = $this->instances( $kind, $errors );
		if ( ! isset( $instances[ $name ] ) || ! is_object( $instances[ $name ] ) ) {
			throw new \InvalidArgumentException( 'Requested Elementor ' . $kind . ' is not registered: ' . $name );
		}
		$entry = $this->describe_instance( $instances[ $name ], $name, $include_raw_metadata, $kind );
		if ( $errors ) {
			$entry['scanErrors'] = array_merge( (array) ( $entry['scanErrors'] ?? [] ), $errors );
		}
		return $entry;
	}

	public function relevant_catalog( array $elements, ?array $catalog = null ): array {
		$catalog = $catalog ?? $this->catalog();
		$widget_names = [];
		$element_names = [];
		$this->collect_types( $elements, $widget_names, $element_names );

		$widgets = [];
		foreach ( array_unique( $widget_names ) as $name ) {
			if ( isset( $catalog['widgets'][ $name ] ) ) { $widgets[ $name ] = $catalog['widgets'][ $name ]; }
		}
		$types = [];
		foreach ( array_unique( $element_names ) as $name ) {
			if ( isset( $catalog['elements'][ $name ] ) ) { $types[ $name ] = $catalog['elements'][ $name ]; }
		}

		return [
			'widgets' => $widgets,
			'elements' => $types,
			'controlMetadataVersion' => (int) ( $catalog['controlMetadataVersion'] ?? 0 ),
		];
	}

	private function instances( string $kind, array &$errors ): array {
		try {
			$plugin = ElementorPlugin::instance();
			if ( 'widget' === $kind ) {
				$manager = $plugin->widgets_manager ?? null;
				if ( ! $manager || ! method_exists( $manager, 'get_widget_types' ) ) { return []; }
				return (array) $manager->get_widget_types();
			}
			$manager = $plugin->elements_manager ?? null;
			if ( ! $manager || ! method_exists( $manager, 'get_element_types' ) ) { return []; }
			return (array) $manager->get_element_types();
		} catch ( \Throwable $error ) {
			$errors[] = $this->scan_error( $kind, '', 'registry', $error );
			return [];
		}
	}

	private function summarize_instance( object $instance, string $fallback_name, string $kind ): array {
		$errors = [];
		$name = $fallback_name;
		if ( method_exists( $instance, 'get_name' ) ) {
			try { $name = (string) $instance->get_name(); } catch ( \Throwable $error ) { $errors[] = $this->scan_error( $kind, $fallback_name, 'name', $error ); }
		}
		$title = $name;
		if ( method_exists( $instance, 'get_title' ) ) {
			try { $title = wp_strip_all_tags( (string) $instance->get_title() ); } catch ( \Throwable $error ) { $errors[] = $this->scan_error( $kind, $name, 'title', $error ); }
		}

		$entry = [
			'name' => $name,
			'title' => $title,
			'className' => get_class( $instance ),
			'categories' => [],
			'keywords' => [],
			'icon' => '',
			'showInPanel' => true,
			'isAtomic' => $this->is_atomic_instance( $instance ),
			'capabilitySource' => $this->is_atomic_instance( $instance ) ? 'atomic-controls+props-schema' : 'classic-controls',
			'detailLoaded' => false,
			'scanErrors' => $errors,
		];

		if ( method_exists( $instance, 'get_categories' ) ) {
			try { $entry['categories'] = array_values( array_map( 'strval', (array) $instance->get_categories() ) ); }
			catch ( \Throwable $error ) { $entry['scanErrors'][] = $this->scan_error( $kind, $name, 'categories', $error ); }
		}
		if ( method_exists( $instance, 'get_keywords' ) ) {
			try { $entry['keywords'] = array_values( array_map( 'strval', (array) $instance->get_keywords() ) ); }
			catch ( \Throwable $error ) { $entry['scanErrors'][] = $this->scan_error( $kind, $name, 'keywords', $error ); }
		}
		if ( method_exists( $instance, 'get_icon' ) ) {
			try { $entry['icon'] = (string) $instance->get_icon(); }
			catch ( \Throwable $error ) { $entry['scanErrors'][] = $this->scan_error( $kind, $name, 'icon', $error ); }
		}
		if ( method_exists( $instance, 'show_in_panel' ) ) {
			try { $entry['showInPanel'] = (bool) $instance->show_in_panel(); }
			catch ( \Throwable $error ) { $entry['scanErrors'][] = $this->scan_error( $kind, $name, 'panel-visibility', $error ); }
		}
		return $entry;
	}

	private function describe_instance( object $instance, string $fallback_name, bool $include_raw_metadata, string $kind ): array {
		$entry = $this->summarize_instance( $instance, $fallback_name, $kind );
		return $entry['isAtomic']
			? $this->describe_atomic_instance( $instance, $entry, $include_raw_metadata, $kind )
			: $this->describe_classic_instance( $instance, $entry, $include_raw_metadata, $kind );
	}

	private function describe_classic_instance( object $instance, array $entry, bool $include_raw_metadata, string $kind ): array {
		$name = (string) $entry['name'];
		$controls = [];
		$raw_controls = [];

		if ( method_exists( $instance, 'get_controls' ) ) {
			try {
				$raw_controls = (array) $instance->get_controls();
				foreach ( $raw_controls as $control_name => $control ) {
					if ( ! is_array( $control ) ) { continue; }
					try {
						$controls[ (string) $control_name ] = $this->describe_control( $control, $include_raw_metadata );
					} catch ( \Throwable $error ) {
						$entry['scanErrors'][] = $this->scan_error( $kind, $name, 'control:' . (string) $control_name, $error );
					}
				}
			} catch ( \Throwable $error ) {
				$entry['scanErrors'][] = $this->scan_error( $kind, $name, 'controls', $error );
			}
		}

		$entry['controls'] = $controls;
		$entry['controlCount'] = count( $controls );
		$entry['defaultSettings'] = $this->classic_defaults_from_controls( $raw_controls );
		$entry['defaultSettingsSource'] = 'control-metadata';
		$entry['persistedSettingsSource'] = 'document-instance-only';
		$entry['detailLoaded'] = true;
		return $entry;
	}

	private function describe_atomic_instance( object $instance, array $entry, bool $include_raw_metadata, string $kind ): array {
		$name = (string) $entry['name'];
		$atomic_tree = [];
		$props_schema = [];
		$controls = [];
		$defaults = [];

		try {
			$raw_atomic_controls = method_exists( $instance, 'get_atomic_controls' ) ? (array) $instance->get_atomic_controls() : [];
			$atomic_tree = $this->safe_metadata( $raw_atomic_controls, 0 );
			if ( ! is_array( $atomic_tree ) ) { $atomic_tree = []; }
		} catch ( \Throwable $error ) {
			$entry['scanErrors'][] = $this->scan_error( $kind, $name, 'atomic-controls', $error );
		}

		try {
			$class = get_class( $instance );
			$raw_schema = method_exists( $class, 'get_props_schema' ) ? (array) $class::get_props_schema() : [];
			foreach ( $raw_schema as $prop_name => $prop ) {
				try {
					$props_schema[ (string) $prop_name ] = $this->describe_atomic_prop( $prop, $include_raw_metadata );
					$initial = $props_schema[ (string) $prop_name ]['initialValue'] ?? null;
					$default = $props_schema[ (string) $prop_name ]['default'] ?? null;
					if ( null !== $initial ) { $defaults[ (string) $prop_name ] = $initial; }
					elseif ( null !== $default ) { $defaults[ (string) $prop_name ] = $default; }
				} catch ( \Throwable $error ) {
					$entry['scanErrors'][] = $this->scan_error( $kind, $name, 'atomic-prop:' . (string) $prop_name, $error );
				}
			}
		} catch ( \Throwable $error ) {
			$entry['scanErrors'][] = $this->scan_error( $kind, $name, 'atomic-props-schema', $error );
		}

		$this->flatten_atomic_controls( $atomic_tree, $props_schema, $controls );
		foreach ( $props_schema as $prop_name => $schema ) {
			if ( isset( $controls[ $prop_name ] ) ) { continue; }
			$controls[ $prop_name ] = [
				'type' => 'atomic-prop',
				'propType' => (string) ( $schema['key'] ?? $schema['kind'] ?? '' ),
				'label' => $prop_name,
				'description' => '',
				'responsive' => false,
				'dynamic' => false,
				'source' => 'atomic-props-schema',
				'bind' => $prop_name,
				'propSchema' => $schema,
			];
		}

		$entry['controls'] = $controls;
		$entry['controlCount'] = count( $controls );
		$entry['atomicUiControlCount'] = $this->count_atomic_control_nodes( $atomic_tree );
		$entry['atomicPropCount'] = count( $props_schema );
		$entry['atomicControls'] = $atomic_tree;
		$entry['atomicPropsSchema'] = $props_schema;
		$entry['defaultSettings'] = $defaults;
		$entry['defaultSettingsSource'] = 'atomic-props-schema';
		$entry['persistedSettingsSource'] = 'document-instance-only';
		$entry['baseSettings'] = $this->call_metadata_method( $instance, 'get_base_settings', $entry, $kind, $name );
		$entry['baseStyles'] = $this->call_metadata_method( $instance, 'get_base_styles', $entry, $kind, $name );
		$entry['baseStylesDictionary'] = $this->call_metadata_method( $instance, 'get_base_styles_dictionary', $entry, $kind, $name );
		$entry['detailLoaded'] = true;
		return $entry;
	}

	private function call_metadata_method( object $instance, string $method, array &$entry, string $kind, string $name ) {
		if ( ! method_exists( $instance, $method ) ) { return []; }
		try { return $this->safe_metadata( $instance->{$method}(), 0 ); }
		catch ( \Throwable $error ) {
			$entry['scanErrors'][] = $this->scan_error( $kind, $name, $method, $error );
			return [];
		}
	}

	private function is_atomic_instance( object $instance ): bool {
		$class = get_class( $instance );
		return method_exists( $instance, 'get_atomic_controls' ) && method_exists( $class, 'get_props_schema' );
	}

	private function classic_defaults_from_controls( array $controls ): array {
		$defaults = [];
		foreach ( $controls as $name => $control ) {
			if ( ! is_array( $control ) ) { continue; }
			$name = (string) $name;
			if ( array_key_exists( 'default', $control ) ) {
				$defaults[ $name ] = $this->safe_metadata( $control['default'], 0 );
			}
			foreach ( self::RESPONSIVE_DEFAULTS as $field => $device ) {
				if ( array_key_exists( $field, $control ) ) {
					$defaults[ $name . '_' . $device ] = $this->safe_metadata( $control[ $field ], 0 );
				}
			}
		}
		return $defaults;
	}

	private function describe_control( array $control, bool $include_raw_metadata ): array {
		$out = [];
		foreach ( self::CONTROL_KEYS as $key ) {
			if ( ! array_key_exists( $key, $control ) ) { continue; }
			$value = $this->safe_metadata( $control[ $key ], 0 );
			if ( null !== $value || null === $control[ $key ] ) { $out[ $key ] = $value; }
		}
		$out['responsive'] = ! empty( $control['responsive'] );
		$out['dynamic'] = ! empty( $control['dynamic']['active'] );
		$out['source'] = 'classic-control';
		if ( $include_raw_metadata ) {
			$raw = $this->safe_metadata( $control, 0 );
			$out['rawMetadata'] = is_array( $raw ) ? $raw : [];
		}
		return $out;
	}

	private function describe_atomic_prop( $prop, bool $include_raw_metadata ): array {
		$out = [ 'className' => is_object( $prop ) ? get_class( $prop ) : gettype( $prop ) ];
		if ( is_object( $prop ) ) {
			$methods = [
				'get_type' => 'kind',
				'get_default' => 'default',
				'get_initial_value' => 'initialValue',
				'get_meta' => 'meta',
				'get_settings' => 'settings',
				'get_dependencies' => 'dependencies',
				'get_aliases' => 'aliases',
				'to_json_schema' => 'jsonSchema',
			];
			foreach ( $methods as $method => $key ) {
				if ( ! method_exists( $prop, $method ) ) { continue; }
				try { $out[ $key ] = $this->safe_metadata( $prop->{$method}(), 0 ); }
				catch ( \Throwable $error ) { $out[ $key . 'Error' ] = wp_strip_all_tags( $error->getMessage() ); }
			}
			$class = get_class( $prop );
			if ( method_exists( $class, 'get_key' ) ) {
				try { $out['key'] = (string) $class::get_key(); } catch ( \Throwable $error ) { $out['keyError'] = wp_strip_all_tags( $error->getMessage() ); }
			}
		}
		if ( $include_raw_metadata ) { $out['rawMetadata'] = $this->safe_metadata( $prop, 0 ); }
		return $out;
	}

	private function flatten_atomic_controls( array $nodes, array $props_schema, array &$controls, string $path = '' ): void {
		foreach ( $nodes as $index => $node ) {
			if ( ! is_array( $node ) ) { continue; }
			$type = (string) ( $node['type'] ?? '' );
			$value = is_array( $node['value'] ?? null ) ? $node['value'] : [];
			if ( 'section' === $type ) {
				$id = (string) ( $value['id'] ?? 'section-' . $index );
				$items = is_array( $value['items'] ?? null ) ? $value['items'] : [];
				$this->flatten_atomic_controls( $items, $props_schema, $controls, trim( $path . '/' . $id, '/' ) );
				continue;
			}
			if ( 'control' !== $type ) {
				if ( isset( $value['items'] ) && is_array( $value['items'] ) ) {
					$this->flatten_atomic_controls( $value['items'], $props_schema, $controls, $path );
				}
				continue;
			}

			$bind = (string) ( $value['bind'] ?? '' );
			$key = '' !== $bind ? $bind : 'atomic_control_' . count( $controls );
			if ( isset( $controls[ $key ] ) ) {
				$key .= '__' . count( $controls );
			}
			$props = is_array( $value['props'] ?? null ) ? $value['props'] : [];
			$control = [
				'type' => (string) ( $value['type'] ?? 'atomic' ),
				'label' => (string) ( $value['label'] ?? $bind ),
				'description' => (string) ( $value['description'] ?? '' ),
				'responsive' => false,
				'dynamic' => false,
				'source' => 'atomic-control',
				'bind' => $bind,
				'sectionPath' => $path,
				'props' => $props,
				'meta' => $this->safe_metadata( $value['meta'] ?? null, 0 ),
			];
			foreach ( [ 'options', 'min', 'max', 'step', 'placeholder', 'multiple', 'size_units', 'range' ] as $common ) {
				if ( array_key_exists( $common, $props ) ) { $control[ $common ] = $props[ $common ]; }
			}
			if ( '' !== $bind && isset( $props_schema[ $bind ] ) ) { $control['propSchema'] = $props_schema[ $bind ]; }
			$controls[ $key ] = $control;
		}
	}

	private function count_atomic_control_nodes( array $nodes ): int {
		$count = 0;
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) { continue; }
			$type = (string) ( $node['type'] ?? '' );
			$value = is_array( $node['value'] ?? null ) ? $node['value'] : [];
			if ( 'control' === $type ) { $count++; }
			if ( isset( $value['items'] ) && is_array( $value['items'] ) ) { $count += $this->count_atomic_control_nodes( $value['items'] ); }
		}
		return $count;
	}

	private function safe_metadata( $value, int $depth ) {
		if ( $depth > self::MAX_DEPTH ) { return '[TRUNCATED]'; }
		if ( $value instanceof \JsonSerializable ) {
			try { return $this->safe_metadata( $value->jsonSerialize(), $depth + 1 ); }
			catch ( \Throwable $error ) { return [ '__serialization_error__' => wp_strip_all_tags( $error->getMessage() ) ?: get_class( $error ) ]; }
		}
		if ( $value instanceof \stdClass ) { return $this->safe_metadata( get_object_vars( $value ), $depth + 1 ); }
		if ( is_array( $value ) ) {
			$out = [];
			$count = 0;
			foreach ( $value as $key => $child ) {
				if ( $count++ >= self::MAX_ARRAY_ITEMS ) { $out['__cresco_truncated__'] = true; break; }
				if ( is_resource( $child ) ) { continue; }
				$serialized = $this->safe_metadata( $child, $depth + 1 );
				if ( is_object( $child ) && null === $serialized ) { continue; }
				$out[ $key ] = $serialized;
			}
			return $out;
		}
		if ( is_string( $value ) ) { return strlen( $value ) > self::MAX_STRING ? substr( $value, 0, self::MAX_STRING ) . '…' : $value; }
		if ( is_null( $value ) || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return $value; }
		return null;
	}

	private function scan_error( string $kind, string $name, string $stage, \Throwable $error ): array {
		$message = wp_strip_all_tags( $error->getMessage() );
		return [
			'kind' => $kind,
			'name' => $name,
			'stage' => $stage,
			'message' => '' !== $message ? $message : get_class( $error ),
		];
	}

	private function notes( bool $include_raw_metadata ): array {
		$notes = [
			'Classic Elementor controls are read from get_controls(); defaults are derived from control metadata without calling get_settings() on registry prototypes.',
			'Atomic/V4 Elementor capabilities are read from get_atomic_controls() plus get_props_schema(); schema-only props are normalized into the controls map so AI can see every editable atomic property.',
			'Persisted element settings remain authoritative and must come from a real Elementor document instance, not a registry prototype.',
			'Runtime inspector loads widget/element details on demand so one addon cannot crash or exhaust memory for the whole catalog.',
			'Unknown Elementor element fields are preserved by Cresco Layer during lossless replace/import operations.',
		];
		if ( $include_raw_metadata ) {
			$notes[] = 'rawMetadata includes serializable classic control metadata and JsonSerializable Atomic control/prop metadata; resources and unsupported runtime objects are omitted.';
		}
		return $notes;
	}

	private function collect_types( array $elements, array &$widget_names, array &$element_names ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$el_type = (string) ( $element['elType'] ?? '' );
			$widget_type = (string) ( $element['widgetType'] ?? '' );
			if ( '' !== $el_type ) { $element_names[] = $el_type; }
			if ( '' !== $widget_type ) { $widget_names[] = $widget_type; }
			$this->collect_types( (array) ( $element['elements'] ?? [] ), $widget_names, $element_names );
		}
	}
}
