<?php
namespace CrescoLayer\AI;

use CrescoLayer\Support\SerializableSanitizer;

final class Diff {
	private const MAX_DETAILS      = 200;
	private const MAX_VALUE_LENGTH = 600;

	/**
	 * Per-operation before/after detail for the preview screen.
	 *
	 * Counting operations tells a reviewer how much changes, not what changes. This resolves each
	 * operation against the current document so the UI can show old → new per setting. Values pass
	 * through the same secret-redaction policy used everywhere else, because a patch can carry a
	 * credential-like key that must not be echoed back into the browser.
	 */
	public static function details( array $operations, array $elements, array $page_settings ): array {
		$sanitizer = new SerializableSanitizer();
		$index     = [];
		self::index_elements( $elements, $index );

		$details   = [];
		$truncated = false;
		foreach ( $operations as $op ) {
			if ( count( $details ) >= self::MAX_DETAILS ) { $truncated = true; break; }
			$type       = (string) ( $op['operation'] ?? '' );
			$element_id = (string) ( $op['elementId'] ?? '' );
			$element    = $index[ $element_id ] ?? null;
			$row = [
				'operation'  => $type,
				'elementId'  => $element_id,
				'widgetType' => self::describe_type( $element ),
				'setting'    => (string) ( $op['setting'] ?? '' ),
				'oldValue'   => null,
				'newValue'   => null,
				'changed'    => true,
			];

			switch ( $type ) {
				case 'update-setting':
					$row['oldValue'] = $element['settings'][ $op['setting'] ] ?? null;
					$row['newValue'] = $op['value'] ?? null;
					break;
				case 'remove-setting':
					$row['oldValue'] = $element['settings'][ $op['setting'] ] ?? null;
					break;
				case 'update-page-setting':
					$row['oldValue'] = $page_settings[ $op['setting'] ] ?? null;
					$row['newValue'] = $op['value'] ?? null;
					break;
				case 'remove-page-setting':
					$row['oldValue'] = $page_settings[ $op['setting'] ] ?? null;
					break;
				case 'replace-settings':
					$row['setting'] = '(all settings)';
					$row['oldValue'] = self::count_label( $element['settings'] ?? [] );
					$row['newValue'] = self::count_label( (array) ( $op['settings'] ?? [] ) );
					break;
				case 'move-element':
				case 'insert-element':
					$row['setting'] = '(structure)';
					$row['newValue'] = 'parent ' . (string) ( $op['parentId'] ?? '' ) . ' @ ' . (string) ( $op['position'] ?? 0 );
					break;
				case 'remove-element':
				case 'replace-element':
				case 'replace-document':
					$row['setting'] = '(structure)';
					break;
			}

			$row['changed'] = self::stable( $row['oldValue'] ) !== self::stable( $row['newValue'] );
			// Pass the setting name as the key: redaction is key-driven, so a bare value would leak
			// a credential-like setting straight back into the browser.
			$setting_key     = (string) ( $op['setting'] ?? '' );
			$row['oldValue'] = self::present( $sanitizer->sanitize( $row['oldValue'], '$.oldValue', $setting_key ) );
			$row['newValue'] = self::present( $sanitizer->sanitize( $row['newValue'], '$.newValue', $setting_key ) );
			$details[] = $row;
		}

		return [ 'items' => $details, 'truncated' => $truncated, 'total' => count( $operations ) ];
	}

	private static function index_elements( array $elements, array &$index ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$id = (string) ( $element['id'] ?? '' );
			if ( '' !== $id ) { $index[ $id ] = $element; }
			self::index_elements( (array) ( $element['elements'] ?? [] ), $index );
		}
	}

	private static function describe_type( ?array $element ): string {
		if ( ! $element ) { return ''; }
		$type = (string) ( $element['elType'] ?? '' );
		$widget = (string) ( $element['widgetType'] ?? '' );
		return '' !== $widget ? $widget : $type;
	}

	private static function count_label( array $value ): string {
		return sprintf( '%d key(s)', count( $value ) );
	}

	private static function stable( $value ): string {
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? $encoded : '';
	}

	/** Collapse anything non-scalar into a short readable form the diff table can render inline. */
	private static function present( $value ): ?string {
		if ( null === $value ) { return null; }
		if ( is_bool( $value ) ) { return $value ? 'true' : 'false'; }
		if ( is_scalar( $value ) ) { $text = (string) $value; }
		else {
			$encoded = wp_json_encode( $value );
			$text = is_string( $encoded ) ? $encoded : '';
		}
		if ( strlen( $text ) > self::MAX_VALUE_LENGTH ) { $text = substr( $text, 0, self::MAX_VALUE_LENGTH ) . '…'; }
		return $text;
	}

	public static function summarize( array $operations ): array {
		$summary = [
			'total' => count( $operations ),
			'inserted' => 0,
			'removed' => 0,
			'moved' => 0,
			'updated' => 0,
			'replaced' => 0,
			'pageSettings' => 0,
			'documentReplacements' => 0,
			'items' => [],
		];
		foreach ( $operations as $op ) {
			$type = (string) ( $op['operation'] ?? '' );
			if ( 'insert-element' === $type ) { $summary['inserted']++; }
			elseif ( 'remove-element' === $type ) { $summary['removed']++; }
			elseif ( 'move-element' === $type ) { $summary['moved']++; }
			elseif ( 'replace-element' === $type ) { $summary['replaced']++; }
			elseif ( 'replace-document' === $type ) { $summary['documentReplacements']++; }
			elseif ( in_array( $type, [ 'update-page-setting', 'remove-page-setting' ], true ) ) { $summary['pageSettings']++; }
			else { $summary['updated']++; }
			$summary['items'][] = [
				'operation' => $type,
				'elementId' => (string) ( $op['elementId'] ?? '' ),
				'parentId' => (string) ( $op['parentId'] ?? '' ),
				'setting' => (string) ( $op['setting'] ?? '' ),
			];
		}
		return $summary;
	}
}
