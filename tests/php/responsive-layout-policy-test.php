<?php
$base = dirname(__DIR__, 2) . '/includes/';
require_once $base . 'SiteSettings/Layout/ResponsiveLayoutPolicy.php';
use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;
function p_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
p_assert([ 'mobile'=>767,'tablet'=>1024,'laptop'=>1440,'widescreen'=>1920 ] === ResponsiveLayoutPolicy::breakpoints(), 'breakpoints');
p_assert([ 'mobile'=>767,'tablet'=>960,'laptop'=>1180,'desktop'=>1320,'widescreen'=>1500 ] === ResponsiveLayoutPolicy::content_widths(), 'widths');
$contract = ResponsiveLayoutPolicy::layout_contract();
p_assert(false === $contract['preserveExistingBreakpoints'], 'exact contexts');
p_assert('block-if-used' === $contract['breakpointMigrationPolicy'], 'migration policy');
p_assert('native-custom-unit-when-supported' === $contract['globalFluidStrategy'], 'native global fluid strategy');
foreach ($contract['containerPadding'] as $device => $padding) {
	p_assert(isset($padding['fluid'],$padding['fallbackPx']), "$device global padding fluid");
	p_assert($padding === $contract['pageGutter'][$device], "$device global padding mirrors gutter");
	p_assert(!isset($padding['fixedPx']), "$device global padding not zeroed");
}
p_assert('clamp(48px, 3vw, 80px)' === $contract['pageGutter']['widescreen']['fluid'], 'widescreen gutter');
echo "PASS: responsive layout policy\n";
