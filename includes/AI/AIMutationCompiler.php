<?php
namespace CrescoLayer\AI;

/**
 * Compiles the AI-facing semantic mutation contract into scoped patch/v1 operations.
 *
 * The external model chooses semantic intent, but it may only name widget/element types that the
 * active Elementor runtime actually exposes. Cresco owns placement, target validation and final
 * Elementor IDs (assigned later by InternalPatchCompiler).
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
			throw new \InvalidArgumentException( 'This selected scope cannot safely add a sibling before/after the target. Select/export the parent Container, then add inside that parent at the intended position.' );
		}
		$position = 'inside-start' === $mode ? 0 : 999999;
		$out = [];
		foreach ( $nodes as $offset => $node ) {
			if ( ! is_array( $node ) ) { continue; }
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
		if ( ! $changes ) { throw new \InvalidArgumentException( 'Edit mutation requires a changes array.' ); }
		$allowed = array_fill_keys( $scope_ids, true );
		$allow_behavioral = true === ( $mutation['allowBehavioralChanges'] ?? false );
		$out = [];
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) { continue; }
			$element_id = trim( (string) ( $change['elementId'] ?? '' ) );
			if ( '' === $element_id || ! isset( $allowed[ $element_id ] ) ) {
				throw new \InvalidArgumentException( 'Semantic edit targets an element outside the exported scope: ' . $element_id );
			}
			$setting = trim( (string) ( $change['setting'] ?? '' ) );
			if ( '' === $setting ) { throw new \InvalidArgumentException( 'Semantic edit change is missing a setting name.' ); }
			if ( ! $allow_behavioral && $this->is_protected_setting( $setting ) ) {
				throw new \InvalidArgumentException( 'Visual semantic mutation cannot modify protected behavioral/external setting "' . $setting . '". Set allowBehavioralChanges only for an explicit user request.' );
			}
			if ( true === ( $change['remove'] ?? false ) ) {
				$out[] = [ 'operation' => 'remove-setting', 'elementId' => $element_id, 'setting' => $setting ];
			} else {
				$out[] = [ 'operation' => 'update-setting', 'elementId' => $element_id, 'setting' => $setting, 'value' => $change['value'] ?? null ];
			}
		}
		return $out;
	}

	private function compile_move( array $mutation, array $scope_ids, string $target_id ): array {
		$element_id = trim( (string) ( $mutation['elementId'] ?? $target_id ) );
		$allowed = array_fill_keys( $scope_ids, true );
		if ( ! isset( $allowed[ $element_id ] ) ) { throw new \InvalidArgumentException( 'Move target is outside the exported scope.' ); }
		$placement = is_array( $mutation['placement'] ?? null ) ? $mutation['placement'] : [];
		$parent_id = trim( (string) ( $placement['parentId'] ?? '' ) );
		if ( '' === $parent_id || ! isset( $allowed[ $parent_id ] ) ) {
			throw new \InvalidArgumentException( 'Move destination must be an editable Container inside the exported scope.' );
		}
		return [[
			'operation' => 'move-element',
			'elementId' => $element_id,
			'parentId' => $parent_id,
			'position' => max( 0, (int) ( $placement['position'] ?? 0 ) ),
		]];
	}

	private function compile_remove( array $mutation, array $scope_ids, string $target_id ): array {
		$ids = is_array( $mutation['elementIds'] ?? null ) ? $mutation['elementIds'] : [];
		$allowed = array_fill_keys( $scope_ids, true );
		$out = [];
		foreach ( array_values( array_unique( array_map( 'strval', $ids ) ) ) as $id ) {
			$id = trim( $id );
			if ( '' === $id ) { continue; }
			if ( $id === $target_id ) { throw new \InvalidArgumentException( 'Semantic remove cannot delete the selected root target. Use an explicitly reviewed wider-scope patch for root removal.' ); }
			if ( ! isset( $allowed[ $id ] ) ) { throw new \InvalidArgumentException( 'Remove target is outside the exported scope: ' . $id ); }
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
			if ( '' !== $live_type && $live_type !== (string) ( $element['elType'] ?? '' ) ) {
				throw new \InvalidArgumentException( 'Rebuild root elType must match the selected Elementor target.' );
			}
			if ( 'widget' === $live_type && '' !== $live_widget && $live_widget !== (string) ( $element['widgetType'] ?? '' ) ) {
				throw new \InvalidArgumentException( 'Widget rebuild must keep the selected widget type. Select a Container for a structural redesign.' );
			}
		}
		return [[
			'operation' => 'replace-element',
			'elementId' => $target_id,
			'element' => $element,
			'preserveChildren' => 'widget' === $scope_mode,
		]];
	}

	private function node_to_element( array $node, array $catalog ): array {
		$intent = trim( (string) ( $node['widgetIntent'] ?? $node['widgetType'] ?? $node['elType'] ?? '' ) );
		if ( '' === $intent ) { throw new \InvalidArgumentException( 'Semantic node is missing widgetIntent.' ); }
		$explicit_el_type = trim( (string) ( $node['elType'] ?? '' ) );
		$is_layout = ( '' !== $explicit_el_type && 'widget' !== $explicit_el_type )
			|| in_array( $intent, [ 'container', 'section', 'column' ], true )
			|| ( isset( $catalog['elements'][ $intent ] ) && is_array( $catalog['elements'][ $intent ] ) && ! isset( $catalog['widgets'][ $intent ] ) );
		$el_type = $is_layout ? ( '' !== $explicit_el_type && 'widget' !== $explicit_el_type ? $explicit_el_type : $intent ) : 'widget';
		$entry = $this->assert_runtime_type( $is_layout ? 'element' : 'widget', $is_layout ? $el_type : $intent, $catalog );

		$element = [
			'elType' => $el_type,
			'settings' => is_array( $node['settings'] ?? null ) ? $node['settings'] : [],
			'elements' => [],
		];
		if ( ! $is_layout ) { $element['widgetType'] = $intent; }
		if ( isset( $node['ref'] ) ) { $element['ref'] = trim( (string) $node['ref'] ); }
		if ( isset( $node['id'] ) ) { $element['id'] = trim( (string) $node['id'] ); }
		if ( isset( $node['isInner'] ) ) { $element['isInner'] = (bool) $node['isInner']; }

		$element['settings'] = array_replace( $this->content_settings_from_runtime( $intent, is_array( $node['content'] ?? null ) ? $node['content'] : [], $entry ), $element['settings'] );
		$children = (array) ( $node['children'] ?? $node['elements'] ?? [] );
		if ( ! $is_layout && $children ) {
			throw new \InvalidArgumentException( 'A widget cannot own arbitrary Elementor child nodes in semantic mutation. Use a structural Elementor element as the parent or the widget native runtime repeater/content controls.' );
		}
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

	private function content_settings_from_runtime( string $intent, array $content, array $entry ): array {
		$out = [];
		$controls = is_array( $entry['controls'] ?? null ) ? $entry['controls'] : [];
		$name = strtolower( $intent );
		$text = (string) ( $content['text'] ?? '' );
		if ( preg_match( '/heading|headline|title/', $name ) ) {
			$text_key = $this->first_runtime_control( $controls, [ 'title', 'text', 'heading', 'headline' ] );
			if ( '' !== $text && '' !== $text_key ) { $out[ $text_key ] = $text; }
			$level = strtolower( (string) ( $content['semanticLevel'] ?? '' ) );
			if ( preg_match( '/^h[1-6]$/', $level ) ) {
				$level_key = $this->first_runtime_control( $controls, [ 'header_size', 'html_tag', 'tag' ] );
				if ( '' !== $level_key && $this->runtime_control_allows( $controls[ $level_key ], $level ) ) { $out[ $level_key ] = $level; }
			}
		} elseif ( preg_match( '/text-editor|paragraph|rich-text|^text$/', $name ) ) {
			$text_key = $this->first_runtime_control( $controls, [ 'editor', 'text', 'content', 'description' ] );
			if ( isset( $content['html'] ) && '' !== $text_key ) { $out[ $text_key ] = (string) $content['html']; }
			elseif ( '' !== $text && '' !== $text_key ) { $out[ $text_key ] = 'editor' === $text_key ? '<p>' . esc_html( $text ) . '</p>' : $text; }
		} elseif ( preg_match( '/button|cta/', $name ) ) {
			$text_key = $this->first_runtime_control( $controls, [ 'text', 'button_text', 'label', 'title' ] );
			if ( '' !== $text && '' !== $text_key ) { $out[ $text_key ] = $text; }
			if ( isset( $content['url'] ) ) {
				$link_key = $this->first_runtime_control( $controls, [ 'link', 'url', 'button_link' ] );
				if ( '' !== $link_key ) { $out[ $link_key ] = is_array( $content['url'] ) ? $content['url'] : [ 'url' => esc_url_raw( (string) $content['url'] ) ]; }
			}
		} elseif ( preg_match( '/image|media/', $name ) && is_array( $content['image'] ?? null ) ) {
			$image_key = $this->first_runtime_control( $controls, [ 'image', 'media', 'source' ] );
			if ( '' !== $image_key ) { $out[ $image_key ] = $content['image']; }
		} elseif ( preg_match( '/icon/', $name ) && is_array( $content['icon'] ?? null ) ) {
			$icon_key = $this->first_runtime_control( $controls, [ 'selected_icon', 'icon' ] );
			if ( '' !== $icon_key ) { $out[ $icon_key ] = $content['icon']; }
		}
		return $out;
	}

	private function first_runtime_control( array $controls, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( isset( $controls[ $candidate ] ) && is_array( $controls[ $candidate ] ) ) { return $candidate; }
		}
		return '';
	}

	private function runtime_control_allows( array $control, string $value ): bool {
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
