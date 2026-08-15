<?php
namespace CrescoLayer\SiteSettings\Contract {
	final class Spec {
		public const SCHEMA='cresco-site-settings/v1'; public const TARGET='elementor'; public const MODE_MERGE='merge'; public const MODES=['merge','sync-owned','force']; public const SECTIONS=['designSystem','themeStyle','settings','fluid'];
	}
}
namespace CrescoLayer\SiteSettings\Gateway {
	interface KitGateway { public function is_available():bool; public function kit_id():int; public function controls():array; public function settings():array; public function save(array $settings):bool; public function refresh():void; public function errors():array; }
}
namespace CrescoLayer\SiteSettings\Diff {
	final class DiffEngine {
		public function compare(array $current,array $desired):array { $merged=array_replace($current,$desired); $created=[];$updated=[];$unchanged=[]; foreach($desired as $k=>$v){ if(!array_key_exists($k,$current))$created[]=$k; elseif($current[$k]===$v)$unchanged[]=$k; else $updated[]=$k; } return ['created'=>$created,'updated'=>$updated,'unchanged'=>$unchanged,'changed'=>(bool)($created||$updated),'merged'=>$merged]; }
		public function hash(array $v):string{return hash('sha256',json_encode($v));}
	}
}
namespace CrescoLayer\SiteSettings\Registry { final class OwnershipRegistry { private int $id=0; public function bind_kit(int $id):void{$this->id=$id;} public function kit_id():int{return $this->id;} public function record_hash(string $h):void{} public function remember(string $a,string $b,string $c):void{} } }
namespace CrescoLayer\SiteSettings\Cache { interface CacheInvalidator { public function clear():bool; public function clears():int; } final class ElementorCache implements CacheInvalidator { public function clear():bool{return true;} public function clears():int{return 1;} } }
namespace CrescoLayer\SiteSettings\Support {
	final class ClampValidator {}
	final class ManagedCssBlock {}
	final class ValueFactory { public function __construct(...$x){} }
	final class Logger { public function context(array $x):void{} public function add_many(string $a,array $x):void{} public function add(string $a,$x):void{} public function render(string $s):string{return $s;} }
}
namespace CrescoLayer\SiteSettings\Verify {
	final class ValueNormalizer { public function satisfies($a,$b,string $t):bool{return $a===$b;} }
	final class Verifier { public function __construct(...$x){} public function verify(array $plan,array $settings):array{return ['status'=>'pass','mismatchCount'=>0,'scopeCount'=>count($plan)];} public function render(...$x):string{return 'verified';} }
}
namespace CrescoLayer\SiteSettings\Profiles {
	use CrescoLayer\SiteSettings\Contract\Spec;
	final class ProfessionalCommerceProfile { public const ID='professional-commerce'; public function spec():array{return ['schema'=>Spec::SCHEMA,'target'=>Spec::TARGET,'profile'=>self::ID,'mode'=>Spec::MODE_MERGE,'designSystem'=>['colors'=>['custom'=>[]],'typography'=>['custom'=>[]]],'themeStyle'=>[],'settings'=>['layout'=>[]],'fluid'=>['tokens'=>[]]];} }
}
namespace CrescoLayer\SiteSettings\Adapter {
	final class FakeCapabilities { public function __construct(private \CrescoLayer\SiteSettings\Discovery\RuntimeControlResolver $r){} public function resolver(){return $this->r;} public function summary():array{return ['ok'=>true];} }
	final class ElementorClassicKitAdapter {
		private FakeCapabilities $caps;
		public function __construct(private $gateway,...$rest){ $this->caps=new FakeCapabilities(new \CrescoLayer\SiteSettings\Discovery\RuntimeControlResolver($gateway->controls(),$gateway->settings())); }
		public function supports():bool{return true;} public function id():string{return 'elementor-classic';} public function capabilities():FakeCapabilities{return $this->caps;}
		public function build(array $spec):array{return ['settings'=>[],'plan'=>[],'skipped'=>[],'preserved'=>[],'notes'=>[]];}
	}
	final class ResponsiveFoundationBridge {
		public function __construct(...$x){}
		public function apply(array $spec,array $built):array { $l=$spec['settings']['layout']; $built['settings']['active_breakpoints']=['viewport_mobile','viewport_tablet','viewport_laptop','viewport_widescreen']; foreach($l['breakpoints'] as $d=>$v){$built['settings']['viewport_'.$d]=$v;} return $built; }
	}
}
namespace {
	$base=dirname(__DIR__,2).'/includes/';
	require_once $base.'SiteSettings/Layout/ResponsiveLayoutPolicy.php';
	require_once $base.'SiteSettings/Discovery/RuntimeControlResolver.php';
	require_once $base.'SiteSettings/Migration/BreakpointUsageScanner.php';
	require_once $base.'SiteSettings/Migration/BreakpointMigrationGuard.php';
	require_once $base.'SiteSettings/Validation/ResponsiveFoundationValidator.php';
	require_once $base.'SiteSettings/Validation/ResponsiveFoundationCoordinator.php';
}
namespace {
	require_once dirname(__DIR__,2).'/includes/SiteSettings/SiteSettingsEngine.php';
	use CrescoLayer\SiteSettings\Gateway\KitGateway; use CrescoLayer\SiteSettings\Migration\BreakpointUsageScanner; use CrescoLayer\SiteSettings\SiteSettingsEngine;
	function eg_assert(bool $x,string $m):void{if(!$x){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
	final class EngineGateway implements KitGateway {
		public int $saves=0; public function __construct(private array $c,private array $s){} public function is_available():bool{return true;} public function kit_id():int{return 9;} public function controls():array{return $this->c;} public function settings():array{return $this->s;} public function save(array $settings):bool{$this->s=$settings;$this->saves++;return true;} public function refresh():void{} public function errors():array{return [];}
	}
	$controls=['active_breakpoints'=>['type'=>'select2'],'viewport_mobile'=>['type'=>'number'],'viewport_tablet'=>['type'=>'number'],'viewport_laptop'=>['type'=>'number'],'viewport_widescreen'=>['type'=>'number'],'container_width'=>['type'=>'slider','is_responsive'=>true,'size_units'=>['px'],'range'=>['px'=>['min'=>300,'max'=>1500]]],'container_padding'=>['type'=>'dimensions','is_responsive'=>true,'size_units'=>['px']]];
	$gateway=new EngineGateway($controls,['active_breakpoints'=>['viewport_mobile','viewport_mobile_extra','viewport_tablet','viewport_laptop','viewport_widescreen']]);
	$scanner=new class implements BreakpointUsageScanner { public function scan(array $devices):array{return ['scannedDocuments'=>3,'truncated'=>false,'totalSettingCount'=>2,'hasUsage'=>true,'usage'=>['mobile_extra'=>['settingCount'=>2,'documentCount'=>1,'documents'=>[15],'samples'=>[]]]];} };
	$engine=new SiteSettingsEngine($gateway,null,null,null,$scanner);
	$preview=$engine->preview(); eg_assert(true===$preview['success'] && 'preview'===$preview['status'],'preview allowed'); eg_assert(true===$preview['breakpointMigration']['blocking'],'preview reports migration impact'); eg_assert(0===$gateway->saves,'preview no save');
	$apply=$engine->apply(); eg_assert(false===$apply['success'] && 'breakpoint_migration_blocked'===$apply['status'],'apply blocked'); eg_assert(0===$gateway->saves,'blocked apply no save');
	echo "PASS: Site Settings engine responsive guard\n";
}
