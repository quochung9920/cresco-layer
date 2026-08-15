<?php
namespace CrescoLayer\SiteSettings\Cache;

use Elementor\Plugin as ElementorPlugin;

/**
 * Invalidates only Elementor's generated CSS.
 *
 * Scope is deliberately narrow. Clearing a page cache, an object cache or a CDN from here would be
 * a side effect the caller never asked for and cannot easily undo, so those stay the integration
 * owner's decision. Invalidation is also counted, because clearing more than once per transaction
 * means regenerating every stylesheet more than once for a single change.
 */
final class ElementorCache implements CacheInvalidator {
	private int $clears = 0;

	public function clear(): bool {
		try {
			if ( ! class_exists( ElementorPlugin::class ) ) { return false; }
			$plugin = ElementorPlugin::instance();
			$files = $plugin->files_manager ?? null;
			if ( ! is_object( $files ) || ! method_exists( $files, 'clear_cache' ) ) { return false; }
			$files->clear_cache();
			$this->clears++;
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	public function clears(): int { return $this->clears; }
}
