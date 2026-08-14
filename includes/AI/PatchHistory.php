<?php
namespace CrescoLayer\AI;

/**
 * Bounded, per-document history of applied AI patches.
 *
 * Each entry keeps the working document exactly as it was *before* the patch, so a reviewer can undo
 * an AI change without hunting through WordPress revisions. Elementor documents can be large, so the
 * store is bounded twice: by entry count and by total serialized bytes. When a snapshot is too big to
 * keep, the entry is still recorded for the audit trail but is marked as not restorable rather than
 * silently pushing the post meta row past what the database will accept.
 */
final class PatchHistory {
	public const SCHEMA       = 'cresco-layer-patch-history/v1';
	public const META_KEY     = '_cresco_layer_patch_history';
	private const MAX_ENTRIES = 20;
	private const MAX_SNAPSHOT_BYTES = 2097152;  // 2 MB per snapshot.
	private const MAX_TOTAL_BYTES    = 8388608;  // 8 MB across the whole history.

	public function record( int $post_id, array $entry, array $elements, array $settings ): string {
		$entries = $this->raw( $post_id );
		$id      = $this->generate_id();
		$user    = wp_get_current_user();

		$snapshot     = [ 'elements' => $elements, 'settings' => $settings ];
		$encoded      = wp_json_encode( $snapshot );
		$bytes        = is_string( $encoded ) ? strlen( $encoded ) : PHP_INT_MAX;
		$restorable   = $bytes <= self::MAX_SNAPSHOT_BYTES;

		$record = [
			'id'             => $id,
			'label'          => (string) ( $entry['label'] ?? '' ),
			'kind'           => (string) ( $entry['kind'] ?? 'patch' ),
			'operations'     => (int) ( $entry['operations'] ?? 0 ),
			'scope'          => $entry['scope'] ?? null,
			'storage'        => (string) ( $entry['storage'] ?? '' ),
			'baseChecksum'   => (string) ( $entry['baseChecksum'] ?? '' ),
			'savedChecksum'  => (string) ( $entry['savedChecksum'] ?? '' ),
			'verified'       => $entry['verified'] ?? null,
			'appliedAt'      => gmdate( 'c' ),
			'userId'         => get_current_user_id(),
			'userName'       => $user && $user->exists() ? $user->display_name : '',
			'snapshotBytes'  => $restorable ? $bytes : 0,
			'restorable'     => $restorable,
			'snapshot'       => $restorable ? $snapshot : null,
		];

		$entries[] = $record;
		update_post_meta( $post_id, self::META_KEY, $this->prune( $entries ) );
		return $id;
	}

	/** Listing payload: never ships the snapshots themselves. */
	public function all( int $post_id ): array {
		$entries = array_map(
			static function ( array $entry ): array { unset( $entry['snapshot'] ); return $entry; },
			$this->raw( $post_id )
		);
		return array_values( array_reverse( $entries ) );
	}

	public function get( int $post_id, string $entry_id ): ?array {
		foreach ( $this->raw( $post_id ) as $entry ) {
			if ( (string) ( $entry['id'] ?? '' ) === $entry_id ) { return $entry; }
		}
		return null;
	}

	public function clear( int $post_id ): void {
		delete_post_meta( $post_id, self::META_KEY );
	}

	private function raw( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) { return []; }
		return array_values( array_filter( $stored, 'is_array' ) );
	}

	/**
	 * Keep the newest entries within both bounds. Older entries lose their snapshot before they are
	 * dropped entirely, so the audit trail outlives the ability to restore it.
	 */
	private function prune( array $entries ): array {
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}
		$total = 0;
		for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
			$bytes = (int) ( $entries[ $i ]['snapshotBytes'] ?? 0 );
			if ( ! $bytes ) { continue; }
			if ( $total + $bytes > self::MAX_TOTAL_BYTES ) {
				$entries[ $i ]['snapshot']      = null;
				$entries[ $i ]['restorable']    = false;
				$entries[ $i ]['snapshotBytes'] = 0;
				continue;
			}
			$total += $bytes;
		}
		return array_values( $entries );
	}

	private function generate_id(): string {
		return substr( str_replace( '.', '', uniqid( 'clh', true ) ), 0, 20 );
	}
}
