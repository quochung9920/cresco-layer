<?php
$root = dirname( __DIR__, 2 );
$runtime = file_get_contents( $root . '/includes/Skills/WidgetSkillRuntime.php' );
$catalog = file_get_contents( $root . '/includes/Elementor/ConfigurationCatalog.php' );
$controller = file_get_contents( $root . '/includes/REST/Controller.php' );

if ( ! str_contains( $runtime, '$this->catalog->detail( $kind, $name )' ) ) {
	fwrite( STDERR, "FAIL: widget skill runtime must load detail only for the selected runtime type.\n" ); exit( 1 );
}
if ( ! str_contains( $runtime, '$this->catalog->runtime_breakpoints()' ) ) {
	fwrite( STDERR, "FAIL: widget skill runtime must use lightweight breakpoint lookup.\n" ); exit( 1 );
}
if ( str_contains( $runtime, '$this->catalog->summary()' ) ) {
	fwrite( STDERR, "FAIL: per-widget skill loading must not enumerate the full Elementor registry.\n" ); exit( 1 );
}
if ( ! str_contains( $catalog, 'public function runtime_breakpoints(): array' ) ) {
	fwrite( STDERR, "FAIL: configuration catalog is missing lightweight runtime breakpoints.\n" ); exit( 1 );
}
foreach ( [ '/skills/(?P<element>', 'widget_skills', 'resolve_widget_skill', 'WidgetSkillRuntime' ] as $token ) {
	if ( ! str_contains( $controller, $token ) ) { fwrite( STDERR, "FAIL: skill REST integration missing {$token}.\n" ); exit( 1 ); }
}

echo "Widget skill runtime hot-path contract tests passed.\n";
