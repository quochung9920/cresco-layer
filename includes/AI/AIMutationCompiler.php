<?php
namespace CrescoLayer\AI;

/**
 * Compiles the AI-facing semantic mutation contract into scoped patch/v1 operations.
 *
 * External AI describes semantic intent. Cresco resolves that intent only through the active
 * Elementor runtime, owns placement/target validation, and leaves final new IDs to the downstream
 * InternalPatchCompiler/ElementorIdGenerator.
 */
final class AIMutationCompiler {
	public const SCHEMA = 'cresco-ai-mutation/v2';
	private const INTENTS = [ 'add', 'edit', 'move', 'remove', 'rebuild' ];
	private const PROTECTED_SETTING_FRAGMENTS = [
		'actions_after_submit', 'webhook', 'email_to', 'email_from', 'redirect', 'payment', 'stripe', 'paypal',
		'query_id', 'template_id', 'product_id', 'menu_id', 'shortcode', 'html', 'code',
	];

	private ElementLocator $locator;
	private CapabilityScanner $scanner;

	public function __construct( ?ElementLocator $locator = null, ?CapabilityScanner $scanner = null ) {
		$this->locator = $locator ?? new ElementLocator();
		$this->scanner = $scanner ?? new CapabilityScanner();
	}

	/** @return array{patch:array,report:array} */
	public function compile( array $mutation, int $post_id, array $elements, string $selected = '' ): array {
		if ( self::SCHEMA !== (string) ( $mutation['schema'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'Unsupported semantic mutation schema.' );
		}

		$intent = strtolower( trim( (string) ( $mutation['intent'] ?? '' ) ) );
		if ( ! in_array( $intent, self::INTENTS, true ) ) {
			throw new \InvalidArgumentException( 'cresco-ai-mutation/v2 intent must be add, edit, move, remove or rebuild.' );
		}

		$target_id = $this->resolve_target( $mutation, $post_id, $elements, $selected );
		$live_target = $this->locator->find( $elements, $target_id );
		$scope_mode = is_array( $live_target ) && 'widget' === (string) ( $live_target['elType'] ?? '' ) ? 'widget' : 'subtree';
		$scope_ids = $this->locator->scope_ids( $elements, $scope_mode, [ $target_id ] );
		$catalog = $this->scanner->catalog();
		$operations = [];

		switch ( $intent ) {
			case 'add':
				$operations = $this->compile_add( $mutation, $target_id, $scope_mode, $catalog );
				break;
			case 'edit':
				$operations = $this->compile_edit( $mutation, $scope_ids );
				break;
			case 'move':
				$operations = $this->compile_move( $mutation, $scope_ids, $target_id );
				break;
			case 'remove':
				$operations = $this->compile_remove( $mutation, $scope_ids, $target_id );
				break;
			case 'rebuild':
				$operations = $this->compile_rebuild( $mutation, $target_id, $scope_mode, $live_target, $catalog );
				break;
		}

		if ( ! $operations ) { throw new \InvalidArgumentException( 'The semantic mutation did not produce any Elementor changes.' ); }

		return [
			'patch' => [
				'schema' => 'cresco-layer-patch/v1',
				'base' => [ 'postId' => $post_id ],
				'scope' => [ 'mode' => $scope_mode, 'rootElementId' => $target_id, 'elementIds' => [ $target_id ] ],
				'label' => sanitize_text_field( (string) ( $mutation['label'] ?? 'External AI semantic mutation' ) ),
				'operations' => $operations,
			],
			'report' => [
				'source' => 'ai-mutation-v2',
				'intent' => $intent,
				'targetId' => $target_id,
				'scopeMode' => $scope_mode,
				'operationCount' => count( $operations ),
				'runtimeValidatedWidgetIntent' => in_array( $intent, [ 'add', 'rebuild' ], true ),
				'runtimeSemanticBinding' => in_array( $intent, [ 'add', 'rebuild' ], true ),
			],
		];
	}

	private function compile_add( array $mutation, string $target_id, string $scope_mode, array $catalog ): array {
		if ( 'widget' === $scope_mode ) {
			throw new \InvalidArgumentException( 'A widget cannot own newly inserted child UI. Select/export its parent Container and try Add again.' );
		}
		$nodes = is_array( $mutation['nodes'] ?? null ) ? $mutation['nodes'] : [];
		if ( ! $nodes ) { throw new \InvalidArgumentException( 'Add mutation requires at least one node.' ); }
		$placement = is_array( $mutation['placement'] ?? null ) ? $mutation['placement'] : [];
		$mode = (string) ( $placement['mode'] ?? 'inside-end' );
		if ( ! in_array( $mode, [ 'inside-start', 'inside-end' ], true ) ) {
			throw new \InvalidArgumentException( 'Add mutation placement must be inside-start or inside-end for the selected editable Container. Select/export a wider parent scope for sibling placement.' );
		}
		$position = 'inside-start' === $mode ? 0 : 999999;
		$out = [];
		foreach ( $nodes as $offset => $node ) {
			if ( ! is_array( $node ) ) { throw new \InvalidArgumentException( 'Add mutation contains an invalid semantic node.' ); }
			$out[] = [
				'operation' => 'insert-element',
				'parentId' => $target_id,
				'position' => 999999 === $position ? $position : $position + $offset,
				'element' => $this->node_to_element( $node, $catalog ),
			];
		}
		return $out;
	}

	private function compile_edit( array $mutation, array $scope_ids ): array {
		$changes = is_array( $mutation['changes'] ?? null ) ? $mutation['changes'] : [];
		if ( ! $changes ) { throw new \InvalidArgumentException( 'Edit mutation requires changes.' ); }
		$allow_behavioral = ! empty( $mutation['allowBehavioralChanges'] );
		$out = [];
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) { continue; }
			$id = trim( (string) ( $change['elementId'] ?? '' ) );
			$setting = trim( (string) ( $change['setting'] ?? '' ) );
			if ( '' === $id || ! in_array( $id, $scope_ids, true ) ) { throw new \InvalidArgumentException( 'Edit mutation references an element outside the exported editable scope: ' . $id ); }
			if ( '' === $setting ) { throw new \InvalidArgumentException( 'Edit mutation is missing a setting key.' ); }
			if ( ! $allow_behavioral && $this->is_protected_setting( $setting ) ) { throw new \InvalidArgumentException( 'Edit mutation targets protected behavioral/external setting "' . $setting . '". The user must explicitly request that behavior change.' ); }
			if ( ! empty( $change['remove'] ) ) {
				$out[] = [ 'operation' => 'remove-setting', 'elementId' => $id, 'setting' => $setting ];
			} else {
				$out[] = [ 'operation' => 'update-setting', 'elementId' => $id, 'setting' => $setting, 'value' => $change['value'] ?? null ];
			}
		}
		return $out;
	}

	private function compile_move( array $mutation, array $scope_ids, string $target_id ): array {
		$id = trim( (string) ( $mutation['elementId'] ?? '' ) );
		$placement = is_array( $mutation['placement'] ?? null ) ? $mutation['placement'] : [];
		$parent = trim( (string) ( $placement['parentId'] ?? $target_id ) );
		$position = max( 0, (int) ( $placement['position'] ?? 0 ) );
		if ( '' === $id || ! in_array( $id, $scope_ids, true ) ) { throw new \InvalidArgumentException( 'Move mutation references an element outside the exported editable scope.' ); }
		if ( '' !== $parent && ! in_array( $parent, $scope_ids, true ) ) { throw new \InvalidArgumentException( 'Move destination is outside the exported editable scope.' ); }
		return [[ 'operation' => 'move-element', 'elementId' => $id, 'parentId' => $parent, 'position' => $position ]];
	}

	private function compile_remove( array $mutation, array $scope_ids, string $target_id ): array {
		$ids = array_values( array_unique( array_map( 'strval', (array) ( $mutation['elementIds'] ?? [] ) ) ) );
		if ( ! $ids ) { throw new \InvalidArgumentException( 'Remove mutation requires elementIds.' ); }
		$out = [];
		foreach ( $ids as $id ) {
			if ( $id === $target_id ) { throw new \InvalidArgumentException( 'The selected root cannot be removed through a narrow semantic mutation. Use explicit Rebuild if replacement is intended.' ); }
			if ( ! in_array( $id, $scope_ids, true ) ) { throw new \InvalidArgumentException( 'Remove mutation references an element outside the exported editable scope: ' . $id ); }
			$out[] = [ 'operation' => 'remove-element', 'elementId' => $id ];
		}
		return $out;
	}

	private function compile_rebuild( array $mutation, string $target_id, string $scope_mode, ?array $live_target, array $catalog ): array {
		$nodes = is_array( $mutation['nodes'] ?? null ) ? $mutation['nodes'] : [];
		if ( 1 !== count( $nodes ) || ! is_array( $nodes[0] ) ) {
			throw new \InvalidArgumentException( 'Rebuild mutation requires exactly one root node.' );
		}
		$element = $this->node_to_element( $nodes[0], $catalog );
		$element['id'] = $target_id;
		if ( is_array( $live_target ) ) {
			$live_type = (string) ( $live_target['elType'] ?? '' );
			$live_widget = (string) ( $live_target['widgetType'] ?? '' );
			if ( $live_type !== (string) ( $element['elType'] ?? '' ) ) { throw new \InvalidArgumentException( 'Rebuild must preserve the selected root Elementor elType.' ); }
			if ( 'widget' === $scope_mode && $live_widget !== (string) ( $element['widgetType'] ?? '' ) ) { throw new \InvalidArgumentException( 'Widget rebuild must preserve the selected widget type.' ); }
			foreach ( $live_target as $key => $value ) {
				if ( ! array_key_exists( $key, $element ) && 'elements' !== $key ) { $element[ $key ] = $value; }
			}
		}
		return [[
			'operation' => 'replace-element', 'elementId' => $target_id, 'element' => $element,
			'preserveChildren' => 'widget' === $scope_mode,
		]];
	}

	private function node_to_element( array $node, array $catalog ): array {
		$intent = trim( (string) ( $node['widgetIntent'] ?? $node['widgetType'] ?? $node['elType'] ?? '' ) );
		if ( '' === $intent ) { throw new \InvalidArgumentException( 'Semantic node is missing widgetIntent.' ); }
		$is_layout = in_array( $intent, [ 'container', 'section', 'column' ], true ) || ( isset( $node['elType'] ) && 'widget' !== (string) $node['elType'] );
		$el_type = $is_layout ? (string) ( $node['elType'] ?? $intent ) : 'widget';
		$entry = $this->assert_runtime_type( $is_layout ? 'element' : 'widget', $is_layout ? $el_type : $intent, $catalog );

		$children = (array) ( $node['children'] ?? $node['elements'] ?? [] );
		if ( ! $is_layout && $children ) {
			throw new \InvalidArgumentException( 'Semantic widget "' . $intent . '" cannot own arbitrary Elementor child nodes. Use a runtime-proven structural element/container as the parent.' );
		}

		$element = [
			'elType' => $el_type,
			'settings' => is_array( $node['settings'] ?? null ) ? $node['settings'] : [],
			'elements' => [],
		];
		if ( ! $is_layout ) { $element['widgetType'] = $intent; }
		if ( isset( $node['ref'] ) ) { $element['ref'] = (string) $node['ref']; }
		if ( isset( $node['id'] ) ) { $element['id'] = (string) $node['id']; }
		if ( array_key_exists( 'isInner', $node ) ) { $element['isInner'] = (bool) $node['isInner']; }

		$semantic_settings = $this->content_settings_from_runtime(
			$intent,
			is_array( $node['content'] ?? null ) ? $node['content'] : [],
			$entry
		);
		$element['settings'] = array_replace( $semantic_settings, $element['settings'] );
		foreach ( $children as $child ) {
			if ( is_array( $child ) ) { $element['elements'][] = $this->node_to_element( $child, $catalog ); }
		}
		return $element;
	}

	private function assert_runtime_type( string $kind, string $name, array $catalog ): array {
		$group = 'widget' === $kind ? 'widgets' : 'elements';
		if ( '' !== $name && isset( $catalog[ $group ][ $name ] ) && is_array( $catalog[ $group ][ $name ] ) ) { return $catalog[ $group ][ $name ]; }
		throw new \InvalidArgumentException( sprintf(
			'Semantic %s intent "%s" is not present in the active Elementor runtime. Use widgetIntelligence/runtime from the exported Cresco context instead of inventing a type.',
			$kind,
			$name
		) );
	}

	/**
	 * Resolve semantic content through control keys that are actually present on this runtime entry.
	 * Candidates are intentionally conservative; no setting is emitted unless the exact key exists.
	 */
	private function content_settings_from_runtime( string $intent, array $content, array $entry ): array {
		$controls = is_array( $entry['controls'] ?? null ) ? $entry['controls'] : [];
		$out = [];
		$text = (string) ( $content['text'] ?? '' );
		$name = strtolower( $intent );

		if ( preg_match( '/heading|headline|title/', $name ) ) {
			$key = $this->first_control( $controls, [ 'title', 'text', 'heading', 'headline' ] );
			if ( '' !== $text && '' !== $key ) { $out[ $key ] = $text; }
			$level = strtolower( (string) ( $content['semanticLevel'] ?? '' ) );
			if ( preg_match( '/^h[1-6]$/', $level ) ) {
				$level_key = $this->first_control( $controls, [ 'header_size', 'html_tag', 'tag' ] );
				if ( '' !== $level_key && $this->control_allows( $controls[ $level_key ], $level ) ) { $out[ $level_key ] = $level; }
			}
		} elseif ( preg_match( '/text-editor|paragraph|rich-text|^text$/', $name ) ) {
			$key = $this->first_control( $controls, [ 'editor', 'text', 'content', 'description' ] );
			if ( '' !== $key ) {
				if ( isset( $content['html'] ) ) { $out[ $key ] = (string) $content['html']; }
				elseif ( '' !== $text ) { $out[ $key ] = 'editor' === $key ? '<p>' . esc_html( $text ) . '</p>' : $text; }
			}
		} elseif ( preg_match( '/button|cta/', $name ) ) {
			$text_key = $this->first_control( $controls, [ 'text', 'button_text', 'label', 'title' ] );
			if ( '' !== $text && '' !== $text_key ) { $out[ $text_key ] = $text; }
			if ( isset( $content['url'] ) ) {
				$link_key = $this->first_control( $controls, [ 'link', 'url', 'button_link' ] );
				if ( '' !== $link_key ) { $out[ $link_key ] = is_array( $content['url'] ) ? $content['url'] : [ 'url' => esc_url_raw( (string) $content['url'] ) ]; }
			}
		} elseif ( preg_match( '/image|media/', $name ) && is_array( $content['image'] ?? null ) ) {
			$key = $this->first_control( $controls, [ 'image', 'media', 'source' ] );
			if ( '' !== $key ) { $out[ $key ] = $content['image']; }
		} elseif ( preg_match( '/icon/', $name ) && is_array( $content['icon'] ?? null ) ) {
			$key = $this->first_control( $controls, [ 'selected_icon', 'icon' ] );
			if ( '' !== $key ) { $out[ $key ] = $content['icon']; }
		}
		return $out;
	}

	private function first_control( array $controls, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( isset( $controls[ $candidate ] ) && is_array( $controls[ $candidate ] ) ) { return $candidate; }
		}
		return '';
	}

	private function control_allows( array $control, string $value ): bool {
		$options = is_array( $control['options'] ?? null ) ? $control['options'] : [];
		return ! $options || array_key_exists( $value, $options );
	}

	private function resolve_target( array $mutation, int $post_id, array $elements, string $selected ): string {
		$target = is_array( $mutation['target'] ?? null ) ? $mutation['target'] : [];
		$declared_post = (int) ( $target['postId'] ?? 0 );
		if ( $declared_post && $declared_post !== $post_id ) {
			throw new \InvalidArgumentException( sprintf( 'This semantic mutation belongs to document #%d, not #%d.', $declared_post, $post_id ) );
		}
		$id = trim( (string) ( $target['id'] ?? '' ) );
		if ( '' === $id ) { $id = trim( $selected ); }
		if ( '' === $id ) { throw new \InvalidArgumentException( 'Semantic mutation has no target and nothing is selected in Elementor.' ); }
		if ( '' !== $selected && $id !== $selected ) {
			throw new \InvalidArgumentException( sprintf( 'This semantic mutation targets %s but %s is selected in Elementor.', $id, $selected ) );
		}
		if ( null === $this->locator->find( $elements, $id ) ) { throw new \InvalidArgumentException( 'Semantic mutation target no longer exists: ' . $id ); }
		return $id;
	}

	private function is_protected_setting( string $setting ): bool {
		$setting = strtolower( $setting );
		foreach ( self::PROTECTED_SETTING_FRAGMENTS as $fragment ) {
			if ( str_contains( $setting, $fragment ) ) { return true; }
		}
		return false;
	}
}
