<?php
namespace CrescoLayer\Support;

final class DocumentChecksum {
	public static function hash( array $elements, array $settings = [] ): string {
		$payload = self::canonicalize( [ 'elements' => $elements, 'settings' => $settings ] );
		return hash( 'sha256', (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		if ( self::is_assoc( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}

	private static function is_assoc( array $value ): bool {
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}
}
