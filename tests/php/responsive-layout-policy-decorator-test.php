<?php
$base=dirname(__DIR__,2).'/includes/'; require_once $base.'SiteSettings/Layout/ResponsiveLayoutPolicy.php';
use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;
function d_assert(bool $x,string $m):void{if(!$x){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$spec=['settings'=>['layout'=>['widgetGap'=>['fallbackPx'=>20],'contentWidthPx'=>['desktop'=>1400],'containerPadding'=>['desktop'=>['fluid'=>'old']]]],'themeStyle'=>['helloHeader'=>['width'=>'boxed','contentWidthPx'=>['desktop'=>1400]],'helloFooter'=>['width'=>'boxed','contentWidthPx'=>['desktop'=>1400]]],'fluid'=>['tokens'=>['--cresco-fs-body'=>'clamp(1rem,2vw,1.1rem)','--cresco-gutter'=>'old']]];
$out=ResponsiveLayoutPolicy::apply_to_spec($spec);
d_assert(20===$out['settings']['layout']['widgetGap']['fallbackPx'],'unrelated layout preserved');
d_assert(!isset($out['settings']['layout']['contentWidthPx']),'legacy layout width removed');
d_assert('px'===$out['settings']['layout']['contentWidth']['laptop']['unit'] && 1440===$out['settings']['layout']['contentWidth']['laptop']['size'],'laptop canvas upgraded');
d_assert('%'===$out['settings']['layout']['contentWidth']['desktop']['unit'] && 100===$out['settings']['layout']['contentWidth']['desktop']['size'],'desktop base upgraded');
d_assert(1920===$out['settings']['layout']['contentWidth']['widescreen']['size'],'widescreen canvas upgraded');
d_assert(!isset($out['themeStyle']['helloHeader']['contentWidthPx']),'legacy Hello width removed');
d_assert(1440===$out['themeStyle']['helloHeader']['contentWidth']['laptop']['size'],'Hello laptop width upgraded');
d_assert(100===$out['themeStyle']['helloHeader']['contentWidth']['desktop']['size'],'Hello desktop base upgraded');
d_assert(1920===$out['themeStyle']['helloFooter']['contentWidth']['widescreen']['size'],'Hello widescreen width upgraded');
d_assert('clamp(32px, 2.5vw, 48px)'===$out['settings']['layout']['containerPadding']['desktop']['fluid'],'global clamp upgraded');
d_assert('clamp(1rem,2vw,1.1rem)'===$out['fluid']['tokens']['--cresco-fs-body'],'typography token preserved');
d_assert('100%'===$out['fluid']['tokens']['--cresco-container-max'],'desktop layout token replaced');
d_assert('1920px'===$out['fluid']['tokens']['--cresco-container-max-widescreen'],'widescreen layout token replaced');
echo "PASS: responsive layout policy decorator\n";
