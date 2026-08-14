<?php
namespace CrescoLayer\DesignSystem;

use Elementor\Plugin as ElementorPlugin;

/**
 * Runtime access to the active Elementor Kit.
 *
 * The Kit is an Elementor Document (Kit extends PageBase), and its values live in the same
 * `_elementor_page_settings` meta that the AI patch pipeline already reads and writes. That is what
 * lets the design standard reuse the existing validated path — patch, semantic guard, preview,
 * history and rollback — instead of introducing a second way to write to Elementor.
 *
 * Control names are always read from the live Kit rather than hardcoded: Kit control names differ
 * between Elementor versions, and the plugin's core rule is that a setting key is never invented.
 * The accessors live in KitSource so the rules can be tested against fixture data.
 */
final class KitReader extends KitSource {
	private ?array $cache = null;

	public function read(): array {
		if ( null !== $this->cache ) { return $this->cache; }

		$result = [ 'available' => false, 'postId' => 0, 'settings' => [], 'controls' => [], 'breakpoints' => [], 'errors' => [] ];

		try {
			$plugin = ElementorPlugin::instance();
			$manager = $plugin->kits_manager ?? null;
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_active_kit' ) ) {
				$result['errors'][] = [ 'stage' => 'kit-manager', 'message' => 'Elementor Kit manager is unavailable.' ];
				return $this->cache = $result;
			}
			$kit = $manager->get_active_kit();
			if ( ! is_object( $kit ) ) {
				$result['errors'][] = [ 'stage' => 'active-kit', 'message' => 'Elementor has no active Kit.' ];
				return $this->cache = $result;
			}

			$post = method_exists( $kit, 'get_post' ) ? $kit->get_post() : null;
			$result['postId'] = $post ? (int) $post->ID : 0;

			if ( method_exists( $kit, 'get_settings_for_display' ) ) {
				$settings = $kit->get_settings_for_display();
				$result['settings'] = is_array( $settings ) ? $settings : [];
			}
			$result['controls'] = $this->controls( $kit, $result['errors'] );
			$result['breakpoints'] = $this->breakpoints( $result['errors'] );
			$result['available'] = $result['postId'] > 0;
		} catch ( \Throwable $error ) {
			$result['errors'][] = [ 'stage' => 'kit-read', 'message' => wp_strip_all_tags( $error->getMessage() ) ];
		}

		return $this->cache = $result;
	}

	private function controls( object $kit, array &$errors ): array {
		try {
			if ( ! method_exists( $kit, 'get_controls' ) ) { return []; }
			$controls = $kit->get_controls();
			return is_array( $controls ) ? $controls : [];
		} catch ( \Throwable $error ) {
			$errors[] = [ 'stage' => 'kit-controls', 'message' => wp_strip_all_tags( $error->getMessage() ) ];
			return [];
		}
	}

	private function breakpoints( array &$errors ): array {
		try {
			$plugin = ElementorPlugin::instance();
			$manager = $plugin->breakpoints ?? null;
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_breakpoints_config' ) ) { return []; }
			$config = $manager->get_breakpoints_config();
			return is_array( $config ) ? $config : [];
		} catch ( \Throwable $error ) {
			$errors[] = [ 'stage' => 'kit-breakpoints', 'message' => wp_strip_all_tags( $error->getMessage() ) ];
			return [];
		}
	}
}
