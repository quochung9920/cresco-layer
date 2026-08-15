<?php
namespace CrescoLayer\SiteSettings\Gateway;

use Elementor\Plugin as ElementorPlugin;

/**
 * KitGateway backed by the live Elementor runtime.
 *
 * Writes go through the Kit document's own save(), not update_post_meta(). Elementor's Kit save
 * path does more than persist an array — it runs the document's own sanitisation and bookkeeping —
 * so writing the meta directly would produce a Kit that looks right in the database but is not
 * consistent with what Elementor expects to read back.
 *
 * The Kit ID is always resolved through the Kits manager. Nothing here assumes an ID.
 */
final class ElementorKitGateway implements KitGateway {
	private ?array $state = null;

	public function is_available(): bool { return $this->read()['available']; }
	public function kit_id(): int { return $this->read()['postId']; }
	public function controls(): array { return $this->read()['controls']; }
	public function settings(): array { return $this->read()['settings']; }
	public function errors(): array { return $this->read()['errors']; }

	public function refresh(): void { $this->state = null; }

	public function save( array $settings ): bool {
		$kit = $this->kit_document();
		if ( ! $kit ) { return false; }

		// Kit::save() expects the document payload shape. A Kit carries no elements, but the key must
		// be present or Elementor treats the save as a partial document write.
		$result = $kit->save( [ 'elements' => [], 'settings' => $settings ] );
		$this->refresh();
		return false !== $result;
	}

	/** The active Kit document, or null when Elementor cannot give us an editable one. */
	private function kit_document(): ?object {
		try {
			if ( ! class_exists( ElementorPlugin::class ) ) { return null; }
			$plugin = ElementorPlugin::instance();
			$manager = $plugin->kits_manager ?? null;
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_active_kit' ) ) { return null; }
			$kit = $manager->get_active_kit();
			if ( ! is_object( $kit ) || ! method_exists( $kit, 'save' ) ) { return null; }
			return $kit;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	private function read(): array {
		if ( null !== $this->state ) { return $this->state; }

		$state = [ 'available' => false, 'postId' => 0, 'settings' => [], 'controls' => [], 'errors' => [] ];

		try {
			if ( ! class_exists( ElementorPlugin::class ) ) {
				$state['errors'][] = [ 'stage' => 'elementor', 'message' => 'Elementor is not loaded.' ];
				return $this->state = $state;
			}
			$plugin = ElementorPlugin::instance();
			$manager = $plugin->kits_manager ?? null;
			if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_active_kit' ) ) {
				$state['errors'][] = [ 'stage' => 'kits-manager', 'message' => 'Elementor Kits manager is unavailable.' ];
				return $this->state = $state;
			}
			$kit = $manager->get_active_kit();
			if ( ! is_object( $kit ) ) {
				$state['errors'][] = [ 'stage' => 'active-kit', 'message' => 'Elementor has no active Kit.' ];
				return $this->state = $state;
			}

			$post = method_exists( $kit, 'get_post' ) ? $kit->get_post() : null;
			$state['postId'] = $post ? (int) $post->ID : 0;

			if ( method_exists( $kit, 'get_settings_for_display' ) ) {
				$settings = $kit->get_settings_for_display();
				$state['settings'] = is_array( $settings ) ? $settings : [];
			}
			if ( method_exists( $kit, 'get_controls' ) ) {
				$controls = $kit->get_controls();
				$state['controls'] = is_array( $controls ) ? $controls : [];
			}

			// An unreadable control list is a hard stop: capability discovery is what keeps the engine
			// from inventing setting keys, so writing without it is exactly the failure mode to avoid.
			if ( ! $state['controls'] ) {
				$state['errors'][] = [ 'stage' => 'kit-controls', 'message' => 'The active Kit exposed no controls, so capability discovery is not possible.' ];
				return $this->state = $state;
			}

			$state['available'] = $state['postId'] > 0 && current_user_can( 'edit_post', $state['postId'] );
			if ( ! $state['available'] && $state['postId'] > 0 ) {
				$state['errors'][] = [ 'stage' => 'permissions', 'message' => 'The current user cannot edit the active Elementor Kit.' ];
			}
		} catch ( \Throwable $error ) {
			$state['errors'][] = [ 'stage' => 'kit-read', 'message' => wp_strip_all_tags( $error->getMessage() ) ];
		}

		return $this->state = $state;
	}
}
