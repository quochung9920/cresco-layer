<?php
namespace CrescoLayer\REST;

use CrescoLayer\AI\ContextResolver;
use CrescoLayer\AI\InternalPatchCompiler;
use CrescoLayer\AI\PackageBuilder;
use CrescoLayer\AI\PatchApplier;
use CrescoLayer\AI\PatchHistory;
use CrescoLayer\AI\PatchValidator;
use CrescoLayer\AI\SemanticPatchGuard;
use CrescoLayer\Audit\Auditor;
use CrescoLayer\Elementor\ConfigurationCatalog;
use CrescoLayer\Elementor\RuntimeSnapshotCoordinator;
use CrescoLayer\Skills\WidgetSkillRuntime;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Controller {
	/** Report from the last AI-result compilation, surfaced so the UI can show what Cresco filled in. */
	private array $aiImport = [];

	public function __construct(
		private PackageBuilder $builder,
		private PatchValidator $validator,
		private SemanticPatchGuard $semantic,
		private ConfigurationCatalog $catalog,
		private RuntimeSnapshotCoordinator $snapshot,
		private WidgetSkillRuntime $skills,
		private PatchApplier $applier,
		private Auditor $auditor
	) {}

	public function register_routes(): void {
		register_rest_route( 'cresco-layer/v1', '/health', [
			'methods' => 'GET',
			'callback' => [ $this, 'health' ],
			'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
		] );
		register_rest_route( 'cresco-layer/v1', '/elementor-catalog', [
			'methods' => 'GET',
			'callback' => [ $this, 'elementor_catalog' ],
			'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
		] );
		register_rest_route( 'cresco-layer/v1', '/elementor-catalog/(?P<kind>widget|element)/(?P<name>[^/]+)', [
			'methods' => 'GET',
			'callback' => [ $this, 'elementor_catalog_detail' ],
			'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
		] );
		register_rest_route( 'cresco-layer/v1', '/elementor-snapshot', [
			'methods' => 'GET',
			'callback' => [ $this, 'elementor_snapshot' ],
			'permission_callback' => [ $this, 'can_manage_snapshot' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/elementor-snapshot/section/(?P<section>[a-z0-9-]+)', [
			'methods' => 'GET',
			'callback' => [ $this, 'elementor_snapshot_section' ],
			'permission_callback' => [ $this, 'can_manage_snapshot' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/elementor-snapshot/(?P<kind>widget|element)/(?P<name>[^/]+)', [
			'methods' => 'GET',
			'callback' => [ $this, 'elementor_snapshot_registry' ],
			'permission_callback' => [ $this, 'can_manage_snapshot' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/elementor-snapshot/record/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [ $this, 'elementor_snapshot_record' ],
			'permission_callback' => [ $this, 'can_manage_snapshot' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/export', [
			'methods' => 'GET',
			'callback' => [ $this, 'export' ],
			'permission_callback' => [ $this, 'can_edit' ],
			'args' => [
				'scope' => [ 'default' => 'document', 'sanitize_callback' => 'sanitize_key' ],
				'selected' => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'context' => [ 'default' => ContextResolver::PROFILE_SMART, 'sanitize_callback' => 'sanitize_key' ],
			],
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/skills/(?P<element>[A-Za-z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [ $this, 'widget_skills' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/skills/(?P<element>[A-Za-z0-9_-]+)/resolve', [
			'methods' => 'POST',
			'callback' => [ $this, 'resolve_widget_skill' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/preview', [
			'methods' => 'POST',
			'callback' => [ $this, 'preview' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/apply', [
			'methods' => 'POST',
			'callback' => [ $this, 'apply' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/audit', [
			'methods' => 'GET',
			'callback' => [ $this, 'audit' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/history', [
			'methods' => 'GET',
			'callback' => [ $this, 'patch_history' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
		register_rest_route( 'cresco-layer/v1', '/documents/(?P<id>\d+)/history/(?P<entry>[A-Za-z0-9]+)/rollback', [
			'methods' => 'POST',
			'callback' => [ $this, 'rollback_patch' ],
			'permission_callback' => [ $this, 'can_edit' ],
		] );
	}

	public function patch_history( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$post_id = absint( $request['id'] );
			return new WP_REST_Response( [
				'schema' => PatchHistory::SCHEMA,
				'postId' => $post_id,
				'entries' => $this->applier->history()->all( $post_id ),
			] );
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function rollback_patch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( $this->applier->rollback( absint( $request['id'] ), (string) $request['entry'] ) );
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function can_edit( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_post', absint( $request['id'] ) );
	}

	public function can_manage_snapshot(): bool {
		return current_user_can( 'manage_options' );
	}

	public function health(): WP_REST_Response {
		return new WP_REST_Response( [
			'ok' => true,
			'version' => CRESCO_LAYER_VERSION,
			'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'elementorPro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			'packageSchema' => 'cresco-layer-ai-package/v2',
			'patchSchema' => 'cresco-layer-patch/v1',
			'aiResultSchema' => 'cresco-layer-ai-result/v1',
			'checksumFreeAiWorkflow' => true,
			'scopedExchange' => true,
			'semanticPatchValidation' => true,
			'postApplyVerification' => true,
			'elementorConfigurationCatalog' => 'lazy-v2',
			'elementorRuntimeSnapshot' => RuntimeSnapshotCoordinator::SCHEMA,
			'widgetSkillRuntime' => 'runtime-v1',
			'deterministicSkillCommands' => true,
			'aiContextResolver' => 'smart-v1',
			'dynamicTagDiscovery' => 'registry-info-v2',
			'elementorProModuleDiscovery' => 'named-modules-v2',
		], 200 );
	}

	public function elementor_catalog( WP_REST_Request $request ) {
		try { return new WP_REST_Response( $this->catalog->summary(), 200 ); }
		catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function elementor_catalog_detail( WP_REST_Request $request ) {
		try {
			$kind = (string) $request['kind'];
			$name = rawurldecode( (string) $request['name'] );
			return new WP_REST_Response( $this->catalog->detail( $kind, $name ), 200 );
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function elementor_snapshot( WP_REST_Request $request ) {
		try { return new WP_REST_Response( $this->snapshot->index(), 200 ); }
		catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function elementor_snapshot_section( WP_REST_Request $request ) {
		try { return new WP_REST_Response( $this->snapshot->section( sanitize_key( (string) $request['section'] ) ), 200 ); }
		catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function elementor_snapshot_registry( WP_REST_Request $request ) {
		try {
			$kind = (string) $request['kind'];
			$name = rawurldecode( (string) $request['name'] );
			return new WP_REST_Response( $this->snapshot->registryEntry( $kind, $name ), 200 );
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function elementor_snapshot_record( WP_REST_Request $request ) {
		try { return new WP_REST_Response( $this->snapshot->record( absint( $request['id'] ) ), 200 ); }
		catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function export( WP_REST_Request $request ) {
		try {
			$selected = array_values( array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'selected' ) ) ) ) );
			return new WP_REST_Response(
				$this->builder->build(
					absint( $request['id'] ),
					(string) $request->get_param( 'scope' ),
					$selected,
					(string) $request->get_param( 'context' )
				),
				200
			);
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function widget_skills( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response( $this->skills->profile( absint( $request['id'] ), (string) $request['element'] ), 200 );
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function resolve_widget_skill( WP_REST_Request $request ) {
		try {
			return new WP_REST_Response(
				$this->skills->resolve( absint( $request['id'] ), (string) $request['element'], $this->request_body( $request ) ),
				200
			);
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function preview( WP_REST_Request $request ) {
		try {
			$post_id = absint( $request['id'] );
			$body = $this->request_body( $request );
			$patch = $this->validator->validate( $this->request_patch_from_body( $body, $post_id ), $post_id );
			$semantic = $this->semantic->analyze( $post_id, $patch );
			$this->semantic->assert_safe( $semantic );
			$result = $this->applier->preview( $post_id, $patch, $this->expected_scope( $body ) );
			$result['semantic'] = $semantic;
			if ( $this->aiImport ) { $result['aiImport'] = $this->aiImport['report']; }
			return new WP_REST_Response( $result, 200 );
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function apply( WP_REST_Request $request ) {
		try {
			$post_id = absint( $request['id'] );
			$body = $this->request_body( $request );
			$patch = $this->validator->validate( $this->request_patch_from_body( $body, $post_id ), $post_id );
			$semantic = $this->semantic->analyze( $post_id, $patch );
			$this->semantic->assert_safe( $semantic );
			$result = $this->applier->apply( $post_id, $patch, $this->expected_scope( $body ) );
			$result['semantic'] = $semantic;
			if ( $this->aiImport ) { $result['aiImport'] = $this->aiImport['report']; }
			$result['verification'] = $this->semantic->verify( $post_id, $patch );
			return new WP_REST_Response( $result, 200 );
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function audit( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->auditor->audit_post( absint( $request['id'] ) ), 200 );
	}

	private function request_body( WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { throw new \InvalidArgumentException( 'Request must contain JSON.' ); }
		return $body;
	}

	/**
	 * Accept either an internal patch or a raw AI answer.
	 *
	 * `aiResult` carries whatever the user pasted, dropped or uploaded — envelope, markdown fence and
	 * surrounding prose included. Compiling it here means preview and apply share one entry point, so
	 * the two can never disagree about what a given answer means.
	 */
	private function request_patch_from_body( array $body, int $post_id = 0 ): array {
		if ( isset( $body['aiResult'] ) && is_string( $body['aiResult'] ) ) {
			$this->aiImport = ( new InternalPatchCompiler() )->compile(
				$body['aiResult'],
				$post_id,
				$this->document_elements( $post_id ),
				trim( (string) ( $body['selectedElementId'] ?? '' ) )
			);
			return $this->aiImport['patch'];
		}
		if ( isset( $body['patch'] ) && is_array( $body['patch'] ) ) { return $body['patch']; }
		if ( isset( $body['schema'] ) ) { return $body; }
		throw new \InvalidArgumentException( 'Request must contain an AI result or a JSON patch.' );
	}

	/** Current working elements, used to resolve the target and avoid ID collisions. */
	private function document_elements( int $post_id ): array {
		if ( ! $post_id ) { return []; }
		$manager = \Elementor\Plugin::instance()->documents;
		$document = $manager->get_doc_or_auto_save( $post_id, get_current_user_id() ) ?: $manager->get( $post_id, false );
		return $document ? (array) $document->get_elements_data() : [];
	}

	private function expected_scope( array $body ): ?array {
		if ( ! isset( $body['expectedScope'] ) ) { return null; }
		if ( ! is_array( $body['expectedScope'] ) ) { throw new \InvalidArgumentException( 'expectedScope must be an object.' ); }
		$mode = sanitize_key( (string) ( $body['expectedScope']['mode'] ?? '' ) );
		$root = trim( (string) ( $body['expectedScope']['rootElementId'] ?? '' ) );
		if ( ! in_array( $mode, [ 'widget', 'subtree', 'selection', 'document' ], true ) ) { throw new \InvalidArgumentException( 'expectedScope mode is invalid.' ); }
		if ( 'document' !== $mode && ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $root ) ) { throw new \InvalidArgumentException( 'expectedScope rootElementId is invalid.' ); }
		return [ 'mode' => $mode, 'rootElementId' => $root ];
	}

	private function error( \Throwable $error ): WP_Error {
		$message = $error->getMessage();
		$lower = strtolower( $message );
		$status = str_contains( $lower, 'older elementor document' ) || str_contains( $lower, 'checksum' ) || str_contains( $lower, 'changed after ai export' )
			? 409
			: ( $error instanceof \InvalidArgumentException ? 400 : 500 );
		return new WP_Error( 'cresco_layer_error', $message, [ 'status' => $status ] );
	}
}
