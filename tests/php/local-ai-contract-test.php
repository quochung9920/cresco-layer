<?php
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); } }

require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/Settings.php';
require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/PlannerContract.php';
require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/ProviderManager.php';

use CrescoLayer\LocalAI\PlannerContract;
use CrescoLayer\LocalAI\ProviderManager;
use CrescoLayer\LocalAI\Settings;

function local_ai_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$providers = ( new ProviderManager( new Settings() ) )->providers();
local_ai_assert( isset( $providers['ollama'], $providers['lm-studio'], $providers['llama-cpp'], $providers['openai-compatible'] ), 'Expected local provider adapters are missing.' );
local_ai_assert( '/api/tags' === $providers['ollama']['modelsPath'], 'Ollama model discovery must use /api/tags.' );
local_ai_assert( '/models' === $providers['lm-studio']['modelsPath'], 'OpenAI-compatible providers must expose /models from their v1 base URL.' );

$descriptor = PlannerContract::descriptor();
local_ai_assert( PlannerContract::SCHEMA === $descriptor['schema'], 'Planning contract schema mismatch.' );
local_ai_assert( 'Cresco Skill Runtime only' === $descriptor['executionAuthority'], 'Local AI must not own execution authority.' );
local_ai_assert( in_array( 'invent-elementor-setting', $descriptor['forbidden'], true ), 'Planning contract must forbid invented Elementor settings.' );
local_ai_assert( in_array( 'invent-evidence-fact', $descriptor['forbidden'], true ), 'Planning contract must forbid invented evidence facts.' );
local_ai_assert( in_array( 'direct-document-write', $descriptor['forbidden'], true ), 'Planning contract must forbid direct document writes.' );
local_ai_assert( in_array( 'detach-dynamic-binding', $descriptor['forbidden'], true ), 'Planning contract must preserve dynamic bindings.' );

$plan = PlannerContract::validate( [
	'schema' => PlannerContract::SCHEMA,
	'intent' => 'improve-card-spacing',
	'confidence' => 0.94,
	'summary' => 'Increase card breathing room.',
	'analysis' => [ 'problem' => 'Card spacing is dense.', 'evidence' => [ [ 'factId' => 'skill.s01.mobile.effective', 'operator' => 'eq', 'value' => '8px', 'statement' => 'Mobile padding is 8px.' ] ] ],
	'requestedSkills' => [ [ 'skillId' => 'control.padding', 'params' => [ 'value' => '24px' ], 'reason' => 'More spacing' ] ],
	'questions' => [],
], [ 'control.padding' ] );
local_ai_assert( 0.94 === $plan['confidence'], 'Valid local AI plan was not preserved.' );
local_ai_assert( 'skill.s01.mobile.effective' === $plan['analysis']['evidence'][0]['factId'], 'Evidence fact reference was not preserved.' );

try {
	PlannerContract::validate( [
		'schema' => PlannerContract::SCHEMA,
		'intent' => 'unsafe',
		'confidence' => 0.9,
		'summary' => '',
		'analysis' => [ 'problem' => 'Unsafe guess', 'evidence' => [ [ 'factId' => 'missing.fact', 'operator' => 'eq', 'value' => true, 'statement' => 'No valid skill exists.' ] ] ],
		'requestedSkills' => [ [ 'skillId' => 'invented.setting', 'params' => [], 'reason' => 'Guess' ] ],
		'questions' => [],
	], [ 'control.padding' ] );
	fwrite( STDERR, "FAIL: invented skill ID was accepted.\n" ); exit( 1 );
} catch ( InvalidArgumentException $expected ) {}

// A local model call must never be allowed to consume the whole PHP execution budget: when the HTTP
// timeout equals max_execution_time, PHP aborts with a fatal error and WordPress shows a bare
// "critical error" page instead of a Cresco message.
$provider_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/LocalAI/ProviderManager.php' );
local_ai_assert( ! str_contains( $provider_source, '$config, 120 )' ), 'Chat must not use a hardcoded 120s timeout equal to the default max_execution_time.' );
local_ai_assert( str_contains( $provider_source, 'chat_timeout()' ), 'Chat must derive its timeout from the runtime PHP execution budget.' );
local_ai_assert( str_contains( $provider_source, 'TIME_LIMIT_HEADROOM' ), 'Chat timeout must reserve headroom below max_execution_time.' );
local_ai_assert( str_contains( $provider_source, 'timed out' ), 'A slow local model must surface an actionable timeout message.' );

echo "Local AI manager contract tests passed.\n";
