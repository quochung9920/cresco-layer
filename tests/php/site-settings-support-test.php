<?php
/**
 * ClampValidator / ManagedCssBlock / ValueFactory contract.
 *
 * These three carry the security and idempotency guarantees of the Site Settings engine:
 * a custom-unit value reaches the stylesheet verbatim, the managed CSS block sits inside CSS the
 * site owner also edits, and the value factory decides when a fluid value is safe to emit at all.
 */

require_once dirname( __DIR__, 2 ) . '/includes/SiteSettings/Support/ClampValidator.php';
require_once dirname( __DIR__, 2 ) . '/includes/SiteSettings/Support/ManagedCssBlock.php';
require_once dirname( __DIR__, 2 ) . '/includes/SiteSettings/Support/ValueFactory.php';

use CrescoLayer\SiteSettings\Support\ClampValidator;
use CrescoLayer\SiteSettings\Support\ManagedCssBlock;
use CrescoLayer\SiteSettings\Support\ValueFactory;

function ss_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

/* ---------- ClampValidator: accepts real fluid lengths ---------- */

$clamp = new ClampValidator();

$valid = [
	'clamp(2.25rem, 1.6rem + 2.6vw, 4rem)',
	'clamp(1rem, 0.96rem + 0.18vw, 1.125rem)',
	'clamp(0.75rem,0.71rem + 0.18vw,0.875rem)',
	'min(4rem, 8vw)',
	'max(1rem, 2vw)',
	'calc(1rem + 2vw)',
	'var(--cresco-fs-h1)',
	'var(--cresco-space-md)',
	'clamp(1rem, var(--cresco-gutter), 3rem)',
	'1.5rem',
	'24px',
	'100%',
	'0',
];
foreach ( $valid as $expression ) {
	ss_assert( $clamp->is_valid( $expression ), 'Must accept a legitimate length: ' . $expression . ' (' . var_export( $clamp->rejection_reason( $expression ), true ) . ')' );
}

/* ---------- ClampValidator: rejects stylesheet injection ---------- */

$attacks = [
	'1rem; color: red',                       // declaration break-out
	'1rem} body{display:none',                // rule break-out
	'clamp(1rem, 2vw, 3rem); }',              // trailing rule
	'url(javascript:alert(1))',
	'expression(alert(1))',
	'var(--evil)',                            // foreign custom property
	'attr(data-x)',                           // unsupported function
	'clamp(1rem, 2vw, 3rem) /* comment */',
	'@import "evil.css"',
	'"quoted"',
	'clamp(1rem, 2vw',                        // unbalanced
	'',
	str_repeat( 'clamp(1rem,2vw,3rem)', 40 ), // absurd length
];
foreach ( $attacks as $expression ) {
	ss_assert( ! $clamp->is_valid( $expression ), 'Must reject unsafe value: ' . substr( $expression, 0, 40 ) );
	ss_assert( null !== $clamp->rejection_reason( $expression ), 'A rejection must carry a reason: ' . substr( $expression, 0, 40 ) );
}

ss_assert( 'foreign_css_variable:--evil' === $clamp->rejection_reason( 'var(--evil)' ), 'A foreign variable must name itself in the reason.' );
ss_assert( 'forbidden_character' === $clamp->rejection_reason( '1rem; color:red' ), 'A semicolon must be reported as a forbidden character.' );

ss_assert( $clamp->is_simple_length( '24px' ), '24px is a simple length.' );
ss_assert( ! $clamp->is_simple_length( 'clamp(1rem,2vw,3rem)' ), 'A clamp is not a simple length.' );
ss_assert( $clamp->is_fluid( 'clamp(1rem,2vw,3rem)' ), 'clamp must be detected as fluid.' );
ss_assert( ! $clamp->is_fluid( '24px' ), 'A plain length is not fluid.' );

/* ---------- ManagedCssBlock: owns only its own region ---------- */

$block = new ManagedCssBlock();
$user_css = ".my-hero { color: red; }\n\n.another { margin: 0; }";

$first = $block->write( $user_css, ':root { --cresco-fs-h1: clamp(2rem,4vw,4rem); }' );
ss_assert( str_contains( $first, '.my-hero' ), 'User CSS before the block must survive.' );
ss_assert( str_contains( $first, '.another' ), 'All user CSS must survive.' );
ss_assert( $block->has_block( $first ), 'The managed block must be present after writing.' );
ss_assert( str_contains( $first, ManagedCssBlock::START ) && str_contains( $first, ManagedCssBlock::END ), 'Both markers must be written.' );

// Idempotent: the same body twice is byte identical, which is what lets the diff report NO_OP.
$second = $block->write( $first, ':root { --cresco-fs-h1: clamp(2rem,4vw,4rem); }' );
ss_assert( $first === $second, 'Writing the same tokens twice must be byte-identical.' );

// Updating replaces only the managed body.
$third = $block->write( $second, ':root { --cresco-fs-h1: clamp(2.25rem,4vw,4.5rem); }' );
ss_assert( str_contains( $third, '2.25rem' ), 'The managed block must be updated.' );
ss_assert( ! str_contains( $third, 'clamp(2rem,4vw,4rem)' ), 'The old managed body must be gone.' );
ss_assert( str_contains( $third, '.my-hero' ), 'Updating must not disturb user CSS.' );
ss_assert( 1 === substr_count( $third, ManagedCssBlock::START ), 'Updating must not duplicate the block.' );

