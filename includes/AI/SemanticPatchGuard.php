<?php
namespace CrescoLayer\AI;

use Elementor\Plugin as ElementorPlugin;

final class SemanticPatchGuard {
	private const RESPONSIVE_SUFFIXES = [ 'tablet', 'mobile', 'widescreen', 'laptop', 'tablet_extra', 'mobile_extra' ];
	private const MAX_DISPLAY_LENGTH = 180;
	private const SYNTHETIC_LAYOUT_VARIABLES = [
		'--padding-top', '--padding-right', '--padding-bottom', '--padding-left',
		'--margin-top', '--margin-right', '--margin-bottom', '--margin-left',
		'--min-height', '--max-height', '--content-width', '--max-width', '--gap-row', '--gap-column',
		'--border-radius', '--overflow',
	];
	private const CSS_NATIVE_HINTS = [
		'padding' => [ 'padding' ],
		'margin' => [ 'margin' ],
		'min-height' => [ 'min_height' ],
		'max-height' => [ 'max_height', 'height' ],
		'width' => [ 'width', 'content_width' ],
		'height' => [ 'height', 'min_height' ],
		'gap' => [ 'gap' ],
		'overflow' => [ 'overflow' ],
		'background' => [ 'background' ],
		'background-color' => [ 'background', 'background_color' ],
		'border' => [ 'border' ],
		'border-radius' => [ 'border_radius' ],
		'color' => [ 'color', 'text_color', 'title_color' ],
		'font-size' => [ 'typography', 'font_size' ],
		'font-family' => [ 'typography', 'font_family' ],
		'font-weight' => [ 'typography', 'font_weight' ],
		'line-height' => [ 'typography', 'line_height' ],
		'letter-spacing' => [ 'typography', 'letter_spacing' ],
	];

	private ElementLocator $locator;
	private ?CapabilityScanner $scanner;

	public function __construct( ?ElementLocator $locator = null, ?CapabilityScanner $scanner = null ) {
		$this->locator = $locator ?? new ElementLocator();
		$this->scanner = $scanner;
	}

	public function analyze( int $post_id, array $patch ): array {
		[ $elements, $page_settings ] = $this->load_working_data( $post_id );
		$scanner = $this->scanner ??= new CapabilityScanner();
		return $this->analyze_data( $patch, $elements, $page_settings, $scanner->catalog() );
	}

