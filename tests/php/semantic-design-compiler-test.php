<?php
namespace CrescoLayer\AI {
	final class CapabilityScanner {
		public function catalog(): array {
			return [
				'elements' => [
					'container' => [
						'controls' => [
							'flex_direction' => [ 'type' => 'choose', 'options' => [ 'row' => 'Row', 'column' => 'Column' ], 'responsive' => true ],
							'gap' => [ 'type' => 'slider', 'size_units' => [ 'px', 'custom' ], 'responsive' => true ],
							'padding' => [ 'type' => 'dimensions', 'size_units' => [ 'px' ], 'responsive' => true, 'default' => [ 'unit' => 'px', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '', 'isLinked' => true ] ],
							'width' => [ 'type' => 'slider', 'size_units' => [ 'px', '%', 'custom' ], 'responsive' => true ],
							'background_background' => [ 'type' => 'choose', 'options' => [ 'classic' => 'Classic', 'gradient' => 'Gradient' ] ],
							'background_color' => [ 'type' => 'color' ],
						],
					],
				],
				'widgets' => [
					'heading' => [
						'controls' => [
							'title' => [ 'type' => 'text' ],
							'header_size' => [ 'type' => 'select', 'options' => [ 'h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3' ] ],
							'typography_font_size' => [ 'type' => 'slider', 'size_units' => [ 'px', 'em', 'rem', 'custom' ], 'responsive' => true ],
							'typography_line_height' => [ 'type' => 'slider', 'size_units' => [ 'px', 'em', 'rem' ], 'responsive' => true ],
							'title_color' => [ 'type' => 'color' ],
							'align' => [ 'type' => 'choose', 'options' => [ 'left' => 'Left', 'center' => 'Center', 'right' => 'Right' ], 'responsive' => true ],
						],
					],
				],
			];
		}
	}

	final class ElementLocator {
		public function find( array $elements, string $id ): ?array {
			foreach ( $elements as $element ) {
				if ( ! is_array( $element ) ) { continue; }
				if ( (string) ( $element['id'] ?? '' ) === $id ) { return $element; }
				$child = $this->find( (array) ( $element['elements'] ?? [] ), $id );
				if ( null !== $child ) { return $child; }
			}
			return null;
		}
	}
}

namespace Elementor {
	final class TestBreakpoint {
		public function __construct( private string $name ) {}
		public function get_name(): string { return $this->name; }
	}
	final class TestBreakpoints {
		public function get_active_breakpoints(): array { return [ 'tablet' => new TestBreakpoint( 'tablet' ), 'mobile' => new TestBreakpoint( 'mobile' ) ]; }
	}
	final class Plugin {
		public TestBreakpoints $breakpoints;
		private static ?self $instance = null;
		private function __construct() { $this->breakpoints = new TestBreakpoints(); }
		public static function instance(): self { return self::$instance ??= new self(); }
	}
}

namespace {
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_url_raw( $value ) { return (string) $value; }

	require_once __DIR__ . '/../../includes/AI/SemanticDesignCompiler.php';

	use CrescoLayer\AI\SemanticDesignCompiler;

	function expect_true( $condition, string $message ): void {
		if ( ! $condition ) { throw new RuntimeException( $message ); }
	}

