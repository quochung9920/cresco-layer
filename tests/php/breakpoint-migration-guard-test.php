<?php
$base = dirname(__DIR__, 2) . '/includes/';
require_once $base . 'SiteSettings/Migration/BreakpointUsageScanner.php';
require_once $base . 'SiteSettings/Migration/BreakpointUsageAnalyzer.php';
require_once $base . 'SiteSettings/Migration/BreakpointMigrationGuard.php';
use CrescoLayer\SiteSettings\Migration\BreakpointUsageScanner;
use CrescoLayer\SiteSettings\Migration\BreakpointUsageAnalyzer;
use CrescoLayer\SiteSettings\Migration\BreakpointMigrationGuard;
function m_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$documents = [
  [ 'id'=>10, 'title'=>'Home', 'type'=>'page', 'elements'=>[
    [ 'id'=>'a', 'elType'=>'container', 'settings'=>[ 'padding_mobile_extra'=>['unit'=>'px','top'=>'0','right'=>'12','bottom'=>'0','left'=>'12'] ], 'elements'=>[
      [ 'id'=>'b', 'elType'=>'widget', 'settings'=>[ 'font_size_tablet_extra'=>['unit'=>'px','size'=>18] ], 'elements'=>[] ],
    ] ],
  ], 'pageSettings'=>[] ],
  [ 'id'=>11, 'title'=>'Shop', 'type'=>'page', 'elements'=>[
    [ 'id'=>'c', 'elType'=>'widget', 'settings'=>[ 'margin_mobile_extra'=>['unit'=>'px','top'=>'','right'=>'','bottom'=>'','left'=>''] ], 'elements'=>[] ],
  ], 'pageSettings'=>[ 'custom_width_mobile_extra'=>0 ] ],
];
$analyzer = new BreakpointUsageAnalyzer();
final class FakeBreakpointScanner implements BreakpointUsageScanner {
  public function __construct(private BreakpointUsageAnalyzer $analyzer, private array $documents) {}
  public function scan(array $devices): array { return $this->analyzer->analyze($this->documents, $devices, false); }
}
$guard = new BreakpointMigrationGuard(new FakeBreakpointScanner($analyzer, $documents));
$report = $guard->inspect(
  ['viewport_mobile','viewport_mobile_extra','viewport_tablet','viewport_tablet_extra','viewport_laptop','viewport_widescreen'],
  ['viewport_mobile','viewport_tablet','viewport_laptop','viewport_widescreen'],
  'block-if-used'
);
m_assert(true === $report['blocking'], 'used removed breakpoint must block');
m_assert(3 === $report['impact']['totalSettingCount'], 'meaningful overrides counted including zero');
m_assert(2 === $report['impact']['usage']['mobile_extra']['settingCount'], 'mobile extra count');
m_assert(1 === $report['impact']['usage']['tablet_extra']['settingCount'], 'tablet extra count');
m_assert(2 === $report['impact']['usage']['mobile_extra']['documentCount'], 'mobile extra document count');
$safe = (new BreakpointMigrationGuard(new class implements BreakpointUsageScanner {
  public function scan(array $devices): array { return [ 'scannedDocuments'=>5,'truncated'=>false,'totalSettingCount'=>0,'hasUsage'=>false,'usage'=>[] ]; }
}))->inspect(['viewport_mobile','viewport_mobile_extra','viewport_tablet'], ['viewport_mobile','viewport_tablet'], 'block-if-used');
m_assert(false === $safe['blocking'] && true === $safe['safe'], 'unused removed breakpoint is safe');
$truncated = (new BreakpointMigrationGuard(new class implements BreakpointUsageScanner {
  public function scan(array $devices): array { return [ 'scannedDocuments'=>1000,'truncated'=>true,'totalSettingCount'=>0,'hasUsage'=>false,'usage'=>[] ]; }
}))->inspect(['viewport_mobile','viewport_mobile_extra','viewport_tablet'], ['viewport_mobile','viewport_tablet'], 'block-if-used');
m_assert(true === $truncated['blocking'], 'truncated scan must fail closed');
echo "PASS: breakpoint migration guard\n";
