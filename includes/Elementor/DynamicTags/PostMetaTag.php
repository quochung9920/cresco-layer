<?php
namespace CrescoLayer\Elementor\DynamicTags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;

final class PostMetaTag extends Tag {
	public function get_name(): string { return 'cresco-post-meta'; }
	public function get_title(): string { return esc_html__( 'Cresco Post Meta', 'cresco-layer' ); }
	public function get_group(): array { return [ 'cresco-layer' ]; }
	public function get_categories(): array { return [ Module::TEXT_CATEGORY, Module::NUMBER_CATEGORY, Module::URL_CATEGORY, Module::POST_META_CATEGORY ]; }

	protected function register_controls(): void {
		$this->add_control( 'key', [
			'label' => esc_html__( 'Meta Key', 'cresco-layer' ),
			'type' => Controls_Manager::TEXT,
			'placeholder' => 'my_custom_field',
		] );
		$this->add_control( 'fallback', [
			'label' => esc_html__( 'Fallback', 'cresco-layer' ),
			'type' => Controls_Manager::TEXT,
		] );
	}

	public function render(): void {
		$key = sanitize_key( (string) $this->get_settings( 'key' ) );
		if ( '' === $key ) { return; }
		$value = get_post_meta( get_the_ID(), $key, true );
		if ( is_array( $value ) || is_object( $value ) ) { $value = wp_json_encode( $value ); }
		if ( '' === (string) $value ) { $value = (string) $this->get_settings( 'fallback' ); }
		echo esc_html( (string) $value );
	}
}