// User CSS added after the block also survives.
$with_trailing = $third . "\n\n.added-later { padding: 0; }";
$fourth = $block->write( $with_trailing, ':root { --cresco-x: 1rem; }' );
ss_assert( str_contains( $fourth, '.added-later' ), 'User CSS after the block must survive.' );
ss_assert( str_contains( $fourth, '.my-hero' ), 'User CSS before the block must still survive.' );

ss_assert( str_contains( $block->user_css( $fourth ), '.my-hero' ), 'user_css must return content outside the block.' );
ss_assert( ! str_contains( $block->user_css( $fourth ), '--cresco-x' ), 'user_css must exclude the managed body.' );

// Removing the block leaves user CSS intact.
$removed = $block->write( $fourth, '' );
ss_assert( ! $block->has_block( $removed ), 'An empty body must remove the block.' );
ss_assert( str_contains( $removed, '.my-hero' ) && str_contains( $removed, '.added-later' ), 'Removal must preserve user CSS.' );

// A malformed block (start without end) must not eat the document.
$malformed = ManagedCssBlock::START . "\n:root{}\n.user{color:red}";
ss_assert( null === $block->extract( $malformed ), 'An unterminated block must not be treated as managed.' );
ss_assert( str_contains( $block->user_css( $malformed ), '.user' ), 'An unterminated block must not discard user CSS.' );

// Rendering from a token map.
$rendered = $block->render_tokens( [ '--cresco-a' => '1rem', '--cresco-b' => 'clamp(1rem,2vw,2rem)' ] );
ss_assert( str_contains( $rendered, ':root {' ), 'Tokens must render inside :root.' );
ss_assert( str_contains( $rendered, '--cresco-b: clamp(1rem,2vw,2rem);' ), 'Each token must render as a declaration.' );
ss_assert( '' === $block->render_tokens( [] ), 'No tokens means no CSS.' );

/* ---------- ValueFactory: capability decides the shape ---------- */

$factory = new ValueFactory( $clamp );

$fluid = $factory->slider( 'clamp(1rem, 2vw, 2rem)', 18, true );
ss_assert( true === $fluid['fluid'], 'A supported custom unit must produce a fluid value.' );
ss_assert( 'custom' === $fluid['value']['unit'], 'Fluid sliders must use the custom unit.' );
ss_assert( 'clamp(1rem, 2vw, 2rem)' === $fluid['value']['size'], 'The expression must reach the control verbatim.' );

$native = $factory->slider( 'clamp(1rem, 2vw, 2rem)', 18, false );
ss_assert( false === $native['fluid'], 'Without custom-unit support the value must not be fluid.' );
ss_assert( 'px' === $native['value']['unit'] && 18.0 === $native['value']['size'], 'The native fallback must be used.' );
ss_assert( 'custom_unit_unsupported' === $native['reason'], 'The fallback reason must be reported.' );

$unsafe = $factory->slider( '1rem; color:red', 18, true );
ss_assert( false === $unsafe['fluid'], 'An unsafe expression must never be emitted.' );
ss_assert( str_starts_with( $unsafe['reason'], 'invalid_value:' ), 'An unsafe expression must be reported as invalid.' );
ss_assert( 18.0 === $unsafe['value']['size'], 'An unsafe expression must fall back to the native value.' );

$dims = $factory->dimensions(
	[ 'top' => 'clamp(.75rem,.71rem + .18vw,.875rem)', 'right' => 'clamp(1.125rem,1.02rem + .54vw,1.5rem)', 'bottom' => 'clamp(.75rem,.71rem + .18vw,.875rem)', 'left' => 'clamp(1.125rem,1.02rem + .54vw,1.5rem)' ],
	[ 'top' => 14, 'right' => 22, 'bottom' => 14, 'left' => 22 ],
	true
);
ss_assert( true === $dims['fluid'], 'Fluid dimensions must be produced when supported.' );
ss_assert( 'custom' === $dims['value']['unit'], 'Fluid dimensions must use the custom unit.' );
ss_assert( false === $dims['value']['isLinked'], 'Independent sides must not be linked.' );

$dims_unsafe = $factory->dimensions(
	[ 'top' => '1rem', 'right' => '1rem}', 'bottom' => '1rem', 'left' => '1rem' ],
	[ 'top' => 14, 'right' => 22, 'bottom' => 14, 'left' => 22 ],
	true
);
ss_assert( false === $dims_unsafe['fluid'], 'One unsafe side must reject the whole dimensions value.' );
ss_assert( '22' === $dims_unsafe['value']['right'], 'The native fallback must be used for all sides.' );

$uniform = $factory->dimensions_uniform( 'var(--cresco-space-md)', 16, true );
ss_assert( true === $uniform['value']['isLinked'], 'A uniform value must be linked.' );
ss_assert( 'var(--cresco-space-md)' === $uniform['value']['top'], 'A Cresco variable is an accepted fluid value.' );

$row = $factory->typography_row( 'primary', 'Primary', [ 'font_family' => 'Inter', 'font_weight' => '700' ] );
ss_assert( 'custom' === $row['typography_typography'], 'A typography row must be marked custom.' );
ss_assert( 'Inter' === $row['typography_font_family'], 'Declared properties must be prefixed and written.' );
ss_assert( ! array_key_exists( 'typography_font_size', $row ), 'Undeclared typography properties must not be invented.' );

echo "Site settings support contract tests passed.\n";
