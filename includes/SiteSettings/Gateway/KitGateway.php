<?php
namespace CrescoLayer\SiteSettings\Gateway;

/**
 * The only way the Site Settings engine touches an Elementor Kit.
 *
 * Everything above this boundary works against the interface, which keeps the engine testable and
 * keeps the write path in exactly one place: a single implementation to audit when asking whether
 * Cresco writes through Elementor's document API rather than straight to post meta.
 */
interface KitGateway {
	/** False when Elementor is missing, has no active Kit, or the Kit cannot be edited. */
	public function is_available(): bool;

	/** Resolved at runtime from the Kits manager; never a hardcoded ID. */
	public function kit_id(): int;

	/** Controls the running Kit actually registers, keyed by control name. */
	public function controls(): array;

	/** Current Kit settings. */
	public function settings(): array;

	/** Persist settings through Elementor's document API. Returns false when Elementor refuses. */
	public function save( array $settings ): bool;

	/** Drop any cached read so the next settings() call reflects what was persisted. */
	public function refresh(): void;

	/** Non-fatal problems encountered while reading the Kit. */
	public function errors(): array;
}
