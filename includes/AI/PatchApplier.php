<?php
namespace CrescoLayer\AI;

use CrescoLayer\Audit\Auditor;
use CrescoLayer\Support\DocumentChecksum;
use Elementor\Plugin as ElementorPlugin;

final class PatchApplier {
	private ElementLocator $locator;
	private PatchHistory $history;

	public function __construct( private PatchValidator $validator, private Auditor $auditor, ?ElementLocator $locator = null, ?PatchHistory $history = null ) {
		$this->locator = $locator ?? new ElementLocator();
		$this->history = $history ?? new PatchHistory();
	}

	public function history(): PatchHistory { return $this->history; }

	/**
	 * Internal checksum of the Elementor working document.
	 *
	 * AI patches no longer carry or validate freshness checksums. This value remains available for
	 * audit/history/rollback integrity and server-side diagnostics only.
	 */
	public function current_checksum( int $post_id ): string {
		return $this->load_document( $post_id )[4];
	}

	public function preview( int $post_id, array $raw_patch, ?array $expected_scope = null ): array {
		$patch = $this->validator->validate( $raw_patch, $post_id );
		[ $main_document, $working_document, $elements, $settings ] = $this->load_document( $post_id );
		$this->assert_expected_scope( $patch, $expected_scope );
		$this->assert_scope_operations( $patch, $elements );
		$candidate_elements = $elements;
		$candidate_settings = $settings;
		$this->apply_operations( $candidate_elements, $candidate_settings, $patch['operations'], $patch['scope']['mode'] ?? 'document' );
		return [
			'valid' => true,
			'scope' => $patch['scope'] ?? null,
			'diff' => Diff::summarize( $patch['operations'] ),
			'diffDetails' => Diff::details( $patch['operations'], $elements, $settings ),
			'auditBefore' => $this->auditor->audit_elements( $elements ),
			'auditAfter' => $this->auditor->audit_elements( $candidate_elements ),
			'willUseAutosave' => $this->should_use_autosave( $main_document, $working_document ),
		];
	}

	public function apply( int $post_id, array $raw_patch, ?array $expected_scope = null ): array {
		$patch = $this->validator->validate( $raw_patch, $post_id );
		[ $main_document, $working_document, $elements, $settings, $current_checksum ] = $this->load_document( $post_id );
		$this->assert_expected_scope( $patch, $expected_scope );
		$this->assert_scope_operations( $patch, $elements );
		$candidate_elements = $elements;
		$candidate_settings = $settings;
		$this->apply_operations( $candidate_elements, $candidate_settings, $patch['operations'], $patch['scope']['mode'] ?? 'document' );

		$target = $this->save_target( $main_document, $working_document );
		$result = $target->save( [ 'elements' => $candidate_elements, 'settings' => $candidate_settings ] );
		if ( false === $result ) { throw new \RuntimeException( 'Elementor rejected the document save.' ); }

		$target_post = method_exists( $target, 'get_post' ) ? $target->get_post() : null;
		$target_id = $target_post ? (int) $target_post->ID : $post_id;
		$reloaded = ElementorPlugin::instance()->documents->get( $target_id, false );
		$saved_elements = $reloaded ? (array) $reloaded->get_elements_data() : $candidate_elements;
		$saved_settings = get_post_meta( $target_id, '_elementor_page_settings', true );
		$saved_settings = is_array( $saved_settings ) ? $saved_settings : $candidate_settings;
		$saved_checksum = DocumentChecksum::hash( $saved_elements, $saved_settings );
		$is_autosave = $target_id !== $post_id;

		update_post_meta( $post_id, '_cresco_layer_last_ai_import', [
			'label' => $patch['label'],
			'base_checksum' => $current_checksum,
			'saved_checksum' => $saved_checksum,
			'operations' => count( $patch['operations'] ),
			'scope' => $patch['scope'] ?? null,
			'user_id' => get_current_user_id(),
			'storage' => $is_autosave ? 'elementor-autosave' : 'draft-document',
			'applied_at' => gmdate( 'c' ),
		] );
		// $elements/$settings are the pre-patch working document, so this entry can restore it later.
		$history_id = $this->history->record( $post_id, [
			'label' => $patch['label'],
			'kind' => 'patch',
			'operations' => count( $patch['operations'] ),
			'scope' => $patch['scope'] ?? null,
			'storage' => $is_autosave ? 'elementor-autosave' : 'draft-document',
			'baseChecksum' => $current_checksum,
			'savedChecksum' => $saved_checksum,
		], $elements, $settings );
		$this->auditor->invalidate_post_cache( $post_id );
		do_action( 'cresco_layer/ai_patch_applied', $post_id, $patch, $saved_checksum, $target );

		return [
			'applied' => true,
			'checksum' => $saved_checksum,
			'storage' => $is_autosave ? 'elementor-autosave' : 'draft-document',
			'scope' => $patch['scope'] ?? null,
			'diff' => Diff::summarize( $patch['operations'] ),
			'audit' => $this->auditor->audit_elements( $saved_elements ),
			'historyId' => $history_id,
		];
	}

