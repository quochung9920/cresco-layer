<?php
namespace CrescoLayer\LocalAI;

use CrescoLayer\AI\ElementLocator;
use CrescoLayer\Skills\WidgetSkillRuntime;
use Elementor\Plugin as ElementorPlugin;

final class ContextCompiler {
	public const SCHEMA = 'cresco-layer-semantic-context/v2';

	private ElementLocator $locator;
	private EffectiveValueResolver $effective;
	private ContextRedactor $redactor;
	private ContextBudgeter $budgeter;
	private SkillRetriever $retriever;

	public function __construct(
		private WidgetSkillRuntime $skills,
		?ElementLocator $locator = null,
		?EffectiveValueResolver $effective = null,
		?ContextRedactor $redactor = null,
		?ContextBudgeter $budgeter = null,
		?SkillRetriever $retriever = null
	) {
		$this->locator = $locator ?? new ElementLocator();
		$this->effective = $effective ?? new EffectiveValueResolver();
		$this->redactor = $redactor ?? new ContextRedactor();
		$this->budgeter = $budgeter ?? new ContextBudgeter();
		$this->retriever = $retriever ?? new SkillRetriever();
	}

	public function compile( int $post_id, string $element_id, string $task, array $options = [] ): array {
		$task = trim( sanitize_textarea_field( $task ) );
		if ( '' === $task ) { throw new \InvalidArgumentException( 'Local AI task is required.' ); }
		if ( function_exists( 'mb_substr' ) ) { $task = mb_substr( $task, 0, 1200 ); }
		else { $task = substr( $task, 0, 1200 ); }

		$profile = $this->skills->profile( $post_id, $element_id );
		$elements = $this->document_elements( $post_id );
		$selected = $this->locator->find( $elements, $element_id );
		if ( ! is_array( $selected ) ) { throw new \RuntimeException( 'Selected Elementor element no longer exists.' ); }
		$graph = $this->locator->context( $elements, [ $element_id ] );
		$graph = is_array( $graph[0] ?? null ) ? $graph[0] : [];

		$current = is_array( $profile['currentSettings'] ?? null ) ? $profile['currentSettings'] : [];
		$live = is_array( $options['liveSettings'] ?? null ) ? $options['liveSettings'] : [];
		if ( $live ) { $current = array_replace( $current, $live ); }

		$knowledge = is_array( $profile['knowledge'] ?? null ) ? $profile['knowledge'] : [];
		$expert_card = WidgetExpertRegistry::for(
			(string) ( $profile['element']['kind'] ?? '' ),
			(string) ( $profile['element']['name'] ?? '' ),
			$knowledge
		);
		$retrieval = $this->retriever->retrieve(
			(array) ( $profile['skills'] ?? [] ),
			$task,
			$expert_card,
			(int) ( $options['skillLimit'] ?? 18 )
		);

		$available = [];
		$effective_state = [];
		$facts = [];
		foreach ( (array) ( $retrieval['skills'] ?? [] ) as $index => $skill ) {
			if ( ! is_array( $skill ) ) { continue; }
			$id = (string) ( $skill['id'] ?? '' );
			if ( '' === $id ) { continue; }
			$ref = 's' . str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT );
			$semantic_skill = $this->semantic_skill( $skill );
			$semantic_skill['evidenceRef'] = 'skill.' . $ref;
			$available[] = $semantic_skill;
			$devices = $this->effective->describe( $skill, $current );
			$effective_state[ $id ] = [
				'semanticId' => (string) ( $skill['semanticId'] ?? '' ),
				'property' => (string) ( $skill['role'] ?? '' ),
				'label' => (string) ( $skill['displayLabel'] ?? $skill['label'] ?? $id ),
				'devices' => $devices,
			];
			foreach ( $devices as $device => $state ) {
				if ( ! is_array( $state ) ) { continue; }
				$prefix = 'skill.' . $ref . '.' . $device;
				$facts[ $prefix . '.effective' ] = [ 'value' => $state['effectiveValue'] ?? null, 'description' => (string) ( $skill['displayLabel'] ?? $skill['label'] ?? $id ) . ' effective value on ' . $device ];
				$facts[ $prefix . '.source' ] = [ 'value' => (string) ( $state['source'] ?? 'unset' ), 'description' => 'Value source for ' . (string) ( $skill['displayLabel'] ?? $id ) . ' on ' . $device ];
				$facts[ $prefix . '.explicit' ] = [ 'value' => ! empty( $state['explicit'] ), 'description' => 'Whether the ' . $device . ' value is explicitly set' ];
			}
		}

