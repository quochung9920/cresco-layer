<?php
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); } }

require_once dirname( __DIR__, 2 ) . '/includes/Skills/SemanticIdentity.php';
require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/SkillRetriever.php';
require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/EvidenceValidator.php';
require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/ConfidenceScorer.php';

use CrescoLayer\LocalAI\ConfidenceScorer;
use CrescoLayer\LocalAI\EvidenceValidator;
use CrescoLayer\LocalAI\SkillRetriever;
use CrescoLayer\Skills\SemanticIdentity;

function accuracy_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$direction = SemanticIdentity::enrich( [
	'id' => 'control.flex_direction', 'setting' => 'flex_direction', 'control' => 'flex_direction', 'label' => 'Direction', 'role' => 'layout.direction', 'category' => 'Layout', 'type' => 'choose', 'mode' => 'direct', 'risk' => 'safe',
], [ 'name' => 'container' ] );
accuracy_assert( str_starts_with( $direction['semanticId'], 'layout.container.direction' ), 'Container direction did not receive a specific semantic identity.' );
accuracy_assert( str_contains( $direction['displayLabel'], 'Container' ), 'Semantic display label does not identify the target part.' );

$skills = [];
for ( $i = 0; $i < 40; $i++ ) {
	$skills[] = SemanticIdentity::enrich( [
		'id' => 'control.misc_' . $i, 'setting' => 'misc_' . $i, 'control' => 'misc_' . $i, 'label' => 'Misc ' . $i, 'role' => 'style.misc', 'category' => 'Style', 'type' => 'text', 'mode' => 'direct', 'risk' => 'safe', 'responsive' => false,
	], [ 'name' => 'container' ] );
}
$padding = SemanticIdentity::enrich( [
	'id' => 'control.padding', 'setting' => 'padding', 'control' => 'padding', 'label' => 'Padding', 'role' => 'spacing.padding', 'category' => 'Spacing', 'type' => 'dimensions', 'mode' => 'direct', 'risk' => 'safe', 'responsive' => true, 'devices' => [ 'desktop', 'mobile' ],
], [ 'name' => 'container' ] );
$skills[] = $padding;
$retrieval = ( new SkillRetriever() )->retrieve( $skills, 'giảm padding mobile cho container', [ 'preferredRoles' => [ 'spacing.padding' ] ], 12 );
accuracy_assert( count( $retrieval['skills'] ) <= 12, 'Retriever returned too many skills.' );
accuracy_assert( 'control.padding' === $retrieval['skills'][0]['id'], 'Task-relevant padding skill was not ranked first.' );
accuracy_assert( $retrieval['dropped'] > 0, 'Retriever did not reduce the candidate skill set.' );

$context = [
	'facts' => [
		'skill.s01.mobile.effective' => [ 'value' => '8px' ],
		'relationship.overflowRisk' => [ 'value' => true ],
	],
	'availableSkills' => [ [ 'skillId' => 'control.padding', 'retrievalScore' => 100 ] ],
	'selectedElement' => [ 'id' => 'abc', 'type' => 'container' ],
	'contextGraph' => [ 'relationships' => [ 'overflowRisk' => true ] ],
	'expertCard' => [ 'purpose' => 'Layout' ],
	'designSystem' => [],
	'renderObservation' => [ 'selected' => [ 'width' => 320 ] ],
];
$evidence = [ [ 'factId' => 'skill.s01.mobile.effective', 'operator' => 'eq', 'value' => '8px', 'statement' => 'Mobile padding is 8px.' ] ];
$validated = ( new EvidenceValidator() )->validate( $evidence, $context );
accuracy_assert( true === $validated['valid'], 'Valid machine evidence was rejected.' );

$bad = ( new EvidenceValidator() )->validate( [ [ 'factId' => 'relationship.overflowRisk', 'operator' => 'eq', 'value' => false, 'statement' => 'No overflow risk.' ] ], $context );
accuracy_assert( false === $bad['valid'], 'False evidence claim was accepted.' );

$plan = [ 'confidence' => 0.95, 'requestedSkills' => [ [ 'skillId' => 'control.padding' ] ] ];
$score = ( new ConfidenceScorer() )->score( $plan, $context, $validated, [ 'validated' => true ] );
accuracy_assert( $score['final'] > 0.8, 'Fully validated plan received unexpectedly low semantic confidence.' );
accuracy_assert( 1.0 === $score['components']['runtimeValidation'], 'Runtime validation component was not included in confidence.' );

echo "Accuracy Core tests passed.\n";
