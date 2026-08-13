<?php
namespace CrescoLayer\LocalAI;

final class ProviderManager {
	public function __construct( private Settings $settings ) {}

	public function providers(): array {
		return [
			'ollama' => [ 'label' => 'Ollama', 'defaultEndpoint' => 'http://127.0.0.1:11434', 'apiStyle' => 'ollama', 'modelsPath' => '/api/tags', 'healthPath' => '/api/version', 'chatPath' => '/api/chat' ],
			'lm-studio' => [ 'label' => 'LM Studio', 'defaultEndpoint' => 'http://127.0.0.1:1234/v1', 'apiStyle' => 'openai-compatible', 'modelsPath' => '/models', 'healthPath' => '/models', 'chatPath' => '/chat/completions' ],
			'llama-cpp' => [ 'label' => 'llama.cpp', 'defaultEndpoint' => 'http://127.0.0.1:8080/v1', 'apiStyle' => 'openai-compatible', 'modelsPath' => '/models', 'healthPath' => '/models', 'chatPath' => '/chat/completions' ],
			'openai-compatible' => [ 'label' => 'OpenAI-compatible local API', 'defaultEndpoint' => 'http://127.0.0.1:1234/v1', 'apiStyle' => 'openai-compatible', 'modelsPath' => '/models', 'healthPath' => '/models', 'chatPath' => '/chat/completions' ],
		];
	}

	public function summary(): array {
		$config = $this->settings->get();
		$provider = $this->provider( (string) $config['provider'] );
		return [
			'schema' => 'cresco-layer-local-ai/v1',
			'configured' => (bool) $config['enabled'] && '' !== (string) $config['analysisModel'],
			'settings' => $config,
			'provider' => $provider,
			'providers' => $this->providers(),
			'connection' => [ 'mode' => (string) $config['connectionMode'], 'browserRequired' => 'browser' === (string) $config['connectionMode'], 'endpoint' => (string) $config['endpoint'] ],
			'planningContract' => PlannerContract::descriptor(),
		];
	}

	public function test(): array {
		$config = $this->settings->get( true );
		if ( 'browser' === (string) $config['connectionMode'] ) {
			return [ 'ok' => null, 'browserRequired' => true, 'message' => 'Browser / Local Bridge mode must be tested from the Cresco Layer admin page on this computer.', 'descriptor' => $this->browser_descriptor( $config ) ];
		}
		$started = microtime( true );
		$provider = $this->provider( (string) $config['provider'] );
		$data = $this->request_json( 'GET', $this->url( $config, (string) $provider['healthPath'] ), null, $config );
		return [ 'ok' => true, 'browserRequired' => false, 'provider' => (string) $config['provider'], 'latencyMs' => (int) round( ( microtime( true ) - $started ) * 1000 ), 'version' => (string) ( $data['version'] ?? '' ), 'message' => 'Local AI endpoint responded successfully.' ];
	}

	public function models(): array {
		$config = $this->settings->get( true );
		if ( 'browser' === (string) $config['connectionMode'] ) { return [ 'browserRequired' => true, 'descriptor' => $this->browser_descriptor( $config ), 'models' => [] ]; }
		$provider = $this->provider( (string) $config['provider'] );
		$data = $this->request_json( 'GET', $this->url( $config, (string) $provider['modelsPath'] ), null, $config );
		return [ 'browserRequired' => false, 'provider' => (string) $config['provider'], 'models' => $this->normalize_models( (string) $config['provider'], $data ) ];
	}