		$siblings = [];
		foreach ( (array) ( $graph['siblings'] ?? [] ) as $sibling ) {
			if ( ! is_array( $sibling ) || (string) ( $sibling['id'] ?? '' ) === $element_id ) { continue; }
			$siblings[] = $this->summarize_element( $sibling );
		}
		$children = [];
		foreach ( (array) ( $selected['elements'] ?? [] ) as $child ) {
			if ( is_array( $child ) ) { $children[] = $this->summarize_element( $child ); }
		}
		$parent = ! empty( $options['includeNeighborContext'] ) && is_array( $graph['parent'] ?? null ) ? $this->summarize_element( $graph['parent'] ) : null;
		$relationships = $this->relationships( $selected, $parent, $siblings, $children );
		$render_observation = $this->normalize_render_observation( is_array( $options['renderObservation'] ?? null ) ? $options['renderObservation'] : [] );

		$facts['selected.type'] = [ 'value' => (string) ( $profile['element']['name'] ?? '' ), 'description' => 'Selected Elementor element type' ];
		$facts['selected.childCount'] = [ 'value' => (int) ( $profile['element']['childCount'] ?? count( $children ) ), 'description' => 'Number of direct children' ];
		$facts['relationship.siblingCount'] = [ 'value' => (int) ( $relationships['siblingCount'] ?? 0 ), 'description' => 'Number of sibling elements' ];
		$facts['relationship.childCount'] = [ 'value' => (int) ( $relationships['childCount'] ?? 0 ), 'description' => 'Number of direct child elements' ];
		if ( null !== ( $relationships['widthPercentSum'] ?? null ) ) { $facts['relationship.widthPercentSum'] = [ 'value' => $relationships['widthPercentSum'], 'description' => 'Sum of known percentage widths for selected element and siblings' ]; }
		$facts['relationship.overflowRisk'] = [ 'value' => ! empty( $relationships['overflowRisk'] ), 'description' => 'Derived horizontal overflow risk from known widths and gap' ];
		if ( isset( $relationships['parentLayout']['direction'] ) ) { $facts['relationship.parent.direction'] = [ 'value' => $relationships['parentLayout']['direction'], 'description' => 'Parent layout direction' ]; }
		if ( isset( $relationships['parentLayout']['gap'] ) ) { $facts['relationship.parent.gap'] = [ 'value' => $relationships['parentLayout']['gap'], 'description' => 'Parent layout gap' ]; }
		foreach ( [ 'width', 'height', 'display', 'flexDirection', 'flexWrap', 'justifyContent', 'alignItems', 'gap', 'fontSize', 'lineHeight', 'overflowX', 'overflowY' ] as $key ) {
			if ( array_key_exists( $key, (array) ( $render_observation['selected'] ?? [] ) ) ) {
				$facts['render.selected.' . $key] = [ 'value' => $render_observation['selected'][ $key ], 'description' => 'Browser render observation: ' . $key ];
			}
		}
		if ( isset( $render_observation['viewport']['width'] ) ) { $facts['render.viewport.width'] = [ 'value' => $render_observation['viewport']['width'], 'description' => 'Observed editor viewport width in CSS pixels' ]; }

