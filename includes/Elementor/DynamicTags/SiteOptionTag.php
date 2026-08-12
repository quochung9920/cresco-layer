<?php
namespace CrescoLayer\Elementor\DynamicTags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;

final class SiteOptionTag extends Tag {
	private const SAFE_OPTIONS = [
		'blogname' => 'Site Name',
		'blogdescription' => 'Tagline',
		'home' => 'Home URL',
		'siteurl' => 'WordPress URL',
		'WPLANG' => 'Site Language',
		'timezone_string' => 'Timezone',
	];

	public function get_name(): string { return 'cresco-site-info'; }
	public function get_title(): string { return esc_html__( 'Cresco Site Info', 'cresco-layer' ); }
	public function get_group(): array { return [ 'cresco-layer' ]; }
	public function get_categories(): array { return [ Module::TEXT_CATEGORY, Module::URL_CATEGORY ]; }

	protected function register_controls(): void {
		$options = [];
		foreach ( self::SAFE_OPTIONS as $key => $label ) { $options[ $key ] = esc_html__( $label, 'cresco-layer' ); }
		$this->add_control( 'option', [ 'label' => esc_html__( 'Value', 'cresco-layer' ), 'type' => Controls_Manager::SELECT, 'default' => 'blogname', 'options' => $options ] );
	}

	public function render(): void {
		$key = (string) $this->get_settings( 'option' );
		if ( ! array_key_exists( $key, self::SAFE_OPTIONS ) ) { return; }
		$value = get_option( $key, '' );
		echo esc_html( is_scalar( $value ) ? (string) $value : '' );
	}
}
