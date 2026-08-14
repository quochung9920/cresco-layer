<?php
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); } }

require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/EffectiveValueResolver.php';
require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/ContextRedactor.php';
require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/ContextBudgeter.php';
require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/WidgetExpertRegistry.php';
require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/PlannerContract.php';

use CrescoLayer\LocalAI\ContextBudgeter;
use CrescoLayer\LocalAI\ContextRedactor;
use CrescoLayer\LocalAI\EffectiveValueResolver;
use CrescoLayer\LocalAI\PlannerContract;
use CrescoLayer\LocalAI\WidgetExpertRegistry;

function semantic_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$resolver = new EffectiveValueResolver();
$state = $resolver->describe( [
	'setting' => 'padding',
	'devices' => [ 'desktop', 'tablet', 'mobile' ],
	'current' => [ 'devices' => [ 'desktop' => [ 'top' => '24' ], 'tablet' => null, 'mobile' => null ] ],
], [ 'padding' => [ 'top' => '24' ] ] );
semantic_assert( true === $state['desktop']['explicit'], 'Desktop explicit value was not detected.' );
semantic_assert( 'inherited' === $state['mobile']['source'], 'Mobile should inherit an explicit larger-breakpoint value.' );
semantic_assert( 'desktop' === $state['mobile']['inheritedFrom'], 'Mobile inheritance source should be desktop in this fixture.' );

$bound = $resolver->describe( [ 'setting' => 'color', 'devices' => [ 'desktop' ], 'current' => [ 'devices' => [] ] ], [ 'color' => '#111', '__globals__' => [ 'color' => 'globals/colors?id=primary' ] ] );
semantic_assert( 'global-reference' === $bound['desktop']['source'], 'Global reference source was not identified.' );
semantic_assert( true === $bound['desktop']['protectedReference'], 'Global reference should be marked protected.' );

$redacted = ( new ContextRedactor() )->redact( [
	'api_key' => 'abc123',
	'url' => 'http://localhost/test?token=sensitive&x=1',
	'nested' => [ 'authorization' => 'Bearer abc.def' ],
] );
semantic_assert( '[REDACTED]' === $redacted['api_key'], 'Sensitive key was not redacted.' );
semantic_assert( str_contains( $redacted['url'], 'token=[REDACTED]' ), 'Sensitive URL query value was not redacted.' );
semantic_assert( '[REDACTED]' === $redacted['nested']['authorization'], 'Nested authorization value was not redacted.' );

$card = WidgetExpertRegistry::for( 'element', 'container', [] );
semantic_assert( 'layout' === $card['family'], 'Container should resolve to the layout expert family.' );
semantic_assert( in_array( 'incorrect mobile stacking', $card['commonProblems'], true ), 'Layout expert card is missing responsive diagnostics.' );

$skills = [];
$effective = [];
$facts = [ 'selected.type' => [ 'value' => 'container' ] ];
for ( $i = 0; $i < 60; $i++ ) {
	$id = 'control.skill-' . $i;
	$ref = 'skill.s' . str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT );
	$skills[] = [ 'skillId' => $id, 'evidenceRef' => $ref, 'description' => str_repeat( 'x', 800 ), 'input' => [ 'options' => array_fill( 0, 100, 'value' ) ] ];
	$effective[ $id ] = [ 'value' => str_repeat( 'z', 500 ) ];
	$facts[ $ref . '.desktop.effective' ] = [ 'value' => $i ];
}
$large = [
	'availableSkills' => $skills,
	'contextGraph' => [ 'siblings' => array_fill( 0, 40, [ 'id' => 'x' ] ), 'children' => array_fill( 0, 50, [ 'id' => 'y' ] ) ],
	'expertCard' => [ 'designRules' => array_fill( 0, 30, 'rule' ), 'commonProblems' => array_fill( 0, 30, 'problem' ) ],
	'effectiveState' => $effective,
	'facts' => $facts,
];
$budgeted = ( new ContextBudgeter() )->budget( $large, 2048 );
semantic_assert( true === $budgeted['contextBudget']['trimmed'], 'Oversized semantic context should be trimmed.' );
semantic_assert( count( $budgeted['availableSkills'] ) <= 24, 'Skill budget was not enforced.' );
semantic_assert( count( $budgeted['contextGraph']['siblings'] ) <= 10, 'Sibling budget was not enforced.' );
semantic_assert( count( $budgeted['effectiveState'] ) <= count( $budgeted['availableSkills'] ), 'Effective state must stay aligned with the retained skills.' );
foreach ( array_keys( $budgeted['facts'] ) as $fact_id ) {
	if ( str_starts_with( $fact_id, 'skill.' ) ) {
		$matched = false;
		foreach ( $budgeted['availableSkills'] as $skill ) { if ( str_starts_with( $fact_id, (string) $skill['evidenceRef'] . '.' ) ) { $matched = true; break; } }
		semantic_assert( $matched, 'Budgeter retained a fact for a dropped skill.' );
	}
}

