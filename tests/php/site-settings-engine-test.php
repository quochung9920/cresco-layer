<?php
/**
 * Site Settings engine contract.
 *
 * Driven through a fake KitGateway so the whole pipeline — discovery, mapping, diff, write, verify,
 * rollback, cache — runs without WordPress or Elementor. The guarantees under test are the ones a
 * second run depends on: stable global IDs, no duplicate swatches, NO_OP when nothing changed, and
 * no cache invalidation when nothing was written.
 */

$options = [];

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { global $options; return $options[ $name ] ?? $default; }
	function update_option( $name, $value, $autoload = null ) { global $options; $options[ $name ] = $value; return true; }
	function delete_option( $name ) { global $options; unset( $options[ $name ] ); return true; }
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
	function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
	function current_user_can( $capability, ...$args ) { return true; }
}

// Autoload the way the plugin itself does, rather than listing every file: the engine gains
// collaborators over time, and a stale require list fails as a confusing "class not found".
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'CrescoLayer\\';
		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) { return; }
		$path = dirname( __DIR__, 2 ) . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) { require_once $path; }
	}
);

use CrescoLayer\SiteSettings\Cache\CacheInvalidator;
use CrescoLayer\SiteSettings\Contract\Spec;
use CrescoLayer\SiteSettings\Diff\DiffEngine;
use CrescoLayer\SiteSettings\Gateway\KitGateway;
use CrescoLayer\SiteSettings\Registry\OwnershipRegistry;
use CrescoLayer\SiteSettings\SiteSettingsEngine;
use CrescoLayer\SiteSettings\Support\ManagedCssBlock;

function eng_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

/** An in-memory Kit. */
final class FakeKitGateway implements KitGateway {
	public array $saved = [];
	public int $saveCount = 0;
	public bool $refuseSave = false;
	/** Simulates Elementor normalising a value on save, which verification must catch. */
	public array $corruptOnSave = [];

	public function __construct( private array $kitControls, private array $kitSettings, private int $id = 42, private bool $available = true ) {}

	public function is_available(): bool { return $this->available; }
	public function kit_id(): int { return $this->id; }
	public function controls(): array { return $this->kitControls; }
	public function errors(): array { return []; }
	public function refresh(): void {}

	/**
	 * Elementor merges each control's default into the stored value when settings are read back, so a
	 * slider always returns with `sizes` present whether or not it was written. Reproducing that here
	 * is what makes this fake represent the real round trip: without it, a partial value written by
	 * Cresco appears to survive intact, and a verification bug stays invisible until production.
	 */
	public function settings(): array {
		$out = $this->kitSettings;
		foreach ( $this->kitControls as $name => $control ) {
			if ( 'slider' !== ( $control['type'] ?? '' ) || ! isset( $out[ $name ] ) || ! is_array( $out[ $name ] ) ) { continue; }
			$out[ $name ] = array_merge( [ 'unit' => 'px', 'size' => '', 'sizes' => [] ], $out[ $name ] );
		}
		return $out;
	}

	public function save( array $settings ): bool {
		if ( $this->refuseSave ) { return false; }
		$this->saveCount++;
		foreach ( $this->corruptOnSave as $key => $value ) {
			if ( array_key_exists( $key, $settings ) ) { $settings[ $key ] = $value; }
		}
		$this->kitSettings = $settings;
		$this->saved = $settings;
		return true;
	}
}

/** Counts invalidations without touching Elementor. */
final class FakeCache implements CacheInvalidator {
	public int $count = 0;
	public function clear(): bool { $this->count++; return true; }
	public function clears(): int { return $this->count; }
}

