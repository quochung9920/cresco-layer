<?php
$base = dirname(__DIR__, 2) . '/includes/';
require_once $base . 'SiteSettings/Layout/ResponsiveLayoutPolicy.php';
require_once $base . 'SiteSettings/Discovery/RuntimeControlResolver.php';
require_once $base . 'SiteSettings/Migration/BreakpointUsageScanner.php';
require_once $base . 'SiteSettings/Migration/BreakpointMigrationGuard.php';
require_once $base . 'SiteSettings/Validation/ResponsiveFoundationValidator.php';
require_once $base . 'SiteSettings/Validation/ResponsiveFoundationCoordinator.php';
use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;
use CrescoLayer\SiteSettings\Discovery\RuntimeControlResolver;
use CrescoLayer\SiteSettings\Migration\BreakpointUsageScanner;
use CrescoLayer\SiteSettings\Validation\ResponsiveFoundationCoordinator;
function co_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$controls = [
  'active_breakpoints'=>['type'=>'select2'],
  'viewport_mobile'=>['type'=>'number'], 'viewport_tablet'=>['type'=>'number'], 'viewport_laptop'=>['type'=>'number'], 'viewport_widescreen'=>['type'=>'number'],
  'container_width'=>['type'=>'slider','is_responsive'=>true,'size_units'=>['px'],'range'=>['px'=>['min'=>300,'max'=>1500]]],
  'container_padding'=>['type'=>'dimensions','is_responsive'=>true,'size_units'=>['px']],
];
$scanner = new class implements BreakpointUsageScanner {
  public function scan(array $devices): array {
    return [ 'scannedDocuments'=>2, 'truncated'=>false, 'totalSettingCount'=>1, 'hasUsage'=>true, 'usage'=>['mobile_extra'=>['settingCount'=>1,'documentCount'=>1,'documents'=>[5],'samples'=>[]]] ];
  }
};
$spec=['settings'=>['layout'=>ResponsiveLayoutPolicy::layout_contract()]];
$co = new ResponsiveFoundationCoordinator(new RuntimeControlResolver($controls), $scanner);
$r = $co->inspect($spec, ['active_breakpoints'=>['viewport_mobile','viewport_mobile_extra','viewport_tablet','viewport_laptop','viewport_widescreen']]);
co_assert(true === $r['applicable'], 'applicable');
co_assert(true === $r['compatible'], 'geometry/runtime remain compatible');
co_assert(false === $r['readyToApply'], 'migration usage blocks apply');
co_assert(true === $r['migration']['blocking'], 'migration flag');
$safeScanner = new class implements BreakpointUsageScanner { public function scan(array $devices): array { return ['scannedDocuments'=>2,'truncated'=>false,'totalSettingCount'=>0,'hasUsage'=>false,'usage'=>[]]; } };
$safe = (new ResponsiveFoundationCoordinator(new RuntimeControlResolver($controls), $safeScanner))->inspect($spec, ['active_breakpoints'=>['viewport_mobile','viewport_mobile_extra','viewport_tablet','viewport_laptop','viewport_widescreen']]);
co_assert(true === $safe['compatible'] && true === $safe['readyToApply'], 'unused extra breakpoint can be removed');
echo "PASS: responsive foundation coordinator\n";