	$elements = [[
		'id' => 'abc1234', 'elType' => 'container', 'settings' => [],
		'elements' => [[ 'id' => 'def5678', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Before' ], 'elements' => [] ]],
	]];

	$compiler = new SemanticDesignCompiler();
	$add = $compiler->lower([
		'schema' => 'cresco-ai-mutation/v3',
		'intent' => 'add',
		'target' => [ 'postId' => 3, 'id' => 'abc1234' ],
		'nodes' => [[
			'ref' => '$new:section',
			'widgetIntent' => 'container',
			'layoutIntent' => [ 'direction' => 'row', 'gap' => '24px', 'padding' => '32px' ],
			'responsiveIntent' => [ 'mobile' => [ 'layout' => [ 'direction' => 'column', 'gap' => '16px' ] ] ],
			'children' => [[
				'ref' => '$new:title', 'widgetIntent' => 'heading',
				'content' => [ 'text' => 'Semantic heading', 'semanticLevel' => 'h2' ],
				'styleIntent' => [ 'fontSize' => 'clamp(32px, 4vw, 56px)', 'textColor' => '#123456' ],
			]],
		]],
	], $elements );

	expect_true( 'cresco-ai-mutation/v2' === $add['mutation']['schema'], 'v3 must lower to v2' );
	$root = $add['mutation']['nodes'][0];
	expect_true( 'row' === $root['settings']['flex_direction'], 'direction must bind to runtime flex_direction' );
	expect_true( 24.0 === $root['settings']['gap']['size'] && 'px' === $root['settings']['gap']['unit'], 'gap must use the runtime slider shape' );
	expect_true( 'column' === $root['settings']['flex_direction_mobile'], 'mobile structural intent must use an active runtime suffix' );
	expect_true( 16.0 === $root['settings']['gap_mobile']['size'], 'mobile gap must compile through the responsive runtime control' );
	expect_true( 'custom' === $root['children'][0]['settings']['typography_font_size']['unit'], 'fluid typography should use native custom unit only when supported' );
	expect_true( 'clamp(32px, 4vw, 56px)' === $root['children'][0]['settings']['typography_font_size']['size'], 'fluid custom value must remain lossless' );
	expect_true( '#123456' === $root['children'][0]['settings']['title_color'], 'semantic text color must bind to the runtime control' );
	expect_true( $add['report']['compiledIntentCount'] >= 6, 'compiler provenance should report compiled intents' );

	$edit = $compiler->lower([
		'schema' => 'cresco-ai-mutation/v3',
		'intent' => 'edit',
		'target' => [ 'postId' => 3, 'id' => 'abc1234' ],
		'designChanges' => [[
			'elementId' => 'def5678',
			'content' => [ 'text' => 'After', 'semanticLevel' => 'h2' ],
			'styleIntent' => [ 'fontSize' => '48px', 'textAlign' => 'center' ],
			'responsiveIntent' => [ 'mobile' => [ 'style' => [ 'fontSize' => '32px', 'textAlign' => 'left' ] ] ],
		]],
	], $elements );

	$changes = $edit['mutation']['changes'] ?? [];
	$by_setting = [];
	foreach ( $changes as $change ) { $by_setting[ $change['setting'] ] = $change['value']; }
	expect_true( 'After' === $by_setting['title'], 'semantic edit content must resolve against the live widget type' );
	expect_true( 'h2' === $by_setting['header_size'], 'semantic heading level must respect runtime options' );
	expect_true( 48.0 === $by_setting['typography_font_size']['size'], 'semantic edit font size must use runtime slider shape' );
	expect_true( 'center' === $by_setting['align'], 'semantic edit alignment must bind to the runtime choose control' );
	expect_true( 32.0 === $by_setting['typography_font_size_mobile']['size'], 'semantic edit mobile font size must emit only a proven suffix' );
	expect_true( 'left' === $by_setting['align_mobile'], 'semantic edit mobile alignment must compile correctly' );

	$blocked = false;
	try {
		$compiler->lower([
			'schema' => 'cresco-ai-mutation/v3', 'intent' => 'add',
			'nodes' => [[ 'widgetIntent' => 'heading', 'children' => [[ 'widgetIntent' => 'heading' ]] ]],
		], $elements );
	} catch ( InvalidArgumentException $error ) {
		$blocked = str_contains( $error->getMessage(), 'does not place arbitrary Elementor child nodes inside a widget' );
	}
	expect_true( $blocked, 'semantic v3 must block arbitrary widget children' );

	$bad_device = false;
	try {
		$compiler->lower([
			'schema' => 'cresco-ai-mutation/v3', 'intent' => 'add',
			'nodes' => [[ 'widgetIntent' => 'container', 'responsiveIntent' => [ 'watch' => [ 'layout' => [ 'direction' => 'column' ] ] ] ]],
		], $elements );
	} catch ( InvalidArgumentException $error ) {
		$bad_device = str_contains( $error->getMessage(), 'not active' );
	}
	expect_true( $bad_device, 'semantic v3 must reject invented responsive devices' );

	echo "semantic design compiler v3 tests passed\n";
}
