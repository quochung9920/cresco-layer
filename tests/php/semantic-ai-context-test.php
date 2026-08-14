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

$large = [
	'availableSkills' => array_fill( 0, 150, [ 'skillId' => 'control.example', 'description' => str_repeat( 'x', 800 ), 'input' => [ 'options' => array_fill( 0, 100, 'value' ) ] ] ),
	'contextGraph' => [ 'siblings' => array_fill( 0, 40, [ 'id' => 'x' ] ), 'children' => array_fill( 0, 50, [ 'id' => 'y' ] ) ],
	'expertCard' => [ 'designRules' => array_fill( 0, 30, 'rule' ), 'commonProblems' => array_fill( 0, 30, 'problem' ) ],
	'effectiveState' => array_fill( 0, 120, [ 'value' => str_repeat( 'z', 500 ) ] ),
];
$budgeted = ( new ContextBudgeter() )->budget( $large, 2048 );
semantic_assert( true === $budgeted['contextBudget']['trimmed'], 'Oversized semantic context should be trimmed.' );
semantic_assert( count( $budgeted['availableSkills'] ) <= 72, 'Skill budget was not enforced.' );
semantic_assert( count( $budgeted['contextGraph']['siblings'] ) <= 12, 'Sibling budget was not enforced.' );

$plan = PlannerContract::validate( [
	'schema' => PlannerContract::SCHEMA,
	'intent' => 'improve-mobile-spacing',
	'confidence' => 0.94,
	'summary' => 'Reduce mobile crowding.',
	'analysis' => [ 'problem' => 'The mobile spacing is too dense.', 'evidence' => [ 'Mobile padding inherits 8px from tablet.' ] ],
	'requestedSkills' => [ [ 'skillId' => 'control.padding', 'params' => [ 'device' => 'mobile', 'value' => '20px' ], 'reason' => 'Increase mobile breathing room.' ] ],
	'questions' => [],
], [ 'control.padding' ] );
semantic_assert( 0.94 === $plan['confidence'], 'Validated semantic plan confidence changed.' );
semantic_assert( 'The mobile spacing is too dense.' === $plan['analysis']['problem'], 'Plan diagnosis was not preserved.' );

try {
	PlannerContract::validate( [
		'schema' => PlannerContract::SCHEMA,
		'intent' => 'unsafe',
		'confidence' => 0.9,
		'summary' => '',
		'analysis' => [ 'problem' => 'Guess', 'evidence' => [ 'No supporting runtime fact.' ] ],
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
		'analysis' => [ 'problem' => 'Ambiguous request', 'evidence' => [ 'The user did not specify what should become larger.' ] ],
		'requestedSkills' => [ [ 'skillId' => 'control.padding', 'params' => [], 'reason' => 'Guess' ] ],
		'questions' => [ 'Do you mean font size or widget width?' ],
	], [ 'control.padding' ] );
	fwrite( STDERR, "FAIL: plan mixed clarification questions with executable skills.\n" ); exit( 1 );
} catch ( InvalidArgumentException $expected ) {}

echo "Semantic Local AI context tests passed.\n";
