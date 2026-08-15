<?php
$base = dirname(__DIR__, 2) . '/includes/';
require_once $base . 'SiteSettings/Layout/ResponsiveLayoutPolicy.php';
require_once $base . 'AI/ContainerRolePolicy.php';
use CrescoLayer\AI\ContainerRolePolicy;
function c_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$tree = [
  [ 'id'=>'outer', 'elType'=>'container', 'settings'=>[], 'elements'=>[
    [ 'id'=>'content', 'elType'=>'container', 'settings'=>[], 'elements'=>[
      [ 'id'=>'card', 'elType'=>'container', 'settings'=>[ 'background_background'=>'classic' ], 'elements'=>[] ],
      [ 'id'=>'nested', 'elType'=>'container', 'settings'=>[], 'elements'=>[] ],
    ] ],
  ] ],
  [ 'id'=>'explicit', 'elType'=>'container', 'settings'=>[ '_css_classes'=>'foo cresco-role-component bar' ], 'elements'=>[] ],
];
$policy = new ContainerRolePolicy();
$report = $policy->analyze($tree);
$roles = $report['roles'];
c_assert('responsive-horizontal-clamp' === $report['globalContainerPadding'], 'global padding is a native fluid baseline');
c_assert('section-shell' === $roles['outer']['role'], 'top-level container is section shell');
c_assert('content' === $roles['content']['role'], 'first nested structural container is content');
c_assert('component' === $roles['card']['role'], 'styled nested container is component');
c_assert('nested' === $roles['nested']['role'], 'deep plain container is nested');
c_assert('component' === $roles['explicit']['role'] && true === $roles['explicit']['explicit'], 'explicit role wins');
c_assert(true === $roles['outer']['paddingPolicy']['pageGutter'], 'section shell owns page gutter');
c_assert(false === $roles['outer']['paddingPolicy']['requiresHorizontalReset'], 'section shell inherits global gutter');
c_assert(true === $roles['content']['paddingPolicy']['requiresHorizontalReset'], 'content resets global gutter');
c_assert(true === $roles['nested']['paddingPolicy']['requiresHorizontalReset'], 'nested structural container resets global gutter');
echo "PASS: container role policy\n";
