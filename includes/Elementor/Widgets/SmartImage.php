<?php
namespace CrescoLayer\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Widget_Base;

final class SmartImage extends Widget_Base {
	public function get_name(): string { return 'cresco-smart-image'; }
	public function get_title(): string { return esc_html__( 'Cresco Smart Image', 'cresco-layer' ); }
	public function get_icon(): string { return 'eicon-image'; }
	public function get_categories(): array { return [ 'basic' ]; }
	public function get_keywords(): array { return [ 'cresco', 'image', 'media', 'accessible' ]; }

	protected function register_controls(): void {
		$this->start_controls_section( 'content', [ 'label' => esc_html__( 'Image', 'cresco-layer' ) ] );
		$this->add_control( 'image', [ 'label' => esc_html__( 'Choose Image', 'cresco-layer' ), 'type' => Controls_Manager::MEDIA, 'dynamic' => [ 'active' => true ], 'default' => [ 'url' => \Elementor\Utils::get_placeholder_image_src() ] ] );
		$this->add_control( 'decorative', [ 'label' => esc_html__( 'Decorative Image', 'cresco-layer' ), 'type' => Controls_Manager::SWITCHER, 'return_value' => 'yes', 'description' => esc_html__( 'Decorative images use an empty alt attribute.', 'cresco-layer' ) ] );
		$this->add_control( 'alt', [ 'label' => esc_html__( 'Alt Text Override', 'cresco-layer' ), 'type' => Controls_Manager::TEXT, 'dynamic' => [ 'active' => true ], 'condition' => [ 'decorative!' => 'yes' ], 'description' => esc_html__( 'Leave empty to use the Media Library alt text.', 'cresco-layer' ) ] );
		$this->add_control( 'link', [ 'label' => esc_html__( 'Link', 'cresco-layer' ), 'type' => Controls_Manager::URL, 'dynamic' => [ 'active' => true ], 'placeholder' => 'https://' ] );
		$this->add_control( 'loading', [ 'label' => esc_html__( 'Loading', 'cresco-layer' ), 'type' => Controls_Manager::SELECT, 'default' => 'lazy', 'options' => [ 'lazy' => esc_html__( 'Lazy', 'cresco-layer' ), 'eager' => esc_html__( 'Eager', 'cresco-layer' ) ] ] );
		$this->add_control( 'fetchpriority', [ 'label' => esc_html__( 'Fetch Priority', 'cresco-layer' ), 'type' => Controls_Manager::SELECT, 'default' => 'auto', 'options' => [ 'auto' => 'auto', 'high' => 'high', 'low' => 'low' ], 'description' => esc_html__( 'Use High sparingly for a true LCP/hero image.', 'cresco-layer' ) ] );
		$this->end_controls_section();

		$this->start_controls_section( 'style', [ 'label' => esc_html__( 'Style', 'cresco-layer' ), 'tab' => Controls_Manager::TAB_STYLE ] );
		$this->add_responsive_control( 'width', [ 'label' => esc_html__( 'Width', 'cresco-layer' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', '%', 'vw' ], 'range' => [ '%' => [ 'min' => 1, 'max' => 100 ], 'px' => [ 'min' => 16, 'max' => 2000 ] ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-image' => 'width: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_responsive_control( 'height', [ 'label' => esc_html__( 'Height', 'cresco-layer' ), 'type' => Controls_Manager::SLIDER, 'size_units' => [ 'px', 'vh' ], 'range' => [ 'px' => [ 'min' => 16, 'max' => 1600 ], 'vh' => [ 'min' => 5, 'max' => 100 ] ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-image' => 'height: {{SIZE}}{{UNIT}};' ] ] );
		$this->add_control( 'object_fit', [ 'label' => esc_html__( 'Object Fit', 'cresco-layer' ), 'type' => Controls_Manager::SELECT, 'default' => 'cover', 'options' => [ 'cover' => 'Cover', 'contain' => 'Contain', 'fill' => 'Fill', 'none' => 'None', 'scale-down' => 'Scale Down' ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-image' => 'object-fit: {{VALUE}};' ] ] );
		$this->add_control( 'object_position', [ 'label' => esc_html__( 'Object Position', 'cresco-layer' ), 'type' => Controls_Manager::SELECT, 'default' => '50% 50%', 'options' => [ '50% 50%' => 'Center', '50% 0%' => 'Top', '50% 100%' => 'Bottom', '0% 50%' => 'Left', '100% 50%' => 'Right' ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-image' => 'object-position: {{VALUE}};' ] ] );
		$this->add_group_control( Group_Control_Border::get_type(), [ 'name' => 'border', 'selector' => '{{WRAPPER}} .cresco-layer-image' ] );
		$this->add_responsive_control( 'radius', [ 'label' => esc_html__( 'Border Radius', 'cresco-layer' ), 'type' => Controls_Manager::DIMENSIONS, 'size_units' => [ 'px', '%', 'em', 'rem' ], 'selectors' => [ '{{WRAPPER}} .cresco-layer-image' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ] ] );
		$this->add_group_control( Group_Control_Box_Shadow::get_type(), [ 'name' => 'shadow', 'selector' => '{{WRAPPER}} .cresco-layer-image' ] );
		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$image = $settings['image'] ?? [];
		$url = isset( $image['url'] ) ? esc_url( $image['url'] ) : '';
		if ( '' === $url ) { return; }
		$attachment_id = ! empty( $image['id'] ) ? absint( $image['id'] ) : 0;
		$alt = '';
		if ( 'yes' !== ( $settings['decorative'] ?? '' ) ) {
			$alt = trim( (string) ( $settings['alt'] ?? '' ) );
			if ( '' === $alt && $attachment_id ) { $alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ); }
		}
		$attrs = [
			'class' => 'cresco-layer-image',
			'src' => $url,
			'alt' => esc_attr( $alt ),
			'loading' => 'eager' === ( $settings['loading'] ?? 'lazy' ) ? 'eager' : 'lazy',
			'decoding' => 'async',
			'fetchpriority' => in_array( $settings['fetchpriority'] ?? 'auto', [ 'auto', 'high', 'low' ], true ) ? $settings['fetchpriority'] : 'auto',
		];
		if ( $attachment_id ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $meta['width'] ) ) { $attrs['width'] = (string) absint( $meta['width'] ); }
			if ( ! empty( $meta['height'] ) ) { $attrs['height'] = (string) absint( $meta['height'] ); }
			$srcset = wp_get_attachment_image_srcset( $attachment_id, 'full' );
			$sizes = wp_get_attachment_image_sizes( $attachment_id, 'full' );
			if ( $srcset ) { $attrs['srcset'] = $srcset; }
			if ( $sizes ) { $attrs['sizes'] = $sizes; }
		}
		$parts = [];
		foreach ( $attrs as $key => $value ) { $parts[] = sprintf( '%s="%s"', esc_attr( $key ), esc_attr( $value ) ); }
		$img = '<img ' . implode( ' ', $parts ) . ' />';
		$link = $settings['link'] ?? [];
		if ( ! empty( $link['url'] ) ) {
			$this->add_link_attributes( 'link', $link );
			echo '<a ' . $this->get_render_attribute_string( 'link' ) . '>' . $img . '</a>';
			return;
		}
		echo $img;
	}
}
