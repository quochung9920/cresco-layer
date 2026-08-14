<?php
/**
 * Diff::details contract — the preview must show what changes, not just how much, and must never echo
 * a credential-like setting value back into the browser.
 */

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
}

require_once dirname( __DIR__, 2 ) . '/includes/Support/SerializableSanitizer.php';
require_once dirname( __DIR__, 2 ) . '/includes/AI/Diff.php';

use CrescoLayer\AI\Diff;

function diff_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$elements = [
	[
		'id' => 'hero1',
		'elType' => 'container',
		'settings' => [ 'padding' => '10px', 'api_key' => 'sk-live-secret-value' ],
		'elements' => [
			[ 'id' => 'head1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Old title' ] ],
		],
	],
];
$page_settings = [ 'page_title' => 'Before' ];

$operations = [
	[ 'operation' => 'update-setting', 'elementId' => 'head1', 'setting' => 'title', 'value' => 'New title' ],
	[ 'operation' => 'update-setting', 'elementId' => 'hero1', 'setting' => 'padding', 'value' => '10px' ],
	[ 'operation' => 'remove-setting', 'elementId' => 'hero1', 'setting' => 'padding' ],
	[ 'operation' => 'update-page-setting', 'setting' => 'page_title', 'value' => 'After' ],
	[ 'operation' => 'update-setting', 'elementId' => 'hero1', 'setting' => 'api_key', 'value' => 'sk-live-new-secret' ],
];

$result = Diff::details( $operations, $elements, $page_settings );
$items  = $result['items'];

diff_assert( 5 === count( $items ), 'Every operation must produce a diff row.' );
diff_assert( false === $result['truncated'], 'A short patch must not report truncation.' );

// Resolves the real previous value from the document, and names the widget.
diff_assert( 'Old title' === $items[0]['oldValue'], 'Old value must be resolved from the current document.' );
diff_assert( 'New title' === $items[0]['newValue'], 'New value must come from the operation.' );
diff_assert( 'heading' === $items[0]['widgetType'], 'Row must identify the widget type.' );
diff_assert( true === $items[0]['changed'], 'A real change must be flagged as changed.' );

// A write of the identical value is surfaced as a no-op rather than a change.
diff_assert( false === $items[1]['changed'], 'Writing the same value must be reported as a no-op.' );

// Removal shows what disappears.
diff_assert( '10px' === $items[2]['oldValue'], 'Removal must show the value being removed.' );
diff_assert( null === $items[2]['newValue'], 'Removal must have no new value.' );

// Page settings resolve against page settings, not elements.
diff_assert( 'Before' === $items[3]['oldValue'], 'Page setting old value must come from page settings.' );
diff_assert( 'After' === $items[3]['newValue'], 'Page setting new value must come from the operation.' );

// Credential-like keys must be redacted on BOTH sides before reaching the browser.
diff_assert( ! str_contains( (string) $items[4]['oldValue'], 'sk-live-secret-value' ), 'Existing secret value leaked into the diff.' );
diff_assert( ! str_contains( (string) $items[4]['newValue'], 'sk-live-new-secret' ), 'Incoming secret value leaked into the diff.' );

// Bounded output: a huge patch must be cut off rather than serialized in full.
$many = [];
for ( $i = 0; $i < 500; $i++ ) { $many[] = [ 'operation' => 'update-setting', 'elementId' => 'head1', 'setting' => 'title', 'value' => 'v' . $i ]; }
$big = Diff::details( $many, $elements, $page_settings );
diff_assert( true === $big['truncated'], 'An oversized patch must report truncation.' );
diff_assert( count( $big['items'] ) <= 200, 'Diff detail rows must be capped.' );
diff_assert( 500 === $big['total'], 'Truncated output must still report the true operation count.' );

// Long values are clipped so one setting cannot dominate the response.
$long = Diff::details(
	[ [ 'operation' => 'update-setting', 'elementId' => 'head1', 'setting' => 'title', 'value' => str_repeat( 'y', 5000 ) ] ],
	$elements,
	$page_settings
);
// 600 characters plus a single "…" marker, which is 3 bytes in UTF-8.
$clipped = (string) $long['items'][0]['newValue'];
diff_assert( strlen( $clipped ) <= 603, 'Long values must be clipped, got ' . strlen( $clipped ) . ' bytes.' );
diff_assert( str_ends_with( $clipped, '…' ), 'A clipped value must be marked as truncated.' );

echo "Diff details contract tests passed.\n";
