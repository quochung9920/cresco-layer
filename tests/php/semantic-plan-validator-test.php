<?php
namespace CrescoLayer\Skills {
	final class WidgetSkillRuntime {
		public function profile( int $post_id, string $element_id ): array {
			return [
				'currentSettings' => [
					'padding' => [ 'unit' => 'px', 'top' => '24', 'right' => '24', 'bottom' => '24', 'left' => '24', 'isLinked' => true ],
					'color' => '#111111',
					'global_color' => '#635BFF',
					'__globals__' => [ 'global_color' => 'globals/colors?id=primary' ],
				],
				'globalReferences' => [ 'global_color' => 'globals/colors?id=primary' ],
			];
		}

		public function resolve( int $post_id, string $element_id, array $request ): array {
			$id = (string) ( $request['skillId'] ?? '' );
			$params = is_array( $request['params'] ?? null ) ? $request['params'] : [];
			$device = (string) ( $params['device'] ?? 'desktop' );
			if ( 'control.expert' === $id ) {
				return $this->resolution( $element_id, $id, 'expert_setting', 'before', 'after', 'expert', $device );
			}
			if ( 'control.global' === $id ) {
				return $this->resolution( $element_id, $id, 'global_color', '#635BFF', '#7C3AED', 'safe', $device );
			}
			if ( 'control.noop' === $id ) {
				return $this->resolution( $element_id, $id, 'color', '#111111', '#111111', 'safe', $device );
			}
			$setting = 'mobile' === $device ? 'padding_mobile' : 'padding';
			$before = 'mobile' === $device ? null : [ 'unit' => 'px', 'top' => '24', 'right' => '24', 'bottom' => '24', 'left' => '24', 'isLinked' => true ];
			$after = [ 'unit' => 'px', 'top' => (string) ( $params['value'] ?? 20 ), 'right' => (string) ( $params['value'] ?? 20 ), 'bottom' => (string) ( $params['value'] ?? 20 ), 'left' => (string) ( $params['value'] ?? 20 ), 'isLinked' => true ];
			return $this->resolution( $element_id, $id, $setting, $before, $after, 'safe', $device );
		}

		private function resolution( string $element_id, string $id, string $setting, $before, $after, string $risk, string $device ): array {
			return [
				'elementId' => $element_id,
				'skillId' => $id,
				'label' => $id,
				'device' => $device,
				'risk' => $risk,
				'operations' => [ [ 'operation' => 'update-setting', 'elementId' => $element_id, 'setting' => $setting, 'value' => $after ] ],
				'preview' => [ 'setting' => $setting, 'before' => $before, 'after' => $after, 'prerequisites' => [] ],
			];
		}
	}
}

namespace {
	require_once dirname( __DIR__, 2 ) . '/includes/LocalAI/PlanValidator.php';

	use CrescoLayer\LocalAI\PlanValidator;
	use CrescoLayer\Skills\WidgetSkillRuntime;

	function plan_assert( bool $condition, string $message ): void {
		if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
	}

	$validator = new PlanValidator( new WidgetSkillRuntime() );
	$valid = $validator->validate( 3, 'abc123', [
		'requestedSkills' => [
			[ 'skillId' => 'control.padding', 'params' => [ 'device' => 'desktop', 'value' => 40 ], 'reason' => 'Desktop spacing' ],
			[ 'skillId' => 'control.padding', 'params' => [ 'device' => 'mobile', 'value' => 20 ], 'reason' => 'Mobile spacing' ],
		],
	] );
	plan_assert( true === $valid['validated'], 'Valid responsive plan was not accepted.' );
	plan_assert( 2 === $valid['stepCount'], 'Responsive repeated skill did not produce two validated steps.' );
	plan_assert( 'safe' === $valid['maxRisk'], 'Safe plan risk was changed.' );

	foreach ( [
		[ 'id' => 'control.noop', 'message' => 'No-op AI step was accepted.' ],
		[ 'id' => 'control.expert', 'message' => 'Expert AI step was accepted.' ],
		[ 'id' => 'control.global', 'message' => 'Globally-bound AI step was accepted.' ],
	] as $case ) {
		try {
			$validator->validate( 3, 'abc123', [ 'requestedSkills' => [ [ 'skillId' => $case['id'], 'params' => [ 'value' => 20 ], 'reason' => 'test' ] ] ] );
			fwrite( STDERR, 'FAIL: ' . $case['message'] . "\n" ); exit( 1 );
		} catch ( InvalidArgumentException $expected ) {}
	}

	echo "Semantic Local AI plan validator tests passed.\n";
}
