<?php
namespace CrescoLayer\SiteSettings\Discovery;

use CrescoLayer\SiteSettings\Gateway\KitGateway;

/** What the running Kit can actually accept, normalized through runtime capability discovery. */
final class CapabilityReport {
	private array $controls;
	private RuntimeControlResolver $resolver;

	public function __construct( KitGateway $gateway ) {
		$this->controls = $gateway->controls();
		$this->resolver = new RuntimeControlResolver( $this->controls, $gateway->settings() );
	}

	public function has( string $control ): bool { return $this->resolver->has( $control ); }
	public function control( string $control ): array { return $this->resolver->control( $control ); }
	public function resolver(): RuntimeControlResolver { return $this->resolver; }
	public function supports_custom_unit( string $control ): bool { return $this->resolver->supports_unit( $control, 'custom' ); }
	public function supports_unit( string $control, string $unit ): bool { return $this->resolver->supports_unit( $control, $unit ); }
	public function is_responsive( string $control ): bool { return $this->resolver->is_responsive( $control ); }
	public function explicit_range( string $control, string $unit ): ?array { return $this->resolver->explicit_range( $control, $unit ); }

	public function options( string $control ): array {
		$options = $this->control( $control )['options'] ?? [];
		return is_array( $options ) ? array_map( 'strval', array_keys( $options ) ) : [];
	}
	public function allows_option( string $control, string $value ): bool { $options = $this->options( $control ); return ! $options || in_array( $value, $options, true ); }
	public function has_any( array $controls ): bool { foreach ( $controls as $control ) { if ( $this->has( $control ) ) { return true; } } return false; }
	public function present( array $controls ): array { return array_values( array_filter( $controls, fn( string $control ): bool => $this->has( $control ) ) ); }
	public function count(): int { return count( $this->controls ); }

	public function summary(): array {
		$foundation_breakpoints = $this->present( [ 'viewport_mobile', 'viewport_tablet', 'viewport_laptop', 'viewport_widescreen' ] );
		return [
			'controlCount' => $this->count(),
			'helloHeader' => $this->has_any( [ 'hello_header_logo_display', 'hello_header_menu_display', 'hello_header_layout' ] ),
			'helloFooter' => $this->has_any( [ 'hello_footer_logo_display', 'hello_footer_copyright_display', 'hello_footer_layout' ] ),
			'customCss' => $this->has( 'custom_css' ),
			'pageTransitions' => $this->has_any( [ 'page_transitions_type', 'transitions_animation', 'page_transition_type' ] ),
			'lightbox' => $this->has( 'global_image_lightbox' ),
			'responsiveFoundation' => [
				'breakpoints' => $foundation_breakpoints,
				'exactFiveContexts' => 4 === count( $foundation_breakpoints ) && $this->has( 'active_breakpoints' ),
				'contentWidthResponsive' => $this->has( 'container_width' ) && $this->is_responsive( 'container_width' ),
				'contentWidthPercentUnit' => $this->has( 'container_width' ) && $this->supports_unit( 'container_width', '%' ),
				'contentWidthCustomUnit' => $this->has( 'container_width' ) && $this->supports_custom_unit( 'container_width' ),
				'contentWidthPxRange' => $this->has( 'container_width' ) ? $this->explicit_range( 'container_width', 'px' ) : null,
				'contentWidthStrategy' => 'canvas-aligned-native-with-custom-overflow',
				'containerPaddingResponsive' => $this->has( 'container_padding' ) && $this->is_responsive( 'container_padding' ),
				'containerPaddingCustomUnit' => $this->has( 'container_padding' ) && $this->supports_custom_unit( 'container_padding' ),
				'globalFluidStrategy' => 'native-custom-unit-when-supported',
			],
		];
	}
}
