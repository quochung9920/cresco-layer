<?php
namespace CrescoLayer\Elementor\ThemeConditions;

use ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base;

final class UserRoleCondition extends Condition_Base {
	public function __construct( private string $role_slug, private string $role_label ) { parent::__construct(); }
	public static function get_type(): string { return 'cresco_user_role'; }
	public function get_name(): string { return 'cresco_role_' . sanitize_key( $this->role_slug ); }
	public function get_label(): string { return sprintf( esc_html__( '%s role', 'cresco-layer' ), $this->role_label ); }
	public function check( $args ): bool { return in_array( $this->role_slug, (array) wp_get_current_user()->roles, true ); }
}
