<?php
$GLOBALS['rf_scanner_queries'] = [];
$GLOBALS['rf_scanner_meta'] = [
	21 => [
		'_elementor_data' => '[{"id":"a","elType":"container","settings":{"padding_mobile_extra":{"unit":"px","top":"0","right":"16","bottom":"0","left":"16"}},"elements":[]}]',
		'_elementor_page_settings' => [],
	],
	22 => [
		'_elementor_data' => '[]',
		'_elementor_page_settings' => [ 'width_tablet_extra' => [ 'unit' => 'px', 'size' => 900 ] ],
	],
];
function get_posts( array $args ) { $GLOBALS['rf_scanner_queries'][] = $args; return [ 21, 22 ]; }
function get_post_meta( $post_id, $key, $single = false ) { return $GLOBALS['rf_scanner_meta'][ $post_id ][ $key ] ?? ''; }
function get_post_types( $args = [], $output = 'names' ) { return [ 'page' => 'page', 'hidden_shop' => 'hidden_shop', 'attachment' => 'attachment', 'revision' => 'revision' ]; }
function get_post( $post_id ) { return (object) [ 'ID' => $post_id, 'post_title' => 'Doc ' . $post_id, 'post_type' => 21 === $post_id ? 'hidden_shop' : 'page' ]; }

$base = dirname( __DIR__, 2 ) . '/includes/SiteSettings/Migration/';
require_once $base . 'BreakpointUsageScanner.php';
require_once $base . 'BreakpointUsageAnalyzer.php';
require_once $base . 'ElementorBreakpointUsageScanner.php';

use CrescoLayer\SiteSettings\Migration\ElementorBreakpointUsageScanner;

function scanner_assert( bool $ok, string $message ): void { if ( ! $ok ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
$report = ( new ElementorBreakpointUsageScanner( 10 ) )->scan( [ 'mobile_extra', 'tablet_extra' ] );
scanner_assert( 2 === $report['scannedDocuments'], 'scanner should inspect both Elementor documents' );
scanner_assert( 2 === $report['totalSettingCount'], 'scanner should count element and page-setting overrides' );
$query = $GLOBALS['rf_scanner_queries'][0] ?? [];
$post_types = $query['post_type'] ?? [];
scanner_assert( in_array( 'hidden_shop', $post_types, true ), 'hidden CPTs must be included in safety scan' );
scanner_assert( ! in_array( 'attachment', $post_types, true ) && ! in_array( 'revision', $post_types, true ), 'irrelevant internal types must be excluded' );
scanner_assert( '_elementor_edit_mode' === ( $query['meta_key'] ?? '' ) && 'builder' === ( $query['meta_value'] ?? '' ), 'scan must stay scoped to Elementor builder documents' );
echo "PASS: Elementor breakpoint usage scanner\n";
