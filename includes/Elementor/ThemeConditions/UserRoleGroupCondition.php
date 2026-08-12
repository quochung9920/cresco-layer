<?php
namespace CrescoLayer\Elementor\ThemeConditions;

use ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base;

final class UserRoleGroupCondition extends Condition_Base {
	public static function get_type(): string { return 'general'; }
	public function get_name(): string { return 'cresco_user_role'; }
	public function get_label(): string { return esc_html__( 'Cresco · User role', 'cresco-layer' ); }
	public function get_all_label(): string { return esc_html__( 'Any logged-in role', 'cresco-layer' ); }
	public function check( $args ): bool { return is_user_logged_in(); }

	public function register_sub_conditions(): void {
		$roles = wp_roles()->get_names();
		foreach ( $roles as $slug => $label ) {
			$this->register_sub_condition( new UserRoleCondition( (string) $slug, (string) $label ) );
		}
	}
}