/** A Kit that registers the controls a modern Classic install exposes. */
function kit_controls(): array {
	$slider = [ 'type' => 'slider', 'size_units' => [ 'px', 'rem', 'em', 'vw', 'custom' ] ];
	$slider_no_custom = [ 'type' => 'slider', 'size_units' => [ 'px', 'rem' ] ];
	$dimensions = [ 'type' => 'dimensions', 'size_units' => [ 'px', 'rem', 'custom' ] ];
	$color = [ 'type' => 'color' ];
	$controls = [
		'system_colors' => [ 'type' => 'repeater' ],
		'custom_colors' => [ 'type' => 'repeater' ],
		'system_typography' => [ 'type' => 'repeater' ],
		'default_generic_fonts' => [ 'type' => 'text' ],
		'body_color' => $color,
		'body_typography_font_size' => $slider,
		'body_typography_font_weight' => [ 'type' => 'select', 'options' => [ '400' => '400', '600' => '600', '700' => '700' ] ],
		'body_typography_line_height' => $slider,
		'body_typography_letter_spacing' => $slider,
		'body_typography_typography' => [ 'type' => 'popover_toggle' ],
		'paragraph_spacing' => $slider,
		'link_normal_color' => $color,
		'link_hover_color' => $color,
		// Layout controls the responsive foundation requires: responsive, px-capable, and percent on
		// content width so the desktop canvas can stay at 100%.
		'container_width' => [ 'type' => 'slider', 'responsive' => true, 'size_units' => [ 'px', '%', 'em', 'rem', 'vw', 'custom' ] ],
		'container_padding' => [ 'type' => 'dimensions', 'responsive' => true, 'size_units' => [ 'px', '%', 'em', 'rem', 'custom' ] ],
		'space_between_widgets' => $slider,
		'active_breakpoints' => [ 'type' => 'select2' ],
		'viewport_mobile' => [ 'type' => 'number' ],
		'viewport_tablet' => [ 'type' => 'number' ],
		'viewport_laptop' => [ 'type' => 'number' ],
		'viewport_widescreen' => [ 'type' => 'number' ],
		'body_background_color' => $color,
		'mobile_browser_background' => $color,
		'body_overscroll_behavior' => [ 'type' => 'select', 'options' => [ 'auto' => 'Auto', 'contain' => 'Contain', 'none' => 'None' ] ],
		'button_typography_font_size' => $slider,
		'button_typography_font_weight' => [ 'type' => 'select', 'options' => [ '600' => '600' ] ],
		'button_typography_line_height' => $slider,
		'button_typography_typography' => [ 'type' => 'popover_toggle' ],
		'button_text_color' => $color,
		'button_background_color' => $color,
		'button_hover_text_color' => $color,
		'button_hover_background_color' => $color,
		'button_border_radius' => $dimensions,
		'button_padding' => $dimensions,
		'image_border_radius' => $dimensions,
		'image_opacity' => $slider,
		'image_hover_opacity' => $slider,
		'form_label_color' => $color,
		'form_label_typography_font_size' => $slider,
		'form_label_typography_font_weight' => [ 'type' => 'select', 'options' => [ '600' => '600' ] ],
		'form_label_typography_typography' => [ 'type' => 'popover_toggle' ],
		// Deliberately without the custom unit: the engine must fall back rather than emit a clamp.
		'form_field_typography_font_size' => $slider_no_custom,
		'form_field_typography_line_height' => $slider,
		'form_field_typography_typography' => [ 'type' => 'popover_toggle' ],
		'form_field_text_color' => $color,
		'form_field_background_color' => $color,
		'form_field_border_radius' => $dimensions,
		'form_field_padding' => $dimensions,
		'form_field_focus_text_color' => $color,
		'form_field_focus_background_color' => $color,
		'form_field_focus_accent_color' => $color,
		'form_field_focus_transition_duration' => $slider,
		'global_image_lightbox' => [ 'type' => 'switcher' ],
		'lightbox_enable_counter' => [ 'type' => 'switcher' ],
		'lightbox_enable_fullscreen' => [ 'type' => 'switcher' ],
		'lightbox_enable_zoom' => [ 'type' => 'switcher' ],
		'lightbox_enable_share' => [ 'type' => 'switcher' ],
		'lightbox_color' => $color,
		'lightbox_ui_color' => $color,
		'lightbox_ui_color_hover' => $color,
		'lightbox_text_color' => $color,
		'lightbox_icons_size' => $slider,
		'lightbox_slider_icons_size' => $slider,
		// Present only with Elementor Pro; the minimal-Kit case below omits it deliberately.
		'custom_css' => [ 'type' => 'code' ],
	];
	foreach ( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ] as $tag ) {
		$controls[ $tag . '_color' ] = $color;
		$controls[ $tag . '_typography_font_size' ] = $slider;
		$controls[ $tag . '_typography_font_weight' ] = [ 'type' => 'select', 'options' => [ '600' => '600', '700' => '700' ] ];
		$controls[ $tag . '_typography_line_height' ] = $slider;
		$controls[ $tag . '_typography_letter_spacing' ] = $slider;
		$controls[ $tag . '_typography_typography' ] = [ 'type' => 'popover_toggle' ];
	}
	return $controls;
}

