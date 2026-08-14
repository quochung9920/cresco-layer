<?php
namespace CrescoLayer\DesignSystem;

/**
 * Named baseline standards for a new or unopinionated Kit.
 *
 * Unlike the auditor, a preset is deliberately opinionated — that is its value. It therefore never
 * touches brand colours: only measurable structure (type scale, container width, radii, spacing) is
 * written, so applying one cannot silently rebrand a site.
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
			$out[] = [
				'id' => $id,
				'label' => $definition['label'],
				'description' => $definition['description'],
				'baseFontPx' => $definition['baseFontPx'],
				'scaleRatio' => $definition['scaleRatio'],
				'containerPx' => $definition['containerPx'],
				'typeScale' => $scale,
			];
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
			'operations' => $operations,
			'unsupported' => $unsupported,
			'errors' => $kit['errors'],
			'preservesBrandColors' => true,
		];
	}

	/** Elementor dimension controls carry all four sides plus a linked flag. */
	private function dimension( int $px ): array {
		return [ 'unit' => 'px', 'top' => $px, 'right' => $px, 'bottom' => $px, 'left' => $px, 'isLinked' => true ];
	}
}
