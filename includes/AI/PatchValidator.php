<?php
namespace CrescoLayer\AI;

final class PatchValidator {
	public const ALLOWED_OPERATIONS = [
		'update-setting', 'remove-setting', 'replace-settings', 'insert-element', 'remove-element', 'move-element', 'update-page-setting', 'remove-page-setting',
	];
	private const MAX_OPERATIONS = 1000;
	private const MAX_DEPTH = 16;
	private const MAX_STRING = 65536;

	public function validate( array $patch, int $expected_post_id = 0 ): array {
		if ( 'cresco-layer-patch/v1' !== ( $patch['schema'] ?? '' ) ) { throw new \InvalidArgumentException( 'Unsupported patch schema.' ); }
		$base = isset( $patch['base'] ) && is_array( $patch['base'] ) ? $patch['base'] : [];
		$post_id = absint( $base['postId'] ?? 0 );
		if ( $expected_post_id && $post_id !== $expected_post_id ) { throw new \InvalidArgumentException( 'Patch postId does not match the requested document.' ); }
		$checksum = isset( $base['checksum'] ) ? strtolower( (string) $base['checksum'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) { throw new \InvalidArgumentException( 'Patch base checksum is missing or invalid.' ); }
		$operations = $patch['operations'] ?? null;
		if ( ! is_array( $operations ) || count( $operations ) > self::MAX_OPERATIONS ) { throw new \InvalidArgumentException( 'Patch operations are invalid or exceed the limit.' ); }
		$clean = [];
		foreach ( $operations as $index => $operation ) {
			if ( ! is_array( $operation ) ) { throw new \InvalidArgumentException( 'Invalid operation at index ' . $index . '.' ); }
			$clean[] = $this->validate_operation( $operation, $index );
		}
		return [ 'schema' => 'cresco-layer-patch/v1', 'base' => [ 'postId' => $post_id, 'checksum' => $checksum ], 'label' => sanitize_text_field( (string) ( $patch['label'] ?? 'AI Import' ) ), 'operations' => $clean ];
	}

	private function validate_operation( array $op, int $index ): array {
		$type = sanitize_key( (string) ( $op['operation'] ?? '' ) );
		if ( ! in_array( $type, self::ALLOWED_OPERATIONS, true ) ) { throw new \InvalidArgumentException( 'Unsupported operation at index ' . $index . '.' ); }
		$result = [ 'operation' => $type ];
		if ( in_array( $type, [ 'update-setting', 'remove-setting', 'replace-settings', 'remove-element', 'move-element' ], true ) ) { $result['elementId'] = $this->element_id( $op['elementId'] ?? '', $index ); }
		if ( in_array( $type, [ 'update-setting', 'remove-setting' ], true ) ) { $result['setting'] = $this->setting_key( $op['setting'] ?? '', $index ); }
		if ( 'update-setting' === $type ) { $result['value'] = $this->safe_value( $op['value'] ?? null, 0 ); }
		if ( 'replace-settings' === $type ) {
			if ( ! isset( $op['settings'] ) || ! is_array( $op['settings'] ) ) { throw new \InvalidArgumentException( 'replace-settings requires an object at index ' . $index . '.' ); }
			$result['settings'] = $this->safe_value( $op['settings'], 0 );
		}
		if ( 'insert-element' === $type ) {
			$result['parentId'] = '' === (string) ( $op['parentId'] ?? '' ) ? '' : $this->element_id( $op['parentId'], $index );
			$result['position'] = max( 0, absint( $op['position'] ?? PHP_INT_MAX ) );
			if ( ! isset( $op['element'] ) || ! is_array( $op['element'] ) ) { throw new \InvalidArgumentException( 'insert-element requires element data at index ' . $index . '.' ); }
			$result['element'] = $this->validate_element( $op['element'], 0 );
		}
		if ( 'move-element' === $type ) {
			$result['parentId'] = '' === (string) ( $op['parentId'] ?? '' ) ? '' : $this->element_id( $op['parentId'], $index );
			$result['position'] = max( 0, absint( $op['position'] ?? PHP_INT_MAX ) );
		}
		if ( in_array( $type, [ 'update-page-setting', 'remove-page-setting' ], true ) ) { $result['setting'] = $this->setting_key( $op['setting'] ?? '', $index ); }
		if ( 'update-page-setting' === $type ) { $result['value'] = $this->safe_value( $op['value'] ?? null, 0 ); }
		return $result;
	}

	private function validate_element( array $element, int $depth ): array {
		if ( $depth > self::MAX_DEPTH ) { throw new \InvalidArgumentException( 'Inserted element tree is too deep.' ); }
		$id = $this->element_id( $element['id'] ?? '', 0 );
		$el_type = sanitize_key( (string) ( $element['elType'] ?? '' ) );
		if ( '' === $el_type ) { throw new \InvalidArgumentException( 'Inserted element is missing elType.' ); }
		$allowed = [ 'id', 'elType', 'widgetType', 'isInner', 'settings', 'elements', 'version', 'styles', 'interactions', 'editor_settings' ];
		$out = [ 'id' => $id, 'elType' => $el_type ];
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $element ) || in_array( $key, [ 'id', 'elType', 'elements' ], true ) ) { continue; }
			if ( 'widgetType' === $key ) { $out[ $key ] = sanitize_key( (string) $element[ $key ] ); continue; }
			if ( 'isInner' === $key ) { $out[ $key ] = (bool) $element[ $key ]; continue; }
			$out[ $key ] = $this->safe_value( $element[ $key ], $depth + 1 );
		}
		$out['elements'] = [];
		foreach ( (array) ( $element['elements'] ?? [] ) as $child ) {
			if ( ! is_array( $child ) ) { throw new \InvalidArgumentException( 'Inserted child element is invalid.' ); }
			$out['elements'][] = $this->validate_element( $child, $depth + 1 );
		}
		return $out;
	}

	private function safe_value( $value, int $depth ) {
		if ( $depth > self::MAX_DEPTH ) { throw new \InvalidArgumentException( 'Patch value is too deeply nested.' ); }
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $key => $child ) {
				$key = is_int( $key ) ? $key : $this->object_key( (string) $key );
				$out[ $key ] = $this->safe_value( $child, $depth + 1 );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > self::MAX_STRING ) { throw new \InvalidArgumentException( 'Patch string exceeds the size limit.' ); }
			if ( preg_match( '/<\s*(?:script|iframe|object|embed)\b|javascript\s*:|\bon[a-z0-9_-]+\s*=/i', $value ) ) { throw new \InvalidArgumentException( 'Patch contains active or unsafe markup.' ); }
			return $value;
		}
		if ( is_null( $value ) || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return $value; }
		throw new \InvalidArgumentException( 'Patch contains an unsupported value type.' );
	}

	private function element_id( $value, int $index ): string {
		$id = (string) $value;
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ) { throw new \InvalidArgumentException( 'Invalid elementId at index ' . $index . '.' ); }
		return $id;
	}

	private function setting_key( $value, int $index ): string {
		$key = (string) $value;
		if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/', $key ) ) { throw new \InvalidArgumentException( 'Invalid setting key at index ' . $index . '.' ); }
		$this->assert_not_sensitive_key( $key );
		return $key;
	}

	private function object_key( string $key ): string {
		if ( '' === $key || strlen( $key ) > 160 || preg_match( '/[\x00-\x1F\x7F]/', $key ) ) { throw new \InvalidArgumentException( 'Patch contains an invalid nested object key.' ); }
		$this->assert_not_sensitive_key( $key );
		return $key;
	}

	private function assert_not_sensitive_key( string $key ): void {
		if ( preg_match( '/(?:secret|password|passwd|api[_-]?key|private[_-]?key|access[_-]?token|refresh[_-]?token|authorization|nonce)/i', $key ) ) { throw new \InvalidArgumentException( 'Sensitive settings cannot be modified through AI patches.' ); }
	}
}
