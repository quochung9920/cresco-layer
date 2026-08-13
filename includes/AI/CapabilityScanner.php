<?php
namespace CrescoLayer\AI;

use Elementor\Plugin as ElementorPlugin;

final class CapabilityScanner {
	private const MAX_DEPTH = 8;
	private const MAX_STRING = 32768;
	private const MAX_ARRAY_ITEMS = 5000;
	private const CONTROL_KEYS = [
		'type', 'label', 'description', 'default', 'options', 'placeholder', 'separator', 'show_label', 'label_block',
		'responsive', 'dynamic', 'frontend_available', 'size_units', 'range', 'min', 'max', 'step', 'selectors',
		'selectors_dictionary', 'condition', 'conditions', 'render_type', 'prefix_class', 'classes', 'devices',
		'tablet_default', 'mobile_default', 'widescreen_default', 'laptop_default', 'tablet_extra_default',
		'mobile_extra_default', 'group_prefix', 'group_type', 'ai', 'multiple', 'toggle', 'rows', 'language',
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
			'controlMetadataVersion' => $include_raw_metadata ? 4 : 2,
			'notes' => $this->notes( $include_raw_metadata ),
			'scanErrors' => $errors,
		];
	}

	/**
	 * Lightweight registry index for the admin inspector. This deliberately does
	 * not call get_controls() or get_settings(), so one large/broken addon widget
	 * cannot exhaust memory or crash the whole catalog request.
	 */
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
			'controlMetadataVersion' => 4,
			'notes' => $this->notes( true ),
			'scanErrors' => $errors,
		];
	}

	/**
	 * Load one registered widget/element in isolation. Errors from Elementor or
	 * an addon are returned on that entry instead of terminating the full index.
	 */
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
		$name = (string) $entry['name'];
		$controls = [];

		if ( method_exists( $instance, 'get_controls' ) ) {
			try {
				foreach ( (array) $instance->get_controls() as $control_name => $control ) {
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

		$defaults = [];
		if ( method_exists( $instance, 'get_settings' ) ) {
			try {
				$settings = $instance->get_settings();
				if ( is_array( $settings ) ) { $defaults = $this->safe_metadata( $settings, 0 ); }
			} catch ( \Throwable $error ) {
				$entry['scanErrors'][] = $this->scan_error( $kind, $name, 'settings', $error );
			}
		}

		$entry['controls'] = $controls;
		$entry['controlCount'] = count( $controls );
		$entry['defaultSettings'] = is_array( $defaults ) ? $defaults : [];
		$entry['detailLoaded'] = true;
		return $entry;
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
		if ( $include_raw_metadata ) {
			$raw = $this->safe_metadata( $control, 0 );
			$out['rawMetadata'] = is_array( $raw ) ? $raw : [];
		}
		return $out;
	}

	private function safe_metadata( $value, int $depth ) {
		if ( $depth > self::MAX_DEPTH ) { return '[TRUNCATED]'; }
		if ( is_array( $value ) ) {
			$out = [];
			$count = 0;
			foreach ( $value as $key => $child ) {
				if ( $count++ >= self::MAX_ARRAY_ITEMS ) { $out['__cresco_truncated__'] = true; break; }
				if ( is_object( $child ) || is_resource( $child ) ) { continue; }
				$out[ $key ] = $this->safe_metadata( $child, $depth + 1 );
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
			'Raw element settings remain authoritative for persisted values.',
			'Control metadata describes values the current Elementor installation can accept even when a control is still at its default.',
			'Runtime inspector loads widget/element details on demand so one addon cannot crash or exhaust memory for the whole catalog.',
			'Unknown Elementor element fields are preserved by Cresco Layer during lossless replace/import operations.',
		];
		if ( $include_raw_metadata ) {
			$notes[] = 'rawMetadata contains every serializable control field exposed by the requested Elementor runtime entry; object/resource/callback values are intentionally omitted.';
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
