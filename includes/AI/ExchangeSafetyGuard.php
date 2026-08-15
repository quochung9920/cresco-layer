<?php
namespace CrescoLayer\AI;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Keeps exported Elementor source context separate from AI-authored mutations.
 *
 * Exported document/element state exists so the AI can reason about the current design. It is not
 * a payload template that should be echoed back into Elementor. This guard makes that boundary
 * explicit in every REST export and blocks serialization placeholders from ever reaching preview
 * or apply, so lossy context can never overwrite live Elementor values.
 */
final class ExchangeSafetyGuard {
	public const POLICY_SCHEMA = 'cresco-layer-ai-exchange-policy/v1';
	private const BLOCKED_PLACEHOLDERS = [ '[TRUNCATED]', '[REDACTED]' ];

	public function register_hooks(): void {
		add_filter( 'rest_post_dispatch', [ $this, 'decorate_export_response' ], 20, 3 );
		add_filter( 'rest_pre_dispatch', [ $this, 'guard_import_request' ], 20, 3 );
	}

	/**
	 * Add an explicit read-only-source / delta-mutation contract to AI exports.
	 *
	 * Kept public and pure so contract tests can exercise the policy without booting WordPress REST.
	 */
	public function decorate_package( array $package ): array {
		if ( 'cresco-layer-ai-package/v2' !== ( $package['schema'] ?? '' ) ) { return $package; }

		$scope = is_array( $package['editableScope'] ?? null ) ? $package['editableScope'] : [];
		$root = trim( (string) ( $scope['rootElementId'] ?? '' ) );

		$package['exchangePolicy'] = [
			'schema' => self::POLICY_SCHEMA,
			'separation' => 'source-context-is-read-only; mutation-output-is-delta-only-by-default',
			'sourceContext' => [
				'mode' => 'read-only-reference',
				'paths' => [ 'document.content', 'elementContext', 'elementStates' ],
				'echoBack' => false,
				'copyExistingSubtreeIntoMutation' => false,
			],
			'mutationOutput' => [
				'schema' => 'cresco-layer-patch/v1',
				'strategy' => 'delta-first',
				'preferredOperations' => [ 'insert-element', 'update-setting', 'remove-setting', 'move-element' ],
				'destructiveOperations' => [ 'replace-element', 'replace-settings', 'remove-element', 'replace-document' ],
				'replaceElementPolicy' => 'only-when-the-user-explicitly-requests-a-full-rebuild-of-that-exact-target',
				'appendParentId' => $root,
			],
			'placeholderPolicy' => [
				'blockedValues' => self::BLOCKED_PLACEHOLDERS,
				'blockedKeys' => [ '__cresco_truncated__' ],
				'onImport' => 'reject-before-preview-or-apply',
			],
			'layoutPolicy' => [
				'nativeControlsFirst' => true,
				'customCssFallbackOnlyWhenNoNativeControlExists' => true,
				'siblingSpacing' => 'parent-gap',
			],
		];

		$briefing = $this->delta_instructions( $root );
		$existing = trim( (string) ( $package['instructions'] ?? '' ) );
		if ( false === strpos( $existing, 'CRESCO SOURCE / MUTATION SEPARATION:' ) ) {
			$package['instructions'] = trim( $existing . "\n\n" . $briefing );
		}

		return $package;
	}

	/** Recursively reject placeholders produced by lossy export serialization. */
	public static function assert_no_placeholders( $value, string $path = '$' ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$key_string = (string) $key;
				$child_path = $path . '[' . self::path_segment( $key_string ) . ']';
				if ( '__cresco_truncated__' === $key_string ) {
					throw new \InvalidArgumentException(
						'AI import contains a truncated-export marker at ' . $child_path . '. Cresco blocked the write so incomplete export data cannot overwrite live Elementor data. Return only the intended delta change or re-export the source.'
					);
				}
				self::assert_no_placeholders( $child, $child_path );
			}
			return;
		}

		if ( ! is_string( $value ) ) { return; }
		if ( ! in_array( trim( $value ), self::BLOCKED_PLACEHOLDERS, true ) ) { return; }

		throw new \InvalidArgumentException(
			'AI import contains the serialization placeholder ' . trim( $value ) . ' at ' . $path . '. Cresco blocked the write so exported placeholders cannot replace real Elementor settings. Use a delta patch (insert/update) or explicitly rebuild from complete non-placeholder values.'
		);
	}

	public function decorate_export_response( $response, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! $this->is_export_route( $request->get_route() ) ) { return $response; }
		if ( ! $response instanceof WP_REST_Response || $response->is_error() ) { return $response; }

		$data = $response->get_data();
		if ( ! is_array( $data ) ) { return $response; }
		$response->set_data( $this->decorate_package( $data ) );
		return $response;
	}

	public function guard_import_request( $result, $server, $request ) {
		if ( null !== $result ) { return $result; }
		if ( ! $request instanceof WP_REST_Request || ! $this->is_import_route( $request->get_route() ) ) { return $result; }

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) { return $result; }

		try {
			self::assert_no_placeholders( $body, '$.request' );
		} catch ( \InvalidArgumentException $error ) {
			return new WP_Error(
				'cresco_layer_ai_placeholder_blocked',
				$error->getMessage(),
				[ 'status' => 400, 'exchangePolicy' => self::POLICY_SCHEMA ]
			);
		}

		return $result;
	}

	private function delta_instructions( string $root ): string {
		$append = '' !== $root
			? '- For additions to the selected subtree, insert new elements under parentId ' . $root . ' (or the exact intended descendant parent). Do not rebuild the existing root just to append content.'
			: '- For additions, use insert-element against the exact intended existing parent. Do not rebuild an existing subtree just to append content.';

		return implode( "\n", [
			'CRESCO SOURCE / MUTATION SEPARATION:',
			'- document.content, elementContext and elementStates are READ-ONLY SOURCE CONTEXT. Use them to understand the existing Elementor UI; never copy or echo the existing subtree back as the mutation payload.',
			'- Return only what should change. Prefer insert-element for new UI and update-setting/remove-setting for existing native controls.',
			$append,
			'- replace-element is destructive. Use it only when the user explicitly asks to fully rebuild that exact target and you can provide every required persisted value without placeholders or guesses.',
			'- Never emit [TRUNCATED], [REDACTED] or __cresco_truncated__. Cresco rejects these before Preview/Apply.',
			'- Use native Elementor controls first. Use parent gap/row-gap/column-gap for sibling spacing. Use custom_css only when the live runtime has no native control for the required property.',
		] );
	}

	private function is_export_route( string $route ): bool {
		return 1 === preg_match( '#^/cresco-layer/v1/documents/\d+/export$#', $route );
	}

	private function is_import_route( string $route ): bool {
		return 1 === preg_match( '#^/cresco-layer/v1/documents/\d+/(?:preview|apply)$#', $route );
	}

	private static function path_segment( string $key ): string {
		if ( preg_match( '/^[A-Za-z0-9_.:-]+$/', $key ) ) { return $key; }
		$encoded = json_encode( $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return is_string( $encoded ) ? $encoded : rawurlencode( $key );
	}
}