function fresh_registry(): OwnershipRegistry {
	global $options;
	$options = [];
	return new OwnershipRegistry();
}

function run_engine( FakeKitGateway $gateway, ?FakeCache $cache = null, ?OwnershipRegistry $registry = null ): array {
	return ( new SiteSettingsEngine( $gateway, new DiffEngine(), $registry ?? new OwnershipRegistry(), $cache ?? new FakeCache() ) )->apply();
}

/* ---------- Run 1: a fresh Kit is configured ---------- */

$registry = fresh_registry();
$gateway = new FakeKitGateway( kit_controls(), [
	'system_colors' => [
		[ '_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4' ],
		[ '_id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F' ],
		[ '_id' => 'text', 'title' => 'Text', 'color' => '#7A7A7A' ],
		[ '_id' => 'accent', 'title' => 'Accent', 'color' => '#61CE70' ],
	],
	'custom_colors' => [ [ '_id' => 'usr0001', 'title' => 'Client Brand', 'color' => '#FF00AA' ] ],
	'system_typography' => [ [ '_id' => 'primary', 'title' => 'Primary' ] ],
	'active_breakpoints' => [ 'viewport_mobile', 'viewport_tablet', 'viewport_laptop' ],
	'third_party_addon_setting' => 'must-survive',
	'custom_css' => '.client-rule { color: hotpink; }',
] );
$cache = new FakeCache();
$first = run_engine( $gateway, $cache, $registry );

/** A failing apply must print why, not just that it failed. */
function describe_mismatches( array $result ): string {
	$out = implode( ' | ', $result['errors'] ?? [] );
	foreach ( $result['verification']['mismatches'] ?? [] as $mismatch ) {
		$out .= sprintf(
			"\n  %s [%s type=%s]\n    expected: %s\n    actual:   %s",
			$mismatch['semanticPath'], $mismatch['elementorControl'], $mismatch['controlType'],
			json_encode( $mismatch['expectedNormalized'] ), json_encode( $mismatch['actualNormalized'] )
		);
	}
	return $out;
}

eng_assert( true === $first['success'], 'A fresh Kit must apply successfully: ' . describe_mismatches( $first ) );
eng_assert( 'updated' === $first['status'], 'A fresh Kit must report updated, got ' . $first['status'] );
eng_assert( 42 === $first['kitId'], 'The resolved Kit id must be reported.' );
eng_assert( 'elementor-classic' === $first['adapter'], 'The Classic adapter must be reported.' );
eng_assert( 1 === $cache->count, 'A successful transaction must clear the cache exactly once.' );
eng_assert( 1 === $gateway->saveCount, 'A successful transaction must save exactly once.' );

$saved = $gateway->saved;

// System colours land in Elementor's four fixed slots.
$system = [];
foreach ( $saved['system_colors'] as $row ) { $system[ $row['_id'] ] = $row['color']; }
eng_assert( '#0F172A' === $system['primary'], 'Primary system colour must be written.' );
eng_assert( '#2563EB' === $system['accent'], 'Accent system colour must be written.' );
eng_assert( 4 === count( $saved['system_colors'] ), 'System colours must stay at four slots.' );

// Semantic colours become custom globals; the user's own colour survives.
$custom_titles = array_column( $saved['custom_colors'], 'title' );
eng_assert( in_array( 'Client Brand', $custom_titles, true ), 'A user custom colour must be preserved.' );
eng_assert( in_array( 'Surface', $custom_titles, true ), 'Cresco semantic colours must be created.' );
eng_assert( in_array( 'Border Strong', $custom_titles, true ), 'All semantic colours must be created.' );

// Unknown third-party settings survive a merge.
eng_assert( 'must-survive' === $saved['third_party_addon_setting'], 'An unknown third-party Kit setting must be preserved.' );

