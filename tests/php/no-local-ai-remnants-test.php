<?php
$root = dirname( __DIR__, 2 );
$errors = [];

$removed_paths = [
	'includes/LocalAI',
	'assets/local-ai-admin.js',
	'assets/local-ai-admin.css',
	'assets/semantic-ai.js',
	'assets/semantic-ai.css',
	'docs/LOCAL-AI.md',
	'docs/SEMANTIC-AI.md',
	'docs/ACCURACY-CORE.md',
	'tests/js/local-ai-admin-contract-test.mjs',
	'tests/js/semantic-ai-contract-test.mjs',
	'tests/php/local-ai-contract-test.php',
	'tests/php/semantic-ai-context-test.php',
	'tests/php/semantic-plan-validator-test.php',
	'tests/php/accuracy-core-test.php',
];

foreach ( $removed_paths as $relative ) {
	if ( file_exists( $root . '/' . $relative ) ) {
		$errors[] = 'Removed Local AI path still exists: ' . $relative;
	}
}

$forbidden_tokens = [
	'CrescoLayer\\LocalAI',
	'cresco-layer-local-ai',
	'/local-ai/',
	'localAI',
	'local-ai-admin.js',
	'local-ai-admin.css',
	'semantic-ai.js',
	'semantic-ai.css',
];

foreach ( [ 'includes', 'assets' ] as $directory ) {
	$base = $root . '/' . $directory;
	if ( ! is_dir( $base ) ) { continue; }
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) { continue; }
		$source = file_get_contents( $file->getPathname() );
		foreach ( $forbidden_tokens as $token ) {
			if ( str_contains( $source, $token ) ) {
				$errors[] = str_replace( $root . '/', '', $file->getPathname() ) . ' still references removed Local AI token: ' . $token;
			}
		}
	}
}

$package = file_get_contents( $root . '/package.json' );
foreach ( [ 'test:local-ai-admin', 'test:semantic-ai', 'assets/local-ai-admin.js', 'assets/semantic-ai.js' ] as $token ) {
	if ( str_contains( $package, $token ) ) { $errors[] = 'package.json still references removed Local AI token: ' . $token; }
}

$workflow = file_get_contents( $root . '/.github/workflows/ci.yml' );
foreach ( [ 'local-ai-contract-test.php', 'semantic-ai-context-test.php', 'semantic-plan-validator-test.php', 'accuracy-core-test.php' ] as $token ) {
	if ( str_contains( $workflow, $token ) ) { $errors[] = 'CI still references removed Local AI test: ' . $token; }
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

echo "Cresco Layer Local AI removal invariants verified.\n";
