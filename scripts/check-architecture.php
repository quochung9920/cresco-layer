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
foreach ( $php as $path ) {
	if ( realpath( $path ) === __FILE__ ) { continue; }
	$all .= "\n" . file_get_contents( $path );
}

$forbidden = [
	"update_post_meta( \$post_id, '_elementor_data'" => 'Do not persist Elementor document data directly; use Elementor Document::save().',
	"update_post_meta(\$post_id, '_elementor_data'" => 'Do not persist Elementor document data directly; use Elementor Document::save().',
	'eval(' => 'eval() is forbidden.',
	'shell_exec(' => 'shell_exec() is forbidden.',
	'exec(' => 'exec() is forbidden.',
];
foreach ( $forbidden as $needle => $message ) {
	if ( str_contains( $all, $needle ) ) { $errors[] = $message; }
}

$required = [
	'includes/AI/PackageBuilder.php' => [
		'cresco-layer-ai-package/v2', 'scopeChecksum', 'widgetCatalog', 'elementCatalog', 'dynamicTags',
		'ContextResolver', 'contextProfile', 'registryIndex', 'capabilityCoverage', 'fullRuntimeSnapshotIsSeparate',
		'SerializableSanitizer', 'Never invent a setting name.',
	],
	'includes/AI/ContextResolver.php' => [
		'cresco-context-resolver/v1', "PROFILE_SMART = 'smart'", "PROFILE_FULL = 'full'", 'registryIndex',
		'capabilityCoverage', 'insertion-candidate', 'globalDesignSystem', 'dependency_map',
	],
	'includes/AI/ElementLocator.php' => [ "'document', 'widget', 'selection', 'subtree'", 'scope_checksum', 'find_context' ],
	'includes/AI/CapabilityScanner.php' => [ 'defaultSettings', 'size_units', 'selectors_dictionary', 'frontend_available', 'catalog_index', 'catalog_entry', 'scanErrors', 'MAX_ARRAY_ITEMS', 'get_atomic_controls', 'get_props_schema' ],
	'includes/AI/PatchValidator.php' => [ 'cresco-layer-patch/v1', 'replace-element', 'replace-document', 'MAX_OPERATIONS', 'Sensitive settings cannot be modified' ],
	'includes/AI/SemanticPatchGuard.php' => [ 'inert-css-variable', 'custom-css-native-control', 'unknown-setting', 'verify_data', 'nativeControlOperations' ],
	'includes/AI/PatchApplier.php' => [ 'assert_scope_operations', 'staleDocumentButScopeUnchanged', 'preserve_children', 'DocumentChecksum::hash', 'get_with_permissions' ],
	'includes/Elementor/ConfigurationCatalog.php' => [ 'catalog_index', 'lazyDetails', 'activeKit', 'scanErrors', "'[REDACTED]'" ],
	'includes/Elementor/RuntimeSnapshot.php' => [ 'cresco-elementor-snapshot/v1', 'global-settings', 'features', 'active-kit', 'dynamic-tags', 'runtime', 'records', 'normalized', 'raw', 'downloadPlan', 'registryEntry', 'recordIndex', 'ElementorPro' ],
	'includes/Elementor/RuntimeDiscovery.php' => [
		'dynamic_tag_catalog', "['instance']", 'get_tags()', 'get_modules_names()', 'get_modules( $name )',
		'conditionalCapabilities', 'dependency-inactive', 'dynamic-tags-empty', 'dynamic_tags_snapshot', 'runtime_snapshot',
	],
	'includes/Elementor/RuntimeSnapshotCoordinator.php' => [ 'RuntimeSnapshot::SCHEMA', 'runtimeDiscoveryVersion', 'section-aware-v2', "'dynamic-tags'", "'runtime'" ],
	'includes/Skills/SkillCompiler.php' => [
		'cresco-layer-widget-skills/v1', 'cresco-layer-skill-resolution/v1', 'compile_control', 'safe_prerequisite_operations',
		'normalize_command_text', 'spacing.padding', 'responsive.visibility', 'atomic-control',
	],
	'includes/Skills/WidgetSkillRuntime.php' => [
		'ConfigurationCatalog', 'ExpertProfiles::for', 'liveSettings', 'runtimeValidated', 'usesNativeControl',
		'No chatbot or LLM is involved in skill resolution.',
	],
	'includes/Skills/ExpertProfiles.php' => [
		'runtime-derived+expert-hints', 'container-layout', 'forms', 'query-loop', 'commerce', 'atomic-v4',
	],
	'includes/Support/SerializableSanitizer.php' => [ "'[REDACTED]'", 'redactions', 'omissions', 'JsonSerializable', 'runtime-object', 'access[_-]?token' ],
	'includes/REST/Controller.php' => [
		"current_user_can( 'edit_post'", 'expectedScope', '/preview', '/apply', '/elementor-catalog/(?P<kind>widget|element)',
		'elementor_catalog_detail', "'lazy-v2'", 'semanticPatchValidation', 'postApplyVerification', '/elementor-snapshot',
		'elementor_snapshot_section', 'elementor_snapshot_registry', 'elementor_snapshot_record', 'manage_options',
		"'context' =>", "'aiContextResolver' => 'smart-v1'", "'dynamicTagDiscovery' => 'registry-info-v2'", "'elementorProModuleDiscovery' => 'named-modules-v2'",
		'/skills/(?P<element>', 'widget_skills', 'resolve_widget_skill', "'widgetSkillRuntime' => 'runtime-v1'", 'deterministicSkillCommands',
	],
	'includes/Admin/AdminPage.php' => [ 'Elementor Configuration & Full Runtime Snapshot', 'cresco-layer-catalog-load', 'cresco-layer-catalog-query', 'cresco-layer-snapshot-download', 'Download full Elementor snapshot', '/elementor-snapshot/section/', '/elementor-snapshot/record/' ],
	'includes/Support/Assets.php' => [ 'elementor/editor/after_enqueue_scripts', 'cresco-layer-editor', 'cresco-layer-skills', 'assets/skills.js', 'assets/skills.css' ],
	'includes/Audit/Auditor.php' => [ 'missing-alt', 'multiple-h1', 'large-dom' ],
];
foreach ( $required as $relative => $tokens ) {
	$path = $root . '/' . $relative;
	if ( ! is_file( $path ) ) { $errors[] = 'Missing required file: ' . $relative; continue; }
	$content = file_get_contents( $path );
	foreach ( $tokens as $token ) { if ( ! str_contains( $content, $token ) ) { $errors[] = $relative . ' missing architecture token: ' . $token; } }
}

