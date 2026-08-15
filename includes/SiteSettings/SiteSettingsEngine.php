<?php
namespace CrescoLayer\SiteSettings;

use CrescoLayer\SiteSettings\Adapter\ElementorClassicKitAdapter;
use CrescoLayer\SiteSettings\Cache\CacheInvalidator;
use CrescoLayer\SiteSettings\Cache\ElementorCache;
use CrescoLayer\SiteSettings\Contract\Spec;
use CrescoLayer\SiteSettings\Diff\DiffEngine;
use CrescoLayer\SiteSettings\Gateway\KitGateway;
use CrescoLayer\SiteSettings\Profiles\ProfessionalCommerceProfile;
use CrescoLayer\SiteSettings\Registry\OwnershipRegistry;
use CrescoLayer\SiteSettings\Support\ClampValidator;
use CrescoLayer\SiteSettings\Support\Logger;
use CrescoLayer\SiteSettings\Support\ManagedCssBlock;
use CrescoLayer\SiteSettings\Support\ValueFactory;

/**
 * Runs one Site Settings transaction end to end.
 *
 *   resolve kit → discover capabilities → read → snapshot → build → diff
 *     → (no change? stop) → write → verify → clear cache → commit
 *
 * Two properties drive the shape of this class. The write is conditional, so an unchanged re-run
 * costs a read and nothing else. And the write is verified by reading the Kit back rather than
 * trusting save() — Elementor can accept a save and still normalise a value into something other
 * than what was asked for, which would leave Cresco reporting success over a Kit that does not
 * match the spec.
 */
final class SiteSettingsEngine {
	public const VERSION = '1';

	private DiffEngine $diff;
	private OwnershipRegistry $registry;
	private CacheInvalidator $cache;

	public function __construct(
		private KitGateway $gateway,
		?DiffEngine $diff = null,
		?OwnershipRegistry $registry = null,
		?CacheInvalidator $cache = null
	) {
		$this->diff = $diff ?? new DiffEngine();
		$this->registry = $registry ?? new OwnershipRegistry();
		$this->cache = $cache ?? new ElementorCache();
	}

	/** The built-in profile spec, for callers that want to inspect before applying. */
	public function profile_spec(): array {
		return ( new ProfessionalCommerceProfile() )->spec();
	}

	/** Build the desired settings and diff them without writing anything. */
	public function preview( ?array $spec = null ): array {
		return $this->run( $spec, false );
	}

	public function apply( ?array $spec = null ): array {
		return $this->run( $spec, true );
	}

	private function run( ?array $spec, bool $write ): array {
		$log = new Logger();
		$spec = $spec ?? $this->profile_spec();

		$invalid = $this->validate_spec( $spec );
		if ( null !== $invalid ) { return $this->failure( $invalid, [], $log ); }

		if ( ! $this->gateway->is_available() ) {
			return $this->failure( 'Elementor has no writable active Kit.', $this->gateway->errors(), $log );
		}

		$kit_id = $this->gateway->kit_id();
		$this->registry->bind_kit( $kit_id );

		$factory = new ValueFactory( new ClampValidator() );
		$css = new ManagedCssBlock();
		$adapter = new ElementorClassicKitAdapter( $this->gateway, $this->registry, $factory, $css );

		if ( ! $adapter->supports() ) {
			return $this->failure( 'The active Kit is not a Classic Elementor Kit; no adapter can drive it.', $this->gateway->errors(), $log );
		}

		$log->context( [
			'kit' => $kit_id,
			'adapter' => $adapter->id(),
			'profile' => (string) ( $spec['profile'] ?? '' ),
			'mode' => (string) ( $spec['mode'] ?? Spec::MODE_MERGE ),
		] );

		$current = $this->gateway->settings();
		$snapshot = $current;  // Captured before any mutation so a failed verify can be rolled back.

		$built = $adapter->build( $spec );
		$desired = $built['settings'];
		$log->add_many( 'skipped', $built['skipped'] );
		$log->add_many( 'notes', $built['notes'] );

		$comparison = $this->diff->compare( $current, $desired );
		foreach ( $comparison['created'] as $key ) { $log->add( 'created', $key ); }
		foreach ( $comparison['updated'] as $key ) { $log->add( 'updated', $key ); }
		foreach ( $comparison['unchanged'] as $key ) { $log->add( 'unchanged', $key ); }

		$summary = [
			'created' => count( $comparison['created'] ),
			'updated' => count( $comparison['updated'] ),
			'unchanged' => count( $comparison['unchanged'] ),
			'skipped' => count( $built['skipped'] ),
		];

		if ( ! $comparison['changed'] ) {
			// Nothing to write, so nothing to invalidate either.
			return [
				'success' => true,
				'status' => 'no_op',
				'schema' => Spec::SCHEMA,
				'adapter' => $adapter->id(),
				'kitId' => $kit_id,
				'summary' => $summary,
				'skipped' => $built['skipped'],
				'capabilities' => $adapter->capabilities()->summary(),
				'cacheCleared' => false,
				'warnings' => [],
				'errors' => [],
				'log' => $log->render( 'no_op' ),
			];
		}

		if ( ! $write ) {
			return [
				'success' => true,
				'status' => 'preview',
				'schema' => Spec::SCHEMA,
				'adapter' => $adapter->id(),
				'kitId' => $kit_id,
				'summary' => $summary,
				'created' => $comparison['created'],
				'updated' => $comparison['updated'],
				'skipped' => $built['skipped'],
				'capabilities' => $adapter->capabilities()->summary(),
				'cacheCleared' => false,
				'warnings' => [],
				'errors' => [],
				'log' => $log->render( 'preview' ),
			];
		}

		if ( ! $this->gateway->save( $comparison['merged'] ) ) {
			return $this->failure( 'Elementor rejected the Kit save.', $this->gateway->errors(), $log );
		}

		$verification = $this->verify( $desired );
		if ( ! $verification['verified'] ) {
			$rolled_back = $this->gateway->save( $snapshot );
			return [
				'success' => false,
				'status' => 'verification_failed',
				'schema' => Spec::SCHEMA,
				'adapter' => $adapter->id(),
				'kitId' => $kit_id,
				'summary' => $summary,
				'rolledBack' => $rolled_back,
				'mismatched' => $verification['mismatched'],
				'skipped' => $built['skipped'],
				'cacheCleared' => false,
				'warnings' => [],
				'errors' => [ 'Saved Kit settings did not match the requested values.' ],
				'log' => $log->render( 'verification_failed' ),
			];
		}

		// One invalidation for the whole transaction, after the write is known to be good.
		$cleared = $this->cache->clear();
		$this->persist_ownership( $desired );
		$this->registry->record_hash( $this->diff->hash( $desired ) );

		return [
			'success' => true,
			'status' => 'updated',
			'schema' => Spec::SCHEMA,
			'adapter' => $adapter->id(),
			'kitId' => $kit_id,
			'summary' => $summary,
			'created' => $comparison['created'],
			'updated' => $comparison['updated'],
			'skipped' => $built['skipped'],
			'capabilities' => $adapter->capabilities()->summary(),
			'cacheCleared' => $cleared,
			'cacheClears' => $this->cache->clears(),
			'warnings' => [],
			'errors' => [],
			'log' => $log->render( 'success' ),
		];
	}

