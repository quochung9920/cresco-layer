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
  'container_width'=>['type'=>'slider','is_responsive'=>true,'size_units'=>['px','%','custom'],'range'=>['px'=>['min'=>300,'max'=>1500]]],
  'container_padding'=>['type'=>'dimensions','is_responsive'=>true,'size_units'=>['px','custom']],
];
$spec = [ 'settings'=>[ 'layout'=>ResponsiveLayoutPolicy::layout_contract() ] ];
$validator = new ResponsiveFoundationValidator(new RuntimeControlResolver($controls));
$ok = $validator->validate($spec);
v_assert(true === $ok['compatible'], 'valid foundation compatible');
v_assert(0 === $ok['errorCount'], 'no errors');
v_assert('native-custom-unit-when-supported' === $ok['globalFluidStrategy'], 'global fluid strategy reported');
v_assert('canvas-aligned-native-with-custom-overflow' === $ok['contentWidthStrategy'], 'content width strategy reported');
v_assert(in_array('content_width_custom_overflow', array_column($ok['warnings'], 'code'), true), '1920px custom overflow reported');
$broken = $spec;
$broken['settings']['layout']['contentWidth']['laptop']['size'] = 1400;
$bad = $validator->validate($broken);
v_assert(false === $bad['compatible'], 'canvas mismatch incompatible');
v_assert(in_array('content_width_canvas_mismatch', array_column($bad['errors'], 'code'), true), 'canvas mismatch reported');
$missing_controls = $controls; unset($missing_controls['viewport_widescreen']);
$missing = (new ResponsiveFoundationValidator(new RuntimeControlResolver($missing_controls)))->validate($spec);
v_assert(false === $missing['compatible'], 'missing required Widescreen incompatible');
v_assert(in_array('required_breakpoint_unsupported', array_column($missing['errors'],'code'), true), 'missing breakpoint error');
$no_percent = $controls; $no_percent['container_width']['size_units'] = ['px','custom'];
$no_percent_result = (new ResponsiveFoundationValidator(new RuntimeControlResolver($no_percent)))->validate($spec);
v_assert(false === $no_percent_result['compatible'], 'Desktop 100 percent requires percent unit');
v_assert(in_array('content_width_percent_unit_unsupported', array_column($no_percent_result['errors'],'code'), true), 'percent unit error reported');
$no_custom_width = $controls; $no_custom_width['container_width']['size_units'] = ['px','%'];
$no_custom_width_result = (new ResponsiveFoundationValidator(new RuntimeControlResolver($no_custom_width)))->validate($spec);
v_assert(false === $no_custom_width_result['compatible'], 'Widescreen overflow requires custom unit');
v_assert(in_array('content_width_above_control_range_custom_unsupported', array_column($no_custom_width_result['errors'],'code'), true), 'custom overflow error reported');
$padding = $spec; $padding['settings']['layout']['containerPadding']['desktop'] = [ 'fixedPx'=>0 ];
$padding_result = $validator->validate($padding);
v_assert(in_array('global_container_padding_not_fluid', array_column($padding_result['errors'],'code'), true), 'zero global padding blocked');
$no_custom_padding = $controls; $no_custom_padding['container_padding']['size_units'] = ['px'];
$no_custom_padding_result = (new ResponsiveFoundationValidator(new RuntimeControlResolver($no_custom_padding)))->validate($spec);
v_assert(true === $no_custom_padding_result['compatible'], 'padding can fall back to px while width capabilities remain valid');
v_assert(in_array('container_padding_custom_unit_unsupported', array_column($no_custom_padding_result['warnings'],'code'), true), 'padding fallback warning reported');
echo "PASS: responsive foundation validator\n";
