<?php
namespace CrescoLayer\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Icons_Manager;
use Elementor\Widget_Base;

final class AdvancedIcon extends Widget_Base {
	public function get_name(): string { return 'cresco-advanced-icon'; }
	public function get_title(): string { return esc_html__( 'Cresco Advanced Icon', 'cresco-layer' ); }
	public function get_icon(): string { return 'eicon-star'; }
	public function get_categories(): array { return [ 'basic' ]; }
	public function get_keywords(): array { return [ 'cresco', 'icon', 'symbol' ]; }

	protected function register_controls(): void {
		$this->start_controls_section( 'content', [ 'label' => esc_html__( 'Icon', 'cresco-layer' ) ] );
		$this->add_control( 'icon', [ 'label' => esc_html__( 'Icon', 'cresco-layer' ), 'type' => Controls_Manager::ICONS, 'default' => [ 'value' => 'fas fa-star', 'library' => 'fa-solid' ] ] );
		$this->add_control( 'decorative', [ 'label' => esc_html__( 'Decorative', 'cresco-layer' ), 'type' => Controls_Manager::SWITCHER, 'default' => 'yes', 'return_value' => 'yes' ] );
		$this->add_control( 'aria_label', [ 'label' => esc_html__( 'Accessible Label', 'cresco-layer' ), 'type' => Controls_Manager::TEXT, 'condition' => [ 'decorative!' => 'yes' ] ] );
		$this->add_control( 'link', [ 'label' => esc_html__( 'Link', 'cresco-layer' ), 'type' => Controls_Manager::URL, 'dynamic' => [ 'active' => true ] ] );
		$this->end_controls_section();
		$this->start_controls_section( 'style', [ 'label' => esc_html__( 'Style', 'cresco-layer' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_control( 'color', [ 'label' => esc_html__( 'Color', 'cresco-layer' ), 'type' => Controls_Manager::COLOR, 'selectors' => [ '{{WRAPPER}} .cresco-layer-icon' => 'color: {{VALUE}};' ] ] );
		$this->add_responsive_control( 'size', [ 'label' => esc_html__( 'Size', 'cresco-layer' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'em', 'rem' ], 'range' => [ 'px' => [ 'min' => 8, 'max' => 240 ] ], 'default' => [ 'size' => 24, 'unit' => 'px' ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-icon' => 'font-size: {{SIZE}}{{UNIT}};', '{{WRAPPER}} .cresco-layer-icon svg' => 'width: 1em;height: 1em;' ] ] );
		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$this->add_render_attribute( 'icon', 'class', 'cresco-layer-icon' );
		if ( 'yes' === ( $settings['decorative'] ?? 'yes' ) ) {
			$this->add_render_attribute( 'icon', 'aria-hidden', 'true' );
		} elseif ( ! empty( $settings['aria_label'] ) ) {
			$this->add_render_attribute( 'icon', 'role', 'img' );
			$this->add_render_attribute( 'icon', 'aria-label', $settings['aria_label'] );
		}
		ob_start();
		Icons_Manager::render_icon( $settings['icon'] ?? [], [ 'aria-hidden' => 'true' ] );
		$markup = (string) ob_get_clean();
		$content = '<span ' . $this->get_render_attribute_string( 'icon' ) . '>' . $markup . '</span>';
		$link = $settings['link'] ?? [];
		if ( ! empty( $link['url'] ) ) {
			$this->add_link_attributes( 'link', $link );
			if ( ! empty( $link['is_external'] ) ) { $this->add_render_attribute( 'link', 'rel', 'noopener noreferrer' . ( ! empty( $link['nofollow'] ) ? ' nofollow' : '' ) ); }
			echo '<a ' . $this->get_render_attribute_string( 'link' ) . '>' . $content . '</a>';
			return;
		}
		echo $content;
	}
}
