<?php
namespace CrescoLayer\AI;

use Elementor\Plugin as ElementorPlugin;

final class PatchCapabilityValidator {
	private ControlRegistry $registry;
	private $entryResolver;
	private array $entryCache = [];

	public function __construct( ?ControlRegistry $registry = null, ?callable $entry_resolver = null ) {
		$this->registry = $registry ?? new ControlRegistry();
		$this->entryResolver = $entry_resolver ?? static function ( string $kind, string $name ): array {
			return ( new CapabilityScanner() )->catalog_entry( $kind, $name, false );
		};
	}

	public function validate_for_post( array $patch, int $post_id ): array {
		$manager = ElementorPlugin::instance()->documents;
		$document = $manager->get_doc_or_auto_save( $post_id, get_current_user_id() );
		if ( ! $document ) { $document = $manager->get( $post_id ); }
		if ( ! $document ) { throw new \RuntimeException( 'Elementor document is unavailable for runtime capability validation.' ); }
		return $this->validate( $patch, (array) $document->get_elements_data() );
	}

	public function validate( array $patch, array $elements ): array {
		$index = [];
		$this->index_elements( $elements, $index );
		$report = [
			'schema' => 'cresco-layer-patch-validation/v2',
			'status' => 'trusted',
			'checkedSettings' => 0,
			'checkedElements' => 0,
			'preservedUnknownSettings' => 0,
			'rules' => [ 'registered-control', 'responsive-capability', 'unit', 'option', 'range', 'global-reference' ],
		];

		foreach ( (array) ( $patch['operations'] ?? [] ) as $operation ) {
			$type = (string) ( $operation['operation'] ?? '' );
			if ( in_array( $type, [ 'update-setting', 'remove-setting' ], true ) ) {
				$id = (string) ( $operation['elementId'] ?? '' );
				$current = $this->require_element( $index, $id );
				$value = 'update-setting' === $type ? ( $operation['value'] ?? null ) : null;
				$this->assert_setting( $current, (string) ( $operation['setting'] ?? '' ), $value, 'update-setting' === $type, $report );
				continue;
			}
			if ( 'replace-settings' === $type ) {
				$id = (string) ( $operation['elementId'] ?? '' );
				$current = $this->require_element( $index, $id );
				$this->validate_settings( $current, (array) ( $operation['settings'] ?? [] ), (array) ( $current['settings'] ?? [] ), $report );
				continue;
			}
			if ( 'replace-element' === $type ) {
				$id = (string) ( $operation['elementId'] ?? '' );
				$current = $this->require_element( $index, $id );
				$this->validate_element( (array) ( $operation['element'] ?? [] ), $current, $report );
				continue;
			}
			if ( 'insert-element' === $type ) {
				$this->validate_element( (array) ( $operation['element'] ?? [] ), null, $report );
				continue;
			}
			if ( 'replace-document' === $type ) {
				foreach ( (array) ( $operation['content'] ?? [] ) as $element ) {
					if ( ! is_array( $element ) ) { continue; }
					$current = isset( $index[ (string) ( $element['id'] ?? '' ) ] ) ? $index[ (string) $element['id'] ] : null;
					$this->validate_element( $element, $current, $report );
				}
			}
		}
		return $report;
	}

	private function validate_element( array $element, ?array $current, array &$report ): void {
		$report['checkedElements']++;
		$this->entry_for_element( $element );
		$this->validate_settings( $element, (array) ( $element['settings'] ?? [] ), (array) ( $current['settings'] ?? [] ), $report );

		$current_children = [];
		foreach ( (array) ( $current['elements'] ?? [] ) as $child ) {
			if ( is_array( $child ) && '' !== (string) ( $child['id'] ?? '' ) ) { $current_children[ (string) $child['id'] ] = $child; }
		}
		foreach ( (array) ( $element['elements'] ?? [] ) as $child ) {
			if ( ! is_array( $child ) ) { continue; }
			$id = (string) ( $child['id'] ?? '' );
			$this->validate_element( $child, $current_children[ $id ] ?? null, $report );
		}
	}

	private function validate_settings( array $element, array $settings, array $current_settings, array &$report ): void {
		foreach ( $settings as $setting => $value ) {
			$setting = (string) $setting;
			if ( array_key_exists( $setting, $current_settings ) && $current_settings[ $setting ] === $value ) {
				$entry = $this->entry_for_element( $element );
				if ( '__globals__' !== $setting && null === $this->registry->resolve( $entry, $setting ) ) {
					$report['preservedUnknownSettings']++;
					continue;
				}
			}
			$this->assert_setting( $element, $setting, $value, true, $report );
		}
	}

