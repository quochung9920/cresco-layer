<?php
namespace CrescoLayer\AI;

use Elementor\Plugin as ElementorPlugin;

final class CapabilityScanner {
	private const MAX_DEPTH = 8;
	private const MAX_STRING = 32768;
	private const CONTROL_KEYS = [
		'type', 'label', 'description', 'default', 'options', 'placeholder', 'separator', 'show_label', 'label_block',
		'responsive', 'dynamic', 'frontend_available', 'size_units', 'range', 'min', 'max', 'step', 'selectors',
		'selectors_dictionary', 'condition', 'conditions', 'render_type', 'prefix_class', 'classes', 'devices',
		'tablet_default', 'mobile_default', 'widescreen_default', 'laptop_default', 'tablet_extra_default',
		'mobile_extra_default', 'group_prefix', 'group_type', 'ai', 'multiple', 'toggle', 'rows', 'language',
	];

	public function catalog(): array {
		$plugin = ElementorPlugin::instance();
		$widgets = [];
		$elements = [];

		$widget_manager = $plugin->widgets_manager ?? null;
		if ( $widget_manager && method_exists( $widget_manager, 'get_widget_types' ) ) {
			foreach ( (array) $widget_manager->get_widget_types() as $name => $widget ) {
				if ( ! is_object( $widget ) ) { continue; }
				$entry = $this->describe_instance( $widget, (string) $name );
				$widgets[ $entry['name'] ] = $entry;
			}
		}

		$elements_manager = $plugin->elements_manager ?? null;
		if ( $elements_manager && method_exists( $elements_manager, 'get_element_types' ) ) {
			foreach ( (array) $elements_manager->get_element_types() as $name => $element ) {
				if ( ! is_object( $element ) ) { continue; }
				$entry = $this->describe_instance( $element, (string) $name );
				$elements[ $entry['name'] ] = $entry;
			}
		}

		return [
			'widgets' => $widgets,
			'elements' => $elements,
			'controlMetadataVersion' => 2,
			'notes' => [
				'Raw element settings remain authoritative for persisted values.',
				'Control metadata describes values the current Elementor installation can accept even when a control is still at its default.',
				'Unknown Elementor element fields are preserved by Cresco Layer during lossless replace/import operations.',
			],
		];
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
			'controlMetadataVersion' => $catalog['controlMetadataVersion'],
		];
	}

	private function describe_instance( object $instance, string $fallback_name ): array {
		$name = method_exists( $instance, 'get_name' ) ? (string) $instance->get_name() : $fallback_name;
		$title = method_exists( $instance, 'get_title' ) ? wp_strip_all_tags( (string) $instance->get_title() ) : $name;
		$controls = [];
		if ( method_exists( $instance, 'get_controls' ) ) {
			foreach ( (array) $instance->get_controls() as $control_name => $control ) {
				if ( ! is_array( $control ) ) { continue; }
				$controls[ (string) $control_name ] = $this->describe_control( $control );
			}
		}

		$defaults = [];
		if ( method_exists( $instance, 'get_settings' ) ) {
			try {
				$settings = $instance->get_settings();
				if ( is_array( $settings ) ) { $defaults = $this->safe_metadata( $settings, 0 ); }
			} catch ( \Throwable $error ) {
				$defaults = [];
			}
		}

		$entry = [
			'name' => $name,
			'title' => $title,
			'controls' => $controls,
			'defaultSettings' => $defaults,
		];
		if ( method_exists( $instance, 'get_categories' ) ) { $entry['categories'] = array_values( array_map( 'strval', (array) $instance->get_categories() ) ); }
		if ( method_exists( $instance, 'get_keywords' ) ) { $entry['keywords'] = array_values( array_map( 'strval', (array) $instance->get_keywords() ) ); }
		return $entry;
	}

	private function describe_control( array $control ): array {
		$out = [];
		foreach ( self::CONTROL_KEYS as $key ) {
			if ( ! array_key_exists( $key, $control ) ) { continue; }
			$value = $this->safe_metadata( $control[ $key ], 0 );
			if ( null !== $value || null === $control[ $key ] ) { $out[ $key ] = $value; }
		}
		$out['responsive'] = ! empty( $control['responsive'] );
		$out['dynamic'] = ! empty( $control['dynamic']['active'] );
		return $out;
	}

	private function safe_metadata( $value, int $depth ) {
		if ( $depth > self::MAX_DEPTH ) { return '[TRUNCATED]' ; }
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $key => $child ) {
				if ( is_object( $child ) || is_resource( $child ) ) { continue; }
				$out[ $key ] = $this->safe_metadata( $child, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $value ) ) { return strlen( $value ) > self::MAX_STRING ? substr( $value, 0, self::MAX_STRING ) . '…' : $value; }
		if ( is_null( $value ) || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return $value; }
		return null;
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
