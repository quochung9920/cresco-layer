<?php
namespace CrescoLayer\LocalAI;

final class Manager {
	private Settings $settings;
	private ProviderManager $providers;

	public function __construct( ?Settings $settings = null, ?ProviderManager $providers = null ) {
		$this->settings = $settings ?? new Settings();
		$this->providers = $providers ?? new ProviderManager( $this->settings );
	}

	public function summary(): array { return $this->providers->summary(); }
	public function save( array $input ): array { $this->settings->update( $input ); return $this->summary(); }
	public function test(): array { return $this->providers->test(); }
	public function models(): array { return $this->providers->models(); }
	public function diagnostics(): array {
		$result = $this->providers->diagnostics();
		$result['planningContract'] = PlannerContract::descriptor();
		return $result;
	}
	public function contract(): array {
		return [ 'descriptor' => PlannerContract::descriptor(), 'systemPrompt' => PlannerContract::system_prompt() ];
	}
	public function editor_summary(): array { return $this->settings->editor_summary(); }
}
