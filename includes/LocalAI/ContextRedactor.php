<?php
namespace CrescoLayer\LocalAI;

final class ContextRedactor {
	private const SENSITIVE_KEY = '/(?:secret|password|passwd|api[_-]?key|private[_-]?key|access[_-]?token|refresh[_-]?token|authorization|nonce|smtp[_-]?pass|webhook[_-]?secret|client[_-]?secret|credential)/i';

	public function redact( $value, string $key = '' ) {
		if ( '' !== $key && preg_match( self::SENSITIVE_KEY, $key ) ) { return '[REDACTED]'; }
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $child_key => $child ) { $out[ $child_key ] = $this->redact( $child, (string) $child_key ); }
			return $out;
		}
		if ( ! is_string( $value ) ) { return $value; }
		$value = preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value ) ?? $value;
		$value = preg_replace( '/([?&](?:token|api[_-]?key|key|secret|signature|auth)=)[^&#\s]+/i', '$1[REDACTED]', $value ) ?? $value;
		return $value;
	}
}
