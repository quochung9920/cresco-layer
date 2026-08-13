<?php
require_once dirname( __DIR__, 2 ) . '/includes/Support/SerializableSanitizer.php';

use CrescoLayer\Support\SerializableSanitizer;

function assert_snapshot( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

final class SnapshotJsonFixture implements JsonSerializable {
	public function jsonSerialize(): array {
		return [ 'name' => 'fixture', 'client_secret' => 'secret', 'enabled' => true ];
	}
}

final class SnapshotRuntimeFixture {
	public string $value = 'runtime-only';
}

$sanitizer = new SerializableSanitizer();
$output = $sanitizer->sanitize( [
	'api_key' => 'abc123',
	'password' => 'pw',
	'url' => 'https://example.test/?access_token=token-value&mode=1',
	'authorization' => 'Bearer token-value',
	'json' => new SnapshotJsonFixture(),
	'std' => (object) [ 'safe' => 'yes', 'webhook_secret' => 'hidden' ],
	'runtime' => new SnapshotRuntimeFixture(),
	'number' => 42,
] );
$report = $sanitizer->report();

assert_snapshot( '[REDACTED]' === $output['api_key'], 'API key redaction failed.' );
assert_snapshot( '[REDACTED]' === $output['password'], 'Password redaction failed.' );
assert_snapshot( false !== strpos( $output['url'], 'access_token=[REDACTED]' ), 'Token-bearing URL redaction failed.' );
assert_snapshot( '[REDACTED]' === $output['authorization'], 'Authorization redaction failed.' );
assert_snapshot( '[REDACTED]' === $output['json']['client_secret'], 'JsonSerializable nested redaction failed.' );
assert_snapshot( true === $output['json']['enabled'], 'JsonSerializable safe data was lost.' );
assert_snapshot( 'yes' === $output['std']['safe'], 'stdClass safe data was lost.' );
assert_snapshot( '[REDACTED]' === $output['std']['webhook_secret'], 'stdClass nested redaction failed.' );
assert_snapshot( ! array_key_exists( 'runtime', $output ), 'Unsupported runtime object must be omitted.' );
assert_snapshot( 42 === $output['number'], 'Scalar value changed unexpectedly.' );
assert_snapshot( count( $report['redactions'] ) >= 5, 'Redaction paths were not reported.' );
assert_snapshot( false !== strpos( implode( "\n", $report['omissions'] ), 'runtime-object:SnapshotRuntimeFixture' ), 'Runtime object omission was not reported.' );

echo "Serializable sanitizer tests passed.\n";