	/**
	 * Restore a recorded pre-patch snapshot through Elementor's Document API. The restore is itself
	 * recorded, so undoing a rollback is just another rollback rather than a dead end.
	 */
	public function rollback( int $post_id, string $entry_id ): array {
		$entry = $this->history->get( $post_id, $entry_id );
		if ( ! $entry ) { throw new \RuntimeException( 'That Cresco history entry no longer exists.' ); }
		if ( empty( $entry['restorable'] ) || ! is_array( $entry['snapshot'] ?? null ) ) {
			throw new \RuntimeException( 'This history entry kept only its audit record because the document snapshot exceeded the storage budget. It cannot be restored.' );
		}

		$restore_elements = (array) ( $entry['snapshot']['elements'] ?? [] );
		$restore_settings = (array) ( $entry['snapshot']['settings'] ?? [] );

		[ $main_document, $working_document, $elements, $settings, $current_checksum ] = $this->load_document( $post_id );

		$target = $this->save_target( $main_document, $working_document );
		$result = $target->save( [ 'elements' => $restore_elements, 'settings' => $restore_settings ] );
		if ( false === $result ) { throw new \RuntimeException( 'Elementor rejected the rollback save.' ); }

		$target_post = method_exists( $target, 'get_post' ) ? $target->get_post() : null;
		$target_id = $target_post ? (int) $target_post->ID : $post_id;
		$reloaded = ElementorPlugin::instance()->documents->get( $target_id, false );
		$saved_elements = $reloaded ? (array) $reloaded->get_elements_data() : $restore_elements;
		$saved_settings = get_post_meta( $target_id, '_elementor_page_settings', true );
		$saved_settings = is_array( $saved_settings ) ? $saved_settings : $restore_settings;
		$saved_checksum = DocumentChecksum::hash( $saved_elements, $saved_settings );
		$is_autosave = $target_id !== $post_id;

		$history_id = $this->history->record( $post_id, [
			'label' => sprintf( 'Rollback of: %s', (string) ( $entry['label'] ?? '' ) ),
			'kind' => 'rollback',
			'operations' => 0,
			'scope' => $entry['scope'] ?? null,
			'storage' => $is_autosave ? 'elementor-autosave' : 'draft-document',
			'baseChecksum' => $current_checksum,
			'savedChecksum' => $saved_checksum,
		], $elements, $settings );

		$this->auditor->invalidate_post_cache( $post_id );
		do_action( 'cresco_layer/ai_patch_rolled_back', $post_id, $entry_id, $saved_checksum, $target );

		return [
			'restored' => true,
			'restoredFrom' => $entry_id,
			'checksum' => $saved_checksum,
			'verified' => hash_equals( DocumentChecksum::hash( $restore_elements, $restore_settings ), $saved_checksum ),
			'storage' => $is_autosave ? 'elementor-autosave' : 'draft-document',
			'historyId' => $history_id,
			'audit' => $this->auditor->audit_elements( $saved_elements ),
		];
	}

	private function load_document( int $post_id ): array {
		$manager = ElementorPlugin::instance()->documents;
		$main = $manager->get_with_permissions( $post_id );
		if ( ! $main ) { throw new \RuntimeException( 'Elementor document is unavailable or cannot be edited.' ); }
		$working = $manager->get_doc_or_auto_save( $post_id, get_current_user_id() );
		if ( ! $working ) { $working = $main; }
		$elements = (array) $working->get_elements_data();
		$working_post = method_exists( $working, 'get_post' ) ? $working->get_post() : null;
		$settings_id = $working_post ? (int) $working_post->ID : $post_id;
		$settings = get_post_meta( $settings_id, '_elementor_page_settings', true );
		$settings = is_array( $settings ) ? $settings : [];
		return [ $main, $working, $elements, $settings, DocumentChecksum::hash( $elements, $settings ) ];
	}

