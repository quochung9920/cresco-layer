<?php
namespace CrescoLayer\Elementor\FormActions;

use Elementor\Controls_Manager;
use ElementorPro\Modules\Forms\Classes\Action_Base;

final class WorkflowEventAction extends Action_Base {
	public function get_name(): string { return 'cresco-workflow-event'; }
	public function get_label(): string { return esc_html__( 'Cresco Workflow Event', 'cresco-layer' ); }

	public function register_settings_section( $widget ): void {
		$widget->start_controls_section( 'cresco_workflow_section', [
			'label' => esc_html__( 'Cresco Workflow Event', 'cresco-layer' ),
			'condition' => [ 'submit_actions' => $this->get_name() ],
		] );
		$widget->add_control( 'cresco_workflow_event_name', [
			'label' => esc_html__( 'Event Name', 'cresco-layer' ),
			'type' => Controls_Manager::TEXT,
			'default' => 'form.submitted',
			'description' => esc_html__( 'A local WordPress action will be fired. No submission data is sent externally by Cresco Layer.', 'cresco-layer' ),
		] );
		$widget->end_controls_section();
	}

	public function run( $record, $ajax_handler ): void {
		$event = sanitize_key( str_replace( '.', '_', (string) $record->get_form_settings( 'cresco_workflow_event_name' ) ) );
		if ( '' === $event ) { $event = 'form_submitted'; }
		$raw = (array) $record->get( 'fields' );
		$fields = [];
		foreach ( $raw as $id => $field ) {
			$fields[ sanitize_key( (string) $id ) ] = isset( $field['value'] ) && is_scalar( $field['value'] ) ? sanitize_textarea_field( (string) $field['value'] ) : '';
		}
		$payload = [
			'event' => $event,
			'form_name' => sanitize_text_field( (string) $record->get_form_settings( 'form_name' ) ),
			'fields' => $fields,
			'created_at' => gmdate( 'c' ),
		];
		do_action( 'cresco_layer/workflow/' . $event, $payload, $record, $ajax_handler );
		do_action( 'cresco_layer/workflow_event', $payload, $record, $ajax_handler );
	}

	public function on_export( $element ): array {
		return $element;
	}
}
