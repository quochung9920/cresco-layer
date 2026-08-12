<?php
namespace CrescoLayer\AI;

final class Diff {
	public static function summarize( array $operations ): array {
		$summary = [
			'total' => count( $operations ),
			'inserted' => 0,
			'removed' => 0,
			'moved' => 0,
			'updated' => 0,
			'replaced' => 0,
			'pageSettings' => 0,
			'documentReplacements' => 0,
			'items' => [],
		];
		foreach ( $operations as $op ) {
			$type = (string) ( $op['operation'] ?? '' );
			if ( 'insert-element' === $type ) { $summary['inserted']++; }
			elseif ( 'remove-element' === $type ) { $summary['removed']++; }
			elseif ( 'move-element' === $type ) { $summary['moved']++; }
			elseif ( 'replace-element' === $type ) { $summary['replaced']++; }
			elseif ( 'replace-document' === $type ) { $summary['documentReplacements']++; }
			elseif ( in_array( $type, [ 'update-page-setting', 'remove-page-setting' ], true ) ) { $summary['pageSettings']++; }
			else { $summary['updated']++; }
			$summary['items'][] = [
				'operation' => $type,
				'elementId' => (string) ( $op['elementId'] ?? '' ),
				'parentId' => (string) ( $op['parentId'] ?? '' ),
				'setting' => (string) ( $op['setting'] ?? '' ),
			];
		}
		return $summary;
	}
}