	public function analyze_data( array $patch, array $elements, array $page_settings, array $catalog ): array {
		$issues = [];
		$items = [];
		$shadow_elements = $elements;
		$shadow_page_settings = $page_settings;
		$counts = [
			'nativeControlOperations' => 0,
			'customCssOperations' => 0,
			'unknownPersistedOperations' => 0,
			'noOpOperations' => 0,
			'structuralOperations' => 0,
		];

		foreach ( (array) ( $patch['operations'] ?? [] ) as $index => $operation ) {
			if ( ! is_array( $operation ) ) { continue; }
			$type = (string) ( $operation['operation'] ?? '' );
			$item = [
				'index' => $index,
				'operation' => $type,
				'elementId' => (string) ( $operation['elementId'] ?? '' ),
				'setting' => (string) ( $operation['setting'] ?? '' ),
				'classification' => 'structural',
				'effective' => true,
				'before' => '',
				'after' => '',
				'issueCodes' => [],
			];

			switch ( $type ) {
				case 'update-setting':
					$this->analyze_setting_update( $operation, $index, $shadow_elements, $catalog, $issues, $item, $counts );
					$this->shadow_update_setting( $shadow_elements, (string) $operation['elementId'], (string) $operation['setting'], $operation['value'] ?? null );
					break;

				case 'remove-setting':
					$this->analyze_setting_remove( $operation, $index, $shadow_elements, $catalog, $issues, $item, $counts );
					$this->shadow_remove_setting( $shadow_elements, (string) $operation['elementId'], (string) $operation['setting'] );
					break;

				case 'replace-settings':
					$this->analyze_replace_settings( $operation, $index, $shadow_elements, $catalog, $issues, $item, $counts );
					$this->shadow_replace_settings( $shadow_elements, (string) $operation['elementId'], (array) ( $operation['settings'] ?? [] ) );
					break;

				case 'replace-element':
					$this->analyze_replace_element( $operation, $index, $shadow_elements, $catalog, $issues, $item, $counts );
					$counts['structuralOperations']++;
					break;

				case 'insert-element':
					$item['classification'] = 'structural';
					$counts['structuralOperations']++;
					if ( isset( $operation['element'] ) && is_array( $operation['element'] ) ) {
						$this->analyze_new_element_tree( $operation['element'], $index, $catalog, $issues, $item );
					}
					break;

				case 'remove-element':
				case 'move-element':
					$item['classification'] = 'structural';
					$counts['structuralOperations']++;
					break;

				case 'update-page-setting':
					$item['classification'] = 'page-setting';
					$key = (string) ( $operation['setting'] ?? '' );
					$exists = array_key_exists( $key, $shadow_page_settings );
					$before = $exists ? $shadow_page_settings[ $key ] : null;
					$after = $operation['value'] ?? null;
					$item['before'] = $exists ? $this->display_value( $before ) : '(unset)';
					$item['after'] = $this->display_value( $after );
					if ( $exists && $this->same_value( $before, $after ) ) {
						$this->mark_noop( $item, $counts, $issues, $index, '', $key, 'Page setting already has the requested value.' );
					}
					$shadow_page_settings[ $key ] = $after;
					break;

				case 'remove-page-setting':
					$item['classification'] = 'page-setting';
					$key = (string) ( $operation['setting'] ?? '' );
					$exists = array_key_exists( $key, $shadow_page_settings );
					$item['before'] = $exists ? $this->display_value( $shadow_page_settings[ $key ] ) : '(unset)';
					$item['after'] = '(removed)';
					if ( ! $exists ) {
						$this->mark_noop( $item, $counts, $issues, $index, '', $key, 'Page setting is already absent.' );
					}
					unset( $shadow_page_settings[ $key ] );
					break;

				case 'replace-document':
					$item['classification'] = 'document-replacement';
					$counts['structuralOperations']++;
					break;
			}

			$items[] = $item;
		}

		$errors = array_values( array_filter( $issues, static fn( array $issue ): bool => 'error' === $issue['severity'] ) );
		$warnings = array_values( array_filter( $issues, static fn( array $issue ): bool => 'warning' === $issue['severity'] ) );
		$total = count( (array) ( $patch['operations'] ?? [] ) );
		$effective = max( 0, $total - $counts['noOpOperations'] );

		return [
			'blocking' => ! empty( $errors ),
			'totalOperations' => $total,
			'effectiveOperations' => $effective,
			'nativeControlOperations' => $counts['nativeControlOperations'],
			'customCssOperations' => $counts['customCssOperations'],
			'unknownPersistedOperations' => $counts['unknownPersistedOperations'],
			'noOpOperations' => $counts['noOpOperations'],
			'structuralOperations' => $counts['structuralOperations'],
			'errors' => $errors,
			'warnings' => $warnings,
			'items' => $items,
		];
	}

	public function assert_safe( array $analysis ): void {
		if ( empty( $analysis['blocking'] ) ) { return; }
		$messages = [];
		foreach ( array_slice( (array) ( $analysis['errors'] ?? [] ), 0, 3 ) as $issue ) {
			$messages[] = (string) ( $issue['message'] ?? 'Unsafe Elementor patch operation.' );
		}
		throw new \InvalidArgumentException( 'Semantic patch validation blocked this patch: ' . implode( ' ', $messages ) );
	}

	public function verify( int $post_id, array $patch ): array {
		[ $elements, $page_settings ] = $this->load_working_data( $post_id );
		return $this->verify_data( $patch, $elements, $page_settings );
	}