	private function assert_setting( array $element, string $setting, $value, bool $has_value, array &$report ): void {
		$entry = $this->entry_for_element( $element );
		$id = (string) ( $element['id'] ?? '' );
		$type = (string) ( $element['widgetType'] ?? $element['elType'] ?? '' );

		if ( '__globals__' === $setting ) {
			if ( $has_value ) { $this->assert_global_references( $entry, $value, $id ); }
			$report['checkedSettings']++;
			return;
		}

		$contract = $this->registry->resolve( $entry, $setting );
		if ( null === $contract ) {
			throw new \InvalidArgumentException( sprintf( 'Runtime capability rejected unsupported or non-responsive setting "%s" on Elementor element %s (%s).', $setting, $id, $type ) );
		}
		if ( $has_value ) { $this->assert_value( $contract, $value, $id ); }
		$report['checkedSettings']++;
	}

	private function assert_global_references( array $entry, $value, string $id ): void {
		if ( ! is_array( $value ) ) { throw new \InvalidArgumentException( 'Elementor __globals__ must be an object on element ' . $id . '.' ); }
		foreach ( $value as $setting => $reference ) {
			if ( null === $this->registry->resolve( $entry, (string) $setting ) ) {
				throw new \InvalidArgumentException( sprintf( 'Global style reference targets unsupported setting "%s" on element %s.', (string) $setting, $id ) );
			}
			if ( ! is_string( $reference ) || strlen( $reference ) > 512 ) {
				throw new \InvalidArgumentException( 'Elementor global style references must be short strings.' );
			}
		}
	}

	private function assert_value( array $contract, $value, string $element_id ): void {
		$options = (array) ( $contract['options'] ?? [] );
		$type = (string) ( $contract['type'] ?? '' );
		if ( $options && in_array( $type, [ 'select', 'select2', 'choose', 'radio' ], true ) ) {
			$values = is_array( $value ) ? array_values( $value ) : [ $value ];
			foreach ( $values as $candidate ) {
				if ( is_scalar( $candidate ) && ! in_array( (string) $candidate, $options, true ) ) {
					throw new \InvalidArgumentException( sprintf( 'Value "%s" is not an allowed option for setting %s on element %s.', (string) $candidate, (string) $contract['setting'], $element_id ) );
				}
			}
		}

		if ( ! is_array( $value ) ) { return; }
		$unit = isset( $value['unit'] ) ? (string) $value['unit'] : '';
		$units = (array) ( $contract['units'] ?? [] );
		if ( '' !== $unit && $units && ! in_array( $unit, $units, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unit "%s" is not supported for setting %s on element %s.', $unit, (string) $contract['setting'], $element_id ) );
		}
		if ( ! array_key_exists( 'size', $value ) || ! is_numeric( $value['size'] ) ) { return; }
		$size = (float) $value['size'];
		$range = (array) ( $contract['range'] ?? [] );
		$limits = [];
		if ( '' !== $unit && isset( $range[ $unit ] ) && is_array( $range[ $unit ] ) ) { $limits = $range[ $unit ]; }
		elseif ( isset( $range['min'] ) || isset( $range['max'] ) ) { $limits = $range; }
		$min = $limits['min'] ?? ( $contract['min'] ?? null );
		$max = $limits['max'] ?? ( $contract['max'] ?? null );
		if ( is_numeric( $min ) && $size < (float) $min ) {
			throw new \InvalidArgumentException( sprintf( 'Value for setting %s is below its runtime minimum on element %s.', (string) $contract['setting'], $element_id ) );
		}
		if ( is_numeric( $max ) && $size > (float) $max ) {
			throw new \InvalidArgumentException( sprintf( 'Value for setting %s exceeds its runtime maximum on element %s.', (string) $contract['setting'], $element_id ) );
		}
	}

	private function entry_for_element( array $element ): array {
		$widget = (string) ( $element['widgetType'] ?? '' );
		$el_type = (string) ( $element['elType'] ?? '' );
		$kind = '' !== $widget ? 'widget' : 'element';
		$name = '' !== $widget ? $widget : $el_type;
		if ( '' === $name || ( 'element' === $kind && 'widget' === $name ) ) {
			throw new \InvalidArgumentException( 'Elementor element is missing a runtime capability type.' );
		}
		$key = $kind . ':' . $name;
		if ( ! isset( $this->entryCache[ $key ] ) ) {
			$resolver = $this->entryResolver;
			$entry = $resolver( $kind, $name );
			if ( ! is_array( $entry ) || empty( $entry['detailLoaded'] ) ) {
				throw new \InvalidArgumentException( 'Detailed runtime capability is unavailable for Elementor ' . $kind . ' ' . $name . '.' );
			}
			$this->entryCache[ $key ] = $entry;
		}
		return $this->entryCache[ $key ];
	}

	private function require_element( array $index, string $id ): array {
		if ( ! isset( $index[ $id ] ) ) { throw new \InvalidArgumentException( 'Runtime capability validation could not resolve Elementor element ' . $id . '.' ); }
		return $index[ $id ];
	}

	private function index_elements( array $elements, array &$index ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$id = (string) ( $element['id'] ?? '' );
			if ( '' !== $id ) { $index[ $id ] = $element; }
			$this->index_elements( (array) ( $element['elements'] ?? [] ), $index );
		}
	}
}
