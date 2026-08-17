<?php
namespace CrescoLayer\Diagnostics;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Export-scoped diagnostics and fatal-error recovery.
 *
 * The monitor is passive outside Cresco export REST requests. During export it reserves a small
 * emergency memory block and opens an output buffer so fatal PHP errors can still be converted to
 * a machine-readable response instead of an opaque HTTP 500 page.
 */
final class ExportDiagnostics {
	public const SCHEMA = 'cresco-export-diagnostic/v1';
	private const RESERVE_BYTES = 262144;
	private const MAX_OUTPUT_EXCERPT = 800;

	private static bool $registered = false;
	private static string $reserve = '';
	private static ?array $active = null;

	public function register_hooks(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		self::$reserve = str_repeat( 'R', self::RESERVE_BYTES );
		add_filter( 'rest_pre_dispatch', [ $this, 'begin' ], -100, 3 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'finish' ], 100, 3 );
		register_shutdown_function( [ self::class, 'shutdown' ] );
	}

	public function begin( $result, $server, WP_REST_Request $request ) {
		if ( null !== $result || ! $this->is_export_route( $request->get_route() ) ) { return $result; }

		$request_id = $this->request_id( $request );
		self::$active = [
			'id' => $request_id,
			'route' => (string) $request->get_route(),
			'method' => (string) $request->get_method(),
			'postId' => absint( $request['id'] ?? 0 ),
			'scope' => sanitize_key( (string) $request->get_param( 'scope' ) ),
			'selected' => sanitize_text_field( (string) $request->get_param( 'selected' ) ),
			'contextProfile' => sanitize_key( (string) $request->get_param( 'context' ) ),
			'stage' => 'rest-pre-dispatch',
			'startedAt' => microtime( true ),
			'startMemory' => memory_get_usage( true ),
			'bufferBase' => ob_get_level(),
		];

		ob_start();
		$this->send_headers( $request_id, 'rest-pre-dispatch' );
		return $result;
	}

	public function finish( $response, $handler, WP_REST_Request $request ) {
		if ( ! self::$active || ! $this->is_export_route( $request->get_route() ) ) { return $response; }

		$unexpected = self::drain_output_buffer();
		$diagnostic = self::snapshot();
		if ( '' !== $unexpected ) {
			$diagnostic['unexpectedOutput'] = self::clean_excerpt( $unexpected );
		}

		if ( $response instanceof WP_Error ) {
			$codes = $response->get_error_codes();
			$code = $codes[0] ?? 'cresco_layer_error';
			$data = $response->get_error_data( $code );
			$data = is_array( $data ) ? $data : [];
			$data['status'] = (int) ( $data['status'] ?? 500 );
			$data['crescoDiagnostic'] = $diagnostic;
			$response->add_data( $data, $code );
			self::log( 'error', $response->get_error_message( $code ), $diagnostic );
		} elseif ( $response instanceof WP_REST_Response ) {
			$response->header( 'X-Cresco-Request-Id', (string) self::$active['id'] );
			$response->header( 'X-Cresco-Diagnostic-Stage', (string) self::$active['stage'] );
			if ( $response->get_status() >= 400 ) {
				$data = $response->get_data();
				$data = is_array( $data ) ? $data : [ 'message' => 'Cresco export request failed.' ];
				$data['crescoDiagnostic'] = $diagnostic;
				$response->set_data( $data );
				self::log( 'error', (string) ( $data['message'] ?? 'Cresco export request failed.' ), $diagnostic );
			}
		}

		self::$active = null;
		return $response;
	}

	public static function stage( string $stage, array $context = [] ): void {
		if ( ! self::$active ) { return; }
		self::$active['stage'] = sanitize_key( str_replace( '.', '-', $stage ) );
		if ( $context ) { self::$active['stageContext'] = self::sanitize_context( $context ); }
		if ( ! headers_sent() ) {
			header( 'X-Cresco-Diagnostic-Stage: ' . self::$active['stage'] );
		}
	}

	public static function current_id(): string {
		return self::$active ? (string) self::$active['id'] : '';
	}

	public static function snapshot( array $extra = [] ): array {
		$active = self::$active ?? [];
		$started = (float) ( $active['startedAt'] ?? microtime( true ) );
		$diagnostic = [
			'schema' => self::SCHEMA,
			'errorId' => (string) ( $active['id'] ?? '' ),
			'stage' => (string) ( $active['stage'] ?? 'unknown' ),
			'route' => (string) ( $active['route'] ?? '' ),
			'postId' => (int) ( $active['postId'] ?? 0 ),
			'scope' => (string) ( $active['scope'] ?? '' ),
			'selected' => (string) ( $active['selected'] ?? '' ),
			'contextProfile' => (string) ( $active['contextProfile'] ?? '' ),
			'elapsedMs' => max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) ),
			'memory' => [
				'startBytes' => (int) ( $active['startMemory'] ?? 0 ),
				'currentBytes' => memory_get_usage( true ),
				'peakBytes' => memory_get_peak_usage( true ),
				'limit' => (string) ini_get( 'memory_limit' ),
			],
			'runtime' => [
				'phpVersion' => PHP_VERSION,
				'maxExecutionTime' => (int) ini_get( 'max_execution_time' ),
			],
		];
		if ( isset( $active['stageContext'] ) ) { $diagnostic['stageContext'] = $active['stageContext']; }
		return array_replace_recursive( $diagnostic, self::sanitize_context( $extra ) );
	}

	public static function shutdown(): void {
		if ( ! self::$active ) { return; }
		$error = error_get_last();
		$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
		if ( ! is_array( $error ) || ! in_array( (int) ( $error['type'] ?? 0 ), $fatal_types, true ) ) {
			self::drain_output_buffer();
			self::$active = null;
			return;
		}

		self::$reserve = '';
		$unexpected = self::drain_output_buffer();
		$relative_file = self::relative_path( (string) ( $error['file'] ?? '' ) );
		$diagnostic = self::snapshot( [
			'fatal' => [
				'type' => (int) ( $error['type'] ?? 0 ),
				'file' => $relative_file,
				'line' => (int) ( $error['line'] ?? 0 ),
			],
		] );
		if ( '' !== $unexpected ) { $diagnostic['unexpectedOutput'] = self::clean_excerpt( $unexpected ); }

		$id = (string) ( self::$active['id'] ?? 'CX-unknown' );
		$stage = (string) ( self::$active['stage'] ?? 'unknown' );
		$raw_message = trim( (string) ( $error['message'] ?? 'Fatal PHP error.' ) );
		$message = sprintf(
			'Cresco export failed at %s [%s]: %s%s',
			$stage,
			$id,
			$raw_message,
			$relative_file ? sprintf( ' (%s:%d)', $relative_file, (int) ( $error['line'] ?? 0 ) ) : ''
		);
		self::log( 'fatal', $message, $diagnostic );

		if ( ! headers_sent() ) {
			http_response_code( 500 );
			header( 'Content-Type: application/json; charset=UTF-8' );
			header( 'X-Cresco-Request-Id: ' . $id );
			header( 'X-Cresco-Diagnostic-Stage: ' . $stage );
		}

		echo json_encode( [
			'code' => 'cresco_export_fatal',
			'message' => $message,
			'data' => [
				'status' => 500,
				'crescoDiagnostic' => $diagnostic,
			],
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		self::$active = null;
	}

	private function is_export_route( string $route ): bool {
		return (bool) preg_match( '#^/cresco-layer/v1/documents/\d+/export$#', $route );
	}

	private function request_id( WP_REST_Request $request ): string {
		$incoming = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $request->get_header( 'x-cresco-request-id' ) );
		if ( is_string( $incoming ) && preg_match( '/^[A-Za-z0-9_-]{8,80}$/', $incoming ) ) { return $incoming; }
		$random = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
		return 'CX-' . gmdate( 'YmdHis' ) . '-' . substr( preg_replace( '/[^A-Za-z0-9]/', '', $random ), 0, 10 );
	}

	private function send_headers( string $id, string $stage ): void {
		if ( headers_sent() ) { return; }
		header( 'X-Cresco-Request-Id: ' . $id );
		header( 'X-Cresco-Diagnostic-Stage: ' . sanitize_key( str_replace( '.', '-', $stage ) ) );
	}

	private static function drain_output_buffer(): string {
		if ( ! self::$active ) { return ''; }
		$base = (int) ( self::$active['bufferBase'] ?? ob_get_level() );
		$chunks = '';
		while ( ob_get_level() > $base ) {
			$chunk = ob_get_clean();
			if ( is_string( $chunk ) && '' !== $chunk ) { $chunks = $chunk . $chunks; }
		}
		return trim( $chunks );
	}

	private static function clean_excerpt( string $value ): string {
		$value = trim( preg_replace( '/\s+/', ' ', strip_tags( $value ) ) ?? '' );
		if ( strlen( $value ) > self::MAX_OUTPUT_EXCERPT ) { $value = substr( $value, 0, self::MAX_OUTPUT_EXCERPT ) . '…'; }
		return $value;
	}

	private static function sanitize_context( array $context ): array {
		$out = [];
		foreach ( $context as $key => $value ) {
			$key = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $key );
			if ( '' === $key ) { continue; }
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) { $out[ $key ] = $value; continue; }
			if ( is_string( $value ) ) { $out[ $key ] = self::clean_excerpt( $value ); continue; }
			if ( is_array( $value ) ) { $out[ $key ] = self::sanitize_context( $value ); }
		}
		return $out;
	}

	private static function relative_path( string $file ): string {
		if ( '' === $file ) { return ''; }
		$normalized = str_replace( '\\', '/', $file );
		$base = defined( 'CRESCO_LAYER_DIR' ) ? str_replace( '\\', '/', CRESCO_LAYER_DIR ) : '';
		if ( $base && 0 === strpos( $normalized, $base ) ) { return 'cresco-layer/' . ltrim( substr( $normalized, strlen( $base ) ), '/' ); }
		return basename( $normalized );
	}

	private static function log( string $level, string $message, array $diagnostic ): void {
		$payload = [
			'level' => $level,
			'message' => self::clean_excerpt( $message ),
			'diagnostic' => $diagnostic,
		];
		error_log( '[Cresco Layer Export] ' . json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