	public function verify_data( array $patch, array $elements, array $page_settings ): array {
		$items = [];
		$passed = 0;
		foreach ( (array) ( $patch['operations'] ?? [] ) as $index => $operation ) {
			if ( ! is_array( $operation ) ) { continue; }
			$type = (string) ( $operation['operation'] ?? '' );
			$ok = false;
			$detail = '';
			$element_id = (string) ( $operation['elementId'] ?? '' );
			$setting = (string) ( $operation['setting'] ?? '' );

			switch ( $type ) {
				case 'update-setting':
					$element = $this->locator->find( $elements, $element_id );
					$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
					$ok = array_key_exists( $setting, $settings ) && $this->same_value( $settings[ $setting ], $operation['value'] ?? null );
					$detail = $ok ? 'Saved setting matches the reviewed patch.' : 'Saved setting does not match the reviewed value.';
					break;

				case 'remove-setting':
					$element = $this->locator->find( $elements, $element_id );
					$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
					$ok = null !== $element && ! array_key_exists( $setting, $settings );
					$detail = $ok ? 'Setting is absent after save.' : 'Setting is still present after save.';
					break;

				case 'replace-settings':
					$element = $this->locator->find( $elements, $element_id );
					$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
					$ok = null !== $element && $this->same_value( $settings, (array) ( $operation['settings'] ?? [] ) );
					$detail = $ok ? 'Element settings match the reviewed replacement.' : 'Elementor normalized or rejected part of the replacement settings.';
					break;

				case 'replace-element':
					$element = $this->locator->find( $elements, $element_id );
					$replacement = (array) ( $operation['element'] ?? [] );
					$ok = null !== $element;
					foreach ( [ 'elType', 'widgetType', 'settings' ] as $key ) {
						if ( $ok && array_key_exists( $key, $replacement ) ) { $ok = $this->same_value( $element[ $key ] ?? null, $replacement[ $key ] ); }
					}
					$detail = $ok ? 'Replacement element fields were persisted.' : 'Replacement element differs from the reviewed patch after save.';
					break;

				case 'insert-element':
					$new_id = (string) ( $operation['element']['id'] ?? '' );
					$ok = '' !== $new_id && null !== $this->locator->find( $elements, $new_id );
					$detail = $ok ? 'Inserted element exists after save.' : 'Inserted element was not found after save.';
					break;

				case 'remove-element':
					$ok = null === $this->locator->find( $elements, $element_id );
					$detail = $ok ? 'Removed element is absent after save.' : 'Removed element still exists after save.';
					break;

				case 'move-element':
					$location = $this->find_location( $elements, $element_id );
					$expected_parent = (string) ( $operation['parentId'] ?? '' );
					$expected_position = max( 0, (int) ( $operation['position'] ?? 0 ) );
					$ok = null !== $location && $location['parentId'] === $expected_parent && $location['index'] === $expected_position;
					$detail = $ok ? 'Element is at the reviewed parent and position.' : 'Element location differs from the reviewed move.';
					break;

				case 'update-page-setting':
					$ok = array_key_exists( $setting, $page_settings ) && $this->same_value( $page_settings[ $setting ], $operation['value'] ?? null );
					$detail = $ok ? 'Page setting matches the reviewed patch.' : 'Page setting does not match after save.';
					break;

				case 'remove-page-setting':
					$ok = ! array_key_exists( $setting, $page_settings );
					$detail = $ok ? 'Page setting is absent after save.' : 'Page setting is still present after save.';
					break;

				case 'replace-document':
					$expected_ids = $this->locator->collect_ids( (array) ( $operation['content'] ?? [] ) );
					$actual_ids = $this->locator->collect_ids( $elements );
					$ok = $expected_ids === $actual_ids && $this->same_value( (array) ( $operation['pageSettings'] ?? [] ), $page_settings );
					$detail = $ok ? 'Document replacement was persisted.' : 'Saved document differs from the reviewed replacement.';
					break;

				default:
					$detail = 'Verification is not available for this operation.';
			}

			if ( $ok ) { $passed++; }
			$items[] = [
				'index' => $index,
				'operation' => $type,
				'elementId' => $element_id,
				'setting' => $setting,
				'verified' => $ok,
				'detail' => $detail,
			];
		}

		$total = count( $items );
		return [
			'verified' => $total === $passed,
			'total' => $total,
			'passed' => $passed,
			'failed' => $total - $passed,
			'items' => $items,
		];
	}

