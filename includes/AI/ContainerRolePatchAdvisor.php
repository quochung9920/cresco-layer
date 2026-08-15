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
			if ( 'remove-setting' === $type && $this->is_padding_setting( (string) ( $operation['setting'] ?? '' ) ) && in_array( $role, [ ContainerRolePolicy::ROLE_CONTENT, ContainerRolePolicy::ROLE_NESTED ], true ) ) {
				$warnings[] = $this->warning(
					'nested_global_gutter_inherited',
					'Removing a structural nested padding override can make the container inherit the global responsive gutter again. Keep horizontal padding explicitly zero when this container must not repeat the page gutter.',
					$index, $element_id, $role
				);
			}
			if ( 'replace-settings' === $type ) {
				$replacement = (array) ( $operation['settings'] ?? [] );
				foreach ( $replacement as $setting => $value ) {
					if ( $this->is_padding_setting( (string) $setting ) ) { $this->inspect_padding( $index, $element_id, $role, $value, $warnings ); }
				}
				$this->inspect_missing_reset( $index, $element_id, $role, $replacement, $warnings );
			}
			if ( 'replace-element' === $type ) {
				$replacement = (array) ( $operation['element']['settings'] ?? [] );
				foreach ( $replacement as $setting => $value ) {
					if ( $this->is_padding_setting( (string) $setting ) ) { $this->inspect_padding( $index, $element_id, $role, $value, $warnings ); }
				}
				$this->inspect_missing_reset( $index, $element_id, $role, $replacement, $warnings );
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
				'Section-shell containers should inherit the global horizontal page gutter only; put vertical section rhythm on the inner content container.',
				$index, $element_id, $role
			);
		}
		if ( in_array( $role, [ ContainerRolePolicy::ROLE_CONTENT, ContainerRolePolicy::ROLE_NESTED ], true ) && ( $left || $right ) ) {
			$warnings[] = $this->warning(
				'nested_page_gutter_candidate',
				'A structural nested container has horizontal padding. Reset horizontal padding to zero unless this is intentionally local component spacing.',
				$index, $element_id, $role
			);
		}
	}

	private function inspect_missing_reset( int $index, string $element_id, string $role, array $settings, array &$warnings ): void {
		if ( ! in_array( $role, [ ContainerRolePolicy::ROLE_CONTENT, ContainerRolePolicy::ROLE_NESTED ], true ) ) { return; }
		foreach ( array_keys( $settings ) as $setting ) {
			if ( $this->is_padding_setting( (string) $setting ) ) { return; }
		}
		$warnings[] = $this->warning(
			'nested_padding_reset_missing',
			'Replacing all settings on a structural nested container without an explicit padding reset can expose the global responsive gutter. Preserve or write a zero horizontal padding override.',
			$index, $element_id, $role
		);
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
