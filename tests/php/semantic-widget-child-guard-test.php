<?php
namespace CrescoLayer\AI {
	final class CapabilityScanner {
		public function catalog(): array {
			return [
				'widgets' => [ 'heading' => [ 'controls' => [ 'title' => [ 'type' => 'text' ] ] ] ],
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
	$blocked = false;
	try {
		( new AIMutationCompiler() )->compile([
			'schema' => 'cresco-ai-mutation/v2', 'intent' => 'add',
			'target' => [ 'postId' => 3, 'id' => 'abc1234' ],
			'nodes' => [[
				'widgetIntent' => 'heading', 'content' => [ 'text' => 'Parent heading' ],
				'children' => [[ 'widgetIntent' => 'heading', 'content' => [ 'text' => 'Illegal child' ] ]],
			]],
		], 3, $elements, 'abc1234');
	} catch ( InvalidArgumentException $error ) {
		$blocked = str_contains( $error->getMessage(), 'cannot own arbitrary Elementor child nodes' );
	}
	if ( ! $blocked ) { throw new RuntimeException( 'widget child structure should be blocked' ); }
	echo "semantic widget child guard passed\n";
}
