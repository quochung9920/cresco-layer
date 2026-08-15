<?php
$base = dirname(__DIR__, 2) . '/includes/';
require_once $base . 'SiteSettings/Layout/ResponsiveLayoutPolicy.php';
require_once $base . 'SiteSettings/Discovery/RuntimeControlResolver.php';
require_once $base . 'SiteSettings/Validation/ResponsiveFoundationValidator.php';
use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;
use CrescoLayer\SiteSettings\Discovery\RuntimeControlResolver;
use CrescoLayer\SiteSettings\Validation\ResponsiveFoundationValidator;
function v_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$controls = [
  'active_breakpoints'=>['type'=>'select2'],
  'viewport_mobile'=>['type'=>'number'], 'viewport_tablet'=>['type'=>'number'], 'viewport_laptop'=>['type'=>'number'], 'viewport_widescreen'=>['type'=>'number'],
  'container_width'=>['type'=>'slider','is_responsive'=>true,'size_units'=>['px','custom'],'range'=>['px'=>['min'=>300,'max'=>1500]]],
  'container_padding'=>['type'=>'dimensions','is_responsive'=>true,'size_units'=>['px','custom']],
];
$spec = [ 'settings'=>[ 'layout'=>ResponsiveLayoutPolicy::layout_contract() ] ];
$validator = new ResponsiveFoundationValidator(new RuntimeControlResolver($controls));
$ok = $validator->validate($spec);
v_assert(true === $ok['compatible'], 'valid foundation compatible');
v_assert(0 === $ok['errorCount'], 'no errors');
$broken = $spec;
$broken['settings']['layout']['contentWidthPx']['widescreen'] = 1600;
$bad = $validator->validate($broken);
v_assert(false === $bad['compatible'], 'range violation incompatible');
$codes = array_column($bad['errors'], 'code');
v_assert(in_array('content_width_above_control_range', $codes, true), 'explicit range violation reported');
$missing_controls = $controls; unset($missing_controls['viewport_widescreen']);
$missing = (new ResponsiveFoundationValidator(new RuntimeControlResolver($missing_controls)))->validate($spec);
v_assert(false === $missing['compatible'], 'missing required Widescreen incompatible');
v_assert(in_array('required_breakpoint_unsupported', array_column($missing['errors'],'code'), true), 'missing breakpoint error');
$padding = $spec; $padding['settings']['layout']['containerPadding']['desktop'] = [ 'fixedPx'=>32 ];
$padding_result = $validator->validate($padding);
v_assert(in_array('global_container_padding_must_be_zero', array_column($padding_result['errors'],'code'), true), 'global nonzero padding blocked');
echo "PASS: responsive foundation validator\n";
