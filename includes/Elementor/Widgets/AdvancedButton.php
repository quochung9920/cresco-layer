<?php
namespace CrescoLayer\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Icons_Manager;
use Elementor\Widget_Base;

final class AdvancedButton extends Widget_Base {
	public function get_name(): string { return 'cresco-advanced-button'; }
	public function get_title(): string { return esc_html__( 'Cresco Advanced Button', 'cresco-layer' ); }
	public function get_icon(): string { return 'eicon-button'; }
	public function get_categories(): array { return [ 'basic' ]; }
	public function get_keywords(): array { return [ 'cresco', 'button', 'cta', 'link' ]; }

	protected function register_controls(): void {
		$this->start_controls_section( 'content', [ 'label' => esc_html__( 'Content', 'cresco-layer' ) ] );
		$this->add_control( 'text', [ 'label' => esc_html__( 'Label', 'cresco-layer' ), 'type' => Controls_Manager::TEXT, 'default' => esc_html__( 'Get started', 'cresco-layer' ), 'dynamic' => [ 'active' => true ] ] );
		$this->add_control( 'link', [ 'label' => esc_html__( 'Link', 'cresco-layer' ), 'type' => Controls_Manager::URL, 'placeholder' => 'https://', 'dynamic' => [ 'active' => true ], 'default' => [ 'url' => '#' ] ] );
		$this->add_control( 'icon', [ 'label' => esc_html__( 'Icon', 'cresco-layer' ), 'type' => Controls_Manager::ICONS ] );
		$this->add_control( 'icon_position', [ 'label' => esc_html__( 'Icon Position', 'cresco-layer' ), 'type' => Controls_Manager::SELECT, 'default' => 'before', 'options' => [ 'before' => esc_html__( 'Before', 'cresco-layer' ), 'after' => esc_html__( 'After', 'cresco-layer' ) ] ] );
		$this->add_control( 'aria_label', [ 'label' => esc_html__( 'Accessible Label', 'cresco-layer' ), 'type' => Controls_Manager::TEXT, 'description' => esc_html__( 'Optional. Use when the visible label is not descriptive enough.', 'cresco-layer' ) ] );
		$this->add_responsive_control( 'align', [
			'label' => esc_html__( 'Alignment', 'cresco-layer' ),
			'type' => Controls_Manager::CHOOSE,
			'options' => [
				'flex-start' => [ 'title' => esc_html__( 'Start', 'cresco-layer' ), 'icon' => 'eicon-h-align-left' ],
				'center' => [ 'title' => esc_html__( 'Center', 'cresco-layer' ), 'icon' => 'eicon-h-align-center' ],
				'flex-end' => [ 'title' => esc_html__( 'End', 'cresco-layer' ), 'icon' => 'eicon-h-align-right' ],
			],
			'default' => 'flex-start',
			'selectors' => [ '{{WRAPPER}} .cresco-layer-button-wrap' => 'justify-content: {{VALUE}};' ],
		] );
		$this->add_control( 'full_width', [ 'label' => esc_html__( 'Full Width', 'cresco-layer' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'selectors' => [ '{{WRAPPER}} .cresco-layer-button' => 'width: 100%;' ] ] );
		$this->end_controls_section();

		$this->start_controls_section( 'style', [ 'label' => esc_html__( 'Style', 'cresco-layer' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_group_control( Group_Control_Typography::get_type(), [ 'name' => 'typography', 'selector' => '{{WRAPPER}} .cresco-layer-button' ] );
		$this->add_responsive_control( 'padding', [
			'label' => esc_html__( 'Padding', 'cresco-layer' ),
			'type' => Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', 'em', 'rem' ],
			'default' => [ 'top' => 12, 'right' => 20, 'bottom' => 12, 'left' => 20, 'unit' => 'px', 'isLinked' => false ],
			'selectors' => [ '{{WRAPPER}} .cresco-layer-button' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ],
		] );
		$this->add_responsive_control( 'gap', [ 'label' => esc_html__( 'Icon Gap', 'cresco-layer' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'em', 'rem' ], 'range' => [ 'px' => [ 'min' => 0, 'max' => 64 ] ], 'default' => [ 'size' => 8, 'unit' => 'px' ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-button' => 'gap: {{SIZE}}{{UNIT}};' ] ] );
		$this->start_controls_tabs( 'button_states' );
		$this->start_controls_tab( 'normal', [ 'label' => esc_html__( 'Normal', 'cresco-layer' ) ] );
		$this->add_control( 'text_color', [ 'label' => esc_html__( 'Text Color', 'cresco-layer' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .cresco-layer-button' => 'color: {{VALUE}};' ] ] );
		$this->add_control( 'background_color', [ 'label' => esc_html__( 'Background', 'cresco-layer' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .cresco-layer-button' => 'background-color: {{VALUE}};' ] ] );
		$this->end_controls_tab();
		$this->start_controls_tab( 'hover', [ 'label' => esc_html__( 'Hover / Focus', 'cresco-layer' ) ] );
		$this->add_control( 'hover_text_color', [ 'label' => esc_html__( 'Text Color', 'cresco-layer' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .cresco-layer-button:hover, {{WRAPPER}} .cresco-layer-button:focus-visible' => 'color: {{VALUE}};' ] ] );
		$this->add_control( 'hover_background_color', [ 'label' => esc_html__( 'Background', 'cresco-layer' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .cresco-layer-button:hover, {{WRAPPER}} .cresco-layer-button:focus-visible' => 'background-color: {{VALUE}};' ] ] );
		$this->end_controls_tab();
		$this->end_controls_tabs();
		$this->add_group_control( Group_Control_Border::get_type(), [ 'name' => 'border', 'selector' => '{{WRAPPER}} .cresco-layer-button' ] );
		$this->add_responsive_control( 'border_radius', [ 'label' => esc_html__( 'Border Radius', 'cresco-layer' ), 'type' => Controls_Manager::DIMENSIONS, 'size_units' => [ 'px', '%', 'em', 'rem' ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-button' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ] ] );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), [ 'name' => 'shadow', 'selector' => '{{WRAPPER}} .cresco-layer-button' ] );
		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$this->add_render_attribute( 'wrap', 'class', 'cresco-layer-button-wrap' );
		$this->add_render_attribute( 'button', 'class', 'cresco-layer-button' );
		$link = $settings['link'] ?? [];
		if ( ! empty( $link['url'] ) ) { $this->add_link_attributes( 'button', $link ); }
		if ( ! empty( $settings['aria_label'] ) ) { $this->add_render_attribute( 'button', 'aria-label', $settings['aria_label'] ); }
		if ( ! empty( $link['is_external'] ) ) { $this->add_render_attribute( 'button', 'rel', 'noopener noreferrer' . ( ! empty( $link['nofollow'] ) ? ' nofollow' : '' ) ); }
		$text = esc_html( (string) ( $settings['text'] ?? '' ) );
		$icon = '';
		if ( ! empty( $settings['icon']['value'] ) ) { ob_start(); Icons_Manager::render_icon( $settings['icon'], [ 'aria-hidden' => 'true' ] ); $icon = (string) ob_get_clean(); }
		echo '<div ' . $this->get_render_attribute_string( 'wrap' ) . '>';
		echo '<a ' . $this->get_render_attribute_string( 'button' ) . '>';
		if ( 'after' !== ( $settings['icon_position'] ?? 'before' ) ) { echo $icon; }
		echo '<span class="cresco-layer-button__label">' . $text . '</span>';
		if ( 'after' === ( $settings['icon_position'] ?? 'before' ) ) { echo $icon; }
		echo '</a></div>';
	}
}