// Theme Style typography maps, and a fluid size uses the custom unit.
eng_assert( 'custom' === $saved['h1_typography_font_size']['unit'], 'H1 size must use the custom unit.' );
eng_assert( str_starts_with( $saved['h1_typography_font_size']['size'], 'clamp(' ), 'H1 size must be a clamp().' );
eng_assert( '700' === $saved['h1_typography_font_weight'], 'H1 weight must be written.' );
eng_assert( '#0F172A' === $saved['h1_color'], 'H1 colour must resolve from the semantic palette.' );
eng_assert( isset( $saved['h6_typography_font_size'] ), 'All of H1-H6 must be mapped.' );
eng_assert( '#334155' === $saved['body_color'], 'Body colour must resolve semantically.' );
eng_assert( '#2563EB' === $saved['link_normal_color'], 'Link colour must resolve to accent.' );
eng_assert( '#1D4ED8' === $saved['link_hover_color'], 'Link hover must resolve to the semantic accent-hover custom colour.' );

// Buttons.
eng_assert( '#2563EB' === $saved['button_background_color'], 'Button background must resolve to accent.' );
eng_assert( 'custom' === $saved['button_padding']['unit'], 'Button padding must be fluid.' );
eng_assert( false === $saved['button_padding']['isLinked'], 'Button padding sides differ, so they must not be linked.' );
eng_assert( 'rem' === $saved['button_border_radius']['unit'], 'Button radius must stay a fixed rem value.' );

// A control without the custom unit must fall back, never emit a clamp.
eng_assert( 'px' === $saved['form_field_typography_font_size']['unit'], 'A control without the custom unit must fall back to px.' );
eng_assert( 16.0 === (float) $saved['form_field_typography_font_size']['size'], 'The mobile-safe 16px form field floor must be kept.' );

// Layout.
// Content width follows the five-context responsive foundation: the desktop base stays at 100% so the
// canvas is not boxed, and each smaller device gets its own px ceiling.
eng_assert( '%' === $saved['container_width']['unit'], 'Desktop base content width must stay at 100%.' );
eng_assert( 100.0 === (float) $saved['container_width']['size'], 'Desktop base content width must be 100.' );
eng_assert( 767.0 === (float) $saved['container_width_mobile']['size'], 'Mobile content width must be written.' );
eng_assert( 1440.0 === (float) $saved['container_width_laptop']['size'], 'Laptop content width must be written.' );
eng_assert( '0' === (string) $saved['container_padding']['top'], 'Global container padding must be zero.' );
eng_assert( 'custom' === $saved['space_between_widgets']['unit'], 'Widget gap must be fluid.' );

// Breakpoints: required ones present, pre-existing ones preserved.
eng_assert( in_array( 'viewport_laptop', $saved['active_breakpoints'], true ), 'An existing custom breakpoint must be preserved.' );
eng_assert( in_array( 'viewport_mobile', $saved['active_breakpoints'], true ), 'Mobile must be active.' );
eng_assert( 767 === $saved['viewport_mobile'], 'Mobile breakpoint value must be written.' );
eng_assert( 1024 === $saved['viewport_tablet'], 'Tablet breakpoint value must be written.' );

// Lightbox.
eng_assert( 'yes' === $saved['global_image_lightbox'], 'Lightbox must be enabled.' );
eng_assert( '' === $saved['lightbox_enable_share'], 'Share must be off for a commerce baseline.' );

// Custom CSS: managed block added, user CSS untouched.
$css = new ManagedCssBlock();
eng_assert( str_contains( $saved['custom_css'], '.client-rule' ), 'User CSS must survive.' );
eng_assert( $css->has_block( $saved['custom_css'] ), 'The managed token block must be added.' );
eng_assert( str_contains( $saved['custom_css'], '--cresco-fs-h1' ), 'Fluid tokens must be published.' );

/* ---------- Regression: slider round trip ----------
 * Elementor's slider default is [unit, size, sizes] and is merged into the stored value on read.
 * Writing a partial shape made every slider come back "different", which failed verification for
 * 37 controls on a real Kit while an unrealistic fake still passed. */

eng_assert( array_key_exists( 'sizes', $saved['h1_typography_font_size'] ), 'A slider must be written with the complete Elementor shape, including sizes.' );
eng_assert( array_key_exists( 'sizes', $saved['container_width'] ), 'container_width must carry the full slider shape.' );
eng_assert( array_key_exists( 'sizes', $saved['space_between_widgets'] ), 'The widget gap must carry the full slider shape.' );

