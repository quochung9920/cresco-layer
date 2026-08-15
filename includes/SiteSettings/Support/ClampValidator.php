<?php
namespace CrescoLayer\SiteSettings\Support;

/**
 * Safety gate for fluid CSS length expressions before they reach an Elementor control.
 *
 * A `custom` unit value is emitted into the stylesheet verbatim, so an unchecked string here is a
 * stylesheet injection: a stray `;` or `}` ends the declaration and lets arbitrary rules follow.
 * This is deliberately an allowlist over a CSS parser — the grammar accepted is only what a length
 * expression needs, and anything outside it is rejected rather than sanitised, because silently
 * rewriting a value would hide the fact that a caller sent something it should not have.
 */
final class ClampValidator {
	/** Functions permitted in a length expression. */
	private const FUNCTIONS = [ 'clamp', 'min', 'max', 'calc' ];
	/** Length units permitted in a length expression. */
	private const UNITS = [ 'rem', 'em', 'px', 'vw', 'vh', 'vmin', 'vmax', 'ch', 'ex', '%' ];
	/** Custom properties may only be referenced inside the namespace Cresco owns. */
	private const VAR_PREFIX = '--cresco-';
	private const MAX_LENGTH = 400;

	/** Characters that terminate a declaration or open a new one; never valid inside a length. */
	private const FORBIDDEN_CHARS = [ ';', '}', '{', '@', '\\', '<', '>', '"', "'", '`' ];

	public function is_valid( string $expression ): bool {
		return null === $this->rejection_reason( $expression );
	}

	/** @return string|null Null when the expression is safe, otherwise why it was rejected. */
	public function rejection_reason( string $expression ): ?string {
		$value = trim( $expression );

		if ( '' === $value ) { return 'empty_expression'; }
		if ( strlen( $value ) > self::MAX_LENGTH ) { return 'expression_too_long'; }

		foreach ( self::FORBIDDEN_CHARS as $char ) {
			if ( str_contains( $value, $char ) ) { return 'forbidden_character'; }
		}
		// Comments can be used to smuggle past a naive scanner; a length never needs them.
		if ( str_contains( $value, '/*' ) || str_contains( $value, '*/' ) ) { return 'comment_not_allowed'; }
		// url() and any scheme-bearing token have no place in a length.
		if ( preg_match( '/url\s*\(|javascript:|expression\s*\(|data:/i', $value ) ) { return 'forbidden_function'; }
		if ( ! $this->balanced( $value ) ) { return 'unbalanced_parentheses'; }

		// var() is permitted here but validated separately below, by namespace rather than by name.
		$allowed_functions = array_merge( self::FUNCTIONS, [ 'var' ] );
		foreach ( $this->functions_used( $value ) as $function ) {
			if ( ! in_array( $function, $allowed_functions, true ) ) { return 'unsupported_function:' . $function; }
		}
		foreach ( $this->variables_used( $value ) as $variable ) {
			if ( ! str_starts_with( $variable, self::VAR_PREFIX ) ) { return 'foreign_css_variable:' . $variable; }
		}

		// Whatever remains after removing the accepted grammar must be empty; anything left over is
		// a token this validator does not understand, and an unknown token is a rejection.
		if ( '' !== $this->residue( $value ) ) { return 'unrecognised_token'; }

		return null;
	}

	/** CSS permits a leading-dot decimal such as `.75rem`, so both number forms must be accepted. */
	private const NUMBER = '-?(?:\d+(?:\.\d+)?|\.\d+)';

	/** True when the value is a plain number or a number with a permitted unit. */
	public function is_simple_length( string $expression ): bool {
		$units = implode( '|', array_map( 'preg_quote', self::UNITS ) );
		return (bool) preg_match( '/^' . self::NUMBER . '(?:' . $units . ')?$/i', trim( $expression ) );
	}

	public function is_fluid( string $expression ): bool {
		return (bool) preg_match( '/\b(?:clamp|min|max|calc)\s*\(/i', $expression );
	}

	private function balanced( string $value ): bool {
		$depth = 0;
		foreach ( str_split( $value ) as $char ) {
			if ( '(' === $char ) { $depth++; }
			if ( ')' === $char ) { $depth--; }
			if ( $depth < 0 ) { return false; }
		}
		return 0 === $depth;
	}

	/** @return string[] lowercased function names appearing before an opening parenthesis. */
	private function functions_used( string $value ): array {
		preg_match_all( '/([a-z_-][a-z0-9_-]*)\s*\(/i', $value, $matches );
		return array_map( 'strtolower', $matches[1] ?? [] );
	}

	/** @return string[] custom property names referenced through var(). */
	private function variables_used( string $value ): array {
		preg_match_all( '/var\s*\(\s*(--[a-z0-9_-]+)/i', $value, $matches );
		return array_map( 'strtolower', $matches[1] ?? [] );
	}

	/** Strip every accepted token; a safe expression leaves nothing behind. */
	private function residue( string $value ): string {
		$units = implode( '|', array_map( 'preg_quote', self::UNITS ) );
		$rest = $value;
		$rest = preg_replace( '/var\s*\(\s*--[a-z0-9_-]+\s*(?:,[^()]*)?\)/i', '', $rest );
		$rest = preg_replace( '/\b(?:' . implode( '|', self::FUNCTIONS ) . ')\s*\(/i', '(', $rest );
		$rest = preg_replace( '/' . self::NUMBER . '(?:' . $units . ')?/i', '', $rest );
		$rest = str_replace( [ '(', ')', ',', '+', '-', '*', '/', ' ', "\t", "\n", "\r" ], '', $rest );
		return trim( $rest );
	}
}
