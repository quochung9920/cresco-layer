<?php
namespace CrescoLayer\DesignSystem;

/**
 * Audits the active Elementor Kit against measurable design standards and proposes concrete fixes.
 *
 * Every finding is derived from something objectively checkable — a WCAG contrast ratio, a type
 * scale ratio, a missing global token — rather than from taste. Brand colours are preserved: a
 * failing colour is only moved in lightness, never replaced with a different hue.
 *
 * Proposals are emitted as `update-page-setting` operations against the Kit document, so they flow
 * through the same validator, semantic guard, preview, history and rollback as any AI patch.
 */
final class StandardAuditor {
	public const SCHEMA = 'cresco-design-standard/v1';

	/** Below this, consecutive heading sizes are too close to read as a deliberate hierarchy. */
	private const MIN_TYPE_RATIO = 1.1;
	/** A comfortable measure needs a bounded container; wider than this reads as edge-to-edge text. */
	private const MAX_CONTAINER_PX = 1600;
	private const MIN_CONTAINER_PX = 960;
	private const MIN_BODY_PX = 16;

	public function __construct( private KitSource $kit ) {}

	public function audit(): array {
		$kit = $this->kit->read();
		if ( ! $kit['available'] ) {
			return [
				'schema' => self::SCHEMA,
				'available' => false,
				'errors' => $kit['errors'],
				'message' => 'Elementor has no readable active Kit, so the design standard cannot be evaluated.',
			];
		}

		$findings = [];
		$operations = [];

		$this->check_global_colors( $findings, $operations );
		$this->check_global_typography( $findings, $operations );
		$this->check_body_size( $findings, $operations );
		$this->check_type_scale( $findings );
		$this->check_container_width( $findings, $operations );

		return [
			'schema' => self::SCHEMA,
			'available' => true,
			'kitPostId' => $kit['postId'],
			'score' => $this->score( $findings ),
			'findings' => array_values( $findings ),
			'proposedOperations' => array_values( $operations ),
			'viewportRange' => FluidScale::viewport_range( $kit['breakpoints'] ),
			'errors' => $kit['errors'],
		];
	}

	/**
	 * Contrast of every global colour against the page background.
	 *
	 * Colours are checked, not judged: the proposal keeps the hue and moves only lightness far enough
	 * to clear WCAG AA, so a brand palette survives the fix.
	 */
	private function check_global_colors( array &$findings, array &$operations ): void {
		$background = $this->page_background();
		$colors = $this->kit->global_colors();

		if ( ! $colors ) {
			$findings[] = $this->finding( 'global-colors-missing', 'error', 'No global colours are defined. Every colour on the site will be a local one-off.', 'colors' );
			return;
		}

		foreach ( $colors as $id => $color ) {
			if ( '' === $color['color'] ) {
				$findings[] = $this->finding( 'global-color-empty', 'warning', sprintf( 'Global colour "%s" has no value.', $color['title'] ), 'colors' );
				continue;
			}
			// A background swatch is not foreground text; judging it against the background is meaningless.
			if ( $this->is_background_token( $id, $color['title'] ) ) { continue; }

			$ratio = ContrastRatio::between( $color['color'], $background );
			if ( null === $ratio ) {
				$findings[] = $this->finding( 'global-color-unparsed', 'info', sprintf( 'Global colour "%s" (%s) could not be measured for contrast.', $color['title'], $color['color'] ), 'colors' );
				continue;
			}
			if ( ContrastRatio::passes( $ratio ) ) {
				$findings[] = $this->finding( 'global-color-ok', 'pass', sprintf( '"%s" %s on %s is %s:1 — meets AA.', $color['title'], $color['color'], $background, $ratio ), 'colors' );
				continue;
			}

			$suggested = ContrastRatio::adjust_for( $color['color'], $background );
			$severity = $ratio < ContrastRatio::AA_LARGE ? 'error' : 'warning';
			$findings[] = $this->finding(
				'global-color-contrast',
				$severity,
				sprintf( '"%s" %s on %s is only %s:1 — below the 4.5:1 AA minimum for body text.%s', $color['title'], $color['color'], $background, $ratio, $suggested ? sprintf( ' Same hue at %s reaches AA.', $suggested ) : ' No same-hue fix reaches AA; this colour needs a design decision.' ),
				'colors',
				$suggested ? [ 'current' => $color['color'], 'suggested' => $suggested, 'ratio' => $ratio ] : [ 'current' => $color['color'], 'ratio' => $ratio ]
			);

			if ( $suggested ) {
				$operation = $this->color_operation( $color['bucket'], $id, $suggested );
				if ( $operation ) { $operations[] = $operation; }
			}
		}
	}

