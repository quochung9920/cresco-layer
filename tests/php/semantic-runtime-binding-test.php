<?php
namespace CrescoLayer\AI {
	final class CapabilityScanner {
		public function catalog(): array {
			return [
				'widgets' => [
					'advanced-heading' => [
						'controls' => [
							'headline' => [ 'type' => 'text' ],
							'html_tag' => [ 'type' => 'select', 'options' => [ 'h1' => 'H1', 'h2' => 'H2' ] ],
						],
					],
					'advanced-button' => [
						'controls' => [
							'label' => [ 'type' => 'text' ],
							'url' => [ 'type' => 'url' ],
						],
					],
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

	$elements = [[ 'id' => 'abc1234', 'elType' => 'container', 'settings' => [], 'elements' => [] ]];
	$compiler = new AIMutationCompiler();

	$compiled = $compiler->compile([
		'schema' => 'cresco-ai-mutation/v2',
		'intent' => 'add',
		'target' => [ 'postId' => 3, 'id' => 'abc1234' ],
		'nodes' => [[
			'ref' => '$new:title',
			'widgetIntent' => 'advanced-heading',
			'content' => [ 'text' => 'Runtime bound title', 'semanticLevel' => 'h2' ],
		]],
	], 3, $elements, 'abc1234');

	$settings = $compiled['patch']['operations'][0]['element']['settings'];
	if ( ( $settings['headline'] ?? '' ) !== 'Runtime bound title' ) { throw new RuntimeException( 'runtime title binding failed' ); }
	if ( ( $settings['html_tag'] ?? '' ) !== 'h2' ) { throw new RuntimeException( 'runtime semantic-level binding failed' ); }
	if ( isset( $settings['title'] ) || isset( $settings['header_size'] ) ) { throw new RuntimeException( 'compiler leaked hard-coded core heading controls' ); }

	$button = $compiler->compile([
		'schema' => 'cresco-ai-mutation/v2',
		'intent' => 'add',
		'target' => [ 'postId' => 3, 'id' => 'abc1234' ],
		'nodes' => [[
			'ref' => '$new:button',
			'widgetIntent' => 'advanced-button',
			'content' => [ 'text' => 'Book now', 'url' => 'https://example.test/book' ],
		]],
	], 3, $elements, 'abc1234');
	$button_settings = $button['patch']['operations'][0]['element']['settings'];
	if ( ( $button_settings['label'] ?? '' ) !== 'Book now' ) { throw new RuntimeException( 'runtime button label binding failed' ); }
	if ( ( $button_settings['url']['url'] ?? '' ) !== 'https://example.test/book' ) { throw new RuntimeException( 'runtime button URL binding failed' ); }

	echo "semantic runtime binding passed\n";
}
