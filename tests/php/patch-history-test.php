<?php
/**
 * PatchHistory bounding + rollback contract.
 *
 * Elementor documents are large, so the history store must stay bounded in both entry count and total
 * bytes. An unbounded store would grow the post meta row until the database refuses the write, which
 * would break saving the page itself — a far worse failure than losing old undo points.
 */

$post_meta = [];

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		global $post_meta;
		return $post_meta[ $post_id ][ $key ] ?? ( $single ? '' : [] );
	}
	function update_post_meta( $post_id, $key, $value ) {
		global $post_meta;
		$post_meta[ $post_id ][ $key ] = $value;
		return true;
	}
	function delete_post_meta( $post_id, $key ) {
		global $post_meta;
		unset( $post_meta[ $post_id ][ $key ] );
		return true;
	}
	function get_current_user_id() { return 7; }
	function wp_get_current_user() { return new class { public function exists() { return true; } public $display_name = 'Reviewer'; }; }
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
}

require_once dirname( __DIR__, 2 ) . '/includes/AI/PatchHistory.php';

use CrescoLayer\AI\PatchHistory;

function history_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$history = new PatchHistory();

// A normal patch is recorded with a restorable snapshot.
$id = $history->record( 10, [ 'label' => 'Upgrade hero', 'operations' => 3, 'storage' => 'draft-document' ], [ [ 'id' => 'a1', 'elType' => 'container' ] ], [ 'title' => 'x' ] );
history_assert( '' !== $id, 'record() must return an entry id.' );

$entry = $history->get( 10, $id );
history_assert( null !== $entry, 'Recorded entry must be retrievable.' );
history_assert( true === $entry['restorable'], 'A small snapshot must be restorable.' );
history_assert( 'a1' === $entry['snapshot']['elements'][0]['id'], 'Snapshot must preserve the pre-patch elements.' );
history_assert( 'Reviewer' === $entry['userName'], 'Entry must attribute the acting user.' );

// The listing endpoint must never ship snapshots to the browser.
$listed = $history->all( 10 );
history_assert( 1 === count( $listed ), 'Listing must contain the recorded entry.' );
history_assert( ! array_key_exists( 'snapshot', $listed[0] ), 'Listing must not expose raw document snapshots.' );
history_assert( true === $listed[0]['restorable'], 'Listing must still report restorability.' );

// Entry count is bounded, newest kept.
for ( $i = 0; $i < 30; $i++ ) {
	$history->record( 11, [ 'label' => 'patch ' . $i, 'operations' => 1 ], [ [ 'id' => 'e' . $i ] ], [] );
}
$bounded = $history->all( 11 );
history_assert( 20 === count( $bounded ), 'History must be capped at 20 entries, got ' . count( $bounded ) . '.' );
history_assert( 'patch 29' === $bounded[0]['label'], 'Newest entry must be listed first.' );
history_assert( 'patch 10' === $bounded[19]['label'], 'Oldest retained entry must be the 20th newest.' );

// A snapshot larger than the per-entry budget is recorded for audit but marked unrestorable.
$huge = [];
for ( $i = 0; $i < 40000; $i++ ) { $huge[] = [ 'id' => 'node' . $i, 'settings' => [ 'text' => str_repeat( 'x', 60 ) ] ]; }
$huge_id = $history->record( 12, [ 'label' => 'Giant document', 'operations' => 1 ], $huge, [] );
$huge_entry = $history->get( 12, $huge_id );
history_assert( false === $huge_entry['restorable'], 'An oversized snapshot must not be marked restorable.' );
history_assert( null === $huge_entry['snapshot'], 'An oversized snapshot must not be stored.' );
history_assert( 'Giant document' === $huge_entry['label'], 'The audit record must survive even without a snapshot.' );

// Total-bytes budget: each snapshot below fits the per-entry cap (~1 MB), but 15 of them exceed the
// 8 MB whole-history budget, so the oldest must lose their snapshots while keeping their audit rows.
$mid = [];
for ( $i = 0; $i < 20000; $i++ ) { $mid[] = [ 'id' => 'm' . $i, 'settings' => [ 't' => str_repeat( 'x', 20 ) ] ]; }
for ( $i = 0; $i < 15; $i++ ) { $history->record( 13, [ 'label' => 'mid ' . $i, 'operations' => 1 ], $mid, [] ); }
$mid_entries = $history->all( 13 );
$restorable_count = count( array_filter( $mid_entries, static fn( array $e ): bool => ! empty( $e['restorable'] ) ) );
history_assert( 15 === count( $mid_entries ), 'All 15 audit records must remain.' );
history_assert( $restorable_count < 15, 'The total byte budget must drop snapshots from the oldest entries.' );
history_assert( ! empty( $mid_entries[0]['restorable'] ), 'The newest entry must keep its snapshot.' );

$history->clear( 13 );
history_assert( [] === $history->all( 13 ), 'clear() must remove the whole history.' );

echo "Patch history contract tests passed.\n";
