<?php
namespace CrescoLayer\Elementor;

use CrescoLayer\AI\CapabilityScanner;
use Elementor\Plugin as ElementorPlugin;

final class ConfigurationCatalog {
	private CapabilityScanner $scanner;

	public function __construct( ?CapabilityScanner $scanner = null ) {
		$this->scanner = $scanner ?? new CapabilityScanner();
	}

	/**
	 * Backward-compatible entry point. The runtime inspector now returns a
	 * lightweight registry index; individual controls are loaded on demand.
	 */
	public function get(): array {
		return $this->summary();
	}

	public function summary(): array {
		$catalog = $this->scanner->catalog_index();
		$widgets = is_array( $catalog['widgets'] ?? null ) ? $catalog['widgets'] : [];
		$elements = is_array( $catalog['elements'] ?? null ) ? $catalog['elements'] : [];

		$result = [
			'generatedAt' => gmdate( 'c' ),
			'environment' => [
				'wordpressVersion' => get_bloginfo( 'version' ),
				'phpVersion' => PHP_VERSION,
				'elementorVersion' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
				'elementorProVersion' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
			],
			'counts' => [
				'widgets' => count( $widgets ),
				'elementTypes' => count( $elements ),
				'widgetControls' => null,
				'elementControls' => null,
			],
			'breakpoints' => $this->runtime_breakpoints(),
			'activeKit' => $this->active_kit(),
			'widgets' => $widgets,
			'elements' => $elements,
			'lazyDetails' => true,
			'controlMetadataVersion' => (int) ( $catalog['controlMetadataVersion'] ?? 0 ),
			'notes' => array_values( array_map( 'strval', (array) ( $catalog['notes'] ?? [] ) ) ),
			'scanErrors' => array_values( (array) ( $catalog['scanErrors'] ?? [] ) ),
		];

		return $this->redact( $result );
	}

	public function detail( string $kind, string $name ): array {
		if ( ! in_array( $kind, [ 'widget', 'element' ], true ) ) {
			throw new \InvalidArgumentException( 'Elementor catalog kind must be widget or element.' );
		}
		if ( '' === $name || strlen( $name ) > 160 || ! preg_match( '/^[A-Za-z0-9_.:-]+$/', $name ) ) {
			throw new \InvalidArgumentException( 'Elementor catalog entry name is invalid.' );
		}

		return $this->redact( [
			'generatedAt' => gmdate( 'c' ),
			'kind' => $kind,
			'name' => $name,
			'entry' => $this->scanner->catalog_entry( $kind, $name, true ),
		] );
	}

	/**
	 * Lightweight breakpoint lookup for hot editor paths such as Widget Skills.
	 * This deliberately avoids catalog_index(), which enumerates every registered
	 * widget/element and is unnecessary when one selected element is being edited.
	 */
	public function runtime_breakpoints(): array {
		try {
			$plugin = ElementorPlugin::instance();
			if ( ! isset( $plugin->breakpoints ) || ! method_exists( $plugin->breakpoints, 'get_active_breakpoints' ) ) {
				return [];
			}
			$active = (array) $plugin->breakpoints->get_active_breakpoints();
		} catch ( \Throwable $error ) {
			return [ '__error' => wp_strip_all_tags( $error->getMessage() ) ?: get_class( $error ) ];
		}

		$out = [];
		foreach ( $active as $key => $breakpoint ) {
			if ( ! is_object( $breakpoint ) ) { continue; }
			try {
				$out[ (string) $key ] = [
					'label' => method_exists( $breakpoint, 'get_label' ) ? wp_strip_all_tags( (string) $breakpoint->get_label() ) : (string) $key,
					'value' => method_exists( $breakpoint, 'get_value' ) ? $breakpoint->get_value() : null,
					'direction' => method_exists( $breakpoint, 'get_direction' ) ? (string) $breakpoint->get_direction() : '',
				];
			} catch ( \Throwable $error ) {
				$out[ (string) $key ] = [ '__error' => wp_strip_all_tags( $error->getMessage() ) ?: get_class( $error ) ];
			}
		}
		return $out;
	}

	private function active_kit(): array {
		try {
			$plugin = ElementorPlugin::instance();
			if ( ! isset( $plugin->kits_manager ) || ! method_exists( $plugin->kits_manager, 'get_active_kit' ) ) {
				return [];
			}
			$kit = $plugin->kits_manager->get_active_kit();
			if ( ! $kit ) { return []; }

			$settings = [];
			if ( method_exists( $kit, 'get_settings_for_display' ) ) {
				try {
					$value = $kit->get_settings_for_display();
					$settings = is_array( $value ) ? $value : [];
				} catch ( \Throwable $error ) {
					$settings = [ '__error' => wp_strip_all_tags( $error->getMessage() ) ?: get_class( $error ) ];
				}
			}

			$post = null;
			if ( method_exists( $kit, 'get_post' ) ) {
				try { $post = $kit->get_post(); } catch ( \Throwable $error ) { $post = null; }
			}
			return [
				'id' => $post ? (int) $post->ID : 0,
				'title' => $post ? (string) $post->post_title : '',
				'settings' => $settings,
			];
		} catch ( \Throwable $error ) {
			return [ '__error' => wp_strip_all_tags( $error->getMessage() ) ?: get_class( $error ) ];
		}
	}

	private function redact( $value, string $key = '' ) {
		if ( preg_match( '/(?:secret|password|passwd|api[_-]?key|private[_-]?key|access[_-]?token|refresh[_-]?token|authorization|nonce|smtp[_-]?pass|webhook[_-]?secret)/i', $key ) ) {
			return '[REDACTED]';
		}
		if ( ! is_array( $value ) ) { return $value; }
		foreach ( $value as $child_key => $child ) {
			$value[ $child_key ] = $this->redact( $child, (string) $child_key );
		}
		return $value;
	}
}