	private function analyze_setting_update( array $operation, int $index, array $elements, array $catalog, array &$issues, array &$item, array &$counts ): void {
		$element_id = (string) ( $operation['elementId'] ?? '' );
		$setting = (string) ( $operation['setting'] ?? '' );
		$element = $this->locator->find( $elements, $element_id );
		if ( null === $element ) {
			$this->add_issue( $issues, $item, 'error', 'missing-element', 'The target Elementor element no longer exists.', $index, $element_id, $setting );
			return;
		}
		$current = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$exists = array_key_exists( $setting, $current );
		$before = $exists ? $current[ $setting ] : null;
		$after = $operation['value'] ?? null;
		$item['before'] = $exists ? $this->display_value( $before ) : '(unset)';
		$item['after'] = $this->display_value( $after );

		$resolution = $this->resolve_control( $element, $setting, $catalog );
		$this->apply_resolution( $resolution, $index, $element_id, $setting, $issues, $item, $counts );
		if ( 'native' === $resolution['status'] ) {
			$this->validate_control_value( $after, $resolution['control'], $index, $element_id, $setting, $issues, $item );
		}
		if ( 'custom_css' === $resolution['base'] ) {
			$counts['customCssOperations']++;
			$item['classification'] = 'custom-css-fallback';
			$this->analyze_custom_css( is_string( $after ) ? $after : '', $element, $catalog, $index, $element_id, $setting, $issues, $item );
		}
		if ( $exists && $this->same_value( $before, $after ) ) {
			$this->mark_noop( $item, $counts, $issues, $index, $element_id, $setting, 'Elementor setting already has the requested value.' );
		}
	}

	private function analyze_setting_remove( array $operation, int $index, array $elements, array $catalog, array &$issues, array &$item, array &$counts ): void {
		$element_id = (string) ( $operation['elementId'] ?? '' );
		$setting = (string) ( $operation['setting'] ?? '' );
		$element = $this->locator->find( $elements, $element_id );
		if ( null === $element ) {
			$this->add_issue( $issues, $item, 'error', 'missing-element', 'The target Elementor element no longer exists.', $index, $element_id, $setting );
			return;
		}
		$current = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$exists = array_key_exists( $setting, $current );
		$item['before'] = $exists ? $this->display_value( $current[ $setting ] ) : '(unset)';
		$item['after'] = '(removed)';
		$resolution = $this->resolve_control( $element, $setting, $catalog );
		$this->apply_resolution( $resolution, $index, $element_id, $setting, $issues, $item, $counts, true );
		if ( ! $exists ) {
			$this->mark_noop( $item, $counts, $issues, $index, $element_id, $setting, 'Elementor setting is already absent.' );
		}
	}

	private function analyze_replace_settings( array $operation, int $index, array $elements, array $catalog, array &$issues, array &$item, array &$counts ): void {
		$element_id = (string) ( $operation['elementId'] ?? '' );
		$element = $this->locator->find( $elements, $element_id );
		if ( null === $element ) {
			$this->add_issue( $issues, $item, 'error', 'missing-element', 'The target Elementor element no longer exists.', $index, $element_id, '', );
			return;
		}
		$current = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$next = (array) ( $operation['settings'] ?? [] );
		$item['classification'] = 'settings-replacement';
		$item['before'] = $this->display_value( $current );
		$item['after'] = $this->display_value( $next );
		$this->assert_lossless_settings_replacement( $element, $current, $next, $catalog, $index, $element_id, $issues, $item );
		foreach ( $next as $setting => $value ) {
			$resolution = $this->resolve_control( $element, (string) $setting, $catalog );
			$this->apply_resolution( $resolution, $index, $element_id, (string) $setting, $issues, $item, $counts );
			if ( 'native' === $resolution['status'] ) {
				$this->validate_control_value( $value, $resolution['control'], $index, $element_id, (string) $setting, $issues, $item );
			}
			if ( 'custom_css' === $resolution['base'] ) {
				$counts['customCssOperations']++;
				$this->analyze_custom_css( is_string( $value ) ? $value : '', $element, $catalog, $index, $element_id, (string) $setting, $issues, $item );
			}
		}
		if ( $this->same_value( $current, $next ) ) {
			$this->mark_noop( $item, $counts, $issues, $index, $element_id, '', 'Element settings already match the replacement object.' );
		}
	}

