<?php
namespace CrescoLayer\SiteSettings\Registry;

/**
 * Remembers which Elementor global IDs Cresco created, so a re-run updates them instead of adding
 * duplicates.
 *
 * Elementor identifies a custom global colour or font by an opaque `_id`, and its title is not a
 * key — writing "Surface" twice produces two swatches, not one updated swatch. Without a stable
 * semantic-key → `_id` mapping, every sync would grow the palette.
 *
 * This is ownership bookkeeping only. The style itself lives in the Elementor Kit, which stays the
 * single source of truth; if the registry were lost, the Kit would still be correct and Cresco
 * would simply re-adopt matching entries rather than duplicate them.
 */
final class OwnershipRegistry {
	public const OPTION = 'cresco_layer_elementor_state';
	private const VERSION = '1';

	private ?array $state = null;

	/** Stable Elementor `_id` for a semantic key, or null when Cresco has not created it yet. */
	public function id_for( string $bucket, string $key ): ?string {
		$state = $this->read();
		$id = $state['tokens'][ $bucket ][ $key ] ?? null;
		return is_string( $id ) && '' !== $id ? $id : null;
	}

	public function remember( string $bucket, string $key, string $id ): void {
		$state = $this->read();
		$state['tokens'][ $bucket ][ $key ] = $id;
		$this->write( $state );
	}

	public function forget( string $bucket, string $key ): void {
		$state = $this->read();
		unset( $state['tokens'][ $bucket ][ $key ] );
		$this->write( $state );
	}

	/** @return array<string,string> semantic key => Elementor id for one bucket. */
	public function bucket( string $bucket ): array {
		$state = $this->read();
		$map = $state['tokens'][ $bucket ] ?? [];
		return is_array( $map ) ? array_filter( $map, 'is_string' ) : [];
	}

	/** True when this Elementor id was created by Cresco; user-authored entries must be left alone. */
	public function owns( string $bucket, string $id ): bool {
		return in_array( $id, array_values( $this->bucket( $bucket ) ), true );
	}

	public function kit_id(): int {
		return (int) ( $this->read()['kitId'] ?? 0 );
	}

	/**
	 * Binding the registry to a Kit matters because switching the active Kit means the remembered
	 * IDs belong to a different palette; adopting them would edit swatches in the wrong Kit.
	 */
	public function bind_kit( int $kit_id ): void {
		$state = $this->read();
		if ( (int) ( $state['kitId'] ?? 0 ) !== $kit_id ) {
			$state['kitId'] = $kit_id;
			$state['tokens'] = [];
			$state['lastHash'] = '';
		}
		$this->write( $state );
	}

	public function last_hash(): string {
		return (string) ( $this->read()['lastHash'] ?? '' );
	}

	public function record_hash( string $hash ): void {
		$state = $this->read();
		$state['lastHash'] = $hash;
		$this->write( $state );
	}

	public function reset(): void {
		$this->state = null;
		delete_option( self::OPTION );
	}

	/**
	 * A new Elementor-style id. Elementor generates 7 hex characters for repeater rows; matching that
	 * shape keeps Cresco-created globals indistinguishable from hand-made ones in the editor.
	 */
	public function generate_id(): string {
		return substr( md5( uniqid( 'cresco', true ) ), 0, 7 );
	}

	private function read(): array {
		if ( null !== $this->state ) { return $this->state; }
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) { $stored = []; }
		return $this->state = [
			'schema' => (string) ( $stored['schema'] ?? self::VERSION ),
			'kitId' => (int) ( $stored['kitId'] ?? 0 ),
			'lastHash' => (string) ( $stored['lastHash'] ?? '' ),
			'tokens' => is_array( $stored['tokens'] ?? null ) ? $stored['tokens'] : [],
		];
	}

	private function write( array $state ): void {
		$state['schema'] = self::VERSION;
		$this->state = $state;
		update_option( self::OPTION, $state, false );
	}
}
