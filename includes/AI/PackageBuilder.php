<?php
namespace CrescoLayer\AI;

use CrescoLayer\Audit\Auditor;
use CrescoLayer\Support\DocumentChecksum;
use Elementor\Plugin as ElementorPlugin;

final class PackageBuilder {
	public function __construct( private Auditor $auditor ) {}

	public function build( int $post_id, string $scope = 'document', array $selected_ids = [] ): array {
		$manager = ElementorPlugin::instance()->documents;
		$main_document = $manager->get( $post_id );
		if ( ! $main_document ) { throw new \RuntimeException( 'Elementor document not found.' ); }
		$document = $manager->get_doc_or_auto_save( $post_id, get_current_user_id() );
		if ( ! $document ) { $document = $main_document; }
		$elements = (array) $document->get_elements_data();
		$working_post = method_exists( $document, 'get_post' ) ? $document->get_post() : null;
		$working_post_id = $working_post ? (int) $working_post->ID : $post_id;
		$page_settings = get_post_meta( $working_post_id, '_elementor_page_settings', true );
		$page_settings = is_array( $page_settings ) ? $page_settings : [];
		$export_elements = $this->scope_elements( $elements, $scope, $selected_ids );
		$checksum = DocumentChecksum::hash( $elements, $page_settings );
		$post = get_post( $post_id );
		$theme = wp_get_theme();

		$package = [
			'schema' => 'cresco-layer-ai-package/v1',
			'manifest' => [
				'pluginVersion' => CRESCO_LAYER_VERSION,
				'elementorVersion' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
				'elementorProVersion' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
				'postId' => $post_id,
				'workingPostId' => $working_post_id,
				'isAutosave' => $working_post_id !== $post_id,
				'documentType' => method_exists( $main_document, 'get_name' ) ? (string) $main_document->get_name() : get_post_type( $post_id ),
				'documentVersion' => '0.4',
				'baseChecksum' => $checksum,
				'exportedAt' => gmdate( 'c' ),
				'scope' => $scope,
			],
			'document' => [
				'title' => $post ? $post->post_title : '',
				'type' => $post ? $post->post_type : '',
				'status' => $post ? $post->post_status : '',
				'page_settings' => $page_settings,
				'content' => $export_elements,
			],
			'siteContext' => [
				'homeUrl' => home_url( '/' ),
				'locale' => get_locale(),
				'theme' => $theme->get( 'Name' ),
				'themeVersion' => $theme->get( 'Version' ),
				'breakpoints' => $this->breakpoints(),
			],
			'designSystem' => $this->get_design_system(),
			'widgetCatalog' => $this->widget_catalog(),
			'capabilities' => [
				'patchSchema' => 'cresco-layer-patch/v1',
				'operations' => PatchValidator::ALLOWED_OPERATIONS,
				'atomicElementsAware' => true,
				'classicalResponsiveSuffixes' => [ 'tablet', 'mobile', 'widescreen', 'laptop', 'tablet_extra', 'mobile_extra' ],
				'preserveExistingElementIds' => true,
				'preferGlobalStyles' => true,
				'elementorOwnsPersistence' => true,
				'publishedDocumentsUseAutosave' => true,
			],
			'audit' => $this->auditor->audit_elements( $export_elements ),
			'instructions' => $this->instructions(),
		];
		return $this->redact( $package );
	}

	private function get_design_system(): array {
		$plugin = ElementorPlugin::instance();
		if ( ! isset( $plugin->kits_manager ) || ! method_exists( $plugin->kits_manager, 'get_active_kit' ) ) { return []; }
		$kit = $plugin->kits_manager->get_active_kit();
		if ( ! $kit ) { return []; }
		if ( method_exists( $kit, 'get_settings_for_display' ) ) { $settings = $kit->get_settings_for_display(); return is_array( $settings ) ? $settings : []; }
		return [];
	}

	private function breakpoints(): array {
		$plugin = ElementorPlugin::instance();
		if ( ! isset( $plugin->breakpoints ) || ! method_exists( $plugin->breakpoints, 'get_active_breakpoints' ) ) { return []; }
		$out = [];
		foreach ( (array) $plugin->breakpoints->get_active_breakpoints() as $key => $breakpoint ) {
			if ( ! is_object( $breakpoint ) ) { continue; }
			$out[ (string) $key ] = [ 'label' => method_exists( $breakpoint, 'get_label' ) ? (string) $breakpoint->get_label() : (string) $key, 'value' => method_exists( $breakpoint, 'get_value' ) ? $breakpoint->get_value() : null, 'direction' => method_exists( $breakpoint, 'get_direction' ) ? (string) $breakpoint->get_direction() : '' ];
		}
		return $out;
	}

