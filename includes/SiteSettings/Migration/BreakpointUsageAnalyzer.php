<?php
namespace CrescoLayer\SiteSettings\Migration;

/** Pure analyzer for responsive suffix usage inside Elementor document data. */
final class BreakpointUsageAnalyzer {
	private int $sampleLimit;

	public function __construct( int $sample_limit = 20 ) {
		$this->sampleLimit = max( 1, $sample_limit );
	}

	/**
	 * @param array<int,array{id:int,title?:string,type?:string,elements?:array,pageSettings?:array}> $documents
	 * @param string[] $devices
	 */
	public function analyze( array $documents, array $devices, bool $truncated = false ): array {
		$devices = array_values( array_unique( array_filter( array_map( 'strval', $devices ) ) ) );
		$usage = [];
		foreach ( $devices as $device ) {
			$usage[ $device ] = [ 'settingCount' => 0, 'documentCount' => 0, 'documents' => [], 'samples' => [] ];
		}

		foreach ( $documents as $document ) {
			if ( ! is_array( $document ) ) { continue; }
			$post_id = (int) ( $document['id'] ?? 0 );
			$title = (string) ( $document['title'] ?? '' );
			$type = (string) ( $document['type'] ?? '' );
			$seen = [];
			$this->scan_elements( (array) ( $document['elements'] ?? [] ), $devices, $post_id, $title, $type, $usage, $seen );
			$this->scan_settings( (array) ( $document['pageSettings'] ?? [] ), $devices, $post_id, $title, $type, '', 'page', $usage, $seen );
			foreach ( array_keys( $seen ) as $device ) {
				$usage[ $device ]['documentCount']++;
				$usage[ $device ]['documents'][] = $post_id;
			}
		}

		$total = 0;
		foreach ( $usage as &$item ) {
			$item['documents'] = array_values( array_unique( array_map( 'intval', $item['documents'] ) ) );
			$total += (int) $item['settingCount'];
		}
		unset( $item );

		return [
			'scannedDocuments' => count( $documents ),
			'truncated' => $truncated,
			'totalSettingCount' => $total,
			'hasUsage' => $total > 0,
			'usage' => $usage,
		];
	}

	private function scan_elements( array $elements, array $devices, int $post_id, string $title, string $type, array &$usage, array &$seen ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) { continue; }
			$element_id = (string) ( $element['id'] ?? '' );
			$this->scan_settings( (array) ( $element['settings'] ?? [] ), $devices, $post_id, $title, $type, $element_id, 'element', $usage, $seen );
			$this->scan_elements( (array) ( $element['elements'] ?? [] ), $devices, $post_id, $title, $type, $usage, $seen );
		}
	}

	private function scan_settings(
		array $settings,
		array $devices,
		int $post_id,
		string $title,
		string $type,
		string $element_id,
		string $scope,
		array &$usage,
		array &$seen
	): void {
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			foreach ( $devices as $device ) {
				if ( ! str_ends_with( $key, '_' . $device ) || ! $this->is_meaningful( $value ) ) { continue; }
				$usage[ $device ]['settingCount']++;
				$seen[ $device ] = true;
				if ( count( $usage[ $device ]['samples'] ) < $this->sampleLimit ) {
					$usage[ $device ]['samples'][] = [
						'postId' => $post_id,
						'title' => $title,
						'postType' => $type,
						'elementId' => $element_id,
						'scope' => $scope,
						'setting' => $key,
					];
				}
			}
		}
	}

	private function is_meaningful( $value ): bool {
		if ( null === $value || '' === $value ) { return false; }
		if ( ! is_array( $value ) ) { return true; }
		if ( [] === $value ) { return false; }

		// Elementor unit/dimension shapes can persist metadata even when the actual value is empty.
		// `unit=px` and `isLinked=false` alone are not responsive overrides; numeric zero is.
		foreach ( $value as $key => $child ) {
			if ( in_array( (string) $key, [ 'unit', 'isLinked' ], true ) ) { continue; }
			if ( $this->is_meaningful( $child ) ) { return true; }
		}
		return false;
	}
}
