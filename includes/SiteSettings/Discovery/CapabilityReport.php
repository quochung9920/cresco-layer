<?php
namespace CrescoLayer\SiteSettings\Discovery;

use CrescoLayer\SiteSettings\Gateway\KitGateway;

/**
 * What the running Kit can actually accept.
 *
 * Discovery runs before any mapping so the adapter can ask "does this control exist, and does it
 * take a custom unit" instead of assuming. Kit control names move between Elementor versions and
 * whole tabs appear only when a theme or Elementor Pro is present, so a guess here becomes a
 * setting written under a key nothing reads.
 */
final class CapabilityReport {
	private array $controls;

	public function __construct( KitGateway $gateway ) {
		$this->controls = $gateway->controls();
	}

	public function has( string $control ): bool {
		return isset( $this->controls[ $control ] );
	}

	public function control( string $control ): array {
		return is_array( $this->controls[ $control ] ?? null ) ? $this->controls[ $control ] : [];
	}

	public function supports_custom_unit( string $control ): bool {
		$units = $this->control( $control )['size_units'] ?? [];
		return is_array( $units ) && in_array( 'custom', array_map( 'strval', $units ), true );
	}

	public function supports_unit( string $control, string $unit ): bool {
		$units = $this->control( $control )['size_units'] ?? [];
		return is_array( $units ) && in_array( $unit, array_map( 'strval', $units ), true );
	}

	/** Allowed option keys for a select/choose control, empty when the control has no options. */
	public function options( string $control ): array {
		$options = $this->control( $control )['options'] ?? [];
		return is_array( $options ) ? array_map( 'strval', array_keys( $options ) ) : [];
	}

	public function allows_option( string $control, string $value ): bool {
		$options = $this->options( $control );
		return ! $options || in_array( $value, $options, true );
	}

	/**
	 * True when at least one control of a group is registered. Used to detect optional tabs such as
	 * Hello Theme header/footer or Elementor Pro custom CSS without version sniffing.
	 *
	 * @param string[] $controls
	 */
	public function has_any( array $controls ): bool {
		foreach ( $controls as $control ) {
			if ( $this->has( $control ) ) { return true; }
		}
		return false;
	}

	/** @return string[] Control names present in the Kit, filtered from the given candidates. */
	public function present( array $controls ): array {
		return array_values( array_filter( $controls, fn( string $control ): bool => $this->has( $control ) ) );
	}

	public function count(): int {
		return count( $this->controls );
	}

	/** A short summary for the diagnostic log and the result object. */
	public function summary(): array {
		return [
			'controlCount' => $this->count(),
			'helloHeader' => $this->has_any( [ 'hello_header_logo_display', 'hello_header_menu_display', 'hello_header_layout' ] ),
			'helloFooter' => $this->has_any( [ 'hello_footer_logo_display', 'hello_footer_copyright_display', 'hello_footer_layout' ] ),
			'customCss' => $this->has( 'custom_css' ),
			'pageTransitions' => $this->has_any( [ 'page_transitions_type', 'transitions_animation', 'page_transition_type' ] ),
			'lightbox' => $this->has( 'global_image_lightbox' ),
		];
	}
}
