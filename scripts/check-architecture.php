<?php
$root = dirname( __DIR__ );
$errors = [];
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$php = [];
foreach ( $files as $file ) {
	$path = $file->getPathname();
	if ( str_contains( $path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR ) || str_contains( $path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR ) ) { continue; }
	if ( 'php' === strtolower( $file->getExtension() ) ) { $php[] = $path; }
}
$all = '';
foreach ( $php as $path ) { $all .= "\n" . file_get_contents( $path ); }

$forbidden = [
	"update_post_meta( $post_id, '_elementor_data'" => 'Do not persist Elementor document data directly; use Elementor Document::save().',
	"update_post_meta($post_id, '_elementor_data'" => 'Do not persist Elementor document data directly; use Elementor Document::save().',
	'eval(' => 'eval() is forbidden.',
	'shell_exec(' => 'shell_exec() is forbidden.',
	'exec(' => 'exec() is forbidden.',
];
foreach ( $forbidden as $needle => $message ) {
	if ( str_contains( $all, $needle ) ) { $errors[] = $message; }
}

$required = [
	'includes/AI/PackageBuilder.php' => [ 'cresco-layer-ai-package/v1', 'baseChecksum', "'[REDACTED]'" ],
	'includes/AI/PatchValidator.php' => [ 'cresco-layer-patch/v1', 'MAX_OPERATIONS', 'Sensitive settings cannot be modified' ],
	'includes/AI/PatchApplier.php' => [ 'DocumentChecksum::hash', 'documents->get_with_permissions', "'elements' => \$candidate_elements" ],
	'includes/REST/Controller.php' => [ "current_user_can( 'edit_post'", '/preview', '/apply' ],
	'includes/Audit/Auditor.php' => [ 'missing-alt', 'multiple-h1', 'large-dom' ],
];
foreach ( $required as $relative => $tokens ) {
	$path = $root . '/' . $relative;
	if ( ! is_file( $path ) ) { $errors[] = 'Missing required file: ' . $relative; continue; }
	$content = file_get_contents( $path );
	foreach ( $tokens as $token ) { if ( ! str_contains( $content, $token ) ) { $errors[] = $relative . ' missing architecture token: ' . $token; } }
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}
echo "Cresco Layer architecture invariants verified.\n";
