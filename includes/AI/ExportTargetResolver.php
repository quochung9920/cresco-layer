<?php
namespace CrescoLayer\AI;

use Elementor\Plugin as ElementorPlugin;

/**
 * Resolve whether the target selected in the Elementor editor is already present in the
 * server-side working document that PackageBuilder will export.
 *
 * This class is intentionally read-only. It never copies client data into Elementor storage and
 * never falls back to stale document content. The editor-side preflight is responsible for asking
 * Elementor to create/update its autosave before the real export request is released.
 */
final class ExportTargetResolver {
	public const SCHEMA = 'cresco-export-target-status/v1';

	private ElementLocator $locator;

	public function __construct( ?ElementLocator $locator = null ) {
		$this->locator = $locator ?? new ElementLocator();
	}

	public function status( int $post_id, string $scope = 'document', array $selected_ids = [] ): array {
		$scope = in_array( $scope, ElementLocator::SCOPES, true ) ? $scope : 'document';
		$selected_ids = $this->locator->normalize_ids( $selected_ids );
		if ( 'widget' === $scope && $selected_ids ) { $selected_ids = [ $selected_ids[0] ]; }
		if ( 'document' !== $scope && ! $selected_ids ) {
			throw new \InvalidArgumentException( 'A scoped export requires an Elementor target ID.' );
		}

		$manager = ElementorPlugin::instance()->documents;
		$main = $manager->get( $post_id );
		if ( ! $main ) { throw new \RuntimeException( 'Elementor document not found.' ); }

		$working = $manager->get_doc_or_auto_save( $post_id, get_current_user_id() );
		if ( ! $working ) { $working = $main; }

		$working_status = $this->inspect_document( $working, $post_id, $selected_ids, 'working' );
		$main_status = $working === $main
			? array_merge( $working_status, [ 'source' => 'main', 'sameAsWorking' => true ] )
			: $this->inspect_document( $main, $post_id, $selected_ids, 'main' );

		if ( 'document' === $scope ) {
			$state = 'ready';
			$message = 'The current Elementor working document is ready for export.';
		} elseif ( $working_status['complete'] ) {
			$state = 'ready';
			$message = 'The selected Elementor target is present in the current working document.';
		} elseif ( $main_status['complete'] ) {
			$state = 'sync-required';
			$message = 'The target exists in the main Elementor document but the current working autosave has not caught up yet.';
		} else {
			$state = 'client-ahead';
			$message = 'The selected target is not present in server-side Elementor data yet. The editor client is likely ahead of its autosave.';
		}

		return [
			'schema' => self::SCHEMA,
			'postId' => $post_id,
			'scope' => $scope,
			'selectedIds' => $selected_ids,
			'state' => $state,
			'ready' => 'ready' === $state,
			'retryable' => 'ready' !== $state,
			'recommendedAction' => 'ready' === $state ? 'export' : 'force-autosave',
			'message' => $message,
			'working' => $working_status,
			'main' => $main_status,
		];
	}

	private function inspect_document( $document, int $requested_post_id, array $selected_ids, string $source ): array {
		$elements = $document ? (array) $document->get_elements_data() : [];
		$post = $document && method_exists( $document, 'get_post' ) ? $document->get_post() : null;
		$document_post_id = $post ? (int) $post->ID : $requested_post_id;
		$found = [];
		$missing = [];

		foreach ( $selected_ids as $id ) {
			if ( null !== $this->locator->find( $elements, $id ) ) { $found[] = $id; }
			else { $missing[] = $id; }
		}

		return [
			'source' => $source,
			'postId' => $document_post_id,
			'isAutosave' => $document_post_id !== $requested_post_id,
			'foundIds' => $found,
			'missingIds' => $missing,
			'complete' => ! $missing,
			'elementCount' => count( $this->locator->collect_ids( $elements ) ),
			'sameAsWorking' => false,
		];
	}
}