	public function diagnostics(): array {
		$config = $this->settings->get();
		$checks = [
			[ 'id' => 'enabled', 'label' => 'Local AI enabled', 'status' => $config['enabled'] ? 'pass' : 'warning', 'detail' => $config['enabled'] ? 'Enabled' : 'Disabled' ],
			[ 'id' => 'endpoint', 'label' => 'Local endpoint policy', 'status' => 'pass', 'detail' => (string) $config['endpoint'] ],
			[ 'id' => 'privacy', 'label' => 'Sensitive context redaction', 'status' => ! empty( $config['redactSensitiveContext'] ) ? 'pass' : 'fail', 'detail' => ! empty( $config['redactSensitiveContext'] ) ? 'Forced on' : 'Disabled' ],
		];
		if ( 'browser' === (string) $config['connectionMode'] ) {
			$checks[] = [ 'id' => 'connection', 'label' => 'Connection', 'status' => 'browser', 'detail' => 'Run browser diagnostics below; WordPress server is intentionally bypassed.' ];
			return [ 'ok' => null, 'browserRequired' => true, 'checks' => $checks, 'descriptor' => $this->browser_descriptor( $this->settings->get( true ) ) ];
		}
		try { $connection = $this->test(); $checks[] = [ 'id' => 'connection', 'label' => 'Connection', 'status' => 'pass', 'detail' => (string) ( $connection['latencyMs'] ?? 0 ) . ' ms' ]; }
		catch ( \Throwable $error ) { $checks[] = [ 'id' => 'connection', 'label' => 'Connection', 'status' => 'fail', 'detail' => $error->getMessage() ]; return [ 'ok' => false, 'browserRequired' => false, 'checks' => $checks ]; }
		try {
			$model_result = $this->models();
			$models = (array) ( $model_result['models'] ?? [] );
			$analysis = (string) $config['analysisModel'];
			$vision = (string) $config['visionModel'];
			$ids = array_values( array_filter( array_map( static fn( array $model ): string => (string) ( $model['id'] ?? '' ), $models ) ) );
			$checks[] = [ 'id' => 'models', 'label' => 'Model discovery', 'status' => $models ? 'pass' : 'warning', 'detail' => count( $models ) . ' model(s) available' ];
			$checks[] = [ 'id' => 'analysis-model', 'label' => 'Analysis model', 'status' => '' !== $analysis && in_array( $analysis, $ids, true ) ? 'pass' : 'warning', 'detail' => '' !== $analysis ? $analysis : 'Not selected' ];
			if ( '' !== $vision ) { $checks[] = [ 'id' => 'vision-model', 'label' => 'Vision model', 'status' => in_array( $vision, $ids, true ) ? 'pass' : 'warning', 'detail' => $vision ]; }
		} catch ( \Throwable $error ) { $checks[] = [ 'id' => 'models', 'label' => 'Model discovery', 'status' => 'fail', 'detail' => $error->getMessage() ]; }
		$ok = ! array_filter( $checks, static fn( array $check ): bool => 'fail' === ( $check['status'] ?? '' ) );
		return [ 'ok' => $ok, 'browserRequired' => false, 'checks' => $checks ];
	}

	public function browser_descriptor( ?array $config = null ): array {
		$config = $config ?? $this->settings->get( true );
		$provider = $this->provider( (string) $config['provider'] );
		return [ 'provider' => (string) $config['provider'], 'apiStyle' => (string) $provider['apiStyle'], 'endpoint' => (string) $config['endpoint'], 'healthUrl' => $this->url( $config, (string) $provider['healthPath'] ), 'modelsUrl' => $this->url( $config, (string) $provider['modelsPath'] ), 'chatUrl' => $this->url( $config, (string) $provider['chatPath'] ), 'hasApiToken' => '' !== (string) ( $config['apiToken'] ?? '' ) ];
	}

	private function provider( string $provider ): array { $providers = $this->providers(); if ( ! isset( $providers[ $provider ] ) ) { throw new \InvalidArgumentException( 'Unsupported local AI provider.' ); } return $providers[ $provider ]; }
	private function url( array $config, string $path ): string { return rtrim( (string) $config['endpoint'], '/' ) . '/' . ltrim( $path, '/' ); }

	private function request_json( string $method, string $url, ?array $body, array $config ): array {
		$headers = [ 'Accept' => 'application/json' ];
		$token = trim( (string) ( $config['apiToken'] ?? '' ) );
		if ( '' !== $token ) { $headers['Authorization'] = 'Bearer ' . $token; }
		$args = [ 'method' => $method, 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => 2097152, 'headers' => $headers ];
		if ( null !== $body ) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode( $body ); }
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) { throw new \RuntimeException( 'Local AI connection failed: ' . $response->get_error_message() ); }
		$status = (int) wp_remote_retrieve_response_code( $response );
		$text = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) { throw new \RuntimeException( 'Local AI endpoint returned HTTP ' . $status . '.' ); }
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) { throw new \RuntimeException( 'Local AI endpoint did not return JSON.' ); }
		return $data;
	}

	private function normalize_models( string $provider, array $data ): array {
		$models = [];
		if ( 'ollama' === $provider ) {
			foreach ( (array) ( $data['models'] ?? [] ) as $model ) {
				if ( ! is_array( $model ) ) { continue; }
				$details = is_array( $model['details'] ?? null ) ? $model['details'] : [];
				$models[] = [ 'id' => (string) ( $model['model'] ?? $model['name'] ?? '' ), 'name' => (string) ( $model['name'] ?? $model['model'] ?? '' ), 'size' => (int) ( $model['size'] ?? 0 ), 'family' => (string) ( $details['family'] ?? '' ), 'parameterSize' => (string) ( $details['parameter_size'] ?? '' ), 'quantization' => (string) ( $details['quantization_level'] ?? '' ) ];
			}
		} else {
			foreach ( (array) ( $data['data'] ?? $data['models'] ?? [] ) as $model ) {
				if ( is_string( $model ) ) { $models[] = [ 'id' => $model, 'name' => $model ]; continue; }
				if ( ! is_array( $model ) ) { continue; }
				$id = (string) ( $model['id'] ?? $model['model'] ?? $model['name'] ?? '' );
				if ( '' === $id ) { continue; }
				$models[] = [ 'id' => $id, 'name' => (string) ( $model['name'] ?? $id ), 'ownedBy' => (string) ( $model['owned_by'] ?? '' ) ];
			}
		}
		return array_values( array_filter( $models, static fn( array $model ): bool => '' !== (string) ( $model['id'] ?? '' ) ) );
	}
}
