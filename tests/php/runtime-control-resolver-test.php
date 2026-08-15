<?php
$base = dirname(__DIR__, 2) . '/includes/';
require_once $base . 'SiteSettings/Discovery/RuntimeControlResolver.php';
use CrescoLayer\SiteSettings\Discovery\RuntimeControlResolver;
function r_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$defaults = static fn(string $type): array => in_array($type, ['slider','dimensions'], true) ? [ 'size_units'=>['px'], 'range'=>['px'=>['min'=>0,'max'=>100]] ] : [];
$controls = [
  'container_width' => [ 'type'=>'slider', 'is_responsive'=>true, 'size_units'=>['px','custom'], 'range'=>['px'=>['min'=>300,'max'=>1500]] ],
  'container_padding' => [ 'type'=>'dimensions', 'is_responsive'=>true ],
  'legacy_size' => [ 'type'=>'slider' ],
];
$resolver = new RuntimeControlResolver($controls, [ 'container_padding'=>['unit'=>'custom','top'=>'0','right'=>'clamp(1px,1vw,2px)','bottom'=>'0','left'=>'clamp(1px,1vw,2px)'] ], $defaults);
r_assert($resolver->supports_unit('container_width','px'), 'explicit px');
r_assert($resolver->supports_unit('container_width','custom'), 'explicit custom');
r_assert($resolver->supports_unit('legacy_size','px'), 'fallback type defaults prove px');
r_assert(!$resolver->supports_unit('legacy_size','custom'), 'generic defaults must not invent custom');
r_assert($resolver->supports_unit('container_padding','custom'), 'persisted custom value is evidence');
r_assert([ 'min'=>300,'max'=>1500 ] === $resolver->explicit_range('container_width','px'), 'explicit range');
r_assert(null === $resolver->explicit_range('legacy_size','px'), 'generic range must not be treated as control range');
r_assert($resolver->is_responsive('container_width'), 'responsive metadata');
echo "PASS: runtime control resolver\n";
