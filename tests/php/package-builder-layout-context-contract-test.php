<?php
$path = dirname( __DIR__, 2 ) . '/includes/AI/PackageBuilder.php';
$source = file_get_contents( $path );
if ( false === $source ) { fwrite( STDERR, "FAIL: could not read PackageBuilder.\n" ); exit( 1 ); }
$checks = [
	"( new LayoutContextBuilder() )->build( \$elements, \$editable_ids )" => 'PackageBuilder must build layout context from the full document and editable scope.',
	"'layoutContext' => \$layout_context" => 'PackageBuilder must expose layoutContext in the AI package.',
	"'containerRoleAware' => true" => 'PackageBuilder capabilities must advertise container-role awareness.',
	'Global Elementor Container Padding carries the Cresco responsive clamp gutter' => 'AI instructions must explain the native global clamp baseline.',
	'structural content/nested containers should explicitly reset horizontal padding to zero' => 'AI instructions must explain nested gutter reset.',
	'inner content container owns vertical section rhythm' => 'AI instructions must assign vertical rhythm to content containers.',
];
foreach ( $checks as $needle => $message ) {
	if ( ! str_contains( $source, $needle ) ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}
echo "PASS: PackageBuilder layout context contract\n";
