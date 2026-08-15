<?php
require_once dirname( __DIR__, 2 ) . '/includes/AI/ExchangeSafetyGuard.php';

use CrescoLayer\AI\ExchangeSafetyGuard;

function exchange_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

function exchange_throws( callable $callback, string $needle, string $message ): void {
	try { $callback(); } catch ( \Throwable $error ) {
		exchange_assert( str_contains( $error->getMessage(), $needle ), $message . ' (got: ' . $error->getMessage() . ')' );
		return;
	}
	fwrite( STDERR, "FAIL: {$message} (nothing was thrown)\n" );
	exit( 1 );
}

$guard = new ExchangeSafetyGuard();
$package = $guard->decorate_package( [
	'schema' => 'cresco-layer-ai-package/v2',
	'editableScope' => [ 'mode' => 'subtree', 'rootElementId' => '3ed4781' ],
	'document' => [ 'content' => [ [ 'id' => '3ed4781', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ] ],
	'elementContext' => [],
	'elementStates' => [],
	'instructions' => 'Use the live Elementor runtime controls.',
] );

exchange_assert( ExchangeSafetyGuard::POLICY_SCHEMA === $package['exchangePolicy']['schema'], 'The export must declare the exchange safety policy.' );
exchange_assert( 'read-only-reference' === $package['exchangePolicy']['sourceContext']['mode'], 'Existing Elementor source context must be marked read-only.' );
exchange_assert( false === $package['exchangePolicy']['sourceContext']['echoBack'], 'Existing subtree data must not be echoed back as mutation output.' );
exchange_assert( 'delta-first' === $package['exchangePolicy']['mutationOutput']['strategy'], 'AI mutation output must default to delta-first.' );
exchange_assert( in_array( 'insert-element', $package['exchangePolicy']['mutationOutput']['preferredOperations'], true ), 'insert-element must be a preferred delta operation.' );
exchange_assert( in_array( 'replace-element', $package['exchangePolicy']['mutationOutput']['destructiveOperations'], true ), 'replace-element must be classified as destructive.' );
exchange_assert( '3ed4781' === $package['exchangePolicy']['mutationOutput']['appendParentId'], 'The selected root must be exposed as the default append parent.' );
exchange_assert( str_contains( $package['instructions'], 'READ-ONLY SOURCE CONTEXT' ), 'The AI briefing must explicitly separate source from mutation.' );
exchange_assert( str_contains( $package['instructions'], 'insert-element' ), 'The AI briefing must direct additions to insert-element.' );
exchange_assert( str_contains( $package['instructions'], 'replace-element is destructive' ), 'The AI briefing must call out destructive replacement.' );

ExchangeSafetyGuard::assert_no_placeholders( [
	'patch' => [
		'operations' => [
			[ 'operation' => 'insert-element', 'element' => [ 'settings' => [ 'title' => 'Safe UI' ] ] ],
		],
	],
] );

exchange_throws(
	fn() => ExchangeSafetyGuard::assert_no_placeholders( [ 'settings' => [ 'title' => '[TRUNCATED]' ] ] ),
	'[TRUNCATED]',
	'A truncated export placeholder must be blocked before import.'
);
exchange_throws(
	fn() => ExchangeSafetyGuard::assert_no_placeholders( [ 'settings' => [ 'token' => '[REDACTED]' ] ] ),
	'[REDACTED]',
	'A redacted export placeholder must be blocked before import.'
);
exchange_throws(
	fn() => ExchangeSafetyGuard::assert_no_placeholders( [ 'document' => [ '__cresco_truncated__' => true ] ] ),
	'truncated-export marker',
	'An array truncation marker must be blocked before import.'
);

echo "AI exchange safety guard tests passed.\n";