	private function widget_catalog(): array {
		$manager = ElementorPlugin::instance()->widgets_manager;
		if ( ! $manager || ! method_exists( $manager, 'get_widget_types' ) ) { return []; }
		$catalog = [];
		foreach ( (array) $manager->get_widget_types() as $name => $widget ) {
			if ( ! is_object( $widget ) ) { continue; }
			$entry = [ 'name' => method_exists( $widget, 'get_name' ) ? $widget->get_name() : (string) $name, 'title' => method_exists( $widget, 'get_title' ) ? wp_strip_all_tags( (string) $widget->get_title() ) : (string) $name, 'categories' => method_exists( $widget, 'get_categories' ) ? array_values( (array) $widget->get_categories() ) : [] ];
			if ( method_exists( $widget, 'get_controls' ) ) {
				$controls = [];
				foreach ( (array) $widget->get_controls() as $control_name => $control ) {
					if ( ! is_array( $control ) ) { continue; }
					$controls[ $control_name ] = [ 'type' => isset( $control['type'] ) ? (string) $control['type'] : '', 'label' => isset( $control['label'] ) ? wp_strip_all_tags( (string) $control['label'] ) : '', 'responsive' => ! empty( $control['responsive'] ), 'dynamic' => ! empty( $control['dynamic']['active'] ) ];
				}
				$entry['controls'] = $controls;
			}
			$catalog[] = $entry;
		}
		return $catalog;
	}

	private function scope_elements( array $elements, string $scope, array $selected_ids ): array {
		$scope = in_array( $scope, [ 'document', 'selection', 'subtree' ], true ) ? $scope : 'document';
		if ( 'document' === $scope ) { return $elements; }
		$ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', $selected_ids ) ) ) );
		if ( ! $ids ) { return []; }
		$result = [];
		$this->collect_selected( $elements, $ids, 'subtree' === $scope, $result );
		return $result;
	}

	private function collect_selected( array $elements, array $ids, bool $include_subtree, array &$result ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$id = isset( $element['id'] ) ? (string) $element['id'] : '';
			if ( in_array( $id, $ids, true ) ) { $copy = $element; if ( ! $include_subtree ) { $copy['elements'] = []; } $result[] = $copy; if ( $include_subtree ) { continue; } }
			$this->collect_selected( (array) ( $element['elements'] ?? [] ), $ids, $include_subtree, $result );
		}
	}

	private function instructions(): string {
		return implode( "\n", [
			'You are editing an Elementor document through Cresco Layer.',
			'Return JSON only, using schema cresco-layer-patch/v1.',
			'Use manifest.baseChecksum as base.checksum and manifest.postId as base.postId.',
			'Preserve existing element IDs unless the operation inserts a new element.',
			'Use only widget/control names present in widgetCatalog unless the current document already uses them.',
			'Respect Elementor responsive suffix semantics: base desktop values inherit unless a breakpoint-specific key exists.',
			'Preserve Atomic/V4 fields when editing Atomic elements.',
			'Prefer existing Elementor global styles/design-system values instead of arbitrary near-duplicate colors, typography and spacing.',
			'Do not output secrets, WordPress nonces, credentials, API keys or executable JavaScript.',
			'Do not save or publish. Cresco Layer validates, previews and stores reviewed changes in Elementor working data; the user chooses Update/Publish.',
		] );
	}

	private function redact( $value, string $key = '' ) {
		if ( preg_match( '/(?:secret|password|passwd|api[_-]?key|private[_-]?key|access[_-]?token|refresh[_-]?token|authorization|nonce|smtp[_-]?pass|webhook[_-]?secret)/i', $key ) ) { return '[REDACTED]'; }
		if ( ! is_array( $value ) ) { return $value; }
		foreach ( $value as $child_key => $child ) { $value[ $child_key ] = $this->redact( $child, (string) $child_key ); }
		return $value;
	}
}
