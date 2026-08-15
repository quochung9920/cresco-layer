<?php
$base=dirname(__DIR__,2).'/includes/'; require_once $base.'SiteSettings/Layout/ResponsiveLayoutPolicy.php';
use CrescoLayer\SiteSettings\Layout\ResponsiveLayoutPolicy;
function d_assert(bool $x,string $m):void{if(!$x){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$spec=['settings'=>['layout'=>['widgetGap'=>['fallbackPx'=>20],'containerPadding'=>['desktop'=>['fluid'=>'old']]]],'themeStyle'=>['helloHeader'=>['width'=>'boxed'],'helloFooter'=>['width'=>'boxed']],'fluid'=>['tokens'=>['--cresco-fs-body'=>'clamp(1rem,2vw,1.1rem)','--cresco-gutter'=>'old']]];
$out=ResponsiveLayoutPolicy::apply_to_spec($spec);
d_assert(20===$out['settings']['layout']['widgetGap']['fallbackPx'],'unrelated layout preserved');
d_assert('clamp(32px, 2.5vw, 48px)'===$out['settings']['layout']['containerPadding']['desktop']['fluid'],'global clamp upgraded');
d_assert(40===$out['settings']['layout']['containerPadding']['desktop']['fallbackPx'],'global padding fallback upgraded');
d_assert(!isset($out['settings']['layout']['containerPadding']['desktop']['fixedPx']),'global padding not zeroed');
d_assert(1500===$out['themeStyle']['helloFooter']['contentWidthPx']['widescreen'],'Hello widths upgraded');
d_assert('clamp(1rem,2vw,1.1rem)'===$out['fluid']['tokens']['--cresco-fs-body'],'typography token preserved');
d_assert('clamp(32px, 2.5vw, 48px)'===$out['fluid']['tokens']['--cresco-gutter'],'layout token replaced');
echo "PASS: responsive layout policy decorator\n";
