<?php
namespace CrescoLayer\LocalAI;

use CrescoLayer\AI\ElementLocator;
use CrescoLayer\Skills\WidgetSkillRuntime;
use Elementor\Plugin as ElementorPlugin;

final class ContextCompiler {
	public const SCHEMA = 'cresco-layer-semantic-context/v1';

	private ElementLocator $locator;
	private EffectiveValueResolver $effective;
	private ContextRedactor $redactor;
	private ContextBudgeter $budgeter;

	public function __construct(
		private WidgetSkillRuntime $skills,
		?ElementLocator $locator = null,
		?EffectiveValueResolver $effective = null,
		?ContextRedactor $redactor = null,
		?ContextBudgeter $budgeter = null
	) {
		$this->locator = $locator ?? new ElementLocator();
		$this->effective = $effective ?? new EffectiveValueResolver();
		$this->redactor = $redactor ?? new ContextRedactor();
		$this->budgeter = $budgeter ?? new ContextBudgeter();
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
		$preferred_roles = array_fill_keys( array_map( 'strval', (array) ( $expert_card['preferredRoles'] ?? [] ) ), true );

		$available = [];
		$effective_state = [];
		foreach ( (array) ( $profile['skills'] ?? [] ) as $skill ) {
			if ( ! is_array( $skill ) || 'read-only' === (string) ( $skill['mode'] ?? '' ) ) { continue; }
			$id = (string) ( $skill['id'] ?? '' );
			if ( '' === $id ) { continue; }
			$semantic_skill = $this->semantic_skill( $skill );
			$semantic_skill['recommended'] = isset( $preferred_roles[ (string) ( $skill['role'] ?? '' ) ] );
			$semantic_skill['priority'] = $this->skill_priority( $semantic_skill, $task );
			$available[] = $semantic_skill;
			$effective_state[ $id ] = [
				'property' => (string) ( $skill['role'] ?? '' ),
				'label' => (string) ( $skill['label'] ?? $id ),
				'devices' => $this->effective->describe( $skill, $current ),
			];
		}
		usort( $available, static function ( array $a, array $b ): int {
			$left = (int) ( $a['priority'] ?? 0 );
			$right = (int) ( $b['priority'] ?? 0 );
			return $left === $right ? strcasecmp( (string) ( $a['label'] ?? '' ), (string) ( $b['label'] ?? '' ) ) : $right <=> $left;
		} );

		$siblings = [];
		foreach ( (array) ( $graph['siblings'] ?? [] ) as $sibling ) {
			if ( ! is_array( $sibling ) || (string) ( $sibling['id'] ?? '' ) === $element_id ) { continue; }
			$siblings[] = $this->summarize_element( $sibling );
		}
		$children = [];
		foreach ( (array) ( $selected['elements'] ?? [] ) as $child ) {
			if ( is_array( $child ) ) { $children[] = $this->summarize_element( $child ); }
		}

		$global_references = is_array( $profile['globalReferences'] ?? null ) ? $profile['globalReferences'] : [];
		$context = [
			'schema' => self::SCHEMA,
			'generatedAt' => gmdate( 'c' ),
			'task' => [
				'command' => $task,
				'language' => function_exists( 'get_user_locale' ) ? (string) get_user_locale() : 'en_US',
				'goal' => 'diagnose first, then choose the smallest safe set of available skills',
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
				'parent' => ! empty( $options['includeNeighborContext'] ) && is_array( $graph['parent'] ?? null ) ? $this->summarize_element( $graph['parent'] ) : null,
				'positionInParent' => isset( $graph['index'] ) ? (int) $graph['index'] : null,
				'siblings' => ! empty( $options['includeNeighborContext'] ) ? $siblings : [],
				'children' => $children,
			],
			'effectiveState' => $effective_state,
			'availableSkills' => $available,
			'designSystem' => [
				'globalBindingCount' => count( $global_references ),
				'hasGlobalBindings' => ! empty( $global_references ),
				'dynamicCapableSkillCount' => count( array_filter( $available, static fn( array $skill ): bool => ! empty( $skill['dynamic'] ) ) ),
			],
			'constraints' => [
				'scope' => 'selected-element',
				'preserveIds' => true,
				'mayModifyChildren' => false,
				'mayModifySiblings' => false,
				'mayUseCustomCss' => false,
				'mustUseAvailableSkills' => true,
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
			'property' => (string) ( $skill['role'] ?? '' ),
			'label' => (string) ( $skill['label'] ?? '' ),
			'description' => (string) ( $skill['description'] ?? '' ),
			'category' => (string) ( $skill['category'] ?? '' ),
			'mode' => (string) ( $skill['mode'] ?? 'direct' ),
			'risk' => (string) ( $skill['risk'] ?? 'safe' ),
			'devices' => array_values( array_map( 'strval', (array) ( $skill['devices'] ?? [ 'desktop' ] ) ) ),
			'responsive' => ! empty( $skill['responsive'] ),
			'dynamic' => ! empty( $skill['dynamic'] ),
			'hasConditions' => ! empty( $skill['conditions'] ),
			'input' => $input,
		];
	}

	private function skill_priority( array $skill, string $task ): int {
		$score = ! empty( $skill['recommended'] ) ? 100 : 0;
		if ( 'safe' === (string) ( $skill['risk'] ?? '' ) ) { $score += 8; }
		$task_text = strtolower( $task );
		$haystack = strtolower( implode( ' ', [ (string) ( $skill['property'] ?? '' ), (string) ( $skill['label'] ?? '' ), (string) ( $skill['category'] ?? '' ) ] ) );
		foreach ( preg_split( '/[^a-z0-9]+/i', $haystack ) ?: [] as $token ) {
			if ( strlen( $token ) >= 4 && str_contains( $task_text, $token ) ) { $score += 12; }
		}
		if ( ! empty( $skill['responsive'] ) && preg_match( '/mobile|tablet|responsive|dien thoai|may tinh bang/u', $task_text ) ) { $score += 18; }
		return $score;
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
