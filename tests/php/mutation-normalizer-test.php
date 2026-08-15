<?php
namespace CrescoLayer\AI {
	final class CapabilityScanner {
		public function catalog(): array {
			return [
				'widgets' => [
					'icon' => [ 'controls' => [
						'size' => [ 'type' => 'slider', 'size_units' => [ 'px' ], 'range' => [ 'px' => [ 'min' => 6, 'max' => 300 ] ] ],
					] ],
				],
				'elements' => [
					'container' => [ 'controls' => [
						'width' => [ 'type' => 'slider', 'size_units' => [ 'px', 'custom' ], 'range' => [ 'px' => [ 'min' => 500, 'max' => 1600 ] ] ],
					] ],
				],
			];
		}
	}
}

namespace {
	require_once __DIR__ . '/../../includes/AI/ElementLocator.php';
	require_once __DIR__ . '/../../includes/AI/MutationNormalizer.php';

	$normalizer = new \CrescoLayer\AI\MutationNormalizer();
	$patch = [
		'schema' => 'cresco-layer-patch/v1',
		'operations' => [[
			'operation' => 'insert-element', 'parentId' => 'abc1234', 'position' => 0,
			'element' => [
				'id' => 'aaa1111', 'elType' => 'container',
				'settings' => [ 'width' => [ 'unit' => 'px', 'size' => 300, 'sizes' => [] ] ],
				'elements' => [[
					'id' => 'bbb2222', 'elType' => 'widget', 'widgetType' => 'icon',
					'settings' => [ 'size' => [ 'unit' => 'px', 'size' => 5, 'sizes' => [] ] ],
					'elements' => [],
				]],
			],
		]],
	];

	$out = $normalizer->normalize( $patch, [] );
	$width = $out['patch']['operations'][0]['element']['settings']['width'];
	$icon = $out['patch']['operations'][0]['element']['elements'][0]['settings']['size'];
	if ( 'custom' !== $width['unit'] || '300px' !== $width['size'] ) { throw new \RuntimeException( 'safe custom-unit repair failed' ); }
	if ( 'px' !== $icon['unit'] || 5 !== $icon['size'] ) { throw new \RuntimeException( 'normalizer silently clamped unsafe icon value' ); }
	if ( 1 !== count( $out['repairs'] ) ) { throw new \RuntimeException( 'repair report mismatch' ); }
	echo "mutation normalizer passed\n";
}