		$global_references = is_array( $profile['globalReferences'] ?? null ) ? $profile['globalReferences'] : [];
		$context = [
			'schema' => self::SCHEMA,
			'generatedAt' => gmdate( 'c' ),
			'task' => [
				'command' => $task,
				'language' => function_exists( 'get_user_locale' ) ? (string) get_user_locale() : 'en_US',
				'goal' => 'diagnose first, cite machine-verifiable facts, then choose the smallest safe set of retrieved skills',
			],
			'selectedElement' => [
				'id' => (string) ( $profile['element']['id'] ?? $element_id ),
				'kind' => (string) ( $profile['element']['kind'] ?? '' ),
				'type' => (string) ( $profile['element']['name'] ?? '' ),
				'label' => (string) ( $profile['element']['title'] ?? $profile['element']['name'] ?? '' ),
				'isAtomic' => ! empty( $profile['element']['isAtomic'] ),
				'childCount' => (int) ( $profile['element']['childCount'] ?? count( $children ) ),
			],
			'expertCard' => $expert_card,
			'contextGraph' => [
				'parent' => $parent,
				'positionInParent' => isset( $graph['index'] ) ? (int) $graph['index'] : null,
				'siblings' => ! empty( $options['includeNeighborContext'] ) ? $siblings : [],
				'children' => $children,
				'relationships' => $relationships,
			],
			'renderObservation' => $render_observation,
			'effectiveState' => $effective_state,
			'availableSkills' => $available,
			'retrieval' => [
				'version' => $retrieval['version'] ?? 1,
				'totalExecutableCandidates' => $retrieval['totalExecutableCandidates'] ?? count( $available ),
				'returned' => count( $available ),
				'dropped' => $retrieval['dropped'] ?? 0,
				'coverage' => $retrieval['coverage'] ?? 0,
				'domainHints' => $retrieval['domainHints'] ?? [],
			],
			'facts' => $facts,
			'designSystem' => [
				'globalBindingCount' => count( $global_references ),
				'hasGlobalBindings' => ! empty( $global_references ),
				'globalBindingKeys' => array_slice( array_values( array_map( 'strval', array_keys( $global_references ) ) ), 0, 24 ),
				'dynamicCapableSkillCount' => count( array_filter( $available, static fn( array $skill ): bool => ! empty( $skill['dynamic'] ) ) ),
			],
			'constraints' => [
				'scope' => 'selected-element',
				'preserveIds' => true,
				'mayModifyChildren' => false,
				'mayModifySiblings' => false,
				'mayUseCustomCss' => false,
				'mustUseAvailableSkills' => true,
				'mustCiteFactIds' => true,
				'mustPreserveDynamicBindings' => true,
				'mustPreserveGlobalReferences' => true,
				'preferNativeResponsiveControls' => true,
				'unknownSkillPolicy' => 'reject',
			],
		];

