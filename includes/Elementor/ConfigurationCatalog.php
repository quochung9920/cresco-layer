<?php
namespace CrescoLayer\Elementor;

use CrescoLayer\AI\CapabilityScanner;
use Elementor\Plugin as ElementorPlugin;

final class ConfigurationCatalog {
	private CapabilityScanner $scanner;

	public function __construct( ?CapabilityScanner $scanner = null ) {
		$this->scanner = $scanner ?? new CapabilityScanner();
	}

	public function get(): array {
		$catalog = $this->scanner->catalog( true );
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
				'widgetControls' => $this->control_count( $widgets ),
				'elementControls' => $this->control_count( $elements ),
			],
			'breakpoints' => $this->breakpoints(),
			'activeKit' => $this->active_kit(),
			'widgets' => $widgets,
			'elements' => $elements,
			'controlMetadataVersion' => (int) ( $catalog['controlMetadataVersion'] ?? 0 ),
			'notes' => array_values( array_map( 'strval', (array) ( $catalog['notes'] ?? [] ) ) ),
		];

		return $this->redact( $result );
	}

	private function control_count( array $entries ): int {
		$count = 0;
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) && is_array( $entry['controls'] ?? null ) ) {
				$count += count( $entry['controls'] );
			}
		}
		return $count;
	}

	private function breakpoints(): array {
		$plugin = ElementorPlugin::instance();
		if ( ! isset( $plugin->breakpoints ) || ! method_exists( $plugin->breakpoints, 'get_active_breakpoints' ) ) {
			return [];
		}

		$out = [];
		foreach ( (array) $plugin->breakpoints->get_active_breakpoints() as $key => $breakpoint ) {
			if ( ! is_object( $breakpoint ) ) { continue; }
			$out[ (string) $key ] = [
				'label' => method_exists( $breakpoint, 'get_label' ) ? wp_strip_all_tags( (string) $breakpoint->get_label() ) : (string) $key,
				'value' => method_exists( $breakpoint, 'get_value' ) ? $breakpoint->get_value() : null,
				'direction' => method_exists( $breakpoint, 'get_direction' ) ? (string) $breakpoint->get_direction() : '',
			];
		}
		return $out;
	}

	private function active_kit(): array {
		$plugin = ElementorPlugin::instance();
		if ( ! isset( $plugin->kits_manager ) || ! method_exists( $plugin->kits_manager, 'get_active_kit' ) ) {
			return [];
		}
		$kit = $plugin->kits_manager->get_active_kit();
		if ( ! $kit ) { return []; }

		$settings = [];
		if ( method_exists( $kit, 'get_settings_for_display' ) ) {
			$value = $kit->get_settings_for_display();
			$settings = is_array( $value ) ? $value : [];
		}

		$post = method_exists( $kit, 'get_post' ) ? $kit->get_post() : null;
		return [
			'id' => $post ? (int) $post->ID : 0,
			'title' => $post ? (string) $post->post_title : '',
			'settings' => $settings,
		];
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
