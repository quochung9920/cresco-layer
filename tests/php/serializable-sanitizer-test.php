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
sanitar_assert_placeholder;