	private function analyze_replace_element( array $operation, int $index, array $elements, array $catalog, array &$issues, array &$item, array &$counts ): void {
		$element_id = (string) ( $operation['elementId'] ?? '' );
		$current = $this->locator->find( $elements, $element_id );
		$replacement = is_array( $operation['element'] ?? null ) ? $operation['element'] : [];
		$item['classification'] = 'element-replacement';
		if ( null === $current ) {
			$this->add_issue( $issues, $item, 'error', 'missing-element', 'The target Elementor element no longer exists.', $index, $element_id, '' );
			return;
		}
		foreach ( $current as $key => $value ) {
			if ( 'elements' === $key && ! empty( $operation['preserveChildren'] ) ) { continue; }
			if ( ! array_key_exists( $key, $replacement ) ) {
				$this->add_issue( $issues, $item, 'error', 'replace-element-drops-field', 'replace-element would drop existing Elementor field "' . $key . '". Preserve unknown and existing fields or use update-setting.', $index, $element_id, '' );
			}
		}
		$current_settings = is_array( $current['settings'] ?? null ) ? $current['settings'] : [];
		$next_settings = is_array( $replacement['settings'] ?? null ) ? $replacement['settings'] : [];
		$this->assert_lossless_settings_replacement( $current, $current_settings, $next_settings, $catalog, $index, $element_id, $issues, $item );
		foreach ( $next_settings as $setting => $value ) {
			$resolution = $this->resolve_control( $current, (string) $setting, $catalog );
			$this->apply_resolution( $resolution, $index, $element_id, (string) $setting, $issues, $item, $counts );
			if ( 'native' === $resolution['status'] ) { $this->validate_control_value( $value, $resolution['control'], $index, $element_id, (string) $setting, $issues, $item ); }
			if ( 'custom_css' === $resolution['base'] ) {
				$counts['customCssOperations']++;
				$this->analyze_custom_css( is_string( $value ) ? $value : '', $current, $catalog, $index, $element_id, (string) $setting, $issues, $item );
			}
		}
	}

	private function analyze_new_element_tree( array $element, int $index, array $catalog, array &$issues, array &$item ): void {
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		foreach ( $settings as $setting => $value ) {
			$resolution = $this->resolve_control( $element, (string) $setting, $catalog );
			if ( 'unknown' === $resolution['status'] && '__globals__' !== $setting ) {
				$this->add_issue( $issues, $item, 'error', 'unknown-setting', 'Inserted element uses a setting that is not exposed by its current Elementor capability catalog: ' . $setting, $index, (string) ( $element['id'] ?? '' ), (string) $setting );
			} elseif ( 'native' === $resolution['status'] ) {
				$this->validate_control_value( $value, $resolution['control'], $index, (string) ( $element['id'] ?? '' ), (string) $setting, $issues, $item );
			}
			if ( 'custom_css' === $resolution['base'] ) {
				$this->analyze_custom_css( is_string( $value ) ? $value : '', $element, $catalog, $index, (string) ( $element['id'] ?? '' ), (string) $setting, $issues, $item );
			}
		}
		foreach ( (array) ( $element['elements'] ?? [] ) as $child ) {
			if ( is_array( $child ) ) { $this->analyze_new_element_tree( $child, $index, $catalog, $issues, $item ); }
		}
	}

	private function assert_lossless_settings_replacement( array $element, array $current, array $next, array $catalog, int $index, string $element_id, array &$issues, array &$item ): void {
		if ( isset( $current['__globals__'] ) && ! empty( $current['__globals__'] ) && ! array_key_exists( '__globals__', $next ) ) {
			$this->add_issue( $issues, $item, 'error', 'drop-global-references', 'The replacement would remove existing Elementor global style references. Preserve __globals__ or use targeted setting operations.', $index, $element_id, '__globals__' );
		}
		foreach ( $current as $setting => $value ) {
			if ( '__globals__' === $setting || array_key_exists( $setting, $next ) ) { continue; }
			$resolution = $this->resolve_control( $element, (string) $setting, $catalog );
			if ( 'existing-unknown' === $resolution['status'] ) {
				$this->add_issue( $issues, $item, 'error', 'drop-unknown-setting', 'The replacement would remove unknown persisted Elementor setting "' . $setting . '". Preserve it or remove it explicitly with remove-setting.', $index, $element_id, (string) $setting );
			}
		}
	}

	private function resolve_control( array $element, string $setting, array $catalog ): array {
		$entry = $this->catalog_entry( $element, $catalog );
		$controls = is_array( $entry['controls'] ?? null ) ? $entry['controls'] : [];
		$current = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		if ( isset( $controls[ $setting ] ) ) {
			return [ 'status' => 'native', 'base' => $setting, 'device' => '', 'control' => $controls[ $setting ], 'controls' => $controls ];
		}
		if ( preg_match( '/^(.+?)_(' . implode( '|', array_map( 'preg_quote', self::RESPONSIVE_SUFFIXES ) ) . ')$/', $setting, $matches ) ) {
			$base = $matches[1];
			$device = $matches[2];
			if ( isset( $controls[ $base ] ) ) {
				if ( empty( $controls[ $base ]['responsive'] ) ) {
					return [ 'status' => 'non-responsive', 'base' => $base, 'device' => $device, 'control' => $controls[ $base ], 'controls' => $controls ];
				}
				return [ 'status' => 'native', 'base' => $base, 'device' => $device, 'control' => $controls[ $base ], 'controls' => $controls ];
			}
		}
		if ( array_key_exists( $setting, $current ) ) {
			return [ 'status' => 'existing-unknown', 'base' => $setting, 'device' => '', 'control' => [], 'controls' => $controls ];
		}
		return [ 'status' => 'unknown', 'base' => $setting, 'device' => '', 'control' => [], 'controls' => $controls ];
	}