	private function assert_expected_scope( array $patch, ?array $expected_scope ): void {
		if ( null === $expected_scope ) { return; }
		$scope = $patch['scope'] ?? null;
		if ( ! is_array( $scope ) ) { throw new \RuntimeException( 'This editor import requires a scoped Cresco Layer patch.' ); }
		$expected_mode = sanitize_key( (string) ( $expected_scope['mode'] ?? '' ) );
		$expected_root = trim( (string) ( $expected_scope['rootElementId'] ?? '' ) );
		if ( $expected_mode && $scope['mode'] !== $expected_mode ) { throw new \RuntimeException( 'The AI patch scope does not match the selected Elementor import mode.' ); }
		if ( ! $expected_root ) { return; }

		/*
		 * Comparing only a declared root let a patch through when it declared none, and its operations
		 * then landed on whatever elementIds they carried. The selected element must be inside the
		 * patch's own scope, however that scope is expressed.
		 */
		$root = trim( (string) ( $scope['rootElementId'] ?? '' ) );
		$ids = array_map( 'strval', (array) ( $scope['elementIds'] ?? [] ) );
		$covers_selection = ( '' !== $root && $root === $expected_root ) || ( '' === $root && in_array( $expected_root, $ids, true ) );
		if ( ! $covers_selection ) {
			throw new \RuntimeException( sprintf(
				'The AI result targets %s but %s is selected in Elementor. Select the matching element, or export again for the current selection.',
				'' !== $root ? $root : ( $ids ? implode( ', ', $ids ) : 'an unnamed element' ),
				$expected_root
			) );
		}
	}

	/**
	 * Element-level operations require a scope that authorises them.
	 *
	 * Page-level operations carry no element target, so they remain valid without one.
	 */
	private function assert_no_element_operations( array $patch ): void {
		foreach ( $patch['operations'] as $op ) {
			$element_id = trim( (string) ( $op['elementId'] ?? $op['parentId'] ?? '' ) );
			if ( '' === $element_id ) { continue; }
			throw new \RuntimeException( sprintf(
				'This patch changes Elementor element %s without declaring which elements it is allowed to modify. Re-export for the element you want to change.',
				$element_id
			) );
		}
	}

	private function assert_scope_operations( array $patch, array $elements ): void {
		$scope = $patch['scope'] ?? null;

		/*
		 * A patch with no scope used to skip every target check, so an operation naming any element
		 * in the document was applied to it — the user selected one container and a different one was
		 * rewritten. Only an explicit document scope may address the whole page; an absent scope is a
		 * patch that never said what it was allowed to touch, which is not permission.
		 */
		if ( ! is_array( $scope ) ) {
			$this->assert_no_element_operations( $patch );
			return;
		}
		if ( 'document' === $scope['mode'] ) { return; }
		$allowed = $this->locator->scope_ids( $elements, $scope['mode'], $scope['elementIds'] );
		if ( ! $allowed ) { throw new \RuntimeException( 'The scoped Elementor target no longer exists.' ); }
		$allowed_map = array_fill_keys( $allowed, true );
		$mode = $scope['mode'];

		foreach ( $patch['operations'] as $op ) {
			$type = $op['operation'];
			if ( in_array( $type, [ 'update-page-setting', 'remove-page-setting', 'replace-document' ], true ) ) {
				throw new \RuntimeException( 'Page/document operations are not allowed in a widget, selection or subtree patch.' );
			}
			if ( isset( $op['elementId'] ) && ! isset( $allowed_map[ $op['elementId'] ] ) ) {
				throw new \RuntimeException( 'AI patch attempts to modify an Elementor element outside the exported scope: ' . $op['elementId'] );
			}
			if ( 'widget' === $mode && in_array( $type, [ 'insert-element', 'move-element' ], true ) ) {
				throw new \RuntimeException( 'Widget-only scope cannot insert or move child elements. Export the subtree instead.' );
			}
			if ( in_array( $type, [ 'insert-element', 'move-element' ], true ) ) {
				$parent_id = (string) ( $op['parentId'] ?? '' );
				if ( '' === $parent_id || ! isset( $allowed_map[ $parent_id ] ) ) {
					throw new \RuntimeException( 'AI patch attempts to move/insert outside the exported Elementor scope.' );
				}
			}
			if ( 'insert-element' === $type ) {
				$new_ids = [];
				$this->collect_ids( [ $op['element'] ], $new_ids );
				foreach ( $new_ids as $id ) { $allowed_map[ $id ] = true; }
			}
			if ( 'replace-element' === $type && 'widget' !== $mode && empty( $op['preserveChildren'] ) ) {
				$replacement_ids = [];
				$this->collect_ids( [ $op['element'] ], $replacement_ids );
				foreach ( $replacement_ids as $id ) { $allowed_map[ $id ] = true; }
			}
		}
	}