	/** Read the Kit back and confirm every managed key really holds what was requested. */
	private function verify( array $desired ): array {
		$this->gateway->refresh();
		$saved = $this->gateway->settings();
		$mismatched = [];
		foreach ( $desired as $key => $value ) {
			if ( ! array_key_exists( $key, $saved ) || ! $this->diff->equivalent( $saved[ $key ], $value ) ) {
				$mismatched[] = $key;
			}
		}
		return [ 'verified' => [] === $mismatched, 'mismatched' => $mismatched ];
	}

	/**
	 * Record the Elementor IDs Cresco now owns, so the next run updates these rows instead of
	 * appending new ones. This is what makes repeated syncs idempotent.
	 */
	private function persist_ownership( array $desired ): void {
		$map = [ 'custom_colors' => 'colors', 'custom_typography' => 'typography' ];
		foreach ( $map as $control => $bucket ) {
			if ( ! isset( $desired[ $control ] ) || ! is_array( $desired[ $control ] ) ) { continue; }
			$titles = [];
			foreach ( $desired[ $control ] as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$id = (string) ( $row['_id'] ?? '' );
				$title = (string) ( $row['title'] ?? '' );
				if ( '' !== $id && '' !== $title ) { $titles[ $title ] = $id; }
			}
			foreach ( $this->semantic_keys( $bucket ) as $key => $title ) {
				if ( isset( $titles[ $title ] ) ) { $this->registry->remember( $bucket, $key, $titles[ $title ] ); }
			}
		}
	}

	/** @return array<string,string> semantic key => display title, from the active profile. */
	private function semantic_keys( string $bucket ): array {
		$spec = $this->profile_spec();
		$source = 'colors' === $bucket ? ( $spec['designSystem']['colors']['custom'] ?? [] ) : ( $spec['designSystem']['typography']['custom'] ?? [] );
		$out = [];
		foreach ( (array) $source as $key => $definition ) {
			$out[ (string) $key ] = (string) ( $definition['title'] ?? ucfirst( (string) $key ) );
		}
		return $out;
	}

	private function validate_spec( array $spec ): ?string {
		if ( ( $spec['schema'] ?? '' ) !== Spec::SCHEMA ) { return 'Spec schema must be ' . Spec::SCHEMA . '.'; }
		if ( ( $spec['target'] ?? '' ) !== Spec::TARGET ) { return 'Spec target must be ' . Spec::TARGET . '.'; }
		$mode = (string) ( $spec['mode'] ?? Spec::MODE_MERGE );
		if ( ! in_array( $mode, Spec::MODES, true ) ) { return 'Unknown sync mode: ' . $mode; }
		foreach ( array_keys( $spec ) as $key ) {
			if ( in_array( $key, [ 'schema', 'target', 'profile', 'mode' ], true ) ) { continue; }
			if ( ! in_array( $key, Spec::SECTIONS, true ) ) { return 'Unknown spec section: ' . $key; }
		}
		return null;
	}

	private function failure( string $message, array $errors, Logger $log ): array {
		return [
			'success' => false,
			'status' => 'failed',
			'schema' => Spec::SCHEMA,
			'summary' => [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0 ],
			'cacheCleared' => false,
			'warnings' => [],
			'errors' => array_merge( [ $message ], array_map( static fn( $error ): string => (string) ( $error['message'] ?? '' ), $errors ) ),
			'log' => $log->render( 'failed' ),
		];
	}
}
