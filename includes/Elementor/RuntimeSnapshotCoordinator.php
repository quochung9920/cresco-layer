<?php
namespace CrescoLayer\Elementor;

final class RuntimeSnapshotCoordinator {
	public const SCHEMA = RuntimeSnapshot::SCHEMA;

	private RuntimeSnapshot $base;
	private RuntimeDiscovery $discovery;

	public function __construct( ?RuntimeSnapshot $base = null, ?RuntimeDiscovery $discovery = null ) {
		$this->base = $base ?? new RuntimeSnapshot();
		$this->discovery = $discovery ?? new RuntimeDiscovery();
	}

	public function index(): array {
		$index = $this->base->index();
		$index['runtimeDiscoveryVersion'] = 2;
		$index['coveragePolicy'] = 'section-aware-v2';
		$index['notes'][] = 'Dynamic Tags are normalized from Elementor get_tags() registry entries, including the registered instance stored under each tag info record.';
		$index['notes'][] = 'Elementor Core/Pro module inventories are resolved through get_modules_names() followed by get_modules(name); get_modules() is never called without its required module name.';
		$index['notes'][] = 'Conditional Pro capabilities are reported with dependency signals so licensed-but-inactive integrations are not confused with live runtime controls.';
		return $index;
	}

	public function section( string $section ): array {
		if ( 'dynamic-tags' === $section ) { return $this->discovery->dynamic_tags_snapshot(); }
		if ( 'runtime' === $section ) { return $this->discovery->runtime_snapshot(); }
		return $this->base->section( $section );
	}

	public function registryEntry( string $kind, string $name ): array {
		return $this->base->registryEntry( $kind, $name );
	}

	public function record( int $postId ): array {
		return $this->base->record( $postId );
	}
}
