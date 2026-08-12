<?php
namespace CrescoLayer\Elementor;

use CrescoLayer\Elementor\DynamicTags\PostMetaTag;
use CrescoLayer\Elementor\DynamicTags\SiteOptionTag;

final class DynamicTagRegistry {
	public function register( $manager ): void {
		if ( ! class_exists( '\\Elementor\\Modules\\DynamicTags\\Module' ) ) {
			return;
		}
		$manager->register_group( 'cresco-layer', [ 'title' => esc_html__( 'Cresco Layer', 'cresco-layer' ) ] );
		$manager->register( new PostMetaTag() );
		$manager->register( new SiteOptionTag() );
	}
}
