<?php
namespace CrescoLayer\LocalAI;

use CrescoLayer\Skills\WidgetSkillRuntime;

final class PlanValidator {
	public function __construct( private WidgetSkillRuntime $skills ) {}

	public function validate( int $post_id, string $element_id, array $plan, array $live_settings = [] ): array {
		$profile = $this->skills->profile( $post_id, $element_id );
		$current = is_array( $profile['currentSettings'] ?? null ) ? $profile['currentSettings'] : [];
		if ( $live_settings ) { $current = array_replace( $current, $live_settings ); }
		$globals = is_array( $profile['globalReferences'] ?? null ) ? $profile['globalReferences'] : [];
		if ( is_array( $current['__globals__'] ?? null ) ) { $globals = array_replace( $globals, $current['__globals__'] ); }
		$dynamic = is_array( $current['__dynamic__'] ?? null ) ? $current['__dynamic__'] : [];

		$steps = [];
		$risk_order = [ 'safe' => 0, 'conditional' => 1, 'expert' => 2, 'structural' => 3, 'external' => 4 ];
		$max_risk = 'safe';
		$operation_count = 0;

		foreach ( (array) ( $plan['requestedSkills'] ?? [] ) as $index => $item ) {
			if ( ! is_array( $item ) ) { throw new \InvalidArgumentException( 'Local AI plan contains an invalid skill step.' ); }
			$skill_id = (string) ( $item['skillId'] ?? '' );
			$params = is_array( $item['params'] ?? null ) ? $item['params'] : [];
			$request = [ 'skillId' => $skill_id, 'params' => $params ];
			if ( $live_settings ) { $request['liveSettings'] = $live_settings; }
			try {
				$resolution = $this->skills->resolve( $post_id, $element_id, $request );
			} catch ( \Throwable $error ) {
				throw new \InvalidArgumentException( 'Local AI step ' . ( $index + 1 ) . ' cannot be resolved by the current Elementor runtime: ' . $error->getMessage(), 0, $error );
			}
			if ( (string) ( $resolution['elementId'] ?? '' ) !== $element_id ) { throw new \InvalidArgumentException( 'Local AI skill resolution escaped the selected Elementor element.' ); }
			$risk = (string) ( $resolution['risk'] ?? 'safe' );
			if ( in_array( $risk, [ 'expert', 'structural', 'external' ], true ) ) {
				throw new \InvalidArgumentException( 'Local AI cannot execute ' . $risk . ' skills. Use the explicit expert/manual workflow for this change.' );
			}

			$operations = (array) ( $resolution['operations'] ?? [] );
			if ( ! $operations ) { throw new \InvalidArgumentException( 'Local AI skill resolution produced no Elementor operations.' ); }
			foreach ( $operations as $operation ) {
				if ( ! is_array( $operation ) || (string) ( $operation['elementId'] ?? '' ) !== $element_id ) { throw new \InvalidArgumentException( 'Local AI plan contains an invalid scoped operation.' ); }
				if ( ! in_array( (string) ( $operation['operation'] ?? '' ), [ 'update-setting', 'remove-setting' ], true ) ) { throw new \InvalidArgumentException( 'Local AI widget plans may only use validated setting operations.' ); }
				$setting = (string) ( $operation['setting'] ?? '' );
				if ( $this->is_bound( $setting, $globals ) ) { throw new \InvalidArgumentException( 'Local AI will not override a setting that is bound to an Elementor Global value.' ); }
				if ( $this->is_bound( $setting, $dynamic ) ) { throw new \InvalidArgumentException( 'Local AI will not override a setting that is bound to an Elementor Dynamic Tag.' ); }
			}

			if ( ( $risk_order[ $risk ] ?? 99 ) > ( $risk_order[ $max_risk ] ?? 0 ) ) { $max_risk = $risk; }
			$operation_count += count( $operations );
			$steps[] = [
				'index' => $index,
				'skillId' => $skill_id,
				'label' => (string) ( $resolution['label'] ?? $skill_id ),
				'device' => (string) ( $resolution['device'] ?? 'desktop' ),
				'risk' => $risk,
				'reason' => (string) ( $item['reason'] ?? '' ),
				'preview' => is_array( $resolution['preview'] ?? null ) ? $resolution['preview'] : [],
				'operationCount' => count( $operations ),
			];
		}

		return [
			'validated' => true,
			'stepCount' => count( $steps ),
			'operationCount' => $operation_count,
			'maxRisk' => $max_risk,
			'steps' => $steps,
		];
	}

	private function is_bound( string $setting, array $bindings ): bool {
		if ( '' === $setting || ! $bindings ) { return false; }
		if ( array_key_exists( $setting, $bindings ) && '' !== (string) $bindings[ $setting ] ) { return true; }
		$base = preg_replace( '/_(?:tablet|mobile|widescreen|laptop|tablet_extra|mobile_extra)$/', '', $setting ) ?? $setting;
		return $base !== $setting && array_key_exists( $base, $bindings ) && '' !== (string) $bindings[ $base ];
	}
}