$evidence = [ [ 'factId' => 'skill.s01.mobile.effective', 'operator' => 'eq', 'value' => '8px', 'statement' => 'Mobile padding inherits 8px.' ] ];
$plan = PlannerContract::validate( [
	'schema' => PlannerContract::SCHEMA,
	'intent' => 'improve-mobile-spacing',
	'confidence' => 0.94,
	'summary' => 'Reduce mobile crowding.',
	'analysis' => [ 'problem' => 'The mobile spacing is too dense.', 'evidence' => $evidence ],
	'requestedSkills' => [ [ 'skillId' => 'control.padding', 'params' => [ 'device' => 'mobile', 'value' => '20px' ], 'reason' => 'Increase mobile breathing room.' ] ],
	'questions' => [],
], [ 'control.padding' ] );
semantic_assert( 0.94 === $plan['confidence'], 'Validated semantic plan confidence changed.' );
semantic_assert( 'skill.s01.mobile.effective' === $plan['analysis']['evidence'][0]['factId'], 'Machine-verifiable evidence was not preserved.' );

$responsive_plan = PlannerContract::validate( [
	'schema' => PlannerContract::SCHEMA,
	'intent' => 'balance-responsive-padding',
	'confidence' => 0.96,
	'summary' => 'Use distinct desktop and mobile padding.',
	'analysis' => [ 'problem' => 'One spacing value is not appropriate for both breakpoints.', 'evidence' => [ [ 'factId' => 'skill.s01.mobile.source', 'operator' => 'eq', 'value' => 'inherited', 'statement' => 'Mobile value is inherited.' ] ] ],
	'requestedSkills' => [
		[ 'skillId' => 'control.padding', 'params' => [ 'device' => 'desktop', 'value' => '40px' ], 'reason' => 'Keep generous desktop spacing.' ],
		[ 'skillId' => 'control.padding', 'params' => [ 'device' => 'mobile', 'value' => '20px' ], 'reason' => 'Reduce mobile spacing.' ],
	],
	'questions' => [],
], [ 'control.padding' ] );
semantic_assert( 2 === count( $responsive_plan['requestedSkills'] ), 'A responsive skill must be reusable for different devices in one plan.' );

try {
	PlannerContract::validate( [
		'schema' => PlannerContract::SCHEMA,
		'intent' => 'unsafe',
		'confidence' => 0.9,
		'summary' => '',
		'analysis' => [ 'problem' => 'Guess', 'evidence' => [ [ 'factId' => 'missing.fact', 'operator' => 'eq', 'value' => true, 'statement' => 'No supporting runtime fact.' ] ] ],
		'requestedSkills' => [ [ 'skillId' => 'invented.setting', 'params' => [], 'reason' => 'Guess' ] ],
		'questions' => [],
	], [ 'control.padding' ] );
	fwrite( STDERR, "FAIL: invented skill ID was accepted.\n" ); exit( 1 );
} catch ( InvalidArgumentException $expected ) {}

try {
	PlannerContract::validate( [
		'schema' => PlannerContract::SCHEMA,
		'intent' => 'ambiguous',
		'confidence' => 0.7,
		'summary' => '',
		'analysis' => [ 'problem' => 'Ambiguous request', 'evidence' => [ [ 'factId' => 'selected.type', 'operator' => 'eq', 'value' => 'container', 'statement' => 'The selected element is a container.' ] ] ],
		'requestedSkills' => [ [ 'skillId' => 'control.padding', 'params' => [], 'reason' => 'Guess' ] ],
		'questions' => [ 'Do you mean font size or widget width?' ],
	], [ 'control.padding' ] );
	fwrite( STDERR, "FAIL: plan mixed clarification questions with executable skills.\n" ); exit( 1 );
} catch ( InvalidArgumentException $expected ) {}

echo "Semantic Local AI context tests passed.\n";
