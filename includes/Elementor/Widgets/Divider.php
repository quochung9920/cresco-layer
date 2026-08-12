<?php
namespace CrescoLayer\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

final class Divider extends Widget_Base {
	public function get_name(): string { return 'cresco-divider'; }
	public function get_title(): string { return esc_html__( 'Cresco Divider', 'cresco-layer' ); }
	public function get_icon(): string { return 'eicon-divider'; }
	public function get_categories(): array { return [ 'basic' ]; }

	protected function register_controls(): void {
		$this->start_controls_section( 'style', [ 'label' => esc_html__( 'Divider', 'cresco-layer' ) ] );
		$this->add_control( 'style_type', [ 'label' => esc_html__( 'Style', 'cresco-layer' ), 'type' => Controls_Manager::SELECT, 'default' => 'solid', 'options' => [ 'solid' => 'Solid', 'dashed' => 'Dashed', 'dotted' => 'Dotted', 'double' => 'Double' ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-divider' => 'border-top-style: {{VALUE}};' ] ] );
		$this->add_control( 'color', [ 'label' => esc_html__( 'Color', 'cresco-layer' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .cresco-layer-divider' => 'border-top-color: {{VALUE}};' ] ] );
		$this->add_responsive_control( 'width', [ 'label' => esc_html__( 'Width', 'cresco-layer' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ '%', 'px', 'vw' ], 'range' => [ '%' => [ 'min' => 1, 'max' => 100 ], 'px' => [ 'min' => 1, 'max' => 1600 ] ], 'default' => [ 'size' => 100, 'unit' => '%' ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-divider' => 'width: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'weight', [ 'label' => esc_html__( 'Thickness', 'cresco-layer' ), 'type' => Controls_Manager::SLIDER, 'range' => [ 'px' => [ 'min' => 1, 'max' => 20 ] ], 'default' => [ 'size' => 1, 'unit' => 'px' ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-divider' => 'border-top-width: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'align', [ 'label' => esc_html__( 'Alignment', 'cresco-layer' ), 'type' => Controls_Manager::CHOOSE, 'options' => [ '0 auto 0 0' => [ 'title' => 'Start', 'icon' => 'eicon-h-align-left' ], '0 auto' => [ 'title' => 'Center', 'icon' => 'eicon-h-align-center' ], '0 0 0 auto' => [ 'title' => 'End', 'icon' => 'eicon-h-align-right' ] ], 'default' => '0 auto', 'selectors' => [ '{{WRAPPER}} .cresco-layer-divider' => 'margin: {{VALUE}};' ] ] );
		$this->end_controls_section();
	}

	protected function render(): void { echo '<div class="cresco-layer-divider" role="separator" aria-orientation="horizontal"></div>'; }
}