$diff = new DiffEngine();
$stored_by_elementor = [ 'unit' => 'custom', 'size' => 'clamp(1rem,2vw,2rem)', 'sizes' => [] ];
$asked_for = [ 'unit' => 'custom', 'size' => 'clamp(1rem,2vw,2rem)' ];
eng_assert( $diff->satisfies( $stored_by_elementor, $asked_for ), 'A default key Elementor adds must not count as a difference.' );
eng_assert( ! $diff->equivalent( $stored_by_elementor, $asked_for ), 'Strict equality is what failed before; it must still be strict.' );
eng_assert( ! $diff->satisfies( [ 'unit' => 'px', 'size' => 5, 'sizes' => [] ], $asked_for ), 'A genuinely different value must still be detected.' );
eng_assert( ! $diff->satisfies( [ 'unit' => 'custom' ], $asked_for ), 'A missing declared key must be detected.' );

// Repeater rows gain default fields the same way.
eng_assert(
	$diff->satisfies(
		[ [ '_id' => 'a1', 'title' => 'Surface', 'color' => '#FFFFFF', 'addon_field' => 'x' ] ],
		[ [ '_id' => 'a1', 'title' => 'Surface', 'color' => '#FFFFFF' ] ]
	),
	'An extra repeater field written by an addon must not count as a difference.'
);
eng_assert(
	! $diff->satisfies(
		[ [ '_id' => 'a1', 'title' => 'Surface', 'color' => '#000000' ] ],
		[ [ '_id' => 'a1', 'title' => 'Surface', 'color' => '#FFFFFF' ] ]
	),
	'A changed repeater value must still be detected.'
);

/* ---------- Run 2 with the same input: NO_OP ---------- */

$cache2 = new FakeCache();
$second = ( new SiteSettingsEngine( $gateway, new DiffEngine(), $registry, $cache2 ) )->apply();

eng_assert( true === $second['success'], 'A second identical run must succeed.' );
eng_assert( 'no_op' === $second['status'], 'A second identical run must be a NO_OP, got ' . $second['status'] );
eng_assert( 1 === $gateway->saveCount, 'A NO_OP must not save again.' );
eng_assert( 0 === $cache2->count, 'A NO_OP must not clear the cache.' );

// Idempotency of the globals themselves.
$after = $gateway->settings();
eng_assert( count( $after['custom_colors'] ) === count( $saved['custom_colors'] ), 'A re-run must not add custom colours.' );
$titles = array_column( $after['custom_colors'], 'title' );
eng_assert( count( $titles ) === count( array_unique( $titles ) ), 'A re-run must not duplicate global colour titles.' );
eng_assert( 1 === substr_count( $after['custom_css'], ManagedCssBlock::START ), 'A re-run must not duplicate the managed CSS block.' );

/* ---------- Run 3: stable IDs even after the registry is lost ---------- */

$ids_before = [];
foreach ( $after['custom_colors'] as $row ) { $ids_before[ $row['title'] ] = $row['_id']; }

global $options;
$options = [];  // registry wiped, e.g. a fresh environment restored from a Kit export
$third = ( new SiteSettingsEngine( $gateway, new DiffEngine(), new OwnershipRegistry(), new FakeCache() ) )->apply();
eng_assert( 'no_op' === $third['status'], 'Losing the registry must not cause a rewrite; titles are adopted instead.' );

$after3 = $gateway->settings();
$ids_after = [];
foreach ( $after3['custom_colors'] as $row ) { $ids_after[ $row['title'] ] = $row['_id']; }
eng_assert( $ids_before === $ids_after, 'Global colour IDs must stay stable when the registry is rebuilt.' );

/* ---------- Verification failure rolls back ---------- */

$registry4 = fresh_registry();
$gateway4 = new FakeKitGateway( kit_controls(), [ 'system_colors' => [], 'custom_colors' => [], 'existing' => 'keep' ] );
$gateway4->corruptOnSave = [ 'h1_color' => '#BADBAD' ];
$cache4 = new FakeCache();
$failed = ( new SiteSettingsEngine( $gateway4, new DiffEngine(), $registry4, $cache4 ) )->apply();

eng_assert( false === $failed['success'], 'A corrupted save must fail.' );
eng_assert( 'verification_failed' === $failed['status'], 'Verification must catch a value Elementor changed.' );
eng_assert( 0 === $cache4->count, 'A failed transaction must not clear the cache.' );
eng_assert( 'keep' === $gateway4->settings()['existing'], 'Rollback must restore the snapshot.' );

