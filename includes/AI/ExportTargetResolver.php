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

	public function status( int $post_id, string $scope = 'document', array $selected_ids = [], ?bool $client_present = null ): array {
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
		} elseif ( false === $client_present ) {
			// A stale browser selection must never be rescued from older server data. If the live
			// Elementor model says the target is gone, require a fresh selection even when a previous
			// autosave/main revision still contains the same ID.
			$state = 'stale-target';
			$message = 'The selected Elementor target no longer exists in the live editor. Select the current element again before exporting.';
		} elseif ( $working_status['complete'] ) {
			$state = 'ready';
			$message = 'The selected Elementor target is present in the current working document.';
		} elseif ( $main_status['complete'] ) {
			$state = 'sync-required';
			$message = 'The target exists in the main Elementor document but the current working autosave has not caught up yet.';
		} elseif ( true === $client_present ) {
			$state = 'sync-pending';
			$message = 'The selected target exists in the live Elementor editor but has not reached server-side working data yet.';
		} else {
			$state = 'target-missing';
			$message = 'The selected target is missing from server-side Elementor data and its live-editor presence could not be confirmed.';
		}

		$retryable = in_array( $state, [ 'sync-required', 'sync-pending', 'target-missing' ], true );
		$recommended_action = match ( $state ) {
			'ready' => 'export',
			'stale-target' => 'reselect-target',
			default => 'force-autosave',
		};

		return [
			'schema' => self::SCHEMA,
			'postId' => $post_id,
			'scope' => $scope,
			'selectedIds' => $selected_ids,
			'clientPresent' => $client_present,
			'state' => $state,
			'ready' => 'ready' === $state,
			'retryable' => $retryable,
			'recommendedAction' => $recommended_action,
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
