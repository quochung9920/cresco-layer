<?php
namespace CrescoLayer\AI {
	final class CapabilityScanner {
		public function catalog(): array {
			return [
				'widgets' => [
					'heading' => [ 'controls' => [
						'title' => [ 'type' => 'text' ],
						'header_size' => [ 'type' => 'select', 'options' => [ 'h1' => 'H1', 'h2' => 'H2' ] ],
						'typography_font_size' => [ 'type' => 'slider', 'size_units' => [ 'px', 'custom' ], 'responsive' => true ],
					] ],
				],
				'elements' => [
					'container' => [ 'controls' => [
						'flex_direction' => [ 'type' => 'choose', 'options' => [ 'row' => 'Row', 'column' => 'Column' ], 'responsive' => true ],
						'gap' => [ 'type' => 'slider', 'size_units' => [ 'px', 'custom' ], 'responsive' => true ],
					] ],
				],
			];
		}
	}
}

namespace Elementor {
	final class TestBreakpoints { public function get_active_breakpoints(): array { return []; } }
	final class Plugin {
		public TestBreakpoints $breakpoints;
		private static ?self $instance = null;
		private function __construct() { $this->breakpoints = new TestBreakpoints(); }
		public static function instance(): self { return self::$instance ??= new self(); }
	}
}

namespace {
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_url_raw( $value ) { return (string) $value; }

	require_once __DIR__ . '/../../includes/AI/AIResultNormalizer.php';
	require_once __DIR__ . '/../../includes/AI/ElementLocator.php';
	require_once __DIR__ . '/../../includes/AI/SemanticDesignCompiler.php';
	require_once __DIR__ . '/../../includes/AI/AIMutationCompiler.php';

	use CrescoLayer\AI\AIResultNormalizer;
	use CrescoLayer\AI\SemanticDesignCompiler;
	use CrescoLayer\AI\AIMutationCompiler;

	$elements = [[ 'id' => 'abc1234', 'elType' => 'container', 'settings' => [], 'elements' => [] ]];
	$raw = <<<'JSON'
Here is the requested result:
```json
{
  "schema": "cresco-ai-mutation/v3",
  "intent": "add",
  "target": { "postId": 3, "id": "abc1234" },
  "placement": { "mode": "inside-end" },
  "nodes": [
    {
      "ref": "$new:content",
      "widgetIntent": "container",
      "layoutIntent": { "direction": "column", "gap": "24px" },
      "children": [
        {
          "ref": "$new:title",
          "widgetIntent": "heading",
          "content": { "text": "Compiled by Cresco", "semanticLevel": "h2" },
          "styleIntent": { "fontSize": "48px" }
        }
      ]
    }
  ]
}
```
JSON;

	$normalized = ( new AIResultNormalizer() )->normalize( $raw );
	if ( 'semantic-design-mutation' !== $normalized['kind'] ) { throw new RuntimeException( 'v3 normalizer recognition failed' ); }

	$design = ( new SemanticDesignCompiler() )->lower( $normalized['result'], $elements );
	if ( 'cresco-ai-mutation/v2' !== $design['mutation']['schema'] ) { throw new RuntimeException( 'v3 did not lower to v2' ); }
	if ( 24.0 !== $design['mutation']['nodes'][0]['settings']['gap']['size'] ) { throw new RuntimeException( 'semantic layout intent was not compiled' ); }
	if ( 48.0 !== $design['mutation']['nodes'][0]['children'][0]['settings']['typography_font_size']['size'] ) { throw new RuntimeException( 'semantic style intent was not compiled' ); }

	$compiled = ( new AIMutationCompiler() )->compile( $design['mutation'], 3, $elements, 'abc1234' );
	$operation = $compiled['patch']['operations'][0] ?? [];
	if ( 'insert-element' !== ( $operation['operation'] ?? '' ) ) { throw new RuntimeException( 'v3 -> v2 -> patch insertion failed' ); }
	if ( 'container' !== ( $operation['element']['elType'] ?? '' ) ) { throw new RuntimeException( 'container intent was lost' ); }
	if ( 'heading' !== ( $operation['element']['elements'][0]['widgetType'] ?? '' ) ) { throw new RuntimeException( 'heading child intent was lost' ); }
	if ( 'Compiled by Cresco' !== ( $operation['element']['elements'][0]['settings']['title'] ?? '' ) ) { throw new RuntimeException( 'semantic content was not bound by AIMutationCompiler' ); }
	if ( 'h2' !== ( $operation['element']['elements'][0]['settings']['header_size'] ?? '' ) ) { throw new RuntimeException( 'semantic heading level was not preserved' ); }

	echo "ai mutation v3 normalization/lowering pipeline passed\n";
}
