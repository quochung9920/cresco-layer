<?php
namespace CrescoLayer\Support;

final class SerializableSanitizer {
	private const REDACTED = '[REDACTED]';
	private const TRUNCATED = '[TRUNCATED]';
	private const MAX_DEPTH = 14;
	private const MAX_STRING = 262144;
	private const MAX_ARRAY_ITEMS = 20000;

	private array $redactions = [];
	private array $omissions = [];
	private array $seenObjects = [];

	public function sanitize( $value, string $path = '$', string $key = '' ) {
		$result = $this->walk( $value, $path, $key, 0 );
		return $result['include'] ? $result['value'] : null;
	}

	public function report(): array {
		return [
			'redactions' => array_values( array_unique( $this->redactions ) ),
			'omissions' => array_values( array_unique( $this->omissions ) ),
		];
	}

	private function walk( $value, string $path, string $key, int $depth ): array {
		if ( $this->isSensitiveKey( $key ) ) {
			$this->redactions[] = $path;
			return [ 'include' => true, 'value' => self::REDACTED ];
		}
		if ( $depth > self::MAX_DEPTH ) {
			$this->omissions[] = $path . ' (max-depth)';
			return [ 'include' => true, 'value' => self::TRUNCATED ];
		}
		if ( is_null( $value ) || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return [ 'include' => true, 'value' => $value ];
		}
		if ( is_string( $value ) ) {
			$value = $this->redactSensitiveString( $value, $path );
			if ( strlen( $value ) > self::MAX_STRING ) {
				$this->omissions[] = $path . ' (string-truncated)';
				$value = substr( $value, 0, self::MAX_STRING ) . '…';
			}
			return [ 'include' => true, 'value' => $value ];
		}
		if ( is_resource( $value ) ) {
			$this->omissions[] = $path . ' (resource:' . get_resource_type( $value ) . ')';
			return [ 'include' => false, 'value' => null ];
		}
		if ( is_array( $value ) ) {
			$out = [];
			$count = 0;
			foreach ( $value as $childKey => $child ) {
				if ( $count++ >= self::MAX_ARRAY_ITEMS ) {
					$out['__cresco_truncated__'] = true;
					$this->omissions[] = $path . ' (array-item-limit)';
					break;
				}
				$childKeyString = (string) $childKey;
				$childPath = $path . '[' . $this->pathSegment( $childKeyString ) . ']';
				$result = $this->walk( $child, $childPath, $childKeyString, $depth + 1 );
				if ( $result['include'] ) {
					$out[ $childKey ] = $result['value'];
				}
			}
			return [ 'include' => true, 'value' => $out ];
		}
		if ( $value instanceof \DateTimeInterface ) {
			return [ 'include' => true, 'value' => $value->format( DATE_ATOM ) ];
		}
		if ( $value instanceof \JsonSerializable ) {
			$objectId = spl_object_id( $value );
			if ( isset( $this->seenObjects[ $objectId ] ) ) {
				$this->omissions[] = $path . ' (object-cycle:' . get_class( $value ) . ')';
				return [ 'include' => false, 'value' => null ];
			}
			$this->seenObjects[ $objectId ] = true;
			try {
				$serialized = $value->jsonSerialize();
				$result = $this->walk( $serialized, $path, $key, $depth + 1 );
			} catch ( \Throwable $error ) {
				$this->omissions[] = $path . ' (json-serialize-error:' . get_class( $value ) . ')';
				$result = [ 'include' => false, 'value' => null ];
			}
			unset( $this->seenObjects[ $objectId ] );
			return $result;
		}
		if ( $value instanceof \stdClass ) {
			return $this->walk( get_object_vars( $value ), $path, $key, $depth + 1 );
		}
		if ( is_object( $value ) ) {
			$this->omissions[] = $path . ' (runtime-object:' . get_class( $value ) . ')';
			return [ 'include' => false, 'value' => null ];
		}

		$this->omissions[] = $path . ' (unsupported:' . gettype( $value ) . ')';
		return [ 'include' => false, 'value' => null ];
	}

	private function isSensitiveKey( string $key ): bool {
		if ( '' === $key ) { return false; }
		return (bool) preg_match(
			'/(?:secret|password|passwd|api[_-]?key|private[_-]?key|access[_-]?token|refresh[_-]?token|bearer|authorization|credential|consumer[_-]?(?:key|secret)|client[_-]?secret|app[_-]?secret|license[_-]?key|smtp[_-]?(?:pass|password)|webhook[_-]?secret|nonce)/i',
			$key
		);
	}

	private function redactSensitiveString( string $value, string $path ): string {
		$original = $value;
		$value = preg_replace(
			'/([?&](?:api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret|password|client[_-]?secret)=)[^&\s]+/i',
			'$1' . self::REDACTED,
			$value
		) ?? $value;
		$value = preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer ' . self::REDACTED, $value ) ?? $value;
		if ( $value !== $original ) {
			$this->redactions[] = $path;
		}
		return $value;
	}

	private function pathSegment( string $key ): string {
		if ( preg_match( '/^[A-Za-z0-9_.:-]+$/', $key ) ) { return $key; }
		$encoded = json_encode( $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return is_string( $encoded ) ? $encoded : rawurlencode( $key );
	}
}