	private function save_target( $main, $working ) {
		if ( $this->document_id( $working ) !== $this->document_id( $main ) ) { return $working; }
		$post = $main->get_post();
		if ( $post && in_array( $post->post_status, [ 'publish', 'private' ], true ) && method_exists( $main, 'get_autosave' ) ) {
			return $main->get_autosave( 0, true );
		}
		return $main;
	}

	private function should_use_autosave( $main, $working ): bool {
		if ( $this->document_id( $working ) !== $this->document_id( $main ) ) { return true; }
		$post = $main->get_post();
		return $post && in_array( $post->post_status, [ 'publish', 'private' ], true );
	}

	private function document_id( $document ): int {
		$post = is_object( $document ) && method_exists( $document, 'get_post' ) ? $document->get_post() : null;
		return $post ? (int) $post->ID : 0;
	}

	private function apply_operations( array &$elements, array &$page_settings, array $operations, string $scope_mode ): void {
		foreach ( $operations as $op ) {
			$type = $op['operation'];
			switch ( $type ) {
				case 'update-setting':
					if ( ! $this->update_setting( $elements, $op['elementId'], $op['setting'], $op['value'] ) ) { throw new \RuntimeException( 'Element not found: ' . $op['elementId'] ); }
					break;
				case 'remove-setting':
					if ( ! $this->remove_setting( $elements, $op['elementId'], $op['setting'] ) ) { throw new \RuntimeException( 'Element not found: ' . $op['elementId'] ); }
					break;
				case 'replace-settings':
					if ( ! $this->replace_settings( $elements, $op['elementId'], $op['settings'] ) ) { throw new \RuntimeException( 'Element not found: ' . $op['elementId'] ); }
					break;
				case 'replace-element':
					$preserve_children = 'widget' === $scope_mode || ! empty( $op['preserveChildren'] );
					if ( ! $preserve_children ) { $this->assert_replacement_unique_ids( $elements, $op['elementId'], $op['element'] ); }
					if ( ! $this->replace_element( $elements, $op['elementId'], $op['element'], $preserve_children ) ) { throw new \RuntimeException( 'Element not found: ' . $op['elementId'] ); }
					break;
				case 'insert-element':
					$this->assert_unique_ids( $elements, $op['element'] );
					if ( ! $this->insert_element( $elements, $op['parentId'], $op['position'], $op['element'] ) ) { throw new \RuntimeException( 'Parent element not found: ' . $op['parentId'] ); }
					break;
				case 'remove-element':
					if ( ! $this->remove_element( $elements, $op['elementId'] ) ) { throw new \RuntimeException( 'Element not found: ' . $op['elementId'] ); }
					break;
				case 'move-element':
					$this->move_element( $elements, $op['elementId'], $op['parentId'], $op['position'] );
					break;
				case 'update-page-setting':
					$page_settings[ $op['setting'] ] = $op['value'];
					break;
				case 'remove-page-setting':
					unset( $page_settings[ $op['setting'] ] );
					break;
				case 'replace-document':
					$this->assert_document_unique_ids( $op['content'] );
					$elements = $op['content'];
					$page_settings = $op['pageSettings'];
					break;
			}
		}
	}

