<?php
namespace CrescoLayer\Elementor;

use CrescoLayer\Elementor\Widgets\AdvancedButton;
use CrescoLayer\Elementor\Widgets\AdvancedHeading;
use CrescoLayer\Elementor\Widgets\AdvancedIcon;
use CrescoLayer\Elementor\Widgets\Divider;
use CrescoLayer\Elementor\Widgets\SmartImage;
use CrescoLayer\Elementor\Widgets\Spacer;

final class WidgetRegistry {
	public function register( $widgets_manager ): void {
		$widgets_manager->register( new AdvancedHeading() );
		$widgets_manager->register( new AdvancedButton() );
		$widgets_manager->register( new SmartImage() );
		$widgets_manager->register( new AdvancedIcon() );
		$widgets_manager->register( new Divider() );
		$widgets_manager->register( new Spacer() );
	}
}
