<?php
namespace CrescoLayer\AI;

use Elementor\Plugin as ElementorPlugin;

/**
 * Lightweight runtime metadata used by external exports.
 *
 * The normal RuntimeDiscovery snapshot intentionally captures deep runtime state. External export
 * only needs enough metadata to describe what exists, so this class avoids calling Dynamic Tag
 * get_controls()/get_editor_config() and avoids instantiating every Elementor module.
 */
final class ExportRuntimeCatalog {
	private const MAX_DYNAMIC_TAGS = 250;

	public function dynamic_tags(): array {
		$errors = [];
		$tags = [];
		$groups = [];
		$manager_class = '';
		$truncated = false;

		try {
			$manager = ElementorPlugin::instance()->dynamic_tags ?? null;
			if ( ! is_object( $manager ) || ! $this->is_public_method( $manager, 'get_tags' ) ) {
				throw new \RuntimeException( 'Elementor Dynamic Tags manager is unavailable.' );
			}
			$manager_class = get_class( $manager );
			$registered = (array) $manager->get_tags();
			$count = 0;
			foreach ( $registered as $fallback_name => $tag_info ) {
				if ( $count >= self::MAX_DYNAMIC_TAGS ) { $truncated = true; break; }
				$instance = null;
				$class_name = '';
				if ( is_array( $tag_info ) ) {
					$instance = isset( $tag_info['instance'] ) && is_object( $tag_info['instance'] ) ? $tag_info['instance'] : null;
					$class_name = is_string( $tag_info['class'] ?? null ) ? (string) $tag_info['class'] : '';
				} elseif ( is_object( $tag_info ) ) {
					$instance = $tag_info;
					$class_name = get_class( $tag_info );
				}
				if ( ! $instance ) {
					$errors[] = [ 'stage' => 'dynamic-tag-instance:' . (string) $fallback_name, 'message' => 'Registered Dynamic Tag did not expose an instance.' ];
					continue;
				}

				$name = $this->scalar_text( $this->safe_call( $instance, 'get_name', (string) $fallback_name, 'dynamic-tag-name', $errors ) );
				if ( '' === $name ) { $name = (string) $fallback_name; }
				if ( '' === $class_name ) { $class_name = get_class( $instance ); }
				$categories = (array) $this->safe_call( $instance, 'get_categories', [], 'dynamic-tag-categories:' . $name, $errors );
				$tags[ $name ] = [
					'name' => $name,
					'title' => wp_strip_all_tags( $this->scalar_text( $this->safe_call( $instance, 'get_title', $name, 'dynamic-tag-title:' . $name, $errors ) ) ),
					'className' => $class_name,
					'group' => $this->scalar_text( $this->safe_call( $instance, 'get_group', '', 'dynamic-tag-group:' . $name, $errors ) ),
					'categories' => array_values( array_filter( array_map( [ $this, 'scalar_text' ], $categories ), static fn( string $value ): bool => '' !== $value ) ),
				];
				$count++;
			}

			if ( $this->is_public_method( $manager, 'get_config' ) ) {
				$config = $manager->get_config();
				if ( is_array( $config ) && is_array( $config['groups'] ?? null ) ) { $groups = $config['groups']; }
			}
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'dynamic-tags', $error );
		}

		return [
			'strategy' => 'metadata-only-no-controls',
			'managerClass' => $manager_class,
			'tags' => $tags,
			'groups' => $groups,
			'count' => count( $tags ),
			'truncated' => $truncated,
			'coverage' => [ 'status' => $errors ? 'partial' : 'complete', 'errors' => count( $errors ) ],
			'scanErrors' => $errors,
		];
	}

	public function module_summary(): array {
		$errors = [];
		$core = [ 'managerClass' => '', 'names' => [], 'count' => 0 ];
		$pro = [ 'managerClass' => '', 'names' => [], 'count' => 0 ];

		try {
			$manager = ElementorPlugin::instance()->modules_manager ?? null;
			if ( is_object( $manager ) ) { $core = $this->module_manager_summary( $manager, 'elementor-modules', $errors ); }
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( 'elementor-modules', $error );
		}

		if ( class_exists( '\\ElementorPro\\Plugin' ) ) {
			try {
				$pro_class = '\\ElementorPro\\Plugin';
				$plugin = $pro_class::instance();
				$manager = null;
				foreach ( [ 'modules_manager', 'modulesManager' ] as $property ) {
					if ( isset( $plugin->{$property} ) && is_object( $plugin->{$property} ) ) { $manager = $plugin->{$property}; break; }
				}
				if ( ! $manager && $this->is_public_method( $plugin, 'get_modules_manager' ) ) { $manager = $plugin->get_modules_manager(); }
				if ( is_object( $manager ) ) { $pro = $this->module_manager_summary( $manager, 'elementor-pro-modules', $errors ); }
				else { $errors[] = [ 'stage' => 'elementor-pro-modules-manager', 'message' => 'Elementor Pro modules manager is unavailable.' ]; }
			} catch ( \Throwable $error ) {
				$errors[] = $this->error( 'elementor-pro-modules', $error );
			}
		}

		return [
			'strategy' => 'manager-name-summary-no-module-instantiation',
			'core' => $core,
			'pro' => $pro,
			'coverage' => [ 'status' => $errors ? 'partial' : 'complete', 'errors' => count( $errors ) ],
			'scanErrors' => $errors,
		];
	}

	private function module_manager_summary( object $manager, string $stage, array &$errors ): array {
		$names = [];
		if ( ! $this->is_public_method( $manager, 'get_modules_names' ) ) {
			$errors[] = [ 'stage' => $stage, 'message' => 'Modules manager does not expose get_modules_names().' ];
			return [ 'managerClass' => get_class( $manager ), 'names' => [], 'count' => 0 ];
		}
		try { $names = array_values( array_unique( array_filter( array_map( 'strval', (array) $manager->get_modules_names() ) ) ) ); }
		catch ( \Throwable $error ) { $errors[] = $this->error( $stage . ':names', $error ); }
		return [ 'managerClass' => get_class( $manager ), 'names' => $names, 'count' => count( $names ) ];
	}

	private function safe_call( object $object, string $method, $default, string $stage, array &$errors ) {
		if ( ! $this->is_public_method( $object, $method ) ) { return $default; }
		try {
			$reflection = new \ReflectionMethod( $object, $method );
			if ( 0 !== $reflection->getNumberOfRequiredParameters() ) { return $default; }
			return $object->{$method}();
		} catch ( \Throwable $error ) {
			$errors[] = $this->error( $stage, $error );
			return $default;
		}
	}

	private function is_public_method( object $object, string $method ): bool {
		if ( ! method_exists( $object, $method ) ) { return false; }
		try { return ( new \ReflectionMethod( $object, $method ) )->isPublic(); }
		catch ( \ReflectionException $error ) { return false; }
	}

	private function scalar_text( $value ): string {
		if ( is_scalar( $value ) ) { return (string) $value; }
		if ( is_array( $value ) ) {
			$parts = [];
			foreach ( $value as $item ) { if ( is_scalar( $item ) ) { $parts[] = (string) $item; } }
			return implode( ', ', $parts );
		}
		return '';
	}

	private function error( string $stage, \Throwable $error ): array {
		return [ 'stage' => $stage, 'message' => wp_strip_all_tags( $error->getMessage() ) ];
	}
}
