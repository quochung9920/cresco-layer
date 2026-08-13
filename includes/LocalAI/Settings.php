<?php
namespace CrescoLayer\LocalAI;

final class Settings {
	public const OPTION = 'cresco_layer_local_ai';

	public function defaults(): array {
		return [
			'enabled' => false,
			'provider' => 'ollama',
			'connectionMode' => 'browser',
			'endpoint' => 'http://127.0.0.1:11434',
			'analysisModel' => '',
			'visionModel' => '',
			'temperature' => 0.2,
			'contextWindow' => 32768,
			'maxOutputTokens' => 4096,
			'minimumConfidence' => 0.85,
			'requirePreview' => true,
			'autoApplySafe' => false,
			'allowScreenshots' => false,
			'includeNeighborContext' => true,
			'redactSensitiveContext' => true,
			'apiToken' => '',
		];
	}

	public function get( bool $include_secret = false ): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$settings = array_replace( $this->defaults(), $stored );
		$settings['hasApiToken'] = '' !== (string) ( $settings['apiToken'] ?? '' );
		if ( ! $include_secret ) { unset( $settings['apiToken'] ); }
		return $settings;
	}

	public function update( array $input ): array {
		$current = $this->get( true );
		$defaults = $this->defaults();
		$provider = sanitize_key( (string) ( $input['provider'] ?? $current['provider'] ) );
		if ( ! in_array( $provider, [ 'ollama', 'lm-studio', 'llama-cpp', 'openai-compatible' ], true ) ) { throw new \InvalidArgumentException( 'Unsupported local AI provider.' ); }
		$mode = sanitize_key( (string) ( $input['connectionMode'] ?? $current['connectionMode'] ) );
		if ( ! in_array( $mode, [ 'server', 'browser' ], true ) ) { throw new \InvalidArgumentException( 'Local AI connection mode must be server or browser.' ); }
		$endpoint = $this->sanitize_endpoint( trim( (string) ( $input['endpoint'] ?? $current['endpoint'] ) ) );
		$token = (string) ( $current['apiToken'] ?? '' );
		if ( array_key_exists( 'apiToken', $input ) ) {
			$incoming = trim( (string) $input['apiToken'] );
			if ( '' !== $incoming && '[REDACTED]' !== $incoming && '********' !== $incoming ) { $token = $incoming; }
		}
		if ( ! empty( $input['clearApiToken'] ) ) { $token = ''; }

		$settings = [
			'enabled' => $this->bool( $input['enabled'] ?? $current['enabled'] ),
			'provider' => $provider,
			'connectionMode' => $mode,
			'endpoint' => $endpoint,
			'analysisModel' => sanitize_text_field( (string) ( $input['analysisModel'] ?? $current['analysisModel'] ) ),
			'visionModel' => sanitize_text_field( (string) ( $input['visionModel'] ?? $current['visionModel'] ) ),
			'temperature' => $this->float_range( $input['temperature'] ?? $current['temperature'], 0.0, 2.0, (float) $defaults['temperature'] ),
			'contextWindow' => $this->int_range( $input['contextWindow'] ?? $current['contextWindow'], 2048, 1048576, (int) $defaults['contextWindow'] ),
			'maxOutputTokens' => $this->int_range( $input['maxOutputTokens'] ?? $current['maxOutputTokens'], 256, 131072, (int) $defaults['maxOutputTokens'] ),
			'minimumConfidence' => $this->float_range( $input['minimumConfidence'] ?? $current['minimumConfidence'], 0.5, 1.0, (float) $defaults['minimumConfidence'] ),
			'requirePreview' => $this->bool( $input['requirePreview'] ?? $current['requirePreview'] ),
			'autoApplySafe' => $this->bool( $input['autoApplySafe'] ?? $current['autoApplySafe'] ),
			'allowScreenshots' => $this->bool( $input['allowScreenshots'] ?? $current['allowScreenshots'] ),
			'includeNeighborContext' => $this->bool( $input['includeNeighborContext'] ?? $current['includeNeighborContext'] ),
			'redactSensitiveContext' => true,
			'apiToken' => $token,
		];
		update_option( self::OPTION, $settings, false );
		return $this->get();
	}

	public function editor_summary(): array {
		$settings = $this->get();
		return [
			'enabled' => (bool) $settings['enabled'],
			'provider' => (string) $settings['provider'],
			'connectionMode' => (string) $settings['connectionMode'],
			'endpoint' => (string) $settings['endpoint'],
			'analysisModel' => (string) $settings['analysisModel'],
			'visionModel' => (string) $settings['visionModel'],
			'minimumConfidence' => (float) $settings['minimumConfidence'],
			'requirePreview' => (bool) $settings['requirePreview'],
			'autoApplySafe' => (bool) $settings['autoApplySafe'],
			'allowScreenshots' => (bool) $settings['allowScreenshots'],
			'includeNeighborContext' => (bool) $settings['includeNeighborContext'],
		];
	}

	private function sanitize_endpoint( string $endpoint ): string {
		$endpoint = rtrim( esc_url_raw( $endpoint ), '/' );
		if ( '' === $endpoint ) { throw new \InvalidArgumentException( 'Local AI endpoint is required.' ); }
		$parts = wp_parse_url( $endpoint );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) { throw new \InvalidArgumentException( 'Local AI endpoint must be an HTTP(S) URL.' ); }
		if ( ! $this->is_local_host( $host ) ) { throw new \InvalidArgumentException( 'Local AI endpoint must resolve to localhost, a private LAN address, host.docker.internal, or a .local host.' ); }
		return $endpoint;
	}

	private function is_local_host( string $host ): bool {
		if ( in_array( $host, [ 'localhost', '::1', '[::1]', 'host.docker.internal' ], true ) || str_ends_with( $host, '.local' ) ) { return true; }
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $host );
			if ( false === $long ) { return false; }
			$unsigned = (int) sprintf( '%u', $long );
			return ( $unsigned >= 167772160 && $unsigned <= 184549375 ) || ( $unsigned >= 2886729728 && $unsigned <= 2887778303 ) || ( $unsigned >= 3232235520 && $unsigned <= 3232301055 ) || ( $unsigned >= 2130706432 && $unsigned <= 2147483647 );
		}
		return false;
	}

	private function bool( $value ): bool { if ( is_bool( $value ) ) { return $value; } return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true ); }
	private function int_range( $value, int $min, int $max, int $fallback ): int { $value = is_numeric( $value ) ? (int) $value : $fallback; return max( $min, min( $max, $value ) ); }
	private function float_range( $value, float $min, float $max, float $fallback ): float { $value = is_numeric( $value ) ? (float) $value : $fallback; return max( $min, min( $max, $value ) ); }
}
