<?php
/**
 * Plugin Name: Cresco Layer
 * Description: Professional Elementor intelligence with deterministic runtime skills, semantic external-AI compilation, AI-first visual context exchange and design auditing.
 * Version: 0.19.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: elementor
 * Author: Cresco
 * Text Domain: cresco-layer
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'CRESCO_LAYER_VERSION', '0.19.0' );
define( 'CRESCO_LAYER_FILE', __FILE__ );
define( 'CRESCO_LAYER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRESCO_LAYER_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'CrescoLayer\\';
		if ( ! str_starts_with( $class, $prefix ) ) { return; }
		$relative = substr( $class, strlen( $prefix ) );
		$path = CRESCO_LAYER_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) { require_once $path; }
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		( new CrescoLayer\Plugin() )->boot();
	}
);
