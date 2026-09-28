<?php
/**
 * Service container.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Domain\DriftEvaluator;
use TranslationDrift\Domain\Hasher;
use TranslationDrift\Domain\Normalizer;
use TranslationDrift\Domain\SnapshotCodec;
use TranslationDrift\Providers\PolylangProvider;
use TranslationDrift\Providers\ProviderDetector;
use TranslationDrift\Providers\TranslationProvider;
use TranslationDrift\Services\BaselineJob;
use TranslationDrift\Services\DriftService;
use TranslationDrift\Services\Fingerprinter;
use TranslationDrift\Services\Permissions;
use TranslationDrift\Services\PostHooks;
use TranslationDrift\Services\Queue;
use TranslationDrift\Services\Repositories\EventRepository;
use TranslationDrift\Services\Repositories\SnapshotRepository;
use TranslationDrift\Services\Repositories\SyncRepository;
use TranslationDrift\Services\SyncService;
use TranslationDrift\Services\TrackedFields;

/**
 * Builds services lazily, once each.
 *
 * Services that need a multilingual plugin throw when none is ready; check
 * {@see Container::has_provider()} first.
 *
 * @since 0.1.0
 */
final class Container {

	/**
	 * Built services, keyed by name.
	 *
	 * @var array<string, object>
	 */
	private array $instances = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param ProviderDetector $detector Provider detector.
	 */
	public function __construct( private ProviderDetector $detector ) {
	}

	/**
	 * Provider detector.
	 *
	 * @since 0.1.0
	 */
	public function detector(): ProviderDetector {
		return $this->detector;
	}

	/**
	 * Settings.
	 *
	 * @since 0.1.0
	 */
	public function settings(): Settings {
		return $this->get( 'settings', static fn() => new Settings() );
	}

	/**
	 * The active provider when it is ready to use, otherwise null.
	 *
	 * @since 0.1.0
	 */
	public function provider(): ?TranslationProvider {
		if ( ! array_key_exists( 'provider', $this->instances ) ) {
			$provider = match ( $this->detector->detect() ) {
				ProviderDetector::POLYLANG => new PolylangProvider( $this->settings() ),
				default                    => null,
			};

			/**
			 * Filters the multilingual provider adapter.
			 *
			 * @since 0.1.0
			 *
			 * @param TranslationProvider|null $provider Adapter, or null when no supported plugin is active.
			 */
			$provider = apply_filters( 'tdrift_provider_instance', $provider );
			if ( $provider instanceof TranslationProvider && $provider->is_ready() ) {
				$this->instances['provider'] = $provider;
			} else {
				return null;
			}
		}

		$provider = $this->instances['provider'];

		return $provider instanceof TranslationProvider ? $provider : null;
	}

	/**
	 * Whether a ready provider exists.
	 *
	 * @since 0.1.0
	 */
	public function has_provider(): bool {
		return null !== $this->provider();
	}

	/**
	 * Job queue.
	 *
	 * @since 0.1.0
	 */
	public function queue(): Queue {
		return $this->get( 'queue', static fn() => new Queue() );
	}

	/**
	 * Permissions.
	 *
	 * @since 0.1.0
	 */
	public function permissions(): Permissions {
		return $this->get( 'permissions', static fn() => new Permissions() );
	}

	/**
	 * Sync rows.
	 *
	 * @since 0.1.0
	 */
	public function sync_repository(): SyncRepository {
		return $this->get( 'sync_repository', static fn() => new SyncRepository() );
	}

	/**
	 * Snapshots.
	 *
	 * @since 0.1.0
	 */
	public function snapshot_repository(): SnapshotRepository {
		return $this->get(
			'snapshot_repository',
			static function (): SnapshotRepository {
				/**
				 * Filters the maximum bytes stored per snapshot value. Longer values are truncated.
				 *
				 * @since 0.1.0
				 *
				 * @param int $max_bytes Bytes. Default 65536.
				 */
				$max_bytes = (int) apply_filters( 'tdrift_snapshot_max_bytes', SnapshotCodec::DEFAULT_MAX_BYTES );

				return new SnapshotRepository( new SnapshotCodec( $max_bytes ) );
			}
		);
	}

	/**
	 * Events.
	 *
	 * @since 0.1.0
	 */
	public function event_repository(): EventRepository {
		return $this->get( 'event_repository', static fn() => new EventRepository() );
	}

	/**
	 * Tracked fields.
	 *
	 * @since 0.1.0
	 */
	public function tracked_fields(): TrackedFields {
		return $this->get( 'tracked_fields', fn() => new TrackedFields( $this->settings(), $this->require_provider() ) );
	}

	/**
	 * Fingerprinter.
	 *
	 * @since 0.1.0
	 */
	public function fingerprinter(): Fingerprinter {
		return $this->get(
			'fingerprinter',
			fn() => new Fingerprinter( $this->tracked_fields(), new Normalizer(), new Hasher(), $this->settings() )
		);
	}

	/**
	 * Sync service.
	 *
	 * @since 0.1.0
	 */
	public function sync_service(): SyncService {
		return $this->get(
			'sync_service',
			fn() => new SyncService(
				$this->require_provider(),
				$this->fingerprinter(),
				$this->sync_repository(),
				$this->snapshot_repository(),
				$this->event_repository()
			)
		);
	}

	/**
	 * Drift service.
	 *
	 * @since 0.1.0
	 */
	public function drift_service(): DriftService {
		return $this->get(
			'drift_service',
			fn() => new DriftService(
				$this->require_provider(),
				$this->tracked_fields(),
				$this->fingerprinter(),
				new DriftEvaluator(),
				$this->sync_repository(),
				$this->snapshot_repository(),
				$this->event_repository()
			)
		);
	}

	/**
	 * Baseline and recalculation jobs.
	 *
	 * @since 0.1.0
	 */
	public function baseline(): BaselineJob {
		return $this->get(
			'baseline',
			fn() => new BaselineJob(
				$this->require_provider(),
				$this->tracked_fields(),
				$this->sync_service(),
				$this->drift_service(),
				$this->sync_repository(),
				$this->queue()
			)
		);
	}

	/**
	 * Post hooks.
	 *
	 * @since 0.1.0
	 */
	public function post_hooks(): PostHooks {
		return $this->get(
			'post_hooks',
			fn() => new PostHooks(
				$this->require_provider(),
				$this->tracked_fields(),
				$this->settings(),
				$this->sync_service(),
				$this->drift_service(),
				$this->baseline(),
				$this->sync_repository(),
				$this->snapshot_repository(),
				$this->event_repository(),
				$this->queue()
			)
		);
	}

	/**
	 * Returns the ready provider or throws.
	 *
	 * @throws \LogicException When no provider is ready.
	 */
	private function require_provider(): TranslationProvider {
		$provider = $this->provider();
		if ( null === $provider ) {
			throw new \LogicException( 'Translation Drift: no multilingual plugin is ready.' );
		}

		return $provider;
	}

	/**
	 * Returns a service, building it on first use.
	 *
	 * @template T of object
	 *
	 * @param string        $name    Service name.
	 * @param callable(): T $factory Factory.
	 * @return T
	 */
	private function get( string $name, callable $factory ): object {
		if ( ! isset( $this->instances[ $name ] ) ) {
			$this->instances[ $name ] = $factory();
		}

		/**
		 * The service the factory built for this name.
		 *
		 * @var T $service
		 */
		$service = $this->instances[ $name ];

		return $service;
	}
}
