<?php
$base = dirname(__DIR__, 2) . '/includes/';
require_once $base . 'SiteSettings/Layout/ResponsiveLayoutPolicy.php';
use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;
function p_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
p_assert([ 'mobile'=>767,'tablet'=>1024,'laptop'=>1440,'widescreen'=>1920 ] === ResponsiveLayoutPolicy::breakpoints(), 'breakpoints');
$widths = ResponsiveLayoutPolicy::content_widths();
p_assert([ 'unit'=>'px','size'=>767 ] === $widths['mobile'], 'mobile canvas width');
p_assert([ 'unit'=>'px','size'=>1024 ] === $widths['tablet'], 'tablet canvas width');
p_assert([ 'unit'=>'px','size'=>1440 ] === $widths['laptop'], 'laptop canvas width');
p_assert([ 'unit'=>'%','size'=>100 ] === $widths['desktop'], 'desktop base canvas');
p_assert(1920 === $widths['widescreen']['size'] && 'custom' === $widths['widescreen']['overflowUnit'], 'widescreen canvas and overflow policy');
p_assert(1500 === $widths['widescreen']['nativeMaxPxHint'], 'widescreen conservative native range hint');
$contract = ResponsiveLayoutPolicy::layout_contract();
p_assert(false === $contract['preserveExistingBreakpoints'], 'exact contexts');
p_assert('block-if-used' === $contract['breakpointMigrationPolicy'], 'migration policy');
p_assert('native-custom-unit-when-supported' === $contract['globalFluidStrategy'], 'native global fluid strategy');
p_assert('canvas-aligned-native-with-custom-overflow' === $contract['contentWidthStrategy'], 'canvas aligned width strategy');
p_assert('desktop-4k' === $contract['deviceIntent']['widescreen'], 'widescreen hardware intent');
p_assert('100%' === ResponsiveLayoutPolicy::content_width_css_values()['desktop'], 'desktop css mirror');
p_assert('1920px' === ResponsiveLayoutPolicy::content_width_css_values()['widescreen'], 'widescreen css mirror');
foreach ($contract['containerPadding'] as $device => $padding) {
	p_assert(isset($padding['fluid'],$padding['fallbackPx']), "$device global padding fluid");
	p_assert($padding === $contract['pageGutter'][$device], "$device global padding mirrors gutter");
	p_assert(!isset($padding['fixedPx']), "$device global padding not zeroed");
}
echo "PASS: responsive layout policy\n";
