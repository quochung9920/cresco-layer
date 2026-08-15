<?php
$base=dirname(__DIR__,2).'/includes/';
require_once $base.'SiteSettings/Layout/ResponsiveLayoutPolicy.php';
require_once $base.'AI/ContainerRolePolicy.php';
require_once $base.'AI/ContainerRolePatchAdvisor.php';
use CrescoLayer\AI\ContainerRolePatchAdvisor;
function a_assert(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$elements=[[ 'id'=>'outer','elType'=>'container','settings'=>[],'elements'=>[[ 'id'=>'inner','elType'=>'container','settings'=>[],'elements'=>[] ]] ]];
$ops=[
 ['operation'=>'update-setting','elementId'=>'outer','setting'=>'padding','value'=>['unit'=>'custom','top'=>'clamp(40px,5vw,80px)','right'=>'clamp(16px,4vw,20px)','bottom'=>'clamp(40px,5vw,80px)','left'=>'clamp(16px,4vw,20px)']],
 ['operation'=>'update-setting','elementId'=>'inner','setting'=>'padding_tablet','value'=>['unit'=>'px','top'=>'32','right'=>'24','bottom'=>'32','left'=>'24']],
 ['operation'=>'remove-setting','elementId'=>'inner','setting'=>'padding'],
 ['operation'=>'replace-settings','elementId'=>'inner','settings'=>['flex_direction'=>'column']],
];
$r=(new ContainerRolePatchAdvisor())->analyze($elements,$ops);
$codes=array_column($r['warnings'],'code');
a_assert(in_array('section_shell_vertical_padding',$codes,true),'outer vertical padding warned');
a_assert(in_array('nested_page_gutter_candidate',$codes,true),'nested horizontal padding warned');
a_assert(in_array('nested_global_gutter_inherited',$codes,true),'removing nested reset warned');
a_assert(in_array('nested_padding_reset_missing',$codes,true),'replacement without reset warned');
echo "PASS: container role patch advisor\n";
