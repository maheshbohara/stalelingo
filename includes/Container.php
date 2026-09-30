<?php
/**
 * Service container.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo;

defined( 'ABSPATH' ) || exit;

use Stalelingo\Admin\Actions;
use Stalelingo\Admin\AdminBar;
use Stalelingo\Admin\EditorPanel;
use Stalelingo\Cli\Command;
use Stalelingo\Admin\ListTable;
use Stalelingo\Admin\Metabox;
use Stalelingo\Domain\DriftEvaluator;
use Stalelingo\Domain\Hasher;
use Stalelingo\Domain\Normalizer;
use Stalelingo\Domain\SnapshotCodec;
use Stalelingo\Integrations\Acf;
use Stalelingo\Integrations\Elementor;
use Stalelingo\Notifications\Notifier;
use Stalelingo\Providers\PolylangProvider;
use Stalelingo\Providers\ProviderDetector;
use Stalelingo\Providers\TranslationProvider;
use Stalelingo\Providers\WpmlProvider;
use Stalelingo\Rest\BaselineController;
use Stalelingo\Rest\DiffController;
use Stalelingo\Rest\GroupController;
use Stalelingo\Rest\ItemPresenter;
use Stalelingo\Rest\MarkSyncedController;
use Stalelingo\Rest\StatusController;
use Stalelingo\Services\BaselineJob;
use Stalelingo\Services\DiffService;
use Stalelingo\Services\DriftService;
use Stalelingo\Services\Fingerprinter;
use Stalelingo\Services\Permissions;
use Stalelingo\Services\PostHooks;
use Stalelingo\Services\Queue;
use Stalelingo\Services\Repositories\EventRepository;
use Stalelingo\Services\Repositories\SnapshotRepository;
use Stalelingo\Services\Repositories\SyncRepository;
use Stalelingo\Services\SyncService;
use Stalelingo\Services\TrackedFields;

/**
 * Builds services lazily, once each.
 *
 * Services that need a multilingual plugin throw when none is ready; check
 * {@see Container::has_provider()} first.
 *
 * @since 1.0.0
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
	 * @since 1.0.0
	 *
	 * @param ProviderDetector $detector Provider detector.
	 */
	public function __construct( private ProviderDetector $detector ) {
	}

	/**
	 * Provider detector.
	 *
	 * @since 1.0.0
	 */
	public function detector(): ProviderDetector {
		return $this->detector;
	}

	/**
	 * Settings.
	 *
	 * @since 1.0.0
	 */
	public function settings(): Settings {
		return $this->get( 'settings', static fn() => new Settings() );
	}

	/**
	 * The active provider when it is ready to use, otherwise null.
	 *
	 * @since 1.0.0
	 */
	public function provider(): ?TranslationProvider {
		if ( ! array_key_exists( 'provider', $this->instances ) ) {
			$provider = match ( $this->detector->detect() ) {
				ProviderDetector::POLYLANG => new PolylangProvider( $this->settings() ),
				ProviderDetector::WPML     => new WpmlProvider(),
				default                    => null,
			};

			/**
			 * Filters the multilingual provider adapter.
			 *
			 * @since 1.0.0
			 *
			 * @param TranslationProvider|null $provider Adapter, or null when no supported plugin is active.
			 */
			$provider = apply_filters( 'stalelingo_provider_instance', $provider );
			if ( $provider instanceof TranslationProvider && $provider->is_ready() ) {
				$this->instances['provider'] = $provider;
			} else {
				return null;
			}
		}//end if

		$provider = $this->instances['provider'];

		return $provider instanceof TranslationProvider ? $provider : null;
	}

	/**
	 * Whether a ready provider exists.
	 *
	 * @since 1.0.0
	 */
	public function has_provider(): bool {
		return null !== $this->provider();
	}

	/**
	 * Job queue.
	 *
	 * @since 1.0.0
	 */
	public function queue(): Queue {
		return $this->get( 'queue', static fn() => new Queue() );
	}

	/**
	 * Permissions.
	 *
	 * @since 1.0.0
	 */
	public function permissions(): Permissions {
		return $this->get( 'permissions', static fn() => new Permissions() );
	}

	/**
	 * Sync rows.
	 *
	 * @since 1.0.0
	 */
	public function sync_repository(): SyncRepository {
		return $this->get( 'sync_repository', static fn() => new SyncRepository() );
	}

	/**
	 * Snapshots.
	 *
	 * @since 1.0.0
	 */
	public function snapshot_repository(): SnapshotRepository {
		return $this->get(
			'snapshot_repository',
			static function (): SnapshotRepository {
				/**
				 * Filters the maximum bytes stored per snapshot value. Longer values are truncated.
				 *
				 * @since 1.0.0
				 *
				 * @param int $max_bytes Bytes. Default 65536.
				 */
				$max_bytes = (int) apply_filters( 'stalelingo_snapshot_max_bytes', SnapshotCodec::DEFAULT_MAX_BYTES );

				return new SnapshotRepository( new SnapshotCodec( $max_bytes ) );
			}
		);
	}

	/**
	 * Events.
	 *
	 * @since 1.0.0
	 */
	public function event_repository(): EventRepository {
		return $this->get( 'event_repository', static fn() => new EventRepository() );
	}

	/**
	 * Tracked fields.
	 *
	 * @since 1.0.0
	 */
	public function tracked_fields(): TrackedFields {
		return $this->get( 'tracked_fields', fn() => new TrackedFields( $this->settings(), $this->require_provider() ) );
	}

	/**
	 * Fingerprinter.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * ACF integration.
	 *
	 * @since 1.0.0
	 */
	public function acf(): Acf {
		return $this->get( 'acf', fn() => new Acf( $this->settings(), $this->require_provider(), new Normalizer() ) );
	}

	/**
	 * Elementor integration.
	 *
	 * @since 1.0.0
	 */
	public function elementor(): Elementor {
		return $this->get( 'elementor', fn() => new Elementor( $this->settings(), new Normalizer() ) );
	}

	/**
	 * Posts list table integration.
	 *
	 * @since 1.0.0
	 */
	public function list_table(): ListTable {
		return $this->get(
			'list_table',
			fn() => new ListTable( $this->tracked_fields(), $this->require_provider(), $this->sync_repository(), $this->sync_service(), $this->permissions() )
		);
	}

	/**
	 * Classic editor metabox.
	 *
	 * @since 1.0.0
	 */
	public function metabox(): Metabox {
		return $this->get(
			'metabox',
			fn() => new Metabox( $this->tracked_fields(), $this->require_provider(), $this->sync_repository(), $this->permissions() )
		);
	}

	/**
	 * Admin-post handlers.
	 *
	 * @since 1.0.0
	 */
	public function actions(): Actions {
		return $this->get(
			'actions',
			fn() => new Actions( $this->require_provider(), $this->sync_service(), $this->baseline(), $this->permissions() )
		);
	}

	/**
	 * Admin bar counter.
	 *
	 * @since 1.0.0
	 */
	public function admin_bar(): AdminBar {
		return $this->get( 'admin_bar', fn() => new AdminBar( $this->sync_repository(), $this->permissions() ) );
	}

	/**
	 * Email digests and immediate notifications.
	 *
	 * @since 1.0.0
	 */
	public function notifier(): Notifier {
		return $this->get( 'notifier', fn() => new Notifier( $this->settings(), $this->require_provider(), $this->sync_repository() ) );
	}

	/**
	 * Personal data exporters and eraser.
	 *
	 * @since 1.0.0
	 */
	public function privacy(): Privacy {
		return $this->get( 'privacy', fn() => new Privacy( $this->sync_repository(), $this->event_repository() ) );
	}

	/**
	 * The `wp stalelingo` command.
	 *
	 * @since 1.0.0
	 */
	public function cli_command(): Command {
		return $this->get(
			'cli_command',
			fn() => new Command( $this->require_provider(), $this->tracked_fields(), $this->sync_repository(), $this->sync_service(), $this->baseline() )
		);
	}

	/**
	 * Block editor panel.
	 *
	 * @since 1.0.0
	 */
	public function editor_panel(): EditorPanel {
		return $this->get( 'editor_panel', fn() => new EditorPanel( $this->tracked_fields() ) );
	}

	/**
	 * Diff service.
	 *
	 * @since 1.0.0
	 */
	public function diff_service(): DiffService {
		return $this->get( 'diff_service', fn() => new DiffService( $this->fingerprinter(), $this->snapshot_repository() ) );
	}

	/**
	 * REST item presenter.
	 *
	 * @since 1.0.0
	 */
	public function item_presenter(): ItemPresenter {
		return $this->get( 'item_presenter', fn() => new ItemPresenter( $this->require_provider(), $this->permissions() ) );
	}

	/**
	 * REST controllers of the `stalelingo/v1` namespace.
	 *
	 * @since 1.0.0
	 *
	 * @return list<\Stalelingo\Rest\Controller>
	 */
	public function rest_controllers(): array {
		$permissions = $this->permissions();
		$presenter   = $this->item_presenter();

		return array(
			new StatusController( $permissions, $this->require_provider(), $this->tracked_fields(), $this->settings(), $this->sync_repository(), $this->baseline(), $presenter ),
			new GroupController( $permissions, $this->require_provider(), $this->tracked_fields(), $this->sync_repository(), $presenter ),
			new DiffController( $permissions, $this->sync_repository(), $this->diff_service(), $presenter ),
			new MarkSyncedController( $permissions, $this->sync_service(), $this->sync_repository(), $presenter ),
			new BaselineController( $permissions, $this->baseline() ),
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
			throw new \LogicException( 'Stalelingo: no multilingual plugin is ready.' );
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
