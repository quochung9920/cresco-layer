<?php
namespace CrescoLayer\AI;

use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;

/** Exposes the shared responsive/container-role contract to AI packages without leaking unrelated IDs. */
final class LayoutContextBuilder {
	public function __construct( private ?ContainerRolePolicy $roles = null ) {
		$this->roles ??= new ContainerRolePolicy();
	}

	/** @param string[] $editable_ids */
	public function build( array $document_elements, array $editable_ids ): array {
		$role_report = $this->roles->analyze( $document_elements );
		$editable = array_fill_keys( array_map( 'strval', $editable_ids ), true );
		$roles = [];
		foreach ( (array) ( $role_report['roles'] ?? [] ) as $id => $role ) {
			if ( isset( $editable[ (string) $id ] ) ) { $roles[ (string) $id ] = $role; }
		}
		return [
			'schema' => 'cresco-layout-context/v1',
			'responsiveFoundation' => ResponsiveLayoutPolicy::layout_contract(),
			'containerRoles' => [
				'schema' => ContainerRolePolicy::SCHEMA,
				'roles' => $roles,
			],
			'rules' => [
				'globalContainerPadding' => 'zero',
				'sectionShellOwnsHorizontalGutter' => true,
				'contentContainerOwnsVerticalRhythm' => true,
				'nestedStructuralPaddingDefault' => 'zero',
				'componentPaddingIsLocal' => true,
			],
		];
	}
}
