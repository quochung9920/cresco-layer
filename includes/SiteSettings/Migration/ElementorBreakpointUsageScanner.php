<?php
namespace CrescoLayer\SiteSettings\Migration;

/** Runtime read-only scan of persisted Elementor documents before breakpoint deactivation. */
final class ElementorBreakpointUsageScanner implements BreakpointUsageScanner {
	public function __construct( private int $limit = 1000, private ?BreakpointUsageAnalyzer $analyzer = null ) {
		$this->limit = max( 1, $this->limit );
		$this->analyzer ??= new BreakpointUsageAnalyzer();
	}

	public function scan( array $devices ): array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
			return [
				'scannedDocuments' => 0,
				'truncated' => true,
				'totalSettingCount' => 0,
				'hasUsage' => false,
				'usage' => [],
				'errors' => [ 'WordPress post APIs are unavailable; breakpoint usage could not be scanned.' ],
			];
		}

		$post_types = function_exists( 'get_post_types' ) ? array_values( get_post_types( [], 'names' ) ) : [ 'post', 'page' ];
		// Scan every registered post type that can carry Elementor builder meta, not only show_ui types.
		// A hidden CPT can still render on the frontend, so omitting it could incorrectly declare a
		// breakpoint migration safe. Revisions/attachments/navigation items are intentionally excluded.
		$post_types = array_values( array_diff( $post_types, [ 'attachment', 'revision', 'nav_menu_item' ] ) );

		$ids = get_posts( [
			'post_type' => $post_types ?: [ 'post', 'page' ],
			'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page' => $this->limit + 1,
			'fields' => 'ids',
			'orderby' => 'ID',
			'order' => 'ASC',
			'no_found_rows' => true,
			'meta_key' => '_elementor_edit_mode',
			'meta_value' => 'builder',
		] );
		$ids = array_values( array_map( 'intval', is_array( $ids ) ? $ids : [] ) );
		$truncated = count( $ids ) > $this->limit;
		$ids = array_slice( $ids, 0, $this->limit );

		$documents = [];
		foreach ( $ids as $post_id ) {
			$raw = get_post_meta( $post_id, '_elementor_data', true );
			$elements = $this->decode_elements( $raw );
			$page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
			$page_settings = is_array( $page_settings ) ? $page_settings : [];
			$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
			$documents[] = [
				'id' => $post_id,
				'title' => is_object( $post ) ? (string) ( $post->post_title ?? '' ) : '',
				'type' => is_object( $post ) ? (string) ( $post->post_type ?? '' ) : '',
				'elements' => $elements,
				'pageSettings' => $page_settings,
			];
		}
		return $this->analyzer->analyze( $documents, $devices, $truncated );
	}

	private function decode_elements( $raw ): array {
		if ( is_array( $raw ) ) { return $raw; }
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) { return []; }
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
