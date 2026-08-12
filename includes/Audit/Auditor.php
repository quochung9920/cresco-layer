<?php
namespace CrescoLayer\Audit;

final class Auditor {
	public function audit_post( int $post_id ): array {
		$key = 'cresco_layer_audit_' . $post_id . '_' . get_current_user_id();
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) { return $cached; }
		$manager = \Elementor\Plugin::instance()->documents;
		$document = $manager->get_doc_or_auto_save( $post_id, get_current_user_id() );
		if ( ! $document ) { $document = $manager->get( $post_id ); }
		if ( ! $document ) { return $this->empty_report(); }
		$report = $this->audit_elements( (array) $document->get_elements_data() );
		set_transient( $key, $report, 5 * MINUTE_IN_SECONDS );
		return $report;
	}

	public function audit_elements( array $elements ): array {
		$state = [ 'nodes' => 0, 'maxDepth' => 0, 'images' => 0, 'missingAlt' => 0, 'oversizedImages' => 0, 'headings' => [], 'buttonsWithoutText' => 0, 'localColors' => [], 'issues' => [] ];
		foreach ( $elements as $element ) { if ( is_array( $element ) ) { $this->walk( $element, 1, $state ); } }
		$h1 = count( array_filter( $state['headings'], static fn( $level ) => 1 === $level ) );
		if ( $h1 > 1 ) { $state['issues'][] = [ 'severity' => 'warning', 'category' => 'accessibility', 'code' => 'multiple-h1', 'elementId' => '', 'message' => sprintf( 'Document contains %d H1 headings.', $h1 ) ]; }
		if ( $state['nodes'] > 500 ) { $state['issues'][] = [ 'severity' => 'warning', 'category' => 'performance', 'code' => 'large-dom', 'elementId' => '', 'message' => 'Large Elementor document: more than 500 elements.' ]; }
		if ( $state['maxDepth'] > 12 ) { $state['issues'][] = [ 'severity' => 'warning', 'category' => 'performance', 'code' => 'deep-tree', 'elementId' => '', 'message' => 'Element nesting depth is greater than 12.' ]; }
		$unique_colors = array_values( array_unique( $state['localColors'] ) );
		$accessibility_penalty = min( 60, 12 * $state['missingAlt'] + 15 * $state['buttonsWithoutText'] + ( $h1 > 1 ? 10 : 0 ) );
		$performance_penalty = min( 70, ( $state['nodes'] > 500 ? 20 : 0 ) + ( $state['maxDepth'] > 12 ? 15 : 0 ) + ( $state['images'] > 30 ? 10 : 0 ) + min( 25, 5 * $state['oversizedImages'] ) );
		$design_penalty = max( 0, min( 35, count( $unique_colors ) - 12 ) );
		return [
			'scores' => [ 'accessibility' => max( 0, 100 - $accessibility_penalty ), 'performance' => max( 0, 100 - $performance_penalty ), 'designConsistency' => max( 0, 100 - $design_penalty ) ],
			'stats' => [ 'nodes' => $state['nodes'], 'maxDepth' => $state['maxDepth'], 'images' => $state['images'], 'missingAlt' => $state['missingAlt'], 'oversizedImages' => $state['oversizedImages'], 'headings' => count( $state['headings'] ), 'localColors' => count( $unique_colors ) ],
			'issues' => $state['issues'],
		];
	}

	public function invalidate_post_cache( int $post_id, array $editor_data = [] ): void {
		delete_transient( 'cresco_layer_audit_' . absint( $post_id ) . '_' . get_current_user_id() );
	}

	private function walk( array $element, int $depth, array &$state ): void {
		$state['nodes']++;
		$state['maxDepth'] = max( $state['maxDepth'], $depth );
		$id = isset( $element['id'] ) ? (string) $element['id'] : '';
		$type = (string) ( $element['widgetType'] ?? $element['elType'] ?? '' );
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];

		if ( in_array( $type, [ 'image', 'cresco-smart-image' ], true ) ) {
			$state['images']++;
			$decorative = 'yes' === ( $settings['decorative'] ?? '' );
			$alt = trim( (string) ( $settings['alt'] ?? '' ) );
			$image = is_array( $settings['image'] ?? null ) ? $settings['image'] : [];
			if ( '' === $alt && ! $decorative && ! empty( $image['id'] ) ) { $alt = trim( (string) get_post_meta( absint( $image['id'] ), '_wp_attachment_image_alt', true ) ); }
			if ( '' === $alt && ! $decorative ) { $state['missingAlt']++; $state['issues'][] = [ 'severity' => 'warning', 'category' => 'accessibility', 'code' => 'missing-alt', 'elementId' => $id, 'message' => 'Image has no alt text and is not marked decorative.' ]; }
			if ( ! empty( $image['id'] ) ) {
				$meta = wp_get_attachment_metadata( absint( $image['id'] ) );
				$width = is_array( $meta ) ? absint( $meta['width'] ?? 0 ) : 0;
				if ( $width > 2560 ) { $state['oversizedImages']++; $state['issues'][] = [ 'severity' => 'warning', 'category' => 'performance', 'code' => 'oversized-image', 'elementId' => $id, 'message' => 'Image source is wider than 2560px; consider an appropriately sized derivative.' ]; }
			}
		}

		if ( in_array( $type, [ 'heading', 'cresco-advanced-heading' ], true ) ) {
			$tag = strtolower( (string) ( $settings['header_size'] ?? $settings['html_tag'] ?? 'h2' ) );
			if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) { $state['headings'][] = (int) $m[1]; }
		}
		if ( in_array( $type, [ 'button', 'cresco-advanced-button' ], true ) ) {
			$text = trim( wp_strip_all_tags( (string) ( $settings['text'] ?? '' ) ) );
			$aria = trim( (string) ( $settings['aria_label'] ?? '' ) );
			if ( '' === $text && '' === $aria ) { $state['buttonsWithoutText']++; $state['issues'][] = [ 'severity' => 'error', 'category' => 'accessibility', 'code' => 'button-name', 'elementId' => $id, 'message' => 'Button has no accessible name.' ]; }
		}
		$this->collect_colors( $settings, $state['localColors'] );
		foreach ( (array) ( $element['elements'] ?? [] ) as $child ) { if ( is_array( $child ) ) { $this->walk( $child, $depth + 1, $state ); } }
	}

	private function collect_colors( array $value, array &$colors ): void {
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) { $this->collect_colors( $child, $colors ); continue; }
			if ( is_string( $child ) && preg_match_all( '/#[0-9a-fA-F]{6}\b/', $child, $matches ) ) { foreach ( $matches[0] as $color ) { $colors[] = strtolower( $color ); } }
		}
	}

	private function empty_report(): array {
		return [ 'scores' => [ 'accessibility' => 100, 'performance' => 100, 'designConsistency' => 100 ], 'stats' => [ 'nodes' => 0, 'maxDepth' => 0, 'images' => 0, 'missingAlt' => 0, 'oversizedImages' => 0, 'headings' => 0, 'localColors' => 0 ], 'issues' => [] ];
	}
}
