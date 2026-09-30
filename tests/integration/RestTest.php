<?php
/**
 * REST API `stalelingo/v1`.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Tests\Integration;

use Stalelingo\Domain\Status;
use Stalelingo\Rest\MarkSyncedController;
use Stalelingo\Services\BaselineJob;

/**
 * @covers \Stalelingo\Rest\Controller
 * @covers \Stalelingo\Rest\StatusController
 * @covers \Stalelingo\Rest\GroupController
 * @covers \Stalelingo\Rest\DiffController
 * @covers \Stalelingo\Rest\MarkSyncedController
 * @covers \Stalelingo\Rest\BaselineController
 * @covers \Stalelingo\Rest\ItemPresenter
 * @covers \Stalelingo\Services\DiffService
 * @covers \Stalelingo\Services\Repositories\SyncRepository
 */
final class RestTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// A fresh server per test fires rest_api_init again with the current settings.
		$GLOBALS['wp_rest_server'] = null;
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null;
		remove_all_filters( 'wp_revisions_to_keep' );
		parent::tear_down();
	}

	/**
	 * Dispatches a request.
	 *
	 * @param array<string, mixed> $params Query or body parameters.
	 */
	private function request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/stalelingo/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function as_role( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );

		return $id;
	}

	/**
	 * A synced EN/FR/ES group whose source title then changed, so FR and ES are outdated.
	 *
	 * @param array<string, mixed> $args Post arguments.
	 * @return array<string, int>
	 */
	private function outdated_group( array $args = array() ): array {
		$group = $this->create_synced_group( array( 'en', 'fr', 'es' ), $args );
		wp_update_post(
			array(
				'ID'         => $group['en'],
				'post_title' => 'Changed title',
			)
		);
		$this->run_jobs();

		return $group;
	}

	/**
	 * Item IDs of a collection response.
	 *
	 * @return list<int>
	 */
	private static function ids( \WP_REST_Response $response ): array {
		return array_map( static fn( array $item ): int => $item['id'], (array) $response->get_data() );
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes( 'stalelingo/v1' );

		foreach ( array( '/stalelingo/v1/status', '/stalelingo/v1/status/summary', '/stalelingo/v1/group/(?P<id>\d+)', '/stalelingo/v1/diff/(?P<translation_id>\d+)', '/stalelingo/v1/mark-synced', '/stalelingo/v1/baseline' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
		}
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function protected_routes(): array {
		return array(
			'status'        => array( 'GET', '/status' ),
			'summary'       => array( 'GET', '/status/summary' ),
			'group'         => array( 'GET', '/group/{fr}' ),
			'diff'          => array( 'GET', '/diff/{fr}' ),
			'mark-synced'   => array( 'POST', '/mark-synced' ),
			'baseline'      => array( 'GET', '/baseline' ),
			'baseline post' => array( 'POST', '/baseline' ),
		);
	}

	/**
	 * @dataProvider protected_routes
	 */
	public function test_logged_out_requests_get_401( string $method, string $route ): void {
		$group = $this->create_synced_group();
		wp_set_current_user( 0 );

		$response = $this->request( $method, str_replace( '{fr}', (string) $group['fr'], $route ), 'POST' === $method && '/mark-synced' === $route ? array( 'ids' => array( $group['fr'] ) ) : array() );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_dashboard_routes_need_the_capability(): void {
		$this->as_role( 'author' );

		foreach ( array( array( 'GET', '/status' ), array( 'GET', '/status/summary' ), array( 'GET', '/baseline' ), array( 'POST', '/baseline' ) ) as [ $method, $route ] ) {
			$this->assertSame( 403, $this->request( $method, $route )->get_status(), "{$method} {$route}" );
		}
	}

	public function test_translator_reads_and_marks_only_translations_they_can_edit(): void {
		$translator = self::factory()->user->create( array( 'role' => 'author' ) );
		$group      = $this->outdated_group();
		wp_update_post(
			array(
				'ID'          => $group['fr'],
				'post_author' => $translator,
			)
		);
		wp_set_current_user( $translator );

		$this->assertSame( 200, $this->request( 'GET', '/group/' . $group['fr'] )->get_status() );
		$this->assertSame( 200, $this->request( 'GET', '/diff/' . $group['fr'] )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/diff/' . $group['es'] )->get_status(), 'Not their translation.' );
		$this->assertSame( 403, $this->request( 'POST', '/mark-synced', array( 'ids' => array( $group['fr'], $group['es'] ) ) )->get_status() );

		$response = $this->request( 'POST', '/mark-synced', array( 'ids' => array( $group['fr'] ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $group['fr'] ), $response->get_data()['updated'] );
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'es' ) );
	}

	public function test_missing_post_gets_404(): void {
		$this->as_role( 'administrator' );

		$this->assertSame( 404, $this->request( 'GET', '/group/999999' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/diff/999999' )->get_status() );
	}

	public function test_status_lists_sources_with_each_translation(): void {
		$this->as_role( 'administrator' );
		$group = $this->outdated_group();

		$response = $this->request( 'GET', '/status' );
		$this->assertSame( 200, $response->get_status() );

		$items = (array) $response->get_data();
		$this->assertCount( 1, $items );
		$item = $items[0];
		$this->assertSame( $group['en'], $item['id'] );
		$this->assertSame( 'Changed title', $item['title'] );
		$this->assertSame( 'en', $item['source_lang'] );
		$this->assertNotNull( $item['edit_url'] );

		$translations = (array) $item['translations'];
		$this->assertSame( array( 'fr', 'es' ), array_keys( $translations ), 'In the site language order.' );
		$this->assertSame( 'outdated', $translations['fr']['status'] );
		$this->assertSame( $group['fr'], $translations['fr']['translation_id'] );
		$this->assertSame( array( 'title' ), array_column( $translations['fr']['changed_fields'], 'key' ) );
		$this->assertTrue( $translations['fr']['can_mark'] );
		$this->assertStringContainsString( 'post=' . $group['fr'], (string) $translations['fr']['edit_url'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $translations['fr']['synced_at'] );
	}

	public function test_status_paginates_with_total_headers(): void {
		$this->as_role( 'administrator' );
		$sources = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$sources[] = $this->create_synced_group( array( 'en', 'fr' ), array( 'post_title' => "Post {$i}" ) )['en'];
		}

		$first = $this->request(
			'GET',
			'/status',
			array(
				'per_page' => 2,
				'orderby'  => 'title',
				'order'    => 'asc',
			)
		);
		$this->assertSame( '3', (string) $first->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', (string) $first->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( array( $sources[0], $sources[1] ), self::ids( $first ) );

		$second = $this->request(
			'GET',
			'/status',
			array(
				'per_page' => 2,
				'page'     => 2,
				'orderby'  => 'title',
				'order'    => 'asc',
			)
		);
		$this->assertSame( array( $sources[2] ), self::ids( $second ) );
	}

	public function test_status_filters(): void {
		$author     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$translator = self::factory()->user->create( array( 'role' => 'author' ) );
		$outdated   = $this->outdated_group( array( 'post_author' => $author ) );
		$synced     = $this->create_synced_group( array( 'en', 'fr', 'es' ), array( 'post_title' => 'Quiet page' ) );
		$page       = $this->create_synced_group( array( 'en', 'fr' ), array( 'post_type' => 'page' ) );
		$this->set_settings( array( 'translators' => array( 'fr' => array( $translator ) ) ) );
		$this->as_role( 'administrator' );

		$this->assertSame( array( $outdated['en'] ), self::ids( $this->request( 'GET', '/status', array( 'status' => array( 'outdated' ) ) ) ) );
		$this->assertSame(
			array( $outdated['en'] ),
			self::ids(
				$this->request(
					'GET',
					'/status',
					array(
						'status' => 'outdated',
						'lang'   => 'fr',
					)
				)
			),
			'Comma lists work too.'
		);
		$this->assertSame( array( $page['en'] ), self::ids( $this->request( 'GET', '/status', array( 'post_type' => array( 'page' ) ) ) ) );
		$this->assertSame(
			array( $page['en'] ),
			self::ids(
				$this->request(
					'GET',
					'/status',
					array(
						'status' => 'missing',
						'lang'   => 'es',
					)
				)
			)
		);
		$this->assertSame( array( $outdated['en'] ), self::ids( $this->request( 'GET', '/status', array( 'author' => $author ) ) ) );
		$this->assertSame( array( $synced['en'] ), self::ids( $this->request( 'GET', '/status', array( 'search' => 'quiet' ) ) ) );
		$this->assertSame(
			array( $outdated['en'] ),
			self::ids(
				$this->request(
					'GET',
					'/status',
					array(
						'translator' => $translator,
						'status'     => 'outdated',
					)
				)
			)
		);
		$this->assertSame(
			array(),
			self::ids(
				$this->request(
					'GET',
					'/status',
					array(
						'translator' => $translator,
						'lang'       => 'es',
					)
				)
			),
			'The translator has no ES assignment.'
		);
		$this->assertSame( array(), self::ids( $this->request( 'GET', '/status', array( 'changed_after' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) ) ) ) );
		$this->assertCount( 3, self::ids( $this->request( 'GET', '/status', array( 'changed_before' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) ) ) ) );
	}

	public function test_trashed_sources_are_left_out(): void {
		$this->as_role( 'administrator' );
		$group = $this->create_synced_group();
		wp_trash_post( $group['en'] );

		$this->assertSame( array(), self::ids( $this->request( 'GET', '/status' ) ) );
		$this->assertSame( 0, $this->request( 'GET', '/status/summary' )->get_data()['totals']['in_sync'] );
	}

	/**
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public static function invalid_requests(): array {
		return array(
			'unknown status'   => array( '/status', array( 'status' => 'stale' ) ),
			'unknown language' => array( '/status', array( 'lang' => 'xx' ) ),
			'per_page too big' => array( '/status', array( 'per_page' => 500 ) ),
			'page zero'        => array( '/status', array( 'page' => 0 ) ),
			'bad date'         => array( '/status', array( 'changed_after' => 'yesterday-ish' ) ),
			'bad orderby'      => array( '/status', array( 'orderby' => 'rand' ) ),
		);
	}

	/**
	 * @dataProvider invalid_requests
	 *
	 * @param array<string, mixed> $params Parameters.
	 */
	public function test_invalid_parameters_get_400( string $route, array $params ): void {
		$this->as_role( 'administrator' );

		$response = $this->request( 'GET', $route, $params );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	public function test_mark_synced_validates_ids(): void {
		$this->as_role( 'administrator' );

		$this->assertSame( 400, $this->request( 'POST', '/mark-synced' )->get_status(), 'ids is required.' );
		$this->assertSame( 400, $this->request( 'POST', '/mark-synced', array( 'ids' => array() ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/mark-synced', array( 'ids' => array( 'abc' ) ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/mark-synced', array( 'ids' => range( 1, MarkSyncedController::MAX_IDS + 1 ) ) )->get_status() );
	}

	public function test_mark_synced_in_bulk_reports_failures(): void {
		$this->as_role( 'administrator' );
		$group = $this->outdated_group();

		$response = $this->request( 'POST', '/mark-synced', array( 'ids' => array( $group['fr'], $group['es'], $group['en'] ) ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( $group['fr'], $group['es'] ), $data['updated'] );
		$this->assertSame( array( $group['en'] ), array_column( $data['failed'], 'id' ), 'A source is not a translation.' );
		$this->assertSame( 'in_sync', ( (array) $data['translations'] )[ (string) $group['fr'] ]['status'] );
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'es' ) );
		$this->assertContains( 'marked_synced', $this->events_of( $group['fr'] ) );
	}

	public function test_summary_counts_by_language_and_post_type(): void {
		$this->as_role( 'administrator' );
		$this->outdated_group();
		$this->create_synced_group( array( 'en', 'fr' ), array( 'post_type' => 'page' ) );

		$data = $this->request( 'GET', '/status/summary' )->get_data();

		$this->assertSame( 2, $data['totals']['outdated'] );
		$this->assertSame( 1, $data['totals']['in_sync'] );
		$this->assertSame( 1, $data['totals']['missing'] );
		$languages = array_column( $data['languages'], null, 'code' );
		$this->assertSame( array( 'en', 'fr', 'es' ), array_keys( $languages ) );
		$this->assertSame( 1, ( (array) $languages['fr']['counts'] )['outdated'] );
		$this->assertSame( 1, ( (array) $languages['es']['counts'] )['missing'] );
		$this->assertNotSame( '', $languages['fr']['name'] );
		$types = array_column( $data['post_types'], null, 'slug' );
		$this->assertSame( 2, ( (array) $types['post']['counts'] )['outdated'] );
		$this->assertSame( 1, ( (array) $types['page']['counts'] )['in_sync'] );
		$this->assertArrayHasKey( 'status', $data['baseline'] );
	}

	public function test_group_of_a_source_a_translation_and_an_untracked_post(): void {
		$this->as_role( 'administrator' );
		$group = $this->outdated_group();

		$source = $this->request( 'GET', '/group/' . $group['en'] )->get_data();
		$this->assertSame( 'source', $source['role'] );
		$this->assertSame( array( 'fr', 'es' ), array_keys( (array) $source['translations'] ) );

		$translation = $this->request( 'GET', '/group/' . $group['fr'] )->get_data();
		$this->assertSame( 'translation', $translation['role'] );
		$this->assertSame( 'fr', $translation['lang'] );
		$this->assertSame( 'outdated', $translation['translation']['status'] );
		$this->assertSame( $group['en'], $translation['source']['id'] );
		$this->assertSame( 'Changed title', $translation['source']['title'] );
		$this->assertTrue( $translation['can_mark'] );

		$attachment = self::factory()->attachment->create();
		$untracked  = $this->request( 'GET', '/group/' . $attachment )->get_data();
		$this->assertFalse( $untracked['tracked'] );
		$this->assertSame( 'none', $untracked['role'] );
	}

	public function test_diff_of_a_title_change_uses_the_stored_revision(): void {
		$this->as_role( 'administrator' );
		$group = $this->create_group();
		// An update gives the source a revision, which the sync point then records.
		wp_update_post(
			array(
				'ID'           => $group['en'],
				'post_excerpt' => 'Edited excerpt',
			)
		);
		$this->container()->sync_service()->mark_synced( $group['fr'] );
		wp_update_post(
			array(
				'ID'         => $group['en'],
				'post_title' => 'Changed title',
			)
		);
		$this->run_jobs();
		$row = $this->row( $group['fr'] );
		$this->assertNotNull( $row );
		$this->assertGreaterThan( 0, $row->source_rev_id );
		$this->assertSame( array(), $this->container()->snapshot_repository()->for_sync( $row->id ), 'Revisioned fields are not snapshotted.' );

		$data = $this->request( 'GET', '/diff/' . $group['fr'] )->get_data();

		$this->assertSame( 'outdated', $data['status'] );
		$this->assertSame( $group['en'], $data['source_id'] );
		$this->assertCount( 1, $data['fields'] );
		$field = $data['fields'][0];
		$this->assertSame( 'title', $field['key'] );
		$this->assertSame( 'Title', $field['label'] );
		$this->assertTrue( $field['available'] );
		$this->assertMatchesRegularExpression( '#<del>Title en</del>|diff-deletedline.*Title en#s', $field['diff'] );
		$this->assertStringContainsString( 'Changed title', $field['diff'] );
		$this->assertStringContainsString( 'screen-reader-text', $field['diff'], 'Core marks added and deleted lines for screen readers.' );
		$this->assertStringNotContainsString( '<script', $field['diff'] );
		$this->assertSame( $group['fr'], $data['translation']['id'] );
		$this->assertNotNull( $data['source']['edit_url'] );
		$this->assertNotNull( $data['source']['view_url'] );
	}

	public function test_diff_content_reads_one_block_per_line(): void {
		$this->as_role( 'administrator' );
		$group = $this->create_synced_group();
		wp_update_post(
			array(
				'ID'           => $group['en'],
				'post_content' => "<!-- wp:paragraph -->\n<p>Content en</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2>New heading</h2>\n<!-- /wp:heading -->",
			)
		);
		$this->run_jobs();

		$diff = $this->request(
			'GET',
			'/diff/' . $group['fr'],
			array( 'split' => false )
		)->get_data()['fields'][0]['diff'];

		$this->assertStringContainsString( 'New heading', $diff );
		$this->assertStringContainsString( '[core/heading]', $diff );
	}

	public function test_diff_survives_the_sync_revision_being_pruned(): void {
		$this->as_role( 'administrator' );
		$group = $this->create_group();
		wp_update_post(
			array(
				'ID'         => $group['en'],
				'post_title' => 'Synced title',
			)
		);
		$this->container()->sync_service()->mark_synced( $group['fr'] );
		$revision_id = (int) $this->row( $group['fr'] )?->source_rev_id;
		$this->assertGreaterThan( 0, $revision_id );

		// A site that keeps one revision deletes the older ones on each save.
		add_filter( 'wp_revisions_to_keep', static fn(): int => 1 );
		foreach ( array( 'Second title', 'Third title' ) as $title ) {
			wp_update_post(
				array(
					'ID'         => $group['en'],
					'post_title' => $title,
				)
			);
		}
		$this->run_jobs();

		$this->assertNull( get_post( $revision_id ), 'The revision was pruned.' );
		$this->assertSame( 0, $this->row( $group['fr'] )?->source_rev_id );
		$field = $this->request( 'GET', '/diff/' . $group['fr'] )->get_data()['fields'][0];
		$this->assertTrue( $field['available'] );
		$this->assertStringContainsString( 'Synced', $field['diff'] );
		$this->assertStringContainsString( 'Third', $field['diff'] );
	}

	public function test_diff_without_revisions_uses_snapshots(): void {
		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		$this->as_role( 'administrator' );
		$group = $this->outdated_group();
		$this->assertSame( 0, $this->row( $group['fr'] )?->source_rev_id );

		$field = $this->request( 'GET', '/diff/' . $group['fr'] )->get_data()['fields'][0];

		$this->assertTrue( $field['available'] );
		$this->assertStringContainsString( 'Title en', $field['diff'] );
		$this->assertStringContainsString( 'Changed title', $field['diff'] );
	}

	public function test_diff_of_a_custom_field_uses_its_snapshot(): void {
		$this->set_settings( array( 'meta_keys' => array( 'subtitle' ) ) );
		$this->as_role( 'administrator' );
		$group = $this->create_group();
		update_post_meta( $group['en'], 'subtitle', 'Old subtitle' );
		$this->container()->sync_service()->mark_synced( $group['fr'] );
		update_post_meta( $group['en'], 'subtitle', 'New subtitle' );
		$this->container()->drift_service()->recalculate_source( $group['en'] );

		$fields = $this->request( 'GET', '/diff/' . $group['fr'] )->get_data()['fields'];

		$this->assertSame( array( 'meta:subtitle' ), array_column( $fields, 'key' ) );
		$this->assertTrue( $fields[0]['available'] );
		$this->assertStringContainsString( '<del>Old</del> subtitle', $fields[0]['diff'] );
		$this->assertStringContainsString( '<ins>New</ins> subtitle', $fields[0]['diff'] );
	}

	public function test_diff_of_an_untracked_translation_is_404(): void {
		$this->as_role( 'administrator' );
		$group = $this->create_group( array( 'en', 'fr' ) );

		$response = $this->request( 'GET', '/diff/' . $group['fr'] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'stalelingo_not_tracked', $response->get_data()['code'] );
	}

	public function test_baseline_is_queued_and_reports_progress(): void {
		$this->as_role( 'administrator' );

		$response = $this->request( 'POST', '/baseline', array( 'force' => true ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['queued'] );
		$this->assertSame( 'queued', $response->get_data()['state']['status'] );
		$this->assertContains( BaselineJob::BASELINE_HOOK, array_column( $this->queued_jobs(), 'hook' ) );
		$this->assertSame( array( 0, true ), $this->queued_jobs()[0]['args'] );

		$this->run_jobs();
		$this->assertSame( 'done', $this->request( 'GET', '/baseline' )->get_data()['state']['status'] );
	}

	public function test_every_response_matches_its_published_schema(): void {
		$this->as_role( 'administrator' );
		$group = $this->outdated_group();

		$responses = array(
			'/stalelingo/v1/status'                       => $this->request( 'GET', '/status' ),
			'/stalelingo/v1/status/summary'               => $this->request( 'GET', '/status/summary' ),
			'/stalelingo/v1/group/(?P<id>\d+)'            => $this->request( 'GET', '/group/' . $group['fr'] ),
			'/stalelingo/v1/diff/(?P<translation_id>\d+)' => $this->request( 'GET', '/diff/' . $group['fr'] ),
			'/stalelingo/v1/baseline'                     => $this->request( 'GET', '/baseline' ),
			'/stalelingo/v1/mark-synced'                  => $this->request( 'POST', '/mark-synced', array( 'ids' => array( $group['fr'] ) ) ),
		);

		foreach ( $responses as $route => $response ) {
			$this->assertSame( 200, $response->get_status(), $route );

			$options = rest_get_server()->dispatch( new \WP_REST_Request( 'OPTIONS', str_replace( array( '(?P<id>\d+)', '(?P<translation_id>\d+)' ), (string) $group['fr'], $route ) ) );
			$schema  = $options->get_data()['schema'] ?? null;
			$this->assertIsArray( $schema, $route );

			// JSON round trip: objects such as `translations` become arrays, as a client would see them.
			$data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
			if ( '/stalelingo/v1/status' === $route ) {
				$schema = array(
					'type'  => 'array',
					'items' => $schema,
				);
			}
			$valid = rest_validate_value_from_schema( $data, $schema, 'response' );
			$this->assertTrue( true === $valid, $route . ': ' . ( is_wp_error( $valid ) ? $valid->get_error_message() : '' ) );
		}
	}

	public function test_first_dashboard_page_responds_within_500_ms(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			$this->create_synced_group();
		}
		$this->as_role( 'administrator' );
		$this->request( 'GET', '/status' );
		wp_cache_flush();

		$times = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$start    = microtime( true );
			$response = $this->request( 'GET', '/status', array( 'per_page' => 20 ) );
			$times[]  = microtime( true ) - $start;
			$this->assertCount( 20, (array) $response->get_data() );
		}
		sort( $times );

		$this->assertLessThan( 0.5, $times[1], sprintf( 'Median %.0f ms.', $times[1] * 1000 ) );
	}
}
