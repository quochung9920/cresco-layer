<?php
namespace CrescoLayer\SiteSettings\Verify;

use CrescoLayer\SiteSettings\Support\ManagedCssBlock;

/**
 * Reduces an Elementor control value to the form that decides whether two values mean the same thing.
 *
 * Elementor does not store back exactly what it was given. A slider gains the `sizes` key from its
 * control default, numbers move between `16`, `"16"` and `16.0` depending on which code path last
 * touched them, a dimensions value gains `isLinked`, and a repeater row gains fields registered by an
 * addon. Comparing raw arrays therefore reports differences that have no effect on the rendered CSS,
 * which is what turned a correct write into VERIFICATION_FAILED across 37 controls.
 *
 * Normalisation is per control type because the rules differ: `sizes` is noise on a slider but a
 * real field on a repeater row, and whitespace is noise in CSS but not in a font family name.
 */
final class ValueNormalizer {
	/** Keys Elementor adds from a control default and which never carry meaning on their own. */
	private const SLIDER_NOISE = [ 'sizes' ];

	public function __construct( private ?ManagedCssBlock $css = null ) {
		$this->css = $css ?? new ManagedCssBlock();
	}

	/**
	 * @param string $type Elementor control type, e.g. slider, dimensions, gaps, color, repeater.
	 * @return mixed A comparable canonical form.
	 */
	public function normalize( $value, string $type = '' ) {
		return match ( $this->family( $type ) ) {
			'slider' => $this->slider( $value ),
			'dimensions' => $this->dimensions( $value ),
			'gaps' => $this->gaps( $value ),
			'repeater' => $this->repeater( $value ),
			'color' => $this->color( $value ),
			'code' => $this->code( $value ),
			'select' => $this->scalar( $value ),
			default => $this->generic( $value ),
		};
	}

	/** Group several Elementor control types that share one comparison rule. */
	private function family( string $type ): string {
		$type = strtolower( $type );
		return match ( $type ) {
			'slider', 'number' => 'slider',
			'dimensions' => 'dimensions',
			'gaps' => 'gaps',
			'repeater' => 'repeater',
			'color' => 'color',
			'code', 'textarea' => 'code',
			'select', 'select2', 'choose', 'switcher', 'popover_toggle' => 'select',
			default => '',
		};
	}

	/**
	 * A slider is (unit, size). `sizes` is a control default that carries nothing unless a range
	 * slider actually populated it, and a size is compared as a number when it is one and as a raw
	 * CSS expression when it is a custom unit.
	 */
	private function slider( $value ): array {
		if ( ! is_array( $value ) ) {
			return [ 'size' => $this->number_or_text( $value ) ];
		}
		$unit = isset( $value['unit'] ) ? strtolower( trim( (string) $value['unit'] ) ) : '';
		$size = $value['size'] ?? '';

		$out = [ 'size' => 'custom' === $unit ? $this->css_expression( (string) $size ) : $this->number_or_text( $size ) ];
		// A control with no unit switcher is written without a unit. Emitting an empty one here would
		// turn "Cresco does not manage this" into "must be empty" and fail against Elementor's default.
		if ( array_key_exists( 'unit', $value ) ) { $out['unit'] = $unit; }

		// Only keep `sizes` when a range slider genuinely used it.
		foreach ( self::SLIDER_NOISE as $key ) {
			if ( ! empty( $value[ $key ] ) && is_array( $value[ $key ] ) ) {
				$out[ $key ] = array_map( fn( $item ) => $this->number_or_text( $item ), $value[ $key ] );
			}
		}
		return $out;
	}