	private function check_global_typography( array &$findings, array &$operations ): void {
		$fonts = $this->kit->global_typography();
		if ( ! $fonts ) {
			$findings[] = $this->finding( 'global-fonts-missing', 'error', 'No global typography is defined. Font choices will be repeated per widget instead of controlled centrally.', 'typography' );
			return;
		}
		$without_family = [];
		foreach ( $fonts as $font ) {
			if ( '' === $font['fontFamily'] ) { $without_family[] = $font['title']; }
		}
		if ( $without_family ) {
			$findings[] = $this->finding( 'global-font-family-missing', 'warning', sprintf( 'Global typography without a font family: %s. These fall back to the theme font.', implode( ', ', $without_family ) ), 'typography' );
		} else {
			$findings[] = $this->finding( 'global-fonts-ok', 'pass', sprintf( '%d global typography entries, all with a font family.', count( $fonts ) ), 'typography' );
		}
	}

	/** Body text below 16px is the single most common readability regression on a real site. */
	private function check_body_size( array &$findings, array &$operations ): void {
		$name = 'body_typography_font_size';
		if ( ! $this->kit->has_control( $name ) ) { return; }
		$value = $this->kit->setting( $name );
		$px = $this->px_value( $value );
		if ( null === $px ) {
			$findings[] = $this->finding( 'body-size-unset', 'info', 'Body font size is not set in the Kit; the theme decides it.', 'typography' );
			return;
		}
		if ( $px >= self::MIN_BODY_PX ) {
			$findings[] = $this->finding( 'body-size-ok', 'pass', sprintf( 'Body text is %spx.', $px ), 'typography' );
			return;
		}
		$findings[] = $this->finding(
			'body-size-small',
			'warning',
			sprintf( 'Body text is %spx, below the %dpx that keeps long-form reading comfortable.', $px, self::MIN_BODY_PX ),
			'typography',
			[ 'current' => $px, 'suggested' => self::MIN_BODY_PX ]
		);
		$operations[] = [
			'operation' => 'update-page-setting',
			'setting' => $name,
			'value' => [ 'unit' => 'px', 'size' => self::MIN_BODY_PX ],
			'crescoReason' => 'Raise body text to a comfortable reading size.',
		];
	}

	/** A hierarchy where every heading is nearly the same size is not a hierarchy. */
	private function check_type_scale( array &$findings ): void {
		$sizes = [];
		foreach ( $this->kit->font_size_controls() as $name => $control ) {
			$px = $this->px_value( $this->kit->setting( $name ) );
			if ( null !== $px && $px > 0 ) { $sizes[ $name ] = $px; }
		}
		if ( count( $sizes ) < 2 ) {
			$findings[] = $this->finding( 'type-scale-unknown', 'info', 'Not enough font sizes are set in the Kit to evaluate a type scale.', 'typography' );
			return;
		}
		$ratio = FluidScale::observed_ratio( array_values( $sizes ) );
		if ( null === $ratio ) { return; }
		if ( $ratio < self::MIN_TYPE_RATIO ) {
			$findings[] = $this->finding(
				'type-scale-flat',
				'warning',
				sprintf( 'Font sizes step by only %sx on average — too close to read as a deliberate hierarchy. A modular scale of 1.200–1.333 separates levels clearly.', $ratio ),
				'typography',
				[ 'observedRatio' => $ratio, 'sizes' => $sizes ]
			);
			return;
		}
		$findings[] = $this->finding( 'type-scale-ok', 'pass', sprintf( 'Font sizes follow a %sx scale.', $ratio ), 'typography' );
	}

