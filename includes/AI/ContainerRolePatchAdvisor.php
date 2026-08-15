<?php
namespace CrescoLayer\AI;

/** Adds non-destructive layout warnings when a patch violates the container role/gutter contract. */
final class ContainerRolePatchAdvisor {
	public function __construct( private ?ContainerRolePolicy $roles = null ) {
		$this->roles ??= new ContainerRolePolicy();
	}

	/**
	 * @param array $elements Current Elementor document element tree.
	 * @param array $operations Validated cresco-layer-patch/v1 operations.
	 */
	public function analyze( array $elements, array $operations ): array {
		$role_report = $this->roles->analyze( $elements );
		$role_map = (array) ( $role_report['roles'] ?? [] );
		$warnings = [];

		foreach ( $operations as $index => $operation ) {
			if ( ! is_array( $operation ) ) { continue; }
			$type = (string) ( $operation['operation'] ?? '' );
			$element_id = (string) ( $operation['elementId'] ?? '' );
			$role = (string) ( $role_map[ $element_id ]['role'] ?? '' );
			if ( '' === $role ) { continue; }

			if ( 'update-setting' === $type && $this->is_padding_setting( (string) ( $operation['setting'] ?? '' ) ) ) {
				$this->inspect_padding( $index, $element_id, $role, $operation['value'] ?? null, $warnings );
			}
			if ( 'remove-setting' === $type && $this->is_padding_setting( (string) ( $operation['setting'] ?? '' ) ) && ContainerRolePolicy::ROLE_SECTION_SHELL === $role ) {
				$warnings[] = $this->warning( 'section_shell_gutter_removed', 'Removing section-shell padding can remove the page gutter because global Container Padding is intentionally zero.', $index, $element_id, $role );
			}
			if ( 'replace-settings' === $type ) {
				foreach ( (array) ( $operation['settings'] ?? [] ) as $setting => $value ) {
					if ( $this->is_padding_setting( (string) $setting ) ) { $this->inspect_padding( $index, $element_id, $role, $value, $warnings ); }
				}
			}
			if ( 'replace-element' === $type ) {
				foreach ( (array) ( $operation['element']['settings'] ?? [] ) as $setting => $value ) {
					if ( $this->is_padding_setting( (string) $setting ) ) { $this->inspect_padding( $index, $element_id, $role, $value, $warnings ); }
				}
			}
		}

		return [
			'schema' => 'cresco-container-role-patch-advice/v1',
			'warningCount' => count( $warnings ),
			'warnings' => $warnings,
			'containerRoles' => $role_report,
		];
	}

	private function inspect_padding( int $index, string $element_id, string $role, $value, array &$warnings ): void {
		if ( ! is_array( $value ) ) { return; }
		$top = $this->non_zero( $value['top'] ?? null );
		$bottom = $this->non_zero( $value['bottom'] ?? null );
		$left = $this->non_zero( $value['left'] ?? null );
		$right = $this->non_zero( $value['right'] ?? null );

		if ( ContainerRolePolicy::ROLE_SECTION_SHELL === $role && ( $top || $bottom ) ) {
			$warnings[] = $this->warning(
				'section_shell_vertical_padding',
				'Section-shell containers should own the horizontal page gutter only; put vertical section rhythm on the inner content container.',
				$index, $element_id, $role
			);
		}
		if ( in_array( $role, [ ContainerRolePolicy::ROLE_CONTENT, ContainerRolePolicy::ROLE_NESTED ], true ) && ( $left || $right ) ) {
			$warnings[] = $this->warning(
				'nested_page_gutter_candidate',
				'A structural nested container has horizontal padding. Avoid repeating the page gutter unless this is intentionally local component spacing.',
				$index, $element_id, $role
			);
		}
	}

	private function is_padding_setting( string $setting ): bool {
		return (bool) preg_match( '/^padding(?:_(?:mobile|tablet|laptop|widescreen|mobile_extra|tablet_extra))?$/', $setting );
	}

	private function non_zero( $value ): bool {
		if ( null === $value ) { return false; }
		$value = strtolower( trim( (string) $value ) );
		return ! in_array( $value, [ '', '0', '0px', '0rem', '0em', '0%' ], true );
	}

	private function warning( string $code, string $message, int $index, string $element_id, string $role ): array {
		return [ 'code' => $code, 'message' => $message, 'operationIndex' => $index, 'elementId' => $element_id, 'role' => $role ];
	}
}