// Rollback must report what happened, not just that something went wrong.
eng_assert( true === $failed['rollback']['attempted'], 'A failed verification must attempt rollback.' );
eng_assert( true === $failed['rollback']['success'], 'The rollback must be reported as successful.' );
eng_assert( 'success' === $failed['rollback']['status'], 'Rollback status must be machine-readable.' );

// The diagnostic must be enough to fix the problem without re-running.
$verification = $failed['verification'];
eng_assert( 'failed' === $verification['status'], 'Verification status must be failed.' );
eng_assert( $verification['scopeCount'] > 0, 'The verification scope must be reported.' );
eng_assert( 1 === $verification['mismatchCount'], 'Exactly the corrupted control must mismatch, got ' . $verification['mismatchCount'] );
eng_assert( $verification['matchedCount'] === $verification['scopeCount'] - 1, 'Every other control must have matched.' );

$mismatch = $verification['mismatches'][0];
foreach ( [ 'semanticPath', 'elementorControl', 'controlType', 'expectedRaw', 'actualRaw', 'expectedNormalized', 'actualNormalized', 'reason' ] as $field ) {
	eng_assert( array_key_exists( $field, $mismatch ), 'A mismatch must report ' . $field . '.' );
}
eng_assert( 'h1_color' === $mismatch['elementorControl'], 'The mismatch must name the Elementor control.' );
eng_assert( 'themeStyle.typography.h1.color' === $mismatch['semanticPath'], 'The mismatch must name the semantic property, got ' . $mismatch['semanticPath'] );
eng_assert( 'color' === $mismatch['controlType'], 'The mismatch must report the runtime control type.' );
eng_assert( '#BADBAD' === $mismatch['actualRaw'], 'The mismatch must show what Elementor actually stored.' );
eng_assert( '#0F172A' === $mismatch['expectedRaw'], 'The mismatch must show what was requested.' );
eng_assert( 'semantic_value_mismatch' === $mismatch['reason'], 'The mismatch must carry a machine-readable reason.' );
eng_assert( str_contains( $failed['log'], 'MISMATCH_COUNT: 1' ), 'The log must lead with the mismatch count.' );
eng_assert( str_contains( $failed['log'], 'h1_color' ), 'The log must name the mismatched control.' );

/* ---------- A refused save fails cleanly ---------- */

$gateway5 = new FakeKitGateway( kit_controls(), [ 'system_colors' => [] ] );
$gateway5->refuseSave = true;
$cache5 = new FakeCache();
$refused = ( new SiteSettingsEngine( $gateway5, new DiffEngine(), fresh_registry(), $cache5 ) )->apply();
eng_assert( false === $refused['success'], 'A refused save must fail.' );
eng_assert( 0 === $cache5->count, 'A refused save must not clear the cache.' );

/* ---------- Optional surfaces are skipped, not failed ---------- */

// Optional surfaces (Hello, Custom CSS, Lightbox) may be absent and must only produce skips. The
// responsive foundation is not optional, so this Kit keeps those controls — a runtime that cannot
// express the foundation is meant to fail closed, which is covered separately below.
$minimal_controls = array_intersect_key(
	kit_controls(),
	array_flip( [
		'system_colors', 'custom_colors', 'system_typography',
		'container_width', 'container_padding', 'active_breakpoints',
		'viewport_mobile', 'viewport_tablet', 'viewport_laptop', 'viewport_widescreen',
	] )
);
$gateway6 = new FakeKitGateway( $minimal_controls, [ 'system_colors' => [], 'custom_colors' => [] ] );
$minimal = ( new SiteSettingsEngine( $gateway6, new DiffEngine(), fresh_registry(), new FakeCache() ) )->apply();

eng_assert( true === $minimal['success'], 'A Kit without optional tabs must still succeed.' );
$skipped_keys = array_column( $minimal['skipped'], 'key' );
eng_assert( in_array( 'themeStyle.helloHeader', $skipped_keys, true ), 'Hello header must be skipped when not registered.' );
eng_assert( in_array( 'settings.customCss', $skipped_keys, true ), 'Custom CSS must be skipped when Pro is absent.' );
eng_assert( in_array( 'settings.lightbox', $skipped_keys, true ), 'Lightbox must be skipped when not registered.' );
foreach ( $minimal['skipped'] as $entry ) {
	eng_assert( '' !== (string) $entry['reason'], 'Every skip must carry a reason.' );
}
eng_assert( false === $minimal['capabilities']['helloHeader'], 'Capability discovery must report Hello header as absent.' );

