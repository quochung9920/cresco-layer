<?php
namespace CrescoLayer\Support;

final class Requirements {
	public function is_elementor_available(): bool {
		return defined( 'ELEMENTOR_VERSION' ) && class_exists( '\\Elementor\\Plugin' );
	}

	public function is_elementor_pro_available(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' ) && class_exists( '\\ElementorPro\\Plugin' );
	}

	public function render_elementor_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Cresco Layer requires Elementor to be installed and active.', 'cresco-layer' ) . '</p></div>';
	}

	public function render_pro_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Cresco Layer is active. Elementor Pro is recommended and required for Dynamic Tags, Pro Forms and Theme Conditions integrations.', 'cresco-layer' ) . '</p></div>';
	}
}
