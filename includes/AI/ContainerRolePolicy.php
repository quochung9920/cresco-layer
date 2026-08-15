<?php
namespace CrescoLayer\AI;

use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;

/**
 * Classifies Elementor Containers so Layer patches know where page gutters and vertical rhythm live.
 *
 * The classifier is deliberately conservative. Depth gives the structural default; explicit
 * `cresco-role-*` classes override it; visual component cues only promote nested containers to a
 * component role. The policy reports intent and warnings — it does not silently rewrite documents.
 */
final class ContainerRolePolicy {
	public const SCHEMA = 'cresco-container-role-policy/v1';
	public const ROLE_SECTION_SHELL = 'section-shell';
	public const ROLE_CONTENT = 'content';
	public const ROLE_NESTED = 'nested';
	public const ROLE_COMPONENT = 'component';

	private const ROLES = [
		self::ROLE_SECTION_SHELL,
		self::ROLE_CONTENT,
		self::ROLE_NESTED,
		self::ROLE_COMPONENT,
	];

	/** Return a serializable role map for an Elementor element tree. */
	public function analyze( array $elements ): array {
		$roles = [];
		$this->walk( $elements, 0, '', $roles );
		return [
			'schema' => self::SCHEMA,
			'layoutPolicy' => ResponsiveLayoutPolicy::ID,
			'pageGutter' => ResponsiveLayoutPolicy::page_gutters(),
			'globalContainerPadding' => 'zero',
			'roles' => $roles,
		];
	}

	/** @return array{role:string,explicit:bool,componentCandidate:bool} */
	public function classify( array $element, int $container_depth ): array {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$explicit = $this->explicit_role( $settings );
		$component = $this->is_component_candidate( $settings );

		if ( null !== $explicit ) {
			return [ 'role' => $explicit, 'explicit' => true, 'componentCandidate' => $component ];
		}
		if ( 0 === $container_depth ) {
			return [ 'role' => self::ROLE_SECTION_SHELL, 'explicit' => false, 'componentCandidate' => $component ];
		}
		if ( $component ) {
			return [ 'role' => self::ROLE_COMPONENT, 'explicit' => false, 'componentCandidate' => true ];
		}
		if ( 1 === $container_depth ) {
			return [ 'role' => self::ROLE_CONTENT, 'explicit' => false, 'componentCandidate' => false ];
		}
		return [ 'role' => self::ROLE_NESTED, 'explicit' => false, 'componentCandidate' => false ];
	}

	/** Rules patch generators/validators can consume without knowing CSS selectors. */
	public function padding_policy( string $role ): array {
		switch ( $role ) {
			case self::ROLE_SECTION_SHELL:
				return [
					'pageGutter' => true,
					'horizontal' => 'responsive-page-gutter',
					'vertical' => '0',
					'verticalSpacingOwner' => 'content',
				];
			case self::ROLE_CONTENT:
				return [
					'pageGutter' => false,
					'horizontal' => '0-by-default',
					'vertical' => 'section-rhythm-allowed',
					'verticalSpacingOwner' => 'self',
				];
			case self::ROLE_COMPONENT:
				return [
					'pageGutter' => false,
					'horizontal' => 'component-local',
					'vertical' => 'component-local',
					'verticalSpacingOwner' => 'self',
				];
			default:
				return [
					'pageGutter' => false,
					'horizontal' => '0-by-default',
					'vertical' => '0-by-default',
					'verticalSpacingOwner' => 'ancestor-content',
				];
		}
	}

	/** @return string|null */
	private function explicit_role( array $settings ): ?string {
		$classes = trim( (string) ( $settings['_css_classes'] ?? $settings['css_classes'] ?? '' ) );
		if ( '' === $classes ) { return null; }
		foreach ( self::ROLES as $role ) {
			if ( preg_match( '/(?:^|\s)cresco-role-' . preg_quote( $role, '/' ) . '(?:\s|$)/', $classes ) ) {
				return $role;
			}
		}
		return null;
	}

	private function is_component_candidate( array $settings ): bool {
		$classes = strtolower( (string) ( $settings['_css_classes'] ?? $settings['css_classes'] ?? '' ) );
		if ( preg_match( '/(?:^|\s)(?:cresco-)?(?:card|tile|panel|component)(?:\s|$)/', $classes ) ) { return true; }

		foreach ( [
			'background_background', '_background_background', 'border_border', '_border_border',
			'box_shadow_box_shadow', '_box_shadow_box_shadow', 'background_color',
		] as $key ) {
			if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] && null !== $settings[ $key ] ) { return true; }
		}
		foreach ( [ 'border_radius', '_border_radius' ] as $key ) {
			$value = $settings[ $key ] ?? null;
			if ( is_array( $value ) && $this->dimensions_non_zero( $value ) ) { return true; }
		}
		return 'article' === (string) ( $settings['html_tag'] ?? '' );
	}

	private function dimensions_non_zero( array $value ): bool {
		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			$raw = trim( (string) ( $value[ $side ] ?? '' ) );
			if ( '' !== $raw && '0' !== $raw && '0px' !== strtolower( $raw ) ) { return true; }
		}
		return false;
	}

	private function walk( array $elements, int $container_depth, string $parent_id, array &$roles ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$is_container = 'container' === (string) ( $element['elType'] ?? '' );
			$id = (string) ( $element['id'] ?? '' );
			$next_depth = $container_depth;

			if ( $is_container ) {
				$classification = $this->classify( $element, $container_depth );
				$role = $classification['role'];
				if ( '' !== $id ) {
					$roles[ $id ] = [
						'id' => $id,
						'role' => $role,
						'containerDepth' => $container_depth,
						'parentContainerId' => $parent_id,
						'explicit' => $classification['explicit'],
						'componentCandidate' => $classification['componentCandidate'],
						'paddingPolicy' => $this->padding_policy( $role ),
					];
				}
				$parent_id = $id;
				$next_depth = $container_depth + 1;
			}

			$this->walk( (array) ( $element['elements'] ?? [] ), $next_depth, $parent_id, $roles );
		}
	}
}