	private function update_setting( array &$elements, string $id, string $key, $value ): bool {
		foreach ( $elements as &$element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) { $element['settings'] = []; }
				$element['settings'][ $key ] = $value;
				return true;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && $this->update_setting( $element['elements'], $id, $key, $value ) ) { return true; }
		}
		unset( $element );
		return false;
	}

	private function remove_setting( array &$elements, string $id, string $key ): bool {
		foreach ( $elements as &$element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) { unset( $element['settings'][ $key ] ); }
				return true;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && $this->remove_setting( $element['elements'], $id, $key ) ) { return true; }
		}
		unset( $element );
		return false;
	}

	private function replace_settings( array &$elements, string $id, array $settings ): bool {
		foreach ( $elements as &$element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) { $element['settings'] = $settings; return true; }
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && $this->replace_settings( $element['elements'], $id, $settings ) ) { return true; }
		}
		unset( $element );
		return false;
	}

	private function replace_element( array &$elements, string $id, array $replacement, bool $preserve_children ): bool {
		foreach ( $elements as &$element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				if ( $preserve_children ) { $replacement['elements'] = (array) ( $element['elements'] ?? [] ); }
				$element = $replacement;
				return true;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && $this->replace_element( $element['elements'], $id, $replacement, $preserve_children ) ) { return true; }
		}
		unset( $element );
		return false;
	}

	private function insert_element( array &$elements, string $parent_id, int $position, array $new_element ): bool {
		if ( '' === $parent_id ) { array_splice( $elements, min( $position, count( $elements ) ), 0, [ $new_element ] ); return true; }
		foreach ( $elements as &$element ) {
			if ( (string) ( $element['id'] ?? '' ) === $parent_id ) {
				if ( ! isset( $element['elements'] ) || ! is_array( $element['elements'] ) ) { $element['elements'] = []; }
				array_splice( $element['elements'], min( $position, count( $element['elements'] ) ), 0, [ $new_element ] );
				return true;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && $this->insert_element( $element['elements'], $parent_id, $position, $new_element ) ) { return true; }
		}
		unset( $element );
		return false;
	}

	private function remove_element( array &$elements, string $id ): bool {
		foreach ( $elements as $index => &$element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) { array_splice( $elements, $index, 1 ); return true; }
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) && $this->remove_element( $element['elements'], $id ) ) { return true; }
		}
		unset( $element );
		return false;
	}

	private function move_element( array &$elements, string $id, string $parent_id, int $position ): void {
		$moving = $this->extract_element( $elements, $id );
		if ( null === $moving ) { throw new \RuntimeException( 'Element not found: ' . $id ); }
		if ( $parent_id && $this->contains_id( $moving, $parent_id ) ) { throw new \RuntimeException( 'Cannot move an element into its own descendant.' ); }
		if ( ! $this->insert_element( $elements, $parent_id, $position, $moving ) ) { throw new \RuntimeException( 'Move target parent not found: ' . $parent_id ); }
	}

	private function extract_element( array &$elements, string $id ): ?array {
		foreach ( $elements as $index => &$element ) {
			if ( (string) ( $element['id'] ?? '' ) === $id ) { $found = $element; array_splice( $elements, $index, 1 ); return $found; }
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = $this->extract_element( $element['elements'], $id );
				if ( null !== $found ) { return $found; }
			}
		}
		unset( $element );
		return null;
	}

	private function contains_id( array $element, string $id ): bool {
		if ( (string) ( $element['id'] ?? '' ) === $id ) { return true; }
		foreach ( (array) ( $element['elements'] ?? [] ) as $child ) {
			if ( is_array( $child ) && $this->contains_id( $child, $id ) ) { return true; }
		}
		return false;
	}

	private function assert_unique_ids( array $existing, array $incoming ): void {
		$existing_ids = [];
		$this->collect_ids( $existing, $existing_ids );
		$incoming_ids = [];
		$this->collect_ids( [ $incoming ], $incoming_ids );
		if ( count( $incoming_ids ) !== count( array_unique( $incoming_ids ) ) ) { throw new \RuntimeException( 'Inserted subtree contains duplicate element IDs.' ); }
		foreach ( $incoming_ids as $id ) {
			if ( in_array( $id, $existing_ids, true ) ) { throw new \RuntimeException( 'Inserted element ID already exists: ' . $id ); }
		}
	}

	private function assert_replacement_unique_ids( array $elements, string $target_id, array $replacement ): void {
		$remaining = $elements;
		if ( ! $this->remove_element( $remaining, $target_id ) ) { throw new \RuntimeException( 'Element not found: ' . $target_id ); }
		$existing_ids = [];
		$this->collect_ids( $remaining, $existing_ids );
		$replacement_ids = [];
		$this->collect_ids( [ $replacement ], $replacement_ids );
		if ( count( $replacement_ids ) !== count( array_unique( $replacement_ids ) ) ) { throw new \RuntimeException( 'Replacement subtree contains duplicate element IDs.' ); }
		foreach ( $replacement_ids as $id ) {
			if ( in_array( $id, $existing_ids, true ) ) { throw new \RuntimeException( 'Replacement element ID already exists elsewhere in the document: ' . $id ); }
		}
	}

	private function assert_document_unique_ids( array $content ): void {
		$ids = [];
		$this->collect_ids( $content, $ids );
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) { throw new \RuntimeException( 'Replacement document contains duplicate Elementor IDs.' ); }
	}

	private function collect_ids( array $elements, array &$ids ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			if ( ! empty( $element['id'] ) ) { $ids[] = (string) $element['id']; }
			$this->collect_ids( (array) ( $element['elements'] ?? [] ), $ids );
		}
	}
}
