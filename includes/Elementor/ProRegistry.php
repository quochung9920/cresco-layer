<?php
namespace CrescoLayer\Elementor;

use CrescoLayer\Elementor\FormActions\WorkflowEventAction;
use CrescoLayer\Elementor\ThemeConditions\LoggedInCondition;
use CrescoLayer\Elementor\ThemeConditions\UserRoleGroupCondition;

final class ProRegistry {
	public function register_hooks(): void {
		add_action( 'elementor/theme/register_conditions', [ $this, 'register_conditions' ] );
		add_action( 'elementor_pro/forms/actions/register', [ $this, 'register_form_actions' ] );
	}

	public function register_conditions( $conditions_manager ): void {
		$general = $conditions_manager->get_condition( 'general' );
		if ( $general ) {
			$general->register_sub_condition( new LoggedInCondition() );
			$general->register_sub_condition( new UserRoleGroupCondition() );
		}
	}

	public function register_form_actions( $registrar ): void {
		$registrar->register( new WorkflowEventAction() );
	}
}