	private function apply_resolution( array $resolution, int $index, string $element_id, string $setting, array &$issues, array &$item, array &$counts, bool $removal = false ): void {
		switch ( $resolution['status'] ) {
			case 'native':
				$item['classification'] = 'native-control';
				$counts['nativeControlOperations']++;
				break;
			case 'existing-unknown':
				$item['classification'] = 'unknown-persisted-setting';
				$counts['unknownPersistedOperations']++;
				$this->add_issue( $issues, $item, 'warning', 'unknown-persisted-setting', 'This setting exists in Elementor data but is not in the current capability catalog. Cresco will preserve the operation, but native control validation is unavailable.', $index, $element_id, $setting );
				break;
			case 'non-responsive':
				$item['classification'] = 'invalid-responsive-control';
				$this->add_issue( $issues, $item, 'error', 'non-responsive-control', 'The patch uses a responsive suffix for an Elementor control that is not responsive: ' . $resolution['base'], $index, $element_id, $setting );
				break;
			default:
				$item['classification'] = 'unknown-setting';
				if ( ! $removal ) {
					$this->add_issue( $issues, $item, 'error', 'unknown-setting', 'The patch tries to set an Elementor setting that is not exposed by the target element capability catalog: ' . $setting, $index, $element_id, $setting );
				}
				break;
		}
	}

	private function validate_control_value( $value, array $control, int $index, string $element_id, string $setting, array &$issues, array &$item ): void {
		$units = array_values( array_map( 'strval', (array) ( $control['size_units'] ?? [] ) ) );
		if ( $units && is_array( $value ) && array_key_exists( 'unit', $value ) ) {
			$unit = (string) $value['unit'];
			if ( '' !== $unit && ! in_array( $unit, $units, true ) ) {
				$this->add_issue( $issues, $item, 'error', 'invalid-unit', 'Unsupported unit "' . $unit . '" for Elementor control ' . $setting . '.', $index, $element_id, $setting );
			}
		}

		$type = (string) ( $control['type'] ?? '' );
		$options = is_array( $control['options'] ?? null ) ? $control['options'] : [];
		if ( $options && in_array( $type, [ 'select', 'choose', 'select2' ], true ) ) {
			$allowed = array_map( 'strval', array_keys( $options ) );
			$values = ! empty( $control['multiple'] ) && is_array( $value ) ? $value : [ $value ];
			foreach ( $values as $candidate ) {
				if ( is_scalar( $candidate ) && ! in_array( (string) $candidate, $allowed, true ) ) {
					$this->add_issue( $issues, $item, 'error', 'invalid-option', 'Value "' . (string) $candidate . '" is not an allowed option for Elementor control ' . $setting . '.', $index, $element_id, $setting );
				}
			}
		}

		$number = null;
		$unit = '';
		if ( is_numeric( $value ) ) { $number = (float) $value; }
		elseif ( is_array( $value ) && isset( $value['size'] ) && is_numeric( $value['size'] ) ) {
			$number = (float) $value['size'];
			$unit = isset( $value['unit'] ) ? (string) $value['unit'] : '';
		}
		if ( null !== $number ) {
			[ $min, $max ] = $this->active_bounds( $control, $unit );
			$shown = $number . ( '' !== $unit ? $unit : '' );
			if ( is_numeric( $min ) && $number < (float) $min ) {
				$this->add_issue( $issues, $item, 'error', 'value-below-range', 'Value ' . $shown . ' for Elementor control ' . $setting . ' is below its supported minimum of ' . $min . ( '' !== $unit ? $unit : '' ) . '.', $index, $element_id, $setting );
			}
			if ( is_numeric( $max ) && $number > (float) $max ) {
				$this->add_issue( $issues, $item, 'error', 'value-above-range', 'Value ' . $shown . ' for Elementor control ' . $setting . ' exceeds its supported maximum of ' . $max . ( '' !== $unit ? $unit : '' ) . '.', $index, $element_id, $setting );
			}
		}
	}