/* ---------- Hello Theme applies when its controls exist ---------- */

$hello_controls = kit_controls();
$hello_controls['hello_header_layout'] = [ 'type' => 'select', 'options' => [ 'default' => 'Default' ] ];
$hello_controls['hello_header_logo_display'] = [ 'type' => 'switcher' ];
$hello_controls['hello_header_menu_display'] = [ 'type' => 'switcher' ];
$hello_controls['hello_header_width'] = [ 'type' => 'select', 'options' => [ 'boxed' => 'Boxed', 'full-width' => 'Full' ] ];
$hello_controls['hello_header_menu_color'] = [ 'type' => 'color' ];
$gateway7 = new FakeKitGateway( $hello_controls, [ 'system_colors' => [], 'custom_colors' => [] ] );
$hello = ( new SiteSettingsEngine( $gateway7, new DiffEngine(), fresh_registry(), new FakeCache() ) )->apply();

eng_assert( true === $hello['success'], 'A Hello-enabled Kit must apply.' );
eng_assert( 'yes' === $gateway7->saved['hello_header_logo_display'], 'Hello header logo display must be written.' );
eng_assert( 'boxed' === $gateway7->saved['hello_header_width'], 'Hello header width must be written.' );
eng_assert( '#0F172A' === $gateway7->saved['hello_header_menu_color'], 'Hello menu colour must resolve semantically.' );
eng_assert( true === $hello['capabilities']['helloHeader'], 'Capability discovery must report Hello header as present.' );

/* ---------- Spec validation ---------- */

$engine = new SiteSettingsEngine( new FakeKitGateway( kit_controls(), [ 'system_colors' => [] ] ), new DiffEngine(), fresh_registry(), new FakeCache() );
$bad_schema = $engine->apply( [ 'schema' => 'wrong', 'target' => 'elementor' ] );
eng_assert( false === $bad_schema['success'], 'A wrong schema must be rejected.' );

$bad_mode = $engine->apply( array_merge( Spec::skeleton(), [ 'mode' => 'destroy-everything' ] ) );
eng_assert( false === $bad_mode['success'], 'An unknown mode must be rejected.' );

$bad_section = $engine->apply( array_merge( Spec::skeleton(), [ 'somethingElse' => [] ] ) );
eng_assert( false === $bad_section['success'], 'An unknown spec section must be rejected.' );

/* ---------- Unavailable Kit fails cleanly ---------- */

$unavailable = new FakeKitGateway( [], [], 0, false );
$none = ( new SiteSettingsEngine( $unavailable, new DiffEngine(), fresh_registry(), new FakeCache() ) )->apply();
eng_assert( false === $none['success'], 'No writable Kit must fail.' );
eng_assert( 0 === $unavailable->saveCount, 'No writable Kit must never save.' );

/* ---------- Preview never writes ---------- */

$gateway8 = new FakeKitGateway( kit_controls(), [ 'system_colors' => [], 'custom_colors' => [] ] );
$cache8 = new FakeCache();
$preview = ( new SiteSettingsEngine( $gateway8, new DiffEngine(), fresh_registry(), $cache8 ) )->preview();
eng_assert( 'preview' === $preview['status'], 'Preview must report preview status.' );
eng_assert( 0 === $gateway8->saveCount, 'Preview must not save.' );
eng_assert( 0 === $cache8->count, 'Preview must not clear the cache.' );
eng_assert( ! empty( $preview['created'] ) || ! empty( $preview['updated'] ), 'Preview must list the pending changes.' );

/* ---------- Verification scope excludes what was never requested ---------- */

// Reproduces the reported environment: the Hello tab is registered, but this build of the theme does
// not expose the menu colour/typography controls. They must land in Skipped, not in Errors.
$partial_hello = $hello_controls;
unset( $partial_hello['hello_header_menu_color'] );
$scope_gateway = new FakeKitGateway( $partial_hello, [ 'system_colors' => [], 'custom_colors' => [] ] );
$scoped = ( new SiteSettingsEngine( $scope_gateway, new DiffEngine(), fresh_registry(), new FakeCache() ) )->apply();

