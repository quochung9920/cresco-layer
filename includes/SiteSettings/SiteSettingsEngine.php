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
use CrescoLayer\SiteSettings\Verify\ValueNormalizer;
use CrescoLayer\SiteSettings\Verify\Verifier;

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
	private Verifier $verifier;
	private ValueNormalizer $normalizer;

	public function __construct(
		private KitGateway $gateway,
		?DiffEngine $diff = null,
		?OwnershipRegistry $registry = null,
		?CacheInvalidator $cache = null
	) {
		$this->diff = $diff ?? new DiffEngine();
		$this->registry = $registry ?? new OwnershipRegistry();
		$this->cache = $cache ?? new ElementorCache();
		$this->normalizer = new ValueNormalizer();
		$this->verifier = new Verifier( $this->normalizer );
	}

	private function adapter(): ElementorClassicKitAdapter {
		return new ElementorClassicKitAdapter(
			$this->gateway,
			$this->registry,
			new ValueFactory( new ClampValidator() ),
			new ManagedCssBlock()
		);
	}

	/** Lightweight environment report for the admin console; reads nothing beyond the Kit. */
	public function health(): array {
		$available = $this->gateway->is_available();
		$adapter = $available ? $this->adapter() : null;
		$supports = $adapter && $adapter->supports();
		return [
			'engineLoaded' => true,
			'elementorLoaded' => class_exists( \Elementor\Plugin::class ),
			'kitResolved' => $available,
			'kitId' => $this->gateway->kit_id(),
			'adapterResolved' => $supports,
			'adapter' => $supports ? $adapter->id() : null,
			'profileLoaded' => ProfessionalCommerceProfile::ID,
			'mode' => Spec::MODE_MERGE,
			'modes' => Spec::MODES,
			'capabilitiesDiscovered' => $supports,
			'capabilities' => $supports ? $adapter->capabilities()->summary() : [],
			'registryValid' => $this->registry->kit_id() === $this->gateway->kit_id() || 0 === $this->registry->kit_id(),
			'errors' => $this->gateway->errors(),
		];
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

		$adapter = $this->adapter();

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
		$plan = $built['plan'];
		$skipped = $built['skipped'];
		$preserved = $built['preserved'];
		$log->add_many( 'skipped', $skipped );
		$log->add_many( 'preserved', $preserved );
		$log->add_many( 'notes', $built['notes'] );

		$comparison = $this->diff->compare( $current, $desired );
		foreach ( $comparison['created'] as $key ) { $log->add( 'created', $key ); }
		foreach ( $comparison['updated'] as $key ) { $log->add( 'updated', $key ); }
		foreach ( $comparison['unchanged'] as $key ) { $log->add( 'unchanged', $key ); }

		$summary = [
			'created' => count( $comparison['created'] ),
			'updated' => count( $comparison['updated'] ),
			'unchanged' => count( $comparison['unchanged'] ),
			'skipped' => count( $skipped ),
			'preserved' => count( $preserved ),
		];

		$base = [
			'schema' => Spec::SCHEMA,
			'adapter' => $adapter->id(),
			'profile' => (string) ( $spec['profile'] ?? '' ),
			'mode' => (string) ( $spec['mode'] ?? Spec::MODE_MERGE ),
			'kitId' => $kit_id,
			'summary' => $summary,
			'skipped' => $skipped,
			'preserved' => $preserved,
			'capabilities' => $adapter->capabilities()->summary(),
			'warnings' => [],
			'errors' => [],
		];

		if ( ! $comparison['changed'] ) {
			// Nothing to write, so nothing to invalidate either.
			return array_merge( $base, [
				'success' => true,
				'status' => 'no_op',
				'verification' => null,
				'rollback' => null,
				'cacheCleared' => false,
				'log' => $log->render( 'no_op' ),
			] );
		}

		if ( ! $write ) {
			return array_merge( $base, [
				'success' => true,
				'status' => 'preview',
				'created' => $comparison['created'],
				'updated' => $comparison['updated'],
				'unchanged' => $comparison['unchanged'],
				'verification' => null,
				'rollback' => null,
				'cacheCleared' => false,
				'log' => $log->render( 'preview' ),
			] );
		}

		if ( ! $this->gateway->save( $comparison['merged'] ) ) {
			return $this->failure( 'Elementor rejected the Kit save.', $this->gateway->errors(), $log );
		}

		$verification = $this->verify_plan( $plan );
		if ( 'pass' !== $verification['status'] ) {
			$rollback = $this->rollback( $snapshot, $plan );
			return array_merge( $base, [
				'success' => false,
				'status' => 'verification_failed',
				'verification' => $verification,
				'rollback' => $rollback,
				'cacheCleared' => false,
				'errors' => [ sprintf( '%d of %d verified settings did not match the requested values.', $verification['mismatchCount'], $verification['scopeCount'] ) ],
				'log' => $log->render( 'verification_failed' ) . "\n\n" . $this->verifier->render( $verification, $skipped, $preserved, $rollback ),
			] );
		}

		// One invalidation for the whole transaction, after the write is known to be good.
		$cleared = $this->cache->clear();
		$this->persist_ownership( $desired );
		$this->registry->record_hash( $this->diff->hash( $desired ) );

		return array_merge( $base, [
			'success' => true,
			'status' => 'updated',
			'created' => $comparison['created'],
			'updated' => $comparison['updated'],
			'verification' => $verification,
			'rollback' => null,
			'cacheCleared' => $cleared,
			'cacheClears' => $this->cache->clears(),
			'log' => $log->render( 'success' ) . "\n\n" . $this->verifier->render( $verification, $skipped, $preserved, null ),
		] );
	}

	/**
	 * Verify the current Kit against the profile without writing anything.
	 *
	 * Separate from apply so a reviewer can ask "does the live Kit still match the standard" at any
	 * time — for example after someone edited Site Settings by hand.
	 */
	public function verify_only( ?array $spec = null ): array {
		$spec = $spec ?? $this->profile_spec();
		$invalid = $this->validate_spec( $spec );
		if ( null !== $invalid ) { return $this->failure( $invalid, [], new Logger() ); }
		if ( ! $this->gateway->is_available() ) {
			return $this->failure( 'Elementor has no readable active Kit.', $this->gateway->errors(), new Logger() );
		}

		$adapter = $this->adapter();
		if ( ! $adapter->supports() ) {
			return $this->failure( 'The active Kit is not a Classic Elementor Kit.', $this->gateway->errors(), new Logger() );
		}

		$built = $adapter->build( $spec );
		$verification = $this->verifier->verify( $built['plan'], $this->gateway->settings() );

		return [
			'success' => 'pass' === $verification['status'],
			'status' => 'pass' === $verification['status'] ? 'verified' : 'verification_failed',
			'schema' => Spec::SCHEMA,
			'adapter' => $adapter->id(),
			'profile' => (string) ( $spec['profile'] ?? '' ),
			'kitId' => $this->gateway->kit_id(),
			'verification' => $verification,
			'skipped' => $built['skipped'],
			'preserved' => $built['preserved'],
			'capabilities' => $adapter->capabilities()->summary(),
			'rollback' => null,
			'cacheCleared' => false,
			'warnings' => [],
			'errors' => [],
			'log' => $this->verifier->render( $verification, $built['skipped'], $built['preserved'], null ),
		];
	}

	/** Restore the pre-apply snapshot and confirm the restore actually took. */
	private function rollback( array $snapshot, array $plan ): array {
		$saved = $this->gateway->save( $snapshot );
		if ( ! $saved ) {
			return [ 'attempted' => true, 'success' => false, 'status' => 'failed', 'verified' => false ];
		}
		$this->gateway->refresh();
		$restored = $this->gateway->settings();
		$still_applied = 0;
		foreach ( $plan as $entry ) {
			$control = (string) ( $entry['control'] ?? '' );
			if ( '' === $control || ! array_key_exists( $control, $snapshot ) ) { continue; }
			if ( ! $this->normalizer->satisfies( $restored[ $control ] ?? null, $snapshot[ $control ], (string) ( $entry['controlType'] ?? '' ) ) ) {
				$still_applied++;
			}
		}
		return [ 'attempted' => true, 'success' => true, 'status' => 'success', 'verified' => 0 === $still_applied ];
	}

	/** Read the Kit back and check every planned write, in profile terms. */
	private function verify_plan( array $plan ): array {
		$this->gateway->refresh();
		return $this->verifier->verify( $plan, $this->gateway->settings() );
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
