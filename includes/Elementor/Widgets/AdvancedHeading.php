<?php
namespace CrescoLayer\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

final class AdvancedHeading extends Widget_Base {
	public function get_name(): string { return 'cresco-advanced-heading'; }
	public function get_title(): string { return esc_html__( 'Cresco Advanced Heading', 'cresco-layer' ); }
	public function get_icon(): string { return 'eicon-heading'; }
	public function get_categories(): array { return [ 'basic' ]; }
	public function get_keywords(): array { return [ 'cresco', 'heading', 'title', 'typography' ]; }

	protected function register_controls(): void {
		$this->start_controls_section( 'content', [ 'label' => esc_html__( 'Content', 'cresco-layer' ) ] );
		$this->add_control( 'text', [
			'label' => esc_html__( 'Heading', 'cresco-layer' ),
			'type' => Controls_Manager::TEXTAREA,
			'default' => esc_html__( 'Build something remarkable.', 'cresco-layer' ),
			'placeholder' => esc_html__( 'Enter heading', 'cresco-layer' ),
			'dynamic' => [ 'active' => true ],
		] );
		$this->add_control( 'html_tag', [
			'label' => esc_html__( 'HTML Tag', 'cresco-layer' ),
			'type' => Controls_Manager::SELECT,
			'default' => 'h2',
			'options' => [ 'h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6', 'div' => 'div', 'span' => 'span', 'p' => 'p' ],
		] );
		$this->add_control( 'link', [
			'label' => esc_html__( 'Link', 'cresco-layer' ),
			'type' => Controls_Manager::URL,
			'placeholder' => 'https://',
			'dynamic' => [ 'active' => true ],
		] );
		$this->add_responsive_control( 'align', [
			'label' => esc_html__( 'Alignment', 'cresco-layer' ),
			'type' => Controls_Manager::CHOOSE,
			'options' => [
				'left' => [ 'title' => esc_html__( 'Left', 'cresco-layer' ), 'icon' => 'eicon-text-align-left' ],
				'center' => [ 'title' => esc_html__( 'Center', 'cresco-layer' ), 'icon' => 'eicon-text-align-center' ],
				'right' => [ 'title' => esc_html__( 'Right', 'cresco-layer' ), 'icon' => 'eicon-text-align-right' ],
			],
			'selectors' => [ '{{WRAPPER}} .cresco-layer-heading' => 'text-align: {{VALUE}};' ],
		] );
		$this->end_controls_section();

		$this->start_controls_section( 'style', [ 'label' => esc_html__( 'Style', 'cresco-layer' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_control( 'color', [
			'label' => esc_html__( 'Color', 'cresco-layer' ),
			'type' => Controls_Manager::COLOR,
			'global' => [ 'default' => '' ],
			'selectors' => [ '{{WRAPPER}} .cresco-layer-heading' => 'color: {{VALUE}};' ],
		] );
		$this->add_group_control( Group_Control_Typography::get_type(), [ 'name' => 'typography', 'selector' => '{{WRAPPER}} .cresco-layer-heading' ] );
		$this->add_group_control( Group_Control_Text_Shadow::get_type(), [ 'name' => 'text_shadow', 'selector' => '{{WRAPPER}} .cresco-layer-heading' ] );
		$this->add_responsive_control( 'max_width', [
			'label' => esc_html__( 'Max Width', 'cresco-layer' ),
			'type' => Controls_Manager::SLIDER,
			'size_units' => [ 'px', '%', 'em', 'rem', 'vw' ],
			'range' => [ 'px' => [ 'min' => 120, 'max' => 1600 ], '%' => [ 'min' => 10, 'max' => 100 ] ],
			'selectors' => [ '{{WRAPPER}} .cresco-layer-heading' => 'max-width: {{SIZE}}{{UNIT}};' ],
		] );
		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$tag = in_array( $settings['html_tag'] ?? 'h2', [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' ], true ) ? $settings['html_tag'] : 'h2';
		$text = isset( $settings['text'] ) ? wp_kses_post( $settings['text'] ) : '';
		$this->add_render_attribute( 'heading', 'class', 'cresco-layer-heading' );
		$this->add_inline_editing_attributes( 'heading' );

		$link = $settings['link'] ?? [];
		if ( ! empty( $link['url'] ) ) {
			$this->add_link_attributes( 'link', $link );
			echo '<' . esc_attr( $tag ) . ' ' . $this->get_render_attribute_string( 'heading' ) . '><a ' . $this->get_render_attribute_string( 'link' ) . '>' . $text . '</a></' . esc_attr( $tag ) . '>';
			return;
		}
		echo '<' . esc_attr( $tag ) . ' ' . $this->get_render_attribute_string( 'heading' ) . '>' . $text . '</' . esc_attr( $tag ) . '>';
	}
}
