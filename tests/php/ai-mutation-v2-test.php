<?php
namespace CrescoLayer\AI {
	final class CapabilityScanner {
		public function catalog(): array {
			return [
				'widgets' => [
					'heading' => [ 'controls' => [ 'title' => [ 'type' => 'text' ], 'header_size' => [ 'type' => 'select' ] ] ],
					'form' => [ 'controls' => [ 'form_fields' => [ 'type' => 'repeater' ], 'webhook' => [ 'type' => 'text' ] ] ],
				],
				'elements' => [ 'container' => [ 'controls' => [] ] ],
			];
		}
	}
}

namespace {
	function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
	function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
	function esc_url_raw( $v ) { return (string) $v; }

	require_once __DIR__ . '/../../includes/AI/ElementLocator.php';
	require_once __DIR__ . '/../../includes/AI/AIMutationCompiler.php';

	use CrescoLayer\AI\AIMutationCompiler;

	$elements = [[
		'id' => 'abc1234', 'elType' => 'container', 'settings' => [], 'elements' => [
			[ 'id' => 'def5678', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Old' ], 'elements' => [] ],
			[ 'id' => 'fed4321', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => [], 'elements' => [] ],
		],
	]];
	$compiler = new AIMutationCompiler();

	$add = $compiler->compile([
		'schema' => 'cresco-ai-mutation/v2',
		'intent' => 'add',
		'target' => [ 'postId' => 3, 'id' => 'abc1234' ],
		'placement' => [ 'mode' => 'inside-end' ],
		'nodes' => [[
			'ref' => '$new:title', 'role' => 'headline', 'widgetIntent' => 'heading',
			'content' => [ 'text' => 'New title', 'semanticLevel' => 'h2' ], 'settings' => [],
		]],
	], 3, $elements, 'abc1234');
	if ( $add['patch']['operations'][0]['operation'] !== 'insert-element' ) { throw new RuntimeException( 'add compile failed' ); }
	if ( $add['patch']['operations'][0]['element']['ref'] !== '$new:title' ) { throw new RuntimeException( 'temporary ref lost' ); }
	if ( $add['patch']['operations'][0]['element']['settings']['header_size'] !== 'h2' ) { throw new RuntimeException( 'semantic heading content failed' ); }
	if ( true !== $add['report']['runtimeValidatedWidgetIntent'] ) { throw new RuntimeException( 'runtime widget validation not reported' ); }

	$unknown_widget_blocked = false;
	try {
		$compiler->compile([
			'schema' => 'cresco-ai-mutation/v2', 'intent' => 'add',
			'target' => [ 'postId' => 3, 'id' => 'abc1234' ],
			'nodes' => [[ 'widgetIntent' => 'invented-super-widget', 'content' => [ 'text' => 'No' ] ]],
		], 3, $elements, 'abc1234');
	} catch ( InvalidArgumentException $error ) { $unknown_widget_blocked = str_contains( $error->getMessage(), 'not present in the active Elementor runtime' ); }
	if ( ! $unknown_widget_blocked ) { throw new RuntimeException( 'invented widget intent should be blocked' ); }

	$edit = $compiler->compile([
		'schema' => 'cresco-ai-mutation/v2', 'intent' => 'edit',
		'target' => [ 'postId' => 3, 'id' => 'abc1234' ],
		'changes' => [[ 'elementId' => 'def5678', 'setting' => 'title', 'value' => 'Updated' ]],
	], 3, $elements, 'abc1234');
	if ( $edit['patch']['operations'][0]['elementId'] !== 'def5678' ) { throw new RuntimeException( 'scoped edit failed' ); }

	$blocked = false;
	try {
		$compiler->compile([
			'schema' => 'cresco-ai-mutation/v2', 'intent' => 'edit',
			'target' => [ 'postId' => 3, 'id' => 'abc1234' ],
			'changes' => [[ 'elementId' => 'fed4321', 'setting' => 'webhook', 'value' => 'https://example.test' ]],
		], 3, $elements, 'abc1234');
	} catch ( InvalidArgumentException $error ) { $blocked = str_contains( $error->getMessage(), 'protected' ); }
	if ( ! $blocked ) { throw new RuntimeException( 'protected behavioral edit was not blocked' ); }

	$widget_add_blocked = false;
	try {
		$compiler->compile([
			'schema' => 'cresco-ai-mutation/v2', 'intent' => 'add',
			'target' => [ 'postId' => 3, 'id' => 'def5678' ],
			'nodes' => [[ 'widgetIntent' => 'heading', 'content' => [ 'text' => 'No' ] ]],
		], 3, $elements, 'def5678');
	} catch ( InvalidArgumentException $error ) { $widget_add_blocked = str_contains( $error->getMessage(), 'cannot own' ); }
	if ( ! $widget_add_blocked ) { throw new RuntimeException( 'widget add should be blocked' ); }

	echo "ai mutation v2 compiler passed\n";
}
