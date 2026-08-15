<?php
namespace CrescoLayer\DesignSystem;

/**
 * Named baseline standards for a new or unopinionated Kit.
 *
 * Unlike the auditor, a preset is deliberately opinionated — that is its value. It therefore never
 * touches brand colours: only measurable structure (type scale, container width, radii, spacing and
 * responsive structure) is written, so applying one cannot silently rebrand a site.
 *
 * Every proposed setting is checked against the live Kit controls before it is emitted, because Kit
 * control names differ between Elementor versions and the plugin never invents a setting key.
 */
final class Presets {
	public const SCHEMA = 'cresco-design-preset/v1';

	public function __construct( private KitSource $kit ) {}

	/** @return array<string,array> Definitions keyed by preset id. */
	public function definitions(): array {
		return [
			'balanced-responsive' => [
				'label' => 'Cresco Balanced Responsive',
				'description' => 'Fluid-first global baseline for desktop, laptop, tablet and mobile. Breakpoints handle structure; clamp() handles scale.',
				'baseFontPx' => 16,
				'scaleRatio' => 1.25,
				'containerPx' => 1320,
				'buttonRadiusPx' => 6,
				'imageRadiusPx' => 10,
				'laptopBreakpointPx' => 1366,
				'breakpoints' => [ 'mobile' => 767, 'tablet' => 1024, 'laptop' => 1366 ],
				'gutterPx' => [ 'desktop' => 48, 'laptop' => 40, 'tablet' => 32, 'mobile' => 20 ],
				'sectionPaddingYPx' => [ 'desktop' => 96, 'laptop' => 80, 'tablet' => 64, 'mobile' => 48 ],
				'headingRanges' => [
					'h1_typography_font_size' => [ 36, 56 ],
					'h2_typography_font_size' => [ 30, 44 ],
					'h3_typography_font_size' => [ 26, 36 ],
					'h4_typography_font_size' => [ 22, 28 ],
					'h5_typography_font_size' => [ 18, 22 ],
					'h6_typography_font_size' => [ 16, 18 ],
				],
			],
			'editorial' => [
				'label' => 'Editorial',
				'description' => 'Long-form reading: generous body size, strong heading contrast, narrow measure.',
				'baseFontPx' => 18,
				'scaleRatio' => 1.333,
				'containerPx' => 1080,
				'buttonRadiusPx' => 4,
				'imageRadiusPx' => 4,
			],
			'saas' => [
				'label' => 'SaaS',
				'description' => 'Product marketing: compact rhythm, rounded controls, roomy container.',
				'baseFontPx' => 16,
				'scaleRatio' => 1.25,
				'containerPx' => 1200,
				'buttonRadiusPx' => 8,
				'imageRadiusPx' => 12,
			],
			'commerce' => [
				'label' => 'Commerce',
				'description' => 'Dense catalogues: tighter scale, wide container, restrained rounding.',
				'baseFontPx' => 16,
				'scaleRatio' => 1.2,
				'containerPx' => 1320,
				'buttonRadiusPx' => 6,
				'imageRadiusPx' => 8,
			],
		];
	}

	public function catalog(): array {
		$out = [];
		foreach ( $this->definitions() as $id => $definition ) {
			$scale = FluidScale::modular_scale( (float) $definition['baseFontPx'], (float) $definition['scaleRatio'], 6 );
			$item = [
				'id' => $id,
				'label' => $definition['label'],
				'description' => $definition['description'],
				'baseFontPx' => $definition['baseFontPx'],
				'scaleRatio' => $definition['scaleRatio'],
				'containerPx' => $definition['containerPx'],
				'typeScale' => $scale,
			];
			foreach ( [ 'breakpoints', 'gutterPx', 'sectionPaddingYPx', 'headingRanges' ] as $key ) {
				if ( isset( $definition[ $key ] ) ) { $item[ $key ] = $definition[ $key ]; }
			}
			$out[] = $item;
		}
		return [ 'schema' => self::SCHEMA, 'presets' => $out ];
	}

