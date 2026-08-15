<?php
namespace CrescoLayer\SiteSettings\Contract;

/**
 * The `cresco-site-settings/v1` contract.
 *
 * Deliberately independent of `cresco-layer-patch/v1`: that schema owns pages, containers and
 * widgets, this one owns the global design system. Keeping them apart means an Elementor version
 * bump can change Kit control names without touching the element-level patch semantics.
 *
 * The spec carries semantic intent ("accent", "h1") rather than Elementor control names. Mapping to
 * whatever the running Elementor actually registers is the adapter's job, so the profile stays
 * readable and survives Elementor renaming its internals.
 */
final class Spec {
	public const SCHEMA = 'cresco-site-settings/v1';
	public const TARGET = 'elementor';

	/** Update declared properties, preserve everything else. The safe default. */
	public const MODE_MERGE = 'merge';
	/** Create/update/remove only resources the ownership registry says Cresco created. */
	public const MODE_SYNC_OWNED = 'sync-owned';
	/** Allow overriding declared Site Settings scope. Only when the caller asks explicitly. */
	public const MODE_FORCE = 'force';

	public const MODES = [ self::MODE_MERGE, self::MODE_SYNC_OWNED, self::MODE_FORCE ];

	/** Elementor's four fixed system colour slots. */
	public const SYSTEM_COLOR_IDS = [ 'primary', 'secondary', 'text', 'accent' ];
	/** Elementor's four fixed system typography slots. */
	public const SYSTEM_TYPOGRAPHY_IDS = [ 'primary', 'secondary', 'text', 'accent' ];

	/** Top-level sections a spec may declare. Anything else is rejected by the validator. */
	public const SECTIONS = [ 'designSystem', 'themeStyle', 'settings', 'fluid' ];

	public static function skeleton(): array {
		return [
			'schema' => self::SCHEMA,
			'target' => self::TARGET,
			'profile' => '',
			'mode' => self::MODE_MERGE,
			'designSystem' => [ 'colors' => [], 'typography' => [] ],
			'themeStyle' => [
				'typography' => [], 'buttons' => [], 'images' => [],
				'formFields' => [], 'helloHeader' => [], 'helloFooter' => [],
			],
			'settings' => [
				'siteIdentity' => [], 'background' => [], 'layout' => [],
				'lightbox' => [], 'pageTransitions' => [], 'customCss' => [],
			],
			'fluid' => [ 'viewportMin' => 320, 'viewportMax' => 1440, 'tokens' => [] ],
		];
	}
}
