<?php
namespace CrescoLayer\AI;

use CrescoLayer\Audit\Auditor;
use CrescoLayer\Support\SerializableSanitizer;
use Elementor\Plugin as ElementorPlugin;

final class PackageBuilder {
	private ElementLocator $locator;
	private CapabilityScanner $scanner;
	private ContextResolver $contextResolver;

	public function __construct(
		private Auditor $auditor,
		?ElementLocator $locator = null,
		?CapabilityScanner $scanner = null,
		?ContextResolver $contextResolver = null
	) {
		$this->locator = $locator ?? new ElementLocator();
		$this->scanner = $scanner ?? new CapabilityScanner();
		$this->contextResolver = $contextResolver ?? new ContextResolver( $this->scanner );
	}

	public function build( int $post_id, string $scope = 'document', array $selected_ids = [], string $context_profile = ContextResolver::PROFILE_SMART ): array {
		$scope = in_array( $scope, ElementLocator::SCOPES, true ) ? $scope : 'document';
		$context_profile = in_array( $context_profile, ContextResolver::PROFILES, true ) ? $context_profile : ContextResolver::PROFILE_SMART;
		$selected_ids = $this->locator->normalize_ids( $selected_ids );
		if ( 'document' !== $scope && ! $selected_ids ) {
			throw new \InvalidArgumentException( 'A widget, selection or subtree export requires at least one selected Elementor element ID.' );
		}
		if ( 'widget' === $scope ) { $selected_ids = [ $selected_ids[0] ]; }

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
		$export_elements = $this->locator->scope_elements( $elements, $scope, $selected_ids );
		if ( 'document' !== $scope && count( $export_elements ) !== count( $selected_ids ) ) {
			throw new \RuntimeException( 'One or more selected Elementor elements no longer exist. Re-select the elements and export again.' );
		}

		$editable_ids = $this->locator->scope_ids( $elements, $scope, $selected_ids );
		$layout_context = ( new LayoutContextBuilder() )->build( $elements, $editable_ids );
		$post = get_post( $post_id );
		$theme = wp_get_theme();
		$element_context = 'document' === $scope ? [] : $this->locator->context( $elements, $selected_ids );
		$resolved = $this->contextResolver->resolve( $export_elements, $element_context, $scope, $context_profile );
		$catalog = [
			'widgets' => (array) ( $resolved['capabilities']['widgets'] ?? [] ),
			'elements' => (array) ( $resolved['capabilities']['elements'] ?? [] ),
			'controlMetadataVersion' => (int) ( $resolved['registryIndex']['controlMetadataVersion'] ?? 0 ),
		];
		$control_registry = ( new ControlRegistry() )->build( $catalog );

		$package = [
			'schema' => 'cresco-layer-ai-package/v2',
			'manifest' => [
				'pluginVersion' => CRESCO_LAYER_VERSION,
				'elementorVersion' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
				'elementorProVersion' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
				'postId' => $post_id,
				'workingPostId' => $working_post_id,
				'isAutosave' => $working_post_id !== $post_id,
				'documentType' => method_exists( $main_document, 'get_name' ) ? (string) $main_document->get_name() : get_post_type( $post_id ),
				'documentVersion' => '0.5',
				'exportedAt' => gmdate( 'c' ),
				'scope' => $scope,
				'contextProfile' => $context_profile,
				'contextResolver' => (string) ( $resolved['resolver'] ?? 'cresco-context-resolver/v1' ),
			],
			'editableScope' => [
				'mode' => $scope,
				'rootElementId' => 1 === count( $selected_ids ) ? $selected_ids[0] : '',
				'elementIds' => $selected_ids,
				'editableElementIds' => $editable_ids,
				'pageSettingsEditable' => 'document' === $scope,
				'preserveChildrenOnRootReplace' => 'widget' === $scope,
			],
			'document' => [
				'title' => $post ? $post->post_title : '',
				'type' => $post ? $post->post_type : '',
				'status' => $post ? $post->post_status : '',
				'page_settings' => $page_settings,
				'content' => $export_elements,
			],
			'elementContext' => $element_context,
			'elementStates' => $this->element_states( $export_elements, $catalog ),
			'siteContext' => [
				'homeUrl' => home_url( '/' ),
				'locale' => get_locale(),
				'theme' => $theme->get( 'Name' ),
				'themeVersion' => $theme->get( 'Version' ),
				'breakpoints' => (array) ( $resolved['siteContext']['breakpoints'] ?? [] ),
			],
			'designSystem' => (array) ( $resolved['siteContext']['designSystem'] ?? [] ),
			'layoutContext' => $layout_context,
			'registryIndex' => (array) ( $resolved['registryIndex'] ?? [] ),
			'controlRegistry' => $control_registry,
			'patchContract' => [
				'schema' => 'cresco-layer-patch-contract/v2',
				'transportSchema' => 'cresco-layer-patch/v1',
				'validationSchema' => 'cresco-layer-patch-validation/v2',
				'mode' => 'runtime-fail-closed',
				'checks' => [ 'scope', 'target-exists', 'registered-control', 'responsive-capability', 'unit', 'option', 'range', 'global-reference', 'unsafe-value' ],
				'unknownPersistedFields' => 'preserve-only-when-unchanged',
			],
			'widgetCatalog' => $catalog['widgets'],
			'elementCatalog' => $catalog['elements'],
			'relevantCapabilities' => [
				'widgets' => $catalog['widgets'],
				'elements' => $catalog['elements'],
				'roles' => (array) ( $resolved['capabilities']['roles'] ?? [] ),
				'controlMetadataVersion' => $catalog['controlMetadataVersion'],
			],
			'dynamicTags' => (array) ( $resolved['dynamicTags'] ?? [] ),
			'capabilityCoverage' => (array) ( $resolved['capabilityCoverage'] ?? [] ),
			'contextResolver' => [
				'profile' => $context_profile,
				'stats' => (array) ( $resolved['contextStats'] ?? [] ),
				'runtime' => (array) ( $resolved['runtime'] ?? [] ),
				'scanErrors' => (array) ( $resolved['scanErrors'] ?? [] ),
			],
			'templates' => $this->template_catalog(),
			'assets' => $this->asset_catalog( $export_elements ),
			'capabilities' => [
				'patchSchema' => 'cresco-layer-patch/v1',
				'packageSchema' => 'cresco-layer-ai-package/v2',
				'normalizedControlRegistry' => ControlRegistry::SCHEMA,
				'patchValidationContract' => 'cresco-layer-patch-validation/v2',
				'runtimeControlValidation' => true,
				'operations' => PatchValidator::ALLOWED_OPERATIONS,
				'atomicElementsAware' => true,
				'losslessUnknownElementFields' => true,
				'checksumFreePatchContract' => true,
				'targetValidatedAtApply' => true,
				'scopeValidatedAtApply' => true,
				'classicalResponsiveSuffixes' => ControlRegistry::RESPONSIVE_SUFFIXES,
				'preserveExistingElementIds' => true,
				'preferGlobalStyles' => true,
				'elementorOwnsPersistence' => true,
				'publishedDocumentsUseAutosave' => true,
				'contextResolved' => true,
				'fullRuntimeSnapshotIsSeparate' => true,
				'containerRoleAware' => true,
				'responsiveFoundationPolicy' => (string) ( $layout_context['responsiveFoundation']['policy'] ?? '' ),
			],
			'audit' => $this->auditor->audit_elements( $export_elements ),
			'instructions' => $this->instructions( $scope, $selected_ids, $context_profile ),
		];

		$sanitizer = new SerializableSanitizer();
		$package = $sanitizer->sanitize( $package, '$.aiPackage' );
		if ( is_array( $package ) ) { $package['serialization'] = $sanitizer->report(); }
		return is_array( $package ) ? $package : [];
	}

