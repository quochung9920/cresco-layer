<?php
namespace CrescoLayer\SiteSettings\Gateway {
	interface KitGateway {
		public function is_available(): bool;
		public function kit_id(): int;
		public function controls(): array;
		public function settings(): array;
		public function save(array $settings): bool;
		public function refresh(): void;
		public function errors(): array;
	}
}
namespace CrescoLayer\SiteSettings\Support {
	final class ValueFactory {
		public function slider_shape(string $unit, $size): array { return ['unit'=>$unit,'size'=>$size,'sizes'=>[]]; }
		public function dimensions(array $sides, array $fallback, bool $custom, bool $linked=false, string $fallbackUnit='px'): array {
			if (!$custom) {
				return ['value'=>['unit'=>$fallbackUnit,'top'=>(string)$fallback['top'],'right'=>(string)$fallback['right'],'bottom'=>(string)$fallback['bottom'],'left'=>(string)$fallback['left'],'isLinked'=>$linked],'fluid'=>false,'reason'=>'custom_unit_unsupported'];
			}
			return ['value'=>['unit'=>'custom','top'=>$sides['top'],'right'=>$sides['right'],'bottom'=>$sides['bottom'],'left'=>$sides['left'],'isLinked'=>$linked],'fluid'=>true,'reason'=>'custom_unit'];
		}
	}
}
namespace {
	$base = dirname(__DIR__, 2) . '/includes/';
	require_once $base . 'SiteSettings/Layout/ResponsiveLayoutPolicy.php';
	require_once $base . 'SiteSettings/Discovery/RuntimeControlResolver.php';
	require_once $base . 'SiteSettings/Discovery/CapabilityReport.php';
	require_once $base . 'SiteSettings/Adapter/ResponsiveFoundationBridge.php';
	use CrescoLayer\SiteSettings\Adapter\ResponsiveFoundationBridge;
	use CrescoLayer\SiteSettings\Discovery\CapabilityReport;
	use CrescoLayer\SiteSettings\Gateway\KitGateway;
	use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;
	use CrescoLayer\SiteSettings\Support\ValueFactory;
	function b_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
	final class V2Gateway implements KitGateway {
		public function __construct(private array $c, private array $s=[]) {}
		public function is_available(): bool { return true; }
		public function kit_id(): int { return 9; }
		public function controls(): array { return $this->c; }
		public function settings(): array { return $this->s; }
		public function save(array $settings): bool { $this->s=$settings; return true; }
		public function refresh(): void {}
		public function errors(): array { return []; }
	}
	$controls = [
		'active_breakpoints'=>['type'=>'select2'],
		'viewport_mobile'=>['type'=>'number'], 'viewport_tablet'=>['type'=>'number'], 'viewport_laptop'=>['type'=>'number'], 'viewport_widescreen'=>['type'=>'number'],
		'container_width'=>['type'=>'slider','is_responsive'=>true,'size_units'=>['px','custom'],'range'=>['px'=>['min'=>300,'max'=>1500]]],
		'container_padding'=>['type'=>'dimensions','is_responsive'=>true,'size_units'=>['px','custom']],
	];
	$gateway = new V2Gateway($controls, ['active_breakpoints'=>['viewport_mobile','viewport_mobile_extra','viewport_tablet']]);
	$bridge = new ResponsiveFoundationBridge(new CapabilityReport($gateway), new ValueFactory(), $gateway->settings());
	$layout = ResponsiveLayoutPolicy::layout_contract();
	$built = $bridge->apply(['settings'=>['layout'=>$layout],'themeStyle'=>[]], ['settings'=>[],'plan'=>[],'skipped'=>[],'notes'=>[]]);
	$s = $built['settings'];
	b_assert(ResponsiveLayoutPolicy::active_breakpoint_controls() === $s['active_breakpoints'], 'exact active breakpoints');
	b_assert(1400.0 === (float)$s['container_width']['size'] && 'px' === $s['container_width']['unit'], 'desktop width px');
	b_assert(1200.0 === (float)$s['container_width_laptop']['size'], 'laptop 13-14 width px');
	b_assert(1500.0 === (float)$s['container_width_widescreen']['size'], 'widescreen 4K width px');
	$expected = [
		'container_padding'=>'clamp(32px, 2.5vw, 48px)',
		'container_padding_mobile'=>'clamp(16px, 4vw, 20px)',
		'container_padding_tablet'=>'clamp(20px, 2.5vw, 28px)',
		'container_padding_laptop'=>'clamp(24px, 2.2vw, 32px)',
		'container_padding_widescreen'=>'clamp(48px, 3vw, 80px)',
	];
	foreach ($expected as $key=>$clamp) {
		b_assert(isset($s[$key]), "$key written");
		b_assert('custom' === $s[$key]['unit'], "$key native custom");
		b_assert('0' === $s[$key]['top'] && '0' === $s[$key]['bottom'], "$key vertical zero");
		b_assert($clamp === $s[$key]['left'] && $clamp === $s[$key]['right'], "$key horizontal clamp");
		b_assert(false === $s[$key]['isLinked'], "$key unlinked axes");
	}
	echo "PASS: responsive foundation bridge v2\n";
}
