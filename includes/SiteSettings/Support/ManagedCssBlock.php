<?php
namespace CrescoLayer\SiteSettings\Support;

/**
 * Ownership of a single delimited region inside the Kit's Global Custom CSS.
 *
 * Cresco writes fluid tokens into CSS the site owner also edits by hand, so it may only ever touch
 * the region between its own markers. Everything before, after, or between other content is
 * returned untouched — replacing the field wholesale would destroy work the plugin did not write.
 *
 * The operation is idempotent: applying the same tokens twice produces a byte-identical string, so
 * the diff engine can report NO_OP instead of re-saving the Kit.
 */
final class ManagedCssBlock {
	public const START = '/* CRESCO:FLUID-TOKENS:START */';
	public const END   = '/* CRESCO:FLUID-TOKENS:END */';

	/**
	 * Return $css with the managed block set to $body, appending the block when absent.
	 * Passing an empty $body removes the block entirely.
	 */
	public function write( string $css, string $body ): string {
		$existing = $this->extract( $css );
		$body = trim( $body );

		if ( '' === $body ) { return $this->remove( $css ); }

		$block = self::START . "\n" . $body . "\n" . self::END;

		if ( null === $existing ) {
			$base = rtrim( $css );
			return '' === $base ? $block : $base . "\n\n" . $block;
		}

		return $this->replace_block( $css, $block );
	}

	/** The body currently inside the managed block, or null when there is no block. */
	public function extract( string $css ): ?string {
		$start = strpos( $css, self::START );
		if ( false === $start ) { return null; }
		$end = strpos( $css, self::END, $start );
		if ( false === $end ) { return null; }
		$inner_start = $start + strlen( self::START );
		return trim( substr( $css, $inner_start, $end - $inner_start ) );
	}

	/** Everything outside the managed block, which Cresco must never modify. */
	public function user_css( string $css ): string {
		$start = strpos( $css, self::START );
		if ( false === $start ) { return trim( $css ); }
		$end = strpos( $css, self::END, $start );
		if ( false === $end ) { return trim( $css ); }
		$before = substr( $css, 0, $start );
		$after = substr( $css, $end + strlen( self::END ) );
		return trim( rtrim( $before ) . "\n" . ltrim( $after ) );
	}

	public function remove( string $css ): string {
		$start = strpos( $css, self::START );
		if ( false === $start ) { return $css; }
		$end = strpos( $css, self::END, $start );
		if ( false === $end ) { return $css; }
		$before = substr( $css, 0, $start );
		$after = substr( $css, $end + strlen( self::END ) );
		return trim( rtrim( $before ) . "\n" . ltrim( $after ) );
	}

	public function has_block( string $css ): bool {
		return null !== $this->extract( $css );
	}

	/**
	 * Render `:root { … }` from a token map. Values are expected to be pre-validated; the caller
	 * owns that check because rejecting a bad token is a reportable outcome, not a silent drop.
	 *
	 * @param array<string,string> $tokens Custom property name (with leading --) => value.
	 */
	public function render_tokens( array $tokens ): string {
		if ( ! $tokens ) { return ''; }
		$lines = [ ':root {' ];
		foreach ( $tokens as $name => $value ) {
			$lines[] = sprintf( "\t%s: %s;", $name, $value );
		}
		$lines[] = '}';
		return implode( "\n", $lines );
	}

	private function replace_block( string $css, string $block ): string {
		$start = strpos( $css, self::START );
		$end = strpos( $css, self::END, $start );
		$before = substr( $css, 0, $start );
		$after = substr( $css, $end + strlen( self::END ) );
		return $before . $block . $after;
	}
}