	/**
	 * Resolve the numeric bounds that actually apply to the unit being written.
	 *
	 * Elementor declares ranges per unit, but a control usually offers more units in size_units than it
	 * defines ranges for. Borrowing another unit's bounds compares unlike quantities — 50vw against a
	 * px minimum of 500, for example — and rejects a perfectly valid patch. When no range exists for
	 * the unit in play, there is nothing to enforce, so enforce nothing.
	 *
	 * @return array{0:mixed,1:mixed} [ min, max ]; null entries mean "unbounded".
	 */
	private function active_bounds( array $control, string $unit ): array {
		// Elementor's "custom" unit carries a raw CSS string; numeric bounds are meaningless for it.
		if ( 'custom' === $unit ) { return [ null, null ]; }

		$range = is_array( $control['range'] ?? null ) ? $control['range'] : [];
		$per_unit = [];
		foreach ( $range as $key => $candidate ) {
			if ( is_string( $key ) && is_array( $candidate ) && ( isset( $candidate['min'] ) || isset( $candidate['max'] ) ) ) {
				$per_unit[ $key ] = $candidate;
			}
		}

		if ( $per_unit ) {
			if ( '' !== $unit ) {
				// No declared range for this unit: never fall back to a different unit's bounds.
				if ( ! isset( $per_unit[ $unit ] ) ) { return [ null, null ]; }
				$active = $per_unit[ $unit ];
			} elseif ( isset( $per_unit['px'] ) ) {
				$active = $per_unit['px'];  // A bare number means px in Elementor.
			} else {
				return [ null, null ];
			}
			return [ $active['min'] ?? null, $active['max'] ?? null ];
		}

		if ( isset( $range['min'] ) || isset( $range['max'] ) ) {
			return [ $range['min'] ?? null, $range['max'] ?? null ];
		}
		return [ $control['min'] ?? null, $control['max'] ?? null ];
	}

	private function analyze_custom_css( string $css, array $element, array $catalog, int $index, string $element_id, string $setting, array &$issues, array &$item ): void {
		foreach ( self::SYNTHETIC_LAYOUT_VARIABLES as $variable ) {
			if ( ! preg_match( '/(^|[;{]\s*)' . preg_quote( $variable, '/' ) . '\s*:/i', $css ) ) { continue; }
			if ( preg_match( '/var\(\s*' . preg_quote( $variable, '/' ) . '(?:\s*[,\)])/i', $css ) ) { continue; }
			$this->add_issue( $issues, $item, 'error', 'inert-css-variable', 'Custom CSS declares ' . $variable . ' but never consumes it with var(). This is a likely visual no-op; use the native Elementor control instead.', $index, $element_id, $setting );
		}

		$entry = $this->catalog_entry( $element, $catalog );
		$controls = is_array( $entry['controls'] ?? null ) ? $entry['controls'] : [];
		foreach ( self::CSS_NATIVE_HINTS as $property => $needles ) {
			if ( ! preg_match( '/(?:^|[;{]\s*)' . preg_quote( $property, '/' ) . '\s*:/i', $css ) ) { continue; }
			if ( ! $this->has_control_like( $controls, $needles ) ) { continue; }
			$this->add_issue( $issues, $item, 'warning', 'custom-css-native-control', 'Custom CSS writes "' . $property . '" even though the target element exposes a related native Elementor control. Prefer update-setting with the native control; custom_css should be fallback-only.', $index, $element_id, $setting );
		}
	}

	private function has_control_like( array $controls, array $needles ): bool {
		foreach ( array_keys( $controls ) as $control_name ) {
			$normalized = strtolower( (string) $control_name );
			foreach ( $needles as $needle ) {
				if ( str_contains( $normalized, strtolower( $needle ) ) ) { return true; }
			}
		}
		return false;
	}

	private function catalog_entry( array $element, array $catalog ): array {
		$widget_type = (string) ( $element['widgetType'] ?? '' );
		$el_type = (string) ( $element['elType'] ?? '' );
		if ( '' !== $widget_type && isset( $catalog['widgets'][ $widget_type ] ) && is_array( $catalog['widgets'][ $widget_type ] ) ) { return $catalog['widgets'][ $widget_type ]; }
		if ( '' !== $el_type && isset( $catalog['elements'][ $el_type ] ) && is_array( $catalog['elements'][ $el_type ] ) ) { return $catalog['elements'][ $el_type ]; }
		return [];
	}