$packageBuilder = $root . '/includes/AI/PackageBuilder.php';
if ( is_file( $packageBuilder ) && str_contains( file_get_contents( $packageBuilder ), '$this->scanner->catalog();' ) ) {
	$errors[] = 'Normal AI exports must not scan and embed the full detailed Elementor catalog; use ContextResolver.';
}

$runtimeDiscovery = $root . '/includes/Elementor/RuntimeDiscovery.php';
if ( is_file( $runtimeDiscovery ) && str_contains( file_get_contents( $runtimeDiscovery ), '$manager->get_modules() as' ) ) {
	$errors[] = 'Elementor module discovery must call get_modules(name), not no-argument get_modules().';
}

$editor_js = $root . '/assets/editor.js';
if ( ! is_file( $editor_js ) || ! str_contains( file_get_contents( $editor_js ), 'elements/context-menu/groups' ) ) {
	$errors[] = 'Elementor editor-native scoped AI exchange is missing.';
}

$skills_js = $root . '/assets/skills.js';
if ( ! is_file( $skills_js ) ) {
	$errors[] = 'Elementor deterministic widget skill palette is missing.';
} else {
	$skills_source = file_get_contents( $skills_js );
	foreach ( [ 'deterministic-widget-skills-v1', 'usesChatbot = false', 'document/elements/settings', 'document/history/start-log', '/skills/', 'liveSettings' ] as $token ) {
		if ( ! str_contains( $skills_source, $token ) ) { $errors[] = 'assets/skills.js missing skill runtime token: ' . $token; }
	}
	if ( preg_match( '/(?:openai|anthropic|chatgpt|completion|messages\/create)/i', $skills_source ) ) {
		$errors[] = 'Deterministic widget skill runtime must not embed a chatbot/LLM provider.';
	}
}

$admin_js = $root . '/assets/admin.js';
if ( ! is_file( $admin_js ) || ! str_contains( file_get_contents( $admin_js ), '/elementor-catalog/' ) || ! str_contains( file_get_contents( $admin_js ), 'loadCatalogDetail' ) || ! str_contains( file_get_contents( $admin_js ), 'downloadFullCatalog' ) ) {
	$errors[] = 'Lazy Elementor runtime configuration catalog inspector is missing.';
}

if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}
echo "Cresco Layer architecture invariants verified.\n";
