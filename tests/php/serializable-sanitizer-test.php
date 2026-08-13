<?php
require_once dirname( __DIR__, 2 ) . '/includes/Support/SerializableSanitizer.php';

use CrescoLayer\Support\SerializableSanitizer;

function sanitizer_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

final class SerializableSnapshotFixture implements JsonSerializable {
	public function jsonSerialize(): array {
		return [
			'name' => 'fixture',
			'client_secret' => 'super-secret',
			'nested' => [ 'enabled' => true ],
		];
	}
}

final class RuntimeOnlySnapshotFixture {
	public string $value = 'must-not-leak-through-object-introspection';
}

$sanitizer = new SerializableSanitizer();
$input = [
	'api_key' => 'abc123',
	'nested' => [
		'password' => 'pw',
		'url' => 'https://example.test/callback?access_token=token-value&mode=1',
		'authorization_header' => 'Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature',
	],
	'json' => new SerializableSnapshotFixture(),
	'std' => (object) [ 'safe' => 'yes', 'webhook_secret' => 'nope' ],
	'runtime' => new RuntimeOnlySnapshotFixture(),
	'scalar' => 42,
];

$output = $sanitizer->sanitize( $input );
$report = $sanitizer->report();

sanitizer_assert( '[REDACTED]' === $output['api_key'], 'API keys must be redacted by key.' );
sanitizer_assert( '[REDACTED]' === $output['nested']['password'], 'Passwords must be redacted by key.' );
sanitizer_assert( false !== strpos( $output['nested']['url'], 'access_token=[REDACTED]' ), 'Token-bearing URL query parameters must be redacted.' );
sanitizer_assert( '[REDACTED]' === $output['nested']['authorization_header'], 'Authorization values must be redacted by key.' );
sanitizer_assert( '[REDACTED]' === $output['json']['client_secret'], 'JsonSerializable secrets must be redacted recursively.' );
sanitizer_assert( true === $output['json']['nested']['enabled'], 'JsonSerializable safe data must be preserved.' );
sanitizer_assert( 'yes' === $output['std']['safe'], 'stdClass serializable fields must be preserved.' );
sanitizer_assert( '[REDACTED]' === $output['std']['webhook_secret'], 'stdClass secrets must be redacted.' );
sanitizer_assert( ! array_key_exists( 'runtime', $output ), 'Unsupported runtime objects must be omitted.' );
sanitizer_assert( 42 === $output['scalar'], 'Scalar values must be preserved.' );
sanitizer_assert( count( $report['redactions'] ) >= 5, 'Redaction report must list redacted paths.' );
sanitizer_assert( count( $report['omissions'] ) >= 1, 'Omission report must list unsupported runtime objects.' );

$hasRuntimeOmission = false;
foreach ( $report['omissions'] as $omission ) {
	if ( false !== strpos( $omission, 'runtime-object:RuntimeOnlySnapshotFixture' ) ) { $hasRuntimeOmission = true; break; }
}
sanitar_assert_placeholder;
