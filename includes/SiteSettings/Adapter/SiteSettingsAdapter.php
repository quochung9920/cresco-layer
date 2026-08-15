<?php
namespace CrescoLayer\SiteSettings\Adapter;

/**
 * Translates a `cresco-site-settings/v1` spec into settings for one generation of Elementor.
 *
 * Separate implementations exist so Classic Kit settings and a future Atomic/V4 model never share
 * one branching class: mixing Atomic variables into Classic Global Colors would corrupt both.
 */
interface SiteSettingsAdapter {
	/** Stable identifier reported in results and logs, e.g. `elementor-classic`. */
	public function id(): string;

	/** True when this adapter can drive the Kit it was given. */
	public function supports(): bool;

	/**
	 * Produce the settings Cresco wants, plus a record of what could not be mapped.
	 *
	 * Returns the desired values only — never the merge with current state, and never a write. The
	 * caller diffs and decides, which is what keeps "no change" from turning into a save.
	 *
	 * @return array{settings:array,skipped:array,notes:array}
	 */
	public function build( array $spec ): array;
}
