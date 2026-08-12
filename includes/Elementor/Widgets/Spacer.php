<?php
namespace CrescoLayer\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

final class Spacer extends Widget_Base {
	public function get_name(): string { return 'cresco-spacer'; }
	public function get_title(): string { return esc_html__( 'Cresco Spacer', 'cresco-layer' ); }
	public function get_icon(): string { return 'eicon-spacer'; }
	public function get_categories(): array { return [ 'basic' ]; }

	protected function register_controls(): void {
		$this->start_controls_section( 'spacing', [ 'label' => esc_html__( 'Spacing', 'cresco-layer' ) ] );
		$this->add_responsive_control( 'space', [ 'label' => esc_html__( 'Height', 'cresco-layer' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'em', 'rem', 'vh' ], 'range' => [ 'px' => [ 'min' => 0, 'max' => 600 ], 'vh' => [ 'min' => 0, 'max' => 100 ] ], 'default' => [ 'size' => 32, 'unit' => 'px' ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-spacer' => 'height: {{SIZE}}{{UNIT}};' ] ] );
		$this->end_controls_section();
	}

	protected function render(): void { echo '<div class="cresco-layer-spacer" aria-hidden="true"></div>'; }
}