	public function plan( string $id ): array {
		$definitions = $this->definitions();
		if ( ! isset( $definitions[ $id ] ) ) {
			throw new \InvalidArgumentException( 'Unknown Cresco design preset.' );
		}
		$kit = $this->kit->read();
		if ( ! $kit['available'] ) {
			return [ 'schema' => self::SCHEMA, 'available' => false, 'operations' => [], 'errors' => $kit['errors'] ];
		}

		$definition = $definitions[ $id ];
		$operations = [];
		$unsupported = [];

		$candidates = [
			[ 'body_typography_font_size', [ 'unit' => 'px', 'size' => $definition['baseFontPx'] ], 'Set the body size this preset is scaled from.' ],
			[ 'container_width', [ 'unit' => 'px', 'size' => $definition['containerPx'] ], 'Bound the content width for this layout style.' ],
			[ 'button_border_radius', $this->dimension( $definition['buttonRadiusPx'] ), 'Apply the preset button rounding.' ],
			[ 'image_border_radius', $this->dimension( $definition['imageRadiusPx'] ), 'Apply the preset image rounding.' ],
		];

		if ( 'balanced-responsive' === $id ) {
			$candidates = array_merge( $candidates, $this->balanced_candidates( $definition, $kit ) );
		}

		foreach ( $candidates as [ $setting, $value, $reason ] ) {
			if ( ! $this->kit->has_control( $setting ) ) {
				$unsupported[] = [ 'setting' => $setting, 'reason' => 'This Elementor version does not register that Kit control.' ];
				continue;
			}
			$operations[] = [
				'operation' => 'update-page-setting',
				'setting' => $setting,
				'value' => $value,
				'crescoReason' => $reason,
			];
		}

		return [
			'schema' => self::SCHEMA,
			'available' => true,
			'kitPostId' => $kit['postId'],
			'preset' => [ 'id' => $id, 'label' => $definition['label'], 'description' => $definition['description'] ],
			'typeScale' => FluidScale::modular_scale( (float) $definition['baseFontPx'], (float) $definition['scaleRatio'], 6 ),
			'responsiveProfile' => $this->responsive_profile( $definition ),
			'operations' => $operations,
			'unsupported' => $unsupported,
			'errors' => $kit['errors'],
			'preservesBrandColors' => true,
		];
	}

	/** @return array<int,array{0:string,1:mixed,2:string}> */
	private function balanced_candidates( array $definition, array $kit ): array {
		$out = [];

		$active = array_values( array_map( 'strval', (array) ( $kit['settings']['active_breakpoints'] ?? [] ) ) );
		if ( ! in_array( 'viewport_laptop', $active, true ) ) { $active[] = 'viewport_laptop'; }
		$out[] = [ 'active_breakpoints', $active, 'Enable Elementor\'s laptop breakpoint while preserving the site\'s already-active responsive breakpoints.' ];
		$out[] = [ 'viewport_laptop', (int) $definition['laptopBreakpointPx'], 'Set the laptop breakpoint to 1366px for the four-device responsive baseline.' ];

		$breakpoints = [
			'mobile' => (float) $definition['breakpoints']['mobile'],
			'tablet' => (float) $definition['breakpoints']['tablet'],
			'laptop' => (float) $definition['breakpoints']['laptop'],
		];
		[ $min_viewport, $max_viewport ] = FluidScale::viewport_range( $breakpoints );

		foreach ( $definition['headingRanges'] as $setting => [ $min, $max ] ) {
			if ( ! $this->kit->has_control( $setting ) || ! $this->kit->supports_custom_unit( $setting ) ) {
				continue;
			}
			$expression = FluidScale::clamp( (float) $min, (float) $max, $min_viewport, $max_viewport );
			if ( null === $expression ) { continue; }
			$out[] = [
				$setting,
				[ 'unit' => 'custom', 'size' => $expression ],
				sprintf( 'Scale %s fluidly from %spx on small screens to %spx on large screens.', $setting, $min, $max ),
			];
		}

		return $out;
	}

	private function responsive_profile( array $definition ): ?array {
		if ( ! isset( $definition['breakpoints'] ) ) { return null; }
		return [
			'strategy' => 'fluid-first-breakpoint-structural',
			'breakpoints' => $definition['breakpoints'],
			'gutterPx' => $definition['gutterPx'],
			'sectionPaddingYPx' => $definition['sectionPaddingYPx'],
			'headingRanges' => $definition['headingRanges'],
			'note' => 'Typography is written with clamp() when the live Elementor control accepts the custom unit. Gutter and section spacing remain profile guidance unless the active Kit exposes native controls for them.',
		];
	}

	/** Elementor dimension controls carry all four sides plus a linked flag. */
	private function dimension( int $px ): array {
		return [ 'unit' => 'px', 'top' => $px, 'right' => $px, 'bottom' => $px, 'left' => $px, 'isLinked' => true ];
	}
}
