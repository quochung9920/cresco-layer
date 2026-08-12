<?php
namespace CrescoLayer\Elementor\ThemeConditions;

use ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base;

final class LoggedInCondition extends Condition_Base {
	public static function get_type(): string { return 'general'; }
	public function get_name(): string { return 'cresco_logged_in'; }
	public function get_label(): string { return esc_html__( 'Cresco · Logged-in visitor', 'cresco-layer' ); }
	public function check( $args ): bool { return is_user_logged_in(); }
}