	/** Dimensions compare side by side; `isLinked` is presentational state in the editor, not CSS. */
	private function dimensions( $value ): array {
		if ( ! is_array( $value ) ) { return [ 'unit' => '', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ]; }
		$unit = isset( $value['unit'] ) ? strtolower( trim( (string) $value['unit'] ) ) : '';
		$out = [ 'unit' => $unit ];
		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			$side_value = $value[ $side ] ?? '';
			$out[ $side ] = 'custom' === $unit ? $this->css_expression( (string) $side_value ) : $this->number_or_text( $side_value );
		}
		return $out;
	}

	/** A gaps control is a two-axis dimensions variant. */
	private function gaps( $value ): array {
		if ( ! is_array( $value ) ) { return [ 'unit' => '', 'column' => '', 'row' => '' ]; }
		$unit = isset( $value['unit'] ) ? strtolower( trim( (string) $value['unit'] ) ) : '';
		$out = [ 'unit' => $unit ];
		foreach ( [ 'column', 'row', 'size' ] as $axis ) {
			if ( ! array_key_exists( $axis, $value ) ) { continue; }
			$out[ $axis ] = 'custom' === $unit ? $this->css_expression( (string) $value[ $axis ] ) : $this->number_or_text( $value[ $axis ] );
		}
		return $out;
	}

	/** Repeater rows are keyed by `_id` so a reorder is not a change, and each field is normalised. */
	private function repeater( $value ): array {
		if ( ! is_array( $value ) ) { return []; }
		$out = [];
		foreach ( $value as $index => $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$id = (string) ( $row['_id'] ?? $index );
			$fields = [];
			foreach ( $row as $key => $item ) {
				$fields[ (string) $key ] = $this->generic( $item );
			}
			ksort( $fields, SORT_STRING );
			$out[ $id ] = $fields;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** Colours are case-insensitive, and #ABC is #AABBCC. */
	private function color( $value ): string {
		$text = trim( (string) ( is_scalar( $value ) ? $value : '' ) );
		if ( preg_match( '/^#([0-9a-f]{3})$/i', $text, $match ) ) {
			$hex = $match[1];
			$text = '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return strtoupper( $text );
	}

	/**
	 * CSS compares by meaning, not by layout: line endings, indentation and trailing whitespace are
	 * editor artefacts. Only the Cresco-managed block is compared, because CSS outside it belongs to
	 * the site owner and is never part of what Cresco asked for.
	 */
	private function code( $value ): string {
		$text = (string) ( is_scalar( $value ) ? $value : '' );
		$managed = $this->css->extract( $text );
		return $this->collapse_css( null === $managed ? $text : $managed );
	}

	private function collapse_css( string $css ): string {
		$css = str_replace( [ "\r\n", "\r" ], "\n", $css );
		$css = preg_replace( '/\s+/', ' ', $css );
		$css = preg_replace( '/\s*([{};:,])\s*/', '$1', (string) $css );
		return trim( (string) $css, " ;" );
	}

	/** A fluid expression differs only by whitespace between authoring styles. */
	private function css_expression( string $expression ): string {
		$text = preg_replace( '/\s+/', ' ', trim( $expression ) );
		$text = preg_replace( '/\s*,\s*/', ',', (string) $text );
		$text = preg_replace( '/\s*\(\s*/', '(', (string) $text );
		$text = preg_replace( '/\s*\)\s*/', ')', (string) $text );
		return strtolower( (string) $text );
	}

	private function scalar( $value ): string {
		if ( is_bool( $value ) ) { return $value ? '1' : ''; }
		if ( null === $value ) { return ''; }
		if ( is_array( $value ) ) { return (string) wp_json_encode( $this->generic( $value ) ); }
		return trim( (string) $value );
	}

	/** Recursive fallback for values with no control-specific rule. */
	private function generic( $value ) {
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $key => $item ) { $out[ (string) $key ] = $this->generic( $item ); }
			ksort( $out, SORT_STRING );
			return $out;
		}
		return $this->number_or_text( $value );
	}

	/** "16", 16 and 16.0 are the same value; anything else compares as trimmed text. */
	private function number_or_text( $value ): string {
		if ( is_bool( $value ) ) { return $value ? '1' : ''; }
		if ( null === $value ) { return ''; }
		if ( is_array( $value ) ) { return (string) wp_json_encode( $value ); }
		$text = trim( (string) $value );
		if ( '' === $text ) { return ''; }
		if ( is_numeric( $text ) ) {
			return rtrim( rtrim( number_format( (float) $text, 6, '.', '' ), '0' ), '.' ) ?: '0';
		}
		// Hex colours are case-insensitive in CSS wherever they appear, including inside group controls
		// such as background, where the sub-key has no control type of its own.
		if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $text ) ) { return $this->color( $text ); }
		return $text;
	}

	/**
	 * True when the actual value carries everything the expected value asks for.
	 *
	 * Subset rather than equality: Cresco owns only the keys it declares, and a key added by
	 * Elementor, a theme or an addon is somebody else's to manage.
	 */
	public function satisfies( $actual, $expected, string $type = '' ): bool {
		return $this->contains( $this->normalize( $actual, $type ), $this->normalize( $expected, $type ) );
	}

	private function contains( $actual, $expected ): bool {
		if ( is_array( $expected ) ) {
			if ( ! is_array( $actual ) ) { return false; }
			foreach ( $expected as $key => $value ) {
				if ( ! array_key_exists( $key, $actual ) ) { return false; }
				if ( ! $this->contains( $actual[ $key ], $value ) ) { return false; }
			}
			return true;
		}
		return $actual === $expected;
	}
}
