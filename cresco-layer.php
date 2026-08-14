<?php
/**
 * Plugin Name: Cresco Layer
 * Description: Professional Elementor intelligence with deterministic runtime skills, semantic local AI analysis, lossless AI exchange, context-resolved snapshots and design auditing.
 * Version: 0.11.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: elementor
 * Author: Cresco
 * Text Domain: cresco-layer
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'CRESCO_LAYER_VERSION', '0.11.1' );
define( 'CRESCO_LAYER_FILE', __FILE__ );
define( 'CRESCO_LAYER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRESCO_LAYER_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'CrescoLayer\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) { return; }
		$relative = substr( $class, strlen( $prefix ) );
		$path     = CRESCO_LAYER_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) { require_once $path; }
	}
);

add_action(
	'plugins_loaded',
	static function (): void { CrescoLayer\Plugin::instance()->boot(); },
	20
);