	private function add_issue( array &$issues, array &$item, string $severity, string $code, string $message, int $index, string $element_id, string $setting ): void {
		$issues[] = [
			'severity' => $severity,
			'code' => $code,
			'message' => $message,
			'operationIndex' => $index,
			'elementId' => $element_id,
			'setting' => $setting,
		];
		$item['issueCodes'][] = $code;
	}

	private function mark_noop( array &$item, array &$counts, array &$issues, int $index, string $element_id, string $setting, string $message ): void {
		if ( false === $item['effective'] ) { return; }
		$item['effective'] = false;
		$counts['noOpOperations']++;
		$this->add_issue( $issues, $item, 'warning', 'no-op', $message, $index, $element_id, $setting );
	}

	private function shadow_update_setting( array &$elements, string $element_id, string $setting, $value ): bool {
		foreach ( $elements as &$element ) {
			if ( (string) ( $element['id'] ?? '' ) === $element_id ) {
				if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) { $element['settings'] = []; }
				$element['settings'][ $setting ] = $value;
				return true;
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) && $this->shadow_update_setting( $element['elements'], $element_id, $setting, $value ) ) { return true; }
		}
		unset( $element );
		return false;
	}

	private function shadow_remove_setting( array &$elements, string $element_id, string $setting ): bool {
		foreach ( $elements as &$element ) {
			if ( (string) ( $element['id'] ?? '' ) === $element_id ) {
				if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) { unset( $element['settings'][ $setting ] ); }
				return true;
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) && $this->shadow_remove_setting( $element['elements'], $element_id, $setting ) ) { return true; }
		}
		unset( $element );
		return false;
	}

	private function shadow_replace_settings( array &$elements, string $element_id, array $settings ): bool {
		foreach ( $elements as &$element ) {
			if ( (string) ( $element['id'] ?? '' ) === $element_id ) { $element['settings'] = $settings; return true; }
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) && $this->shadow_replace_settings( $element['elements'], $element_id, $settings ) ) { return true; }
		}
		unset( $element );
		return false;
	}

	private function find_location( array $elements, string $id, string $parent_id = '' ): ?array {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) { continue; }
			if ( (string) ( $element['id'] ?? '' ) === $id ) { return [ 'parentId' => $parent_id, 'index' => $index ]; }
			$found = $this->find_location( (array) ( $element['elements'] ?? [] ), $id, (string) ( $element['id'] ?? '' ) );
			if ( null !== $found ) { return $found; }
		}
		return null;
	}

	private function same_value( $left, $right ): bool {
		return wp_json_encode( $this->canonicalize( $left ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) === wp_json_encode( $this->canonicalize( $right ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private function canonicalize( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value, SORT_STRING ); }
		foreach ( $value as $key => $child ) { $value[ $key ] = $this->canonicalize( $child ); }
		return $value;
	}

	private function display_value( $value ): string {
		if ( is_null( $value ) ) { return 'null'; }
		if ( is_bool( $value ) ) { return $value ? 'true' : 'false'; }
		if ( is_scalar( $value ) ) { $text = (string) $value; }
		else { $text = (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
		return strlen( $text ) > self::MAX_DISPLAY_LENGTH ? substr( $text, 0, self::MAX_DISPLAY_LENGTH - 1 ) . '…' : $text;
	}

	private function load_working_data( int $post_id ): array {
		$manager = ElementorPlugin::instance()->documents;
		$main = $manager->get_with_permissions( $post_id );
		if ( ! $main ) { throw new \RuntimeException( 'Elementor document is unavailable or cannot be edited.' ); }
		$working = $manager->get_doc_or_auto_save( $post_id, get_current_user_id() );
		if ( ! $working ) { $working = $main; }
		$elements = (array) $working->get_elements_data();
		$working_post = method_exists( $working, 'get_post' ) ? $working->get_post() : null;
		$settings_id = $working_post ? (int) $working_post->ID : $post_id;
		$page_settings = get_post_meta( $settings_id, '_elementor_page_settings', true );
		return [ $elements, is_array( $page_settings ) ? $page_settings : [] ];
	}
}
