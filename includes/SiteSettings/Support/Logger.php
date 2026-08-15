<?php
namespace CrescoLayer\SiteSettings\Support;

/**
 * Diagnostic record of one Site Settings transaction.
 *
 * Records the setting keys that moved and why something was skipped — never the values themselves,
 * since Kit settings carry site content such as logos and copyright text that has no place in a log.
 */
final class Logger {
	private array $lines = [];
	private array $context = [];

	public function context( array $context ): void {
		$this->context = array_merge( $this->context, $context );
	}

	public function add( string $bucket, string $key, string $detail = '' ): void {
		$this->lines[] = [ 'bucket' => $bucket, 'key' => $key, 'detail' => $detail ];
	}

	/** @param array<int,array{key:string,reason:string}> $entries */
	public function add_many( string $bucket, array $entries ): void {
		foreach ( $entries as $entry ) {
			$this->add( $bucket, (string) ( $entry['key'] ?? '' ), (string) ( $entry['reason'] ?? $entry['note'] ?? '' ) );
		}
	}

	/** @return array<string,array<int,string>> */
	public function grouped(): array {
		$out = [];
		foreach ( $this->lines as $line ) {
			$label = $line['key'] . ( '' !== $line['detail'] ? ' (' . $line['detail'] . ')' : '' );
			$out[ $line['bucket'] ][] = $label;
		}
		return $out;
	}

	public function render( string $result ): string {
		$out = [ '[CRESCO][SITE_SETTINGS]' ];
		foreach ( $this->context as $key => $value ) {
			$out[] = sprintf( '%s: %s', $key, is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
		}
		foreach ( $this->grouped() as $bucket => $entries ) {
			$out[] = strtoupper( $bucket ) . ':';
			foreach ( $entries as $entry ) { $out[] = '- ' . $entry; }
		}
		$out[] = 'RESULT: ' . strtoupper( $result );
		return implode( "\n", $out );
	}
}
