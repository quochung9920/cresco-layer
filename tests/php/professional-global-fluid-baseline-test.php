<?php
$base = dirname( __DIR__, 2 ) . '/includes/';
require_once $base . 'SiteSettings/Contract/Spec.php';
require_once $base . 'SiteSettings/Profiles/ProfessionalCommerceProfile.php';
require_once $base . 'SiteSettings/Layout/ResponsiveLayoutPolicy.php';
use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;
use CrescoLayer\SiteSettings\Profiles\ProfessionalCommerceProfile;
function gf_assert(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function gf_value(array $root,array $path){foreach($path as $key){if(!is_array($root)||!array_key_exists($key,$root))return null;$root=$root[$key];}return $root;}
function gf_clamp($value,string $label):void{gf_assert(is_string($value)&&str_starts_with(trim($value),'clamp('),$label.' must be clamp()');}
$spec=ResponsiveLayoutPolicy::apply_to_spec((new ProfessionalCommerceProfile())->spec());
$paths=[
 ['themeStyle','typography','body','fluid'],
 ['themeStyle','typography','paragraphSpacing','fluid'],
 ['themeStyle','typography','headings','h1','fluid'],
 ['themeStyle','typography','headings','h2','fluid'],
 ['themeStyle','typography','headings','h3','fluid'],
 ['themeStyle','typography','headings','h4','fluid'],
 ['themeStyle','typography','headings','h5','fluid'],
 ['themeStyle','typography','headings','h6','fluid'],
 ['themeStyle','buttons','typography','fluid'],
 ['themeStyle','buttons','padding','y'],
 ['themeStyle','buttons','padding','x'],
 ['themeStyle','formFields','label','fluid'],
 ['themeStyle','formFields','padding','y'],
 ['themeStyle','formFields','padding','x'],
 ['settings','layout','widgetGap','fluid'],
 ['themeStyle','helloHeader','logoWidth','fluid'],
 ['themeStyle','helloHeader','menuTypography','fluid'],
 ['themeStyle','helloFooter','logoWidth','fluid'],
 ['themeStyle','helloFooter','copyrightTypography','fluid'],
 ['settings','lightbox','toolbarIcon','fluid'],
 ['settings','lightbox','navigationIcon','fluid'],
];
foreach($paths as $path)gf_clamp(gf_value($spec,$path),implode('.',$path));
foreach(ResponsiveLayoutPolicy::devices() as $device){
 $padding=$spec['settings']['layout']['containerPadding'][$device]??[];
 gf_clamp($padding['fluid']??null,'settings.layout.containerPadding.'.$device);
 gf_assert($padding===$spec['settings']['layout']['pageGutter'][$device],$device.' global padding must equal page gutter');
}
gf_assert([ 'mobile'=>767,'tablet'=>960,'laptop'=>1200,'desktop'=>1400,'widescreen'=>1500 ]===$spec['settings']['layout']['contentWidthPx'],'professional content widths');
gf_assert('desktop-4k'===($spec['settings']['layout']['deviceIntent']['widescreen']??''),'widescreen semantic intent');
gf_assert('1400px'===($spec['fluid']['tokens']['--cresco-container-max']??''),'desktop container token');
gf_assert(16===$spec['themeStyle']['formFields']['field']['fontSizePx'],'form field font size remains fixed 16px for mobile focus safety');
echo "PASS: professional global fluid baseline\n";