eng_assert( true === $scoped['success'], 'Unsupported Hello controls must not fail the transaction: ' . describe_mismatches( $scoped ) );
eng_assert( 'pass' === $scoped['verification']['status'], 'Verification must pass when only unsupported controls were skipped.' );

$scoped_skips = array_column( $scoped['skipped'], 'key' );
eng_assert( in_array( 'themeStyle.helloHeader.menuColor', $scoped_skips, true ), 'An unsupported Hello control must appear as skipped.' );
foreach ( $scoped['verification']['matched'] as $path ) {
	eng_assert( ! str_contains( $path, 'helloHeader.menuColor' ), 'A skipped control must never enter the verification scope.' );
}

// Preserved values are reported separately and are not verified.
$preserved_keys = array_column( $scoped['preserved'], 'key' );
eng_assert( in_array( 'settings.layout.defaultPageTemplate', $preserved_keys, true ), 'Preserved layout values must be reported.' );
eng_assert( in_array( 'settings.layout.pageTitleSelector', $preserved_keys, true ), 'Every preserved value must be reported.' );
foreach ( $scoped['verification']['matched'] as $path ) {
	eng_assert( ! str_contains( $path, 'defaultPageTemplate' ), 'A preserved value must never enter the verification scope.' );
}
eng_assert( str_contains( $scoped['log'], 'SKIPPED_FROM_VERIFICATION' ), 'The log must separate skipped controls from failures.' );
eng_assert( str_contains( $scoped['log'], 'PRESERVED' ), 'The log must list preserved values.' );

/* ---------- Verify-only never writes ---------- */

$verify_gateway = new FakeKitGateway( kit_controls(), [ 'system_colors' => [], 'custom_colors' => [] ] );
$verify_cache = new FakeCache();
$verify_engine = new SiteSettingsEngine( $verify_gateway, new DiffEngine(), fresh_registry(), $verify_cache );

$before_apply = $verify_engine->verify_only();
eng_assert( 'verification_failed' === $before_apply['status'], 'An unconfigured Kit must not verify as matching the profile.' );
eng_assert( 0 === $verify_gateway->saveCount, 'Verify must not write.' );
eng_assert( 0 === $verify_cache->count, 'Verify must not clear the cache.' );
eng_assert( $before_apply['verification']['mismatchCount'] > 0, 'Verify must report the mismatches it found.' );

$verify_engine->apply();
$saves_after_apply = $verify_gateway->saveCount;
$after_apply = $verify_engine->verify_only();
eng_assert( 'verified' === $after_apply['status'], 'A configured Kit must verify: ' . describe_mismatches( $after_apply ) );
eng_assert( 'pass' === $after_apply['verification']['status'], 'Verification must pass after apply.' );
eng_assert( $saves_after_apply === $verify_gateway->saveCount, 'Verify must still not write after an apply.' );

/* ---------- Health reports the environment without writing ---------- */

$health_gateway = new FakeKitGateway( $hello_controls, [ 'system_colors' => [], 'custom_colors' => [] ] );
$health = ( new SiteSettingsEngine( $health_gateway, new DiffEngine(), fresh_registry(), new FakeCache() ) )->health();
eng_assert( true === $health['engineLoaded'], 'Health must report the engine.' );
eng_assert( true === $health['kitResolved'], 'Health must resolve the Kit.' );
eng_assert( 42 === $health['kitId'], 'Health must report the resolved Kit id.' );
eng_assert( 'elementor-classic' === $health['adapter'], 'Health must report the adapter.' );
eng_assert( 'professional-commerce' === $health['profileLoaded'], 'Health must report the profile.' );
eng_assert( true === $health['capabilities']['helloHeader'], 'Health must surface discovered capabilities.' );
eng_assert( 0 === $health_gateway->saveCount, 'Health must not write.' );

$offline = ( new SiteSettingsEngine( new FakeKitGateway( [], [], 0, false ), new DiffEngine(), fresh_registry(), new FakeCache() ) )->health();
eng_assert( false === $offline['kitResolved'], 'Health must report an unresolvable Kit.' );
eng_assert( false === $offline['adapterResolved'], 'Health must report that no adapter applies.' );

echo "Site settings engine contract tests passed.\n";
