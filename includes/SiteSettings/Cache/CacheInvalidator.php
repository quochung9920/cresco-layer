<?php
namespace CrescoLayer\SiteSettings\Cache;

/**
 * Invalidation of generated CSS after a successful write.
 *
 * Behind an interface so the engine can be exercised without Elementor, and so the count of
 * invalidations is observable: clearing more than once per transaction regenerates every stylesheet
 * more than once for a single change.
 */
interface CacheInvalidator {
	/** True when an invalidation actually happened. */
	public function clear(): bool;

	public function clears(): int;
}