	private function check_container_width( array &$findings, array &$operations ): void {
		$name = 'container_width';
		if ( ! $this->kit->has_control( $name ) ) { return; }
		$px = $this->px_value( $this->kit->setting( $name ) );
		if ( null === $px ) {
			$findings[] = $this->finding( 'container-width-unset', 'info', 'Content container width is not set; Elementor uses its default.', 'layout' );
			return;
		}
		if ( $px > self::MAX_CONTAINER_PX ) {
			$findings[] = $this->finding( 'container-too-wide', 'warning', sprintf( 'Content container is %spx. Beyond about %dpx, text lines get long enough to hurt readability.', $px, self::MAX_CONTAINER_PX ), 'layout', [ 'current' => $px, 'suggested' => 1200 ] );
			$operations[] = [ 'operation' => 'update-page-setting', 'setting' => $name, 'value' => [ 'unit' => 'px', 'size' => 1200 ], 'crescoReason' => 'Bound the content width to keep line length readable.' ];
			return;
		}
		if ( $px < self::MIN_CONTAINER_PX ) {
			$findings[] = $this->finding( 'container-narrow', 'info', sprintf( 'Content container is %spx, which is narrow for a desktop layout.', $px ), 'layout', [ 'current' => $px ] );
			return;
		}
		$findings[] = $this->finding( 'container-ok', 'pass', sprintf( 'Content container is %spx.', $px ), 'layout' );
	}

	/**
	 * Build the operation that rewrites one colour inside its list, preserving every sibling entry.
	 * Elementor stores colours as a repeater, so the whole list is written back with one value changed.
	 */
	private function color_operation( string $bucket, string $id, string $color ): ?array {
		if ( ! $this->kit->has_control( $bucket ) ) { return null; }
		$list = $this->kit->setting( $bucket );
		if ( ! is_array( $list ) ) { return null; }
		$changed = false;
		foreach ( $list as $index => $entry ) {
			if ( ! is_array( $entry ) || (string) ( $entry['_id'] ?? '' ) !== $id ) { continue; }
			$list[ $index ]['color'] = $color;
			$changed = true;
		}
		if ( ! $changed ) { return null; }
		return [
			'operation' => 'update-page-setting',
			'setting' => $bucket,
			'value' => $list,
			'crescoReason' => sprintf( 'Raise "%s" to WCAG AA contrast while keeping its hue.', $id ),
		];
	}

	private function page_background(): string {
		foreach ( [ 'body_background_background', 'body_background_color' ] as $name ) {
			$value = $this->kit->setting( $name );
			if ( is_string( $value ) && null !== ContrastRatio::parse( $value ) ) { return $value; }
		}
		return '#FFFFFF';
	}

	/** Background tokens are surfaces, not foreground text, so contrast rules do not apply to them. */
	private function is_background_token( string $id, string $title ): bool {
		$haystack = strtolower( $id . ' ' . $title );
		foreach ( [ 'background', 'surface', 'backdrop' ] as $needle ) {
			if ( str_contains( $haystack, $needle ) ) { return true; }
		}
		return false;
	}

	/** Elementor sizes arrive as [ unit, size ]; only px and rem convert to a comparable number. */
	private function px_value( $value ): ?float {
		if ( is_numeric( $value ) ) { return (float) $value; }
		if ( ! is_array( $value ) || ! isset( $value['size'] ) || ! is_numeric( $value['size'] ) ) { return null; }
		$size = (float) $value['size'];
		$unit = strtolower( (string) ( $value['unit'] ?? 'px' ) );
		if ( 'px' === $unit ) { return $size; }
		if ( 'rem' === $unit || 'em' === $unit ) { return $size * FluidScale::ROOT_PX; }
		return null;
	}

	private function finding( string $code, string $severity, string $message, string $group, array $data = [] ): array {
		return [ 'code' => $code, 'severity' => $severity, 'message' => $message, 'group' => $group, 'data' => $data ];
	}

	/** A simple, explainable score: passes over checks that actually ran. */
	private function score( array $findings ): array {
		$counts = [ 'pass' => 0, 'info' => 0, 'warning' => 0, 'error' => 0 ];
		foreach ( $findings as $finding ) {
			$severity = (string) $finding['severity'];
			if ( isset( $counts[ $severity ] ) ) { $counts[ $severity ]++; }
		}
		$graded = $counts['pass'] + $counts['warning'] + $counts['error'];
		$value = $graded > 0 ? (int) round( ( $counts['pass'] / $graded ) * 100 ) : null;
		return [ 'value' => $value, 'counts' => $counts ];
	}
}