	private function element_states( array $elements, array $catalog ): array {
		$out = [];
		$this->collect_element_states( $elements, $catalog, $out );
		return $out;
	}

	private function collect_element_states( array $elements, array $catalog, array &$out ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$id = (string) ( $element['id'] ?? '' );
			$el_type = (string) ( $element['elType'] ?? '' );
			$widget_type = (string) ( $element['widgetType'] ?? '' );
			$capability = [];
			if ( '' !== $widget_type && isset( $catalog['widgets'][ $widget_type ] ) ) { $capability = $catalog['widgets'][ $widget_type ]; }
			elseif ( '' !== $el_type && isset( $catalog['elements'][ $el_type ] ) ) { $capability = $catalog['elements'][ $el_type ]; }
			$defaults = is_array( $capability['defaultSettings'] ?? null ) ? $capability['defaultSettings'] : [];
			$raw = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
			$controls = is_array( $capability['controls'] ?? null ) ? $capability['controls'] : [];
			$unknown = [];
			foreach ( $raw as $key => $value ) {
				$base_key = preg_replace( '/_(?:tablet|mobile|widescreen|laptop|tablet_extra|mobile_extra)$/', '', (string) $key );
				if ( ! isset( $controls[ $key ] ) && ! isset( $controls[ $base_key ] ) && '__globals__' !== $key ) { $unknown[ $key ] = $value; }
			}
			$out[ $id ] = [
				'id' => $id,
				'elType' => $el_type,
				'widgetType' => $widget_type,
				'rawSettings' => $raw,
				'defaultSettings' => $defaults,
				'effectiveWithDefaults' => array_replace_recursive( $defaults, $raw ),
				'globalReferences' => is_array( $raw['__globals__'] ?? null ) ? $raw['__globals__'] : [],
				'responsiveOverrides' => $this->responsive_overrides( $raw ),
				'unknownPersistedSettings' => $unknown,
			];
			$this->collect_element_states( (array) ( $element['elements'] ?? [] ), $catalog, $out );
		}
	}

	private function responsive_overrides( array $settings ): array {
		$out = [];
		foreach ( $settings as $key => $value ) {
			if ( ! preg_match( '/^(.+?)_(tablet|mobile|widescreen|laptop|tablet_extra|mobile_extra)$/', (string) $key, $matches ) ) { continue; }
			$out[ $matches[1] ][ $matches[2] ] = $value;
		}
		return $out;
	}

	private function template_catalog(): array {
		$query = new \WP_Query( [
			'post_type' => 'elementor_library',
			'post_status' => [ 'publish', 'draft', 'private' ],
			'posts_per_page' => 200,
			'orderby' => 'modified',
			'order' => 'DESC',
			'no_found_rows' => true,
		] );
		$out = [];
		foreach ( $query->posts as $template ) {
			if ( ! current_user_can( 'edit_post', $template->ID ) ) { continue; }
			$out[] = [
				'id' => (int) $template->ID,
				'title' => (string) $template->post_title,
				'type' => (string) get_post_meta( $template->ID, '_elementor_template_type', true ),
				'status' => (string) $template->post_status,
			];
		}
		return $out;
	}

	private function asset_catalog( array $elements ): array {
		$ids = [];
		$this->collect_attachment_ids( $elements, $ids );
		$out = [];
		foreach ( array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 250 ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'attachment' !== $post->post_type ) { continue; }
			$metadata = wp_get_attachment_metadata( $id );
			$out[] = [
				'id' => $id,
				'title' => get_the_title( $id ),
				'url' => wp_get_attachment_url( $id ) ?: '',
				'mime' => get_post_mime_type( $id ) ?: '',
				'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'width' => is_array( $metadata ) ? (int) ( $metadata['width'] ?? 0 ) : 0,
				'height' => is_array( $metadata ) ? (int) ( $metadata['height'] ?? 0 ) : 0,
			];
		}
		return $out;
	}

	private function collect_attachment_ids( $value, array &$ids, string $key = '' ): void {
		if ( ! is_array( $value ) ) {
			if ( is_numeric( $value ) && preg_match( '/(?:image|media|background|icon|video|attachment)(?:_id)?$/i', $key ) ) { $ids[] = absint( $value ); }
			return;
		}
		if ( isset( $value['id'] ) && is_numeric( $value['id'] ) && ( isset( $value['url'] ) || preg_match( '/(?:image|media|background|icon|video)/i', $key ) ) ) {
			$ids[] = absint( $value['id'] );
		}
		foreach ( $value as $child_key => $child ) { $this->collect_attachment_ids( $child, $ids, (string) $child_key ); }
	}

	private function instructions( string $scope, array $selected_ids, string $context_profile ): string {
		$scope_payload = wp_json_encode( [
			'mode' => $scope,
			'rootElementId' => 1 === count( $selected_ids ) ? $selected_ids[0] : '',
			'elementIds' => $selected_ids,
		] );
		return implode( "\n", [
			'You are editing an Elementor document through Cresco Layer.',
			'Return JSON only, using schema cresco-layer-patch/v1.',
			'Use manifest.postId as base.postId. Do not include checksum fields in the patch.',
			'Copy this exact editable scope identity into the patch scope object: ' . $scope_payload,
			'Cresco validates the current post, selected scope, target element existence and runtime capability metadata at preview/apply time; checksum freshness is not part of the AI patch contract.',
			'For scoped exports, modify only the IDs allowed by editableScope. Context parents/siblings are read-only unless they are also editable.',
			'For widget scope, preserve existing children. Prefer update-setting/remove-setting; replace-element is allowed only when you preserve all unknown fields and the same root ID.',
			'For subtree scope, inserted descendants are allowed only below an editable parent. Do not move the subtree into unrelated parts of the page.',
			'This package uses Context Resolver profile "' . $context_profile . '". widgetCatalog and elementCatalog contain detailed controls selected for this task; registryIndex is the compact list of all registered types.',
			'controlRegistry is the normalized AI-facing control contract. Never invent a setting name. Every changed setting must resolve to a registered runtime control, including responsive suffixes.',
			'Runtime import validation is fail-closed: unsupported controls, non-responsive device suffixes, invalid units, invalid select options, out-of-range slider values and invalid global references are rejected before Elementor persistence.',
			'Unknown persisted Elementor fields may be preserved unchanged for forward compatibility, but AI must not create or mutate unknown fields.',
			'Only write settings backed by a detailed capability entry. If a type exists only in registryIndex, leave its settings at Elementor defaults or request a full-context export.',
			'Use capabilityCoverage before relying on controls, Active Kit, breakpoints, Dynamic Tags or Elementor Pro runtime modules. Do not guess data marked partial or unavailable.',
			'Use layoutContext.containerRoles when editing Containers. A section-shell inherits the responsive horizontal page gutter from Elementor Site Settings and should keep vertical padding at zero; its inner content container owns vertical section rhythm.',
			'Global Elementor Container Padding carries the Cresco responsive clamp gutter on left/right and zero on top/bottom; structural content/nested containers should explicitly reset horizontal padding to zero when they must not repeat the page gutter; local component/card padding is allowed.',
			'Respect layoutContext.responsiveFoundation for Mobile, Tablet, Laptop, Desktop and Widescreen geometry instead of inventing additional breakpoint semantics.',
			'Preserve existing element IDs unless inserting new elements. Generate unique IDs for inserted elements.',
			'Respect Elementor responsive suffix semantics and the active breakpoints supplied by the package.',
			'Preserve Dynamic Tags, global style references, Atomic/V4 fields, classes, variables, interactions, editor_settings and unknown Elementor fields unless intentionally changing them.',
			'Prefer existing Elementor global styles/design-system values instead of arbitrary near-duplicate colors, typography and spacing.',
			'Do not output secrets, WordPress nonces, credentials, API keys, authentication data or executable JavaScript.',
			'Do not save or publish. Cresco Layer validates, previews and writes reviewed changes into Elementor working data; the user chooses Update/Publish.',
		] );
	}
}