		$context = $this->redactor->redact( $context );
		$window = max( 2048, (int) ( $options['contextWindow'] ?? 32768 ) );
		return $this->budgeter->budget( $context, $window );
	}

	private function document_elements( int $post_id ): array {
		if ( $post_id <= 0 ) { throw new \InvalidArgumentException( 'Elementor document ID is invalid.' ); }
		$manager = ElementorPlugin::instance()->documents;
		$main = $manager->get( $post_id );
		if ( ! $main ) { throw new \RuntimeException( 'Elementor document not found.' ); }
		$document = $manager->get_doc_or_auto_save( $post_id, get_current_user_id() );
		if ( ! $document ) { $document = $main; }
		return (array) $document->get_elements_data();
	}

	private function semantic_skill( array $skill ): array {
		$input = is_array( $skill['input'] ?? null ) ? $skill['input'] : [];
		if ( isset( $input['options'] ) && is_array( $input['options'] ) && count( $input['options'] ) > 30 ) {
			$input['options'] = array_slice( $input['options'], 0, 30, true );
			$input['optionsTruncated'] = true;
		}
		return [
			'skillId' => (string) ( $skill['id'] ?? '' ),
			'semanticId' => (string) ( $skill['semanticId'] ?? '' ),
			'semanticBase' => (string) ( $skill['semanticBase'] ?? '' ),
			'domain' => (string) ( $skill['semanticDomain'] ?? '' ),
			'targetPart' => (string) ( $skill['targetPart'] ?? '' ),
			'property' => (string) ( $skill['property'] ?? $skill['role'] ?? '' ),
			'state' => (string) ( $skill['state'] ?? 'normal' ),
			'label' => (string) ( $skill['displayLabel'] ?? $skill['label'] ?? '' ),
			'description' => (string) ( $skill['description'] ?? '' ),
			'purpose' => (string) ( $skill['purpose'] ?? '' ),
			'category' => (string) ( $skill['category'] ?? '' ),
			'mode' => (string) ( $skill['mode'] ?? 'direct' ),
			'risk' => (string) ( $skill['risk'] ?? 'safe' ),
			'devices' => array_values( array_map( 'strval', (array) ( $skill['devices'] ?? [ 'desktop' ] ) ) ),
			'responsive' => ! empty( $skill['responsive'] ),
			'dynamic' => ! empty( $skill['dynamic'] ),
			'hasConditions' => ! empty( $skill['conditions'] ),
			'input' => $input,
			'retrievalScore' => (int) ( $skill['retrievalScore'] ?? 0 ),
		];
	}

	private function summarize_element( array $element ): array {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$name = trim( (string) ( $element['widgetType'] ?? '' ) );
		if ( '' === $name ) { $name = trim( (string) ( $element['elType'] ?? '' ) ); }
		return [
			'id' => (string) ( $element['id'] ?? '' ),
			'type' => $name,
			'kind' => '' !== (string) ( $element['widgetType'] ?? '' ) ? 'widget' : 'element',
			'childCount' => (int) ( $element['childCount'] ?? count( (array) ( $element['elements'] ?? [] ) ) ),
			'contentHint' => $this->content_hint( $settings ),
			'layoutHints' => $this->layout_hints( $settings ),
		];
	}

	private function relationships( array $selected, ?array $parent, array $siblings, array $children ): array {
		$selected_hints = $this->layout_hints( is_array( $selected['settings'] ?? null ) ? $selected['settings'] : [] );
		$sum = 0.0; $known = 0;
		$selected_width = $this->percent_width( $selected_hints['width'] ?? null );
		if ( null !== $selected_width ) { $sum += $selected_width; $known++; }
		foreach ( $siblings as $sibling ) {
			$width = $this->percent_width( $sibling['layoutHints']['width'] ?? null );
			if ( null !== $width ) { $sum += $width; $known++; }
		}
		$parent_layout = is_array( $parent['layoutHints'] ?? null ) ? $parent['layoutHints'] : [];
		$gap = $this->numeric_size( $parent_layout['gap'] ?? null );
		$overflow = $known >= 2 && ( $sum > 100.01 || ( $sum >= 99.9 && null !== $gap && $gap > 0 ) );
		return [
			'selectedLayout' => $selected_hints,
			'parentLayout' => $parent_layout,
			'siblingCount' => count( $siblings ),
			'childCount' => count( $children ),
			'siblingTypes' => array_values( array_unique( array_filter( array_map( static fn( array $item ): string => (string) ( $item['type'] ?? '' ), $siblings ) ) ) ),
			'childTypes' => array_values( array_unique( array_filter( array_map( static fn( array $item ): string => (string) ( $item['type'] ?? '' ), $children ) ) ) ),
			'widthPercentKnownCount' => $known,
			'widthPercentSum' => $known >= 2 ? round( $sum, 2 ) : null,
			'overflowRisk' => $overflow,
		];
	}

	private function normalize_render_observation( array $input ): array {
		$out = [ 'viewport' => [], 'selected' => [], 'children' => [] ];
		foreach ( [ 'width', 'height', 'devicePixelRatio' ] as $key ) {
			if ( isset( $input['viewport'][ $key ] ) && is_numeric( $input['viewport'][ $key ] ) ) { $out['viewport'][ $key ] = round( (float) $input['viewport'][ $key ], 2 ); }
		}
		$numeric = [ 'width', 'height', 'top', 'left' ];
		$text = [ 'display', 'position', 'flexDirection', 'flexWrap', 'justifyContent', 'alignItems', 'gap', 'padding', 'margin', 'fontSize', 'lineHeight', 'overflowX', 'overflowY', 'color', 'backgroundColor' ];
		foreach ( $numeric as $key ) { if ( isset( $input['selected'][ $key ] ) && is_numeric( $input['selected'][ $key ] ) ) { $out['selected'][ $key ] = round( (float) $input['selected'][ $key ], 2 ); } }
		foreach ( $text as $key ) { if ( isset( $input['selected'][ $key ] ) && is_scalar( $input['selected'][ $key ] ) ) { $out['selected'][ $key ] = substr( sanitize_text_field( (string) $input['selected'][ $key ] ), 0, 120 ); } }
		foreach ( array_slice( (array) ( $input['children'] ?? [] ), 0, 12 ) as $child ) {
			if ( ! is_array( $child ) ) { continue; }
			$item = [ 'id' => preg_match( '/^[A-Za-z0-9_-]{1,64}$/', (string) ( $child['id'] ?? '' ) ) ? (string) $child['id'] : '' ];
			foreach ( [ 'width', 'height' ] as $key ) { if ( isset( $child[ $key ] ) && is_numeric( $child[ $key ] ) ) { $item[ $key ] = round( (float) $child[ $key ], 2 ); } }
			$out['children'][] = $item;
		}
		return $out;
	}

	private function percent_width( $value ): ?float {
		if ( is_array( $value ) ) {
			$unit = strtolower( (string) ( $value['unit'] ?? '' ) );
			$size = $value['size'] ?? $value['width'] ?? null;
			return '%' === $unit && is_numeric( $size ) ? (float) $size : null;
		}
		if ( is_string( $value ) && preg_match( '/^\s*(-?\d+(?:\.\d+)?)\s*%\s*$/', $value, $match ) ) { return (float) $match[1]; }
		return null;
	}

	private function numeric_size( $value ): ?float {
		if ( is_numeric( $value ) ) { return (float) $value; }
		if ( is_array( $value ) ) {
			foreach ( [ 'size', 'column', 'row' ] as $key ) { if ( isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ) { return (float) $value[ $key ]; } }
		}
		if ( is_string( $value ) && preg_match( '/(-?\d+(?:\.\d+)?)/', $value, $match ) ) { return (float) $match[1]; }
		return null;
	}

	private function content_hint( array $settings ): string {
		foreach ( [ 'title', 'text', 'editor', 'button_text', 'description', 'caption', 'placeholder' ] as $key ) {
			if ( ! isset( $settings[ $key ] ) || ! is_scalar( $settings[ $key ] ) ) { continue; }
			$text = trim( wp_strip_all_tags( (string) $settings[ $key ], true ) );
			if ( '' === $text ) { continue; }
			if ( function_exists( 'mb_substr' ) ) { return mb_substr( $text, 0, 180 ); }
			return substr( $text, 0, 180 );
		}
		return '';
	}

	private function layout_hints( array $settings ): array {
		$map = [
			'direction' => [ 'flex_direction' ],
			'wrap' => [ 'flex_wrap' ],
			'justify' => [ 'flex_justify_content', 'justify_content' ],
			'align' => [ 'flex_align_items', 'align_items' ],
			'gap' => [ 'flex_gap', 'gap' ],
			'width' => [ 'width', '_element_width', 'content_width' ],
			'minHeight' => [ 'min_height' ],
			'padding' => [ 'padding', '_padding' ],
			'margin' => [ 'margin', '_margin' ],
			'hideMobile' => [ 'hide_mobile' ],
			'hideTablet' => [ 'hide_tablet' ],
		];
		$out = [];
		foreach ( $map as $semantic => $keys ) {
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $settings ) && null !== $settings[ $key ] && '' !== $settings[ $key ] ) {
					$out[ $semantic ] = $settings[ $key ];
					break;
				}
			}
		}
		return $out;
	}
}
