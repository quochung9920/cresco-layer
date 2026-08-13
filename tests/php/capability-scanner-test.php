<?php
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
}

require_once dirname( __DIR__, 2 ) . '/includes/AI/CapabilityScanner.php';

use CrescoLayer\AI\CapabilityScanner;

function scanner_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

final class ClassicScannerFixture {
	public int $settings_calls = 0;
	public function get_name() { return 'classic-fixture'; }
	public function get_title() { return 'Classic Fixture'; }
	public function get_controls() {
		return [
			'padding' => [
				'type' => 'dimensions',
				'label' => 'Padding',
				'responsive' => true,
				'size_units' => [ 'px', '%' ],
				'default' => [ 'unit' => 'px', 'top' => '10', 'right' => '10', 'bottom' => '10', 'left' => '10' ],
				'tablet_default' => [ 'unit' => 'px', 'top' => '8', 'right' => '8', 'bottom' => '8', 'left' => '8' ],
			],
		];
	}
	public function get_settings() {
		$this->settings_calls++;
		throw new RuntimeException( 'Registry prototypes must not call get_settings().' );
	}
}

final class AtomicControlFixture implements JsonSerializable {
	public function jsonSerialize(): array {
		return [
			'type' => 'control',
			'value' => [
				'type' => 'text',
				'bind' => 'title',
				'label' => 'Title',
				'description' => 'Atomic title',
				'props' => [ 'placeholder' => 'Enter title' ],
				'meta' => [ 'layout' => 'full' ],
			],
		];
	}
}

final class AtomicSectionFixture implements JsonSerializable {
	public function jsonSerialize(): array {
		return [
			'type' => 'section',
			'value' => [
				'id' => 'content',
				'label' => 'Content',
				'items' => [ new AtomicControlFixture() ],
			],
		];
	}
}

final class AtomicPropFixture implements JsonSerializable {
	private string $default;
	public function __construct( string $default ) { $this->default = $default; }
	public static function get_key(): string { return 'string'; }
	public function get_type(): string { return 'plain'; }
	public function get_default() { return $this->default; }
	public function get_initial_value() { return [ '$$type' => 'string', 'value' => $this->default ]; }
	public function get_meta(): array { return [ 'editable' => true ]; }
	public function get_settings(): array { return [ 'required' => false ]; }
	public function get_dependencies(): ?array { return null; }
	public function get_aliases(): array { return [ 'text' ]; }
	public function to_json_schema(): array { return [ 'type' => 'object' ]; }
	public function jsonSerialize(): array {
		return [
			'kind' => 'plain',
			'key' => 'string',
			'default' => $this->default,
			'meta' => (object) [ 'editable' => true ],
			'settings' => (object) [ 'required' => false ],
			'initial_value' => $this->get_initial_value(),
		];
	}
}

final class AtomicScannerFixture {
	public function get_name() { return 'e-atomic-fixture'; }
	public function get_title() { return 'Atomic Fixture'; }
	public function get_atomic_controls() { return [ new AtomicSectionFixture() ]; }
	public static function get_props_schema(): array {
		return [
			'title' => new AtomicPropFixture( 'Hello' ),
			'internal_note' => new AtomicPropFixture( 'Note' ),
		];
	}
	public function get_base_settings(): array { return [ 'title' => [ '$$type' => 'string', 'value' => 'Hello' ] ]; }
	public function get_base_styles(): array { return [ 'base' => [ 'display' => 'block' ] ]; }
	public function get_base_styles_dictionary(): array { return [ 'base' => 'e-atomic-fixture-base' ]; }
	public function get_controls() { return []; }
	public function get_settings() { throw new RuntimeException( 'Atomic registry prototype get_settings() must not be called.' ); }
}

$scanner = new CapabilityScanner();
$describe = new ReflectionMethod( CapabilityScanner::class, 'describe_instance' );
$describe->setAccessible( true );

$classic_fixture = new ClassicScannerFixture();
$classic = $describe->invoke( $scanner, $classic_fixture, 'classic-fixture', true, 'widget' );
scanner_assert( 0 === $classic_fixture->settings_calls, 'Classic registry scanner must never call get_settings().' );
scanner_assert( 1 === $classic['controlCount'], 'Classic control should be discovered.' );
scanner_assert( 'classic-controls' === $classic['capabilitySource'], 'Classic capability source should be explicit.' );
scanner_assert( isset( $classic['defaultSettings']['padding'] ), 'Classic default should come from control metadata.' );
scanner_assert( isset( $classic['defaultSettings']['padding_tablet'] ), 'Responsive classic default should be normalized.' );
scanner_assert( true === $classic['controls']['padding']['responsive'], 'Classic responsive metadata should be preserved.' );

$atomic = $describe->invoke( $scanner, new AtomicScannerFixture(), 'e-atomic-fixture', true, 'element' );
scanner_assert( true === $atomic['isAtomic'], 'Atomic element should be detected.' );
scanner_assert( 'atomic-controls+props-schema' === $atomic['capabilitySource'], 'Atomic source should use controls plus props schema.' );
scanner_assert( 1 === $atomic['atomicUiControlCount'], 'Atomic UI control tree should be counted.' );
scanner_assert( 2 === $atomic['atomicPropCount'], 'All Atomic props should be discovered.' );
scanner_assert( 2 === $atomic['controlCount'], 'Schema-only Atomic props should also appear in normalized controls.' );
scanner_assert( 'atomic-control' === $atomic['controls']['title']['source'], 'Bound Atomic UI control should be normalized.' );
scanner_assert( 'atomic-props-schema' === $atomic['controls']['internal_note']['source'], 'Schema-only Atomic prop should remain visible.' );
scanner_assert( 'string' === $atomic['controls']['title']['propSchema']['key'], 'Atomic prop type key should be exposed.' );
scanner_assert( 'Hello' === $atomic['defaultSettings']['title']['value'], 'Atomic initial value should be used as registry default.' );
scanner_assert( isset( $atomic['atomicControls'][0]['value']['items'][0]['value']['bind'] ), 'JsonSerializable Atomic control tree should be preserved.' );
scanner_assert( empty( $atomic['scanErrors'] ), 'Supported Atomic fixture should scan without errors.' );

echo "Capability scanner classic + Atomic tests passed.\n";
