<?php
/**
 * ACF fields, ACF blocks and Elementor content drift.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Tests\Integration;

use TranslationDrift\Domain\Status;

/**
 * @covers \TranslationDrift\Integrations\Acf
 * @covers \TranslationDrift\Integrations\Elementor
 * @covers \TranslationDrift\Services\Fingerprinter
 */
final class AcfElementorTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			$this->markTestSkipped( 'ACF is not installed in the test environment.' );
		}

		acf_add_local_field_group(
			array(
				'key'      => 'group_tdrift_test',
				'title'    => 'Drift test',
				'fields'   => array(
					array(
						'key'   => 'field_tdrift_subtitle',
						'name'  => 'subtitle',
						'label' => 'Subtitle',
						'type'  => 'text',
					),
					array(
						'key'   => 'field_tdrift_count',
						'name'  => 'count',
						'label' => 'Count',
						'type'  => 'number',
					),
					array(
						'key'        => 'field_tdrift_box',
						'name'       => 'box',
						'label'      => 'Box',
						'type'       => 'group',
						'sub_fields' => array(
							array(
								'key'   => 'field_tdrift_box_title',
								'name'  => 'title',
								'label' => 'Title',
								'type'  => 'text',
							),
							array(
								'key'     => 'field_tdrift_box_size',
								'name'    => 'size',
								'label'   => 'Size',
								'type'    => 'select',
								'choices' => array(
									's' => 'S',
									'l' => 'L',
								),
							),
						),
					),
					// Block fields (an ACF block's field group).
					array(
						'key'   => 'field_tdrift_heading',
						'name'  => 'heading',
						'label' => 'Heading',
						'type'  => 'text',
					),
					array(
						'key'     => 'field_tdrift_intro_size',
						'name'    => 'intro_size',
						'label'   => 'Intro size',
						'type'    => 'select',
						'choices' => array(
							'small' => 'Small',
							'large' => 'Large',
						),
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			)
		);
		$this->container()->acf()->flush_cache();

		add_filter( 'tdrift_is_acf_block', array( $this, 'is_acf_block' ), 10, 2 );
		add_filter( 'tdrift_elementor_enabled', '__return_true' );
		$this->container()->elementor()->register();
	}

	public function tear_down(): void {
		remove_filter( 'tdrift_is_acf_block', array( $this, 'is_acf_block' ) );
		remove_filter( 'tdrift_elementor_enabled', '__return_true' );
		remove_filter( 'tdrift_tracked_fields', array( $this->container()->elementor(), 'add_tracked_field' ) );
		if ( function_exists( 'acf_remove_local_field_group' ) ) {
			acf_remove_local_field_group( 'group_tdrift_test' );
		}
		$this->container()->acf()->flush_cache();
		parent::tear_down();
	}

	/**
	 * ACF free has no block API, so the test block is declared an ACF block through the filter.
	 */
	public function is_acf_block( bool $is_acf, string $name ): bool {
		return $is_acf || 'tdrift-test/banner' === $name;
	}

	private function recalc( int $source_id ): void {
		$this->container()->drift_service()->recalculate_source( $source_id );
	}

	public function test_text_fields_are_tracked_and_layout_fields_are_not(): void {
		$group = $this->create_group( array( 'en', 'fr' ) );
		update_field( 'subtitle', 'Original subtitle', $group['en'] );
		update_field( 'count', 3, $group['en'] );
		update_field(
			'box',
			array(
				'title' => 'Box title',
				'size'  => 's',
			),
			$group['en']
		);
		$this->container()->sync_service()->mark_synced( $group['fr'] );

		$this->assertContains( 'acf:subtitle', array_keys( $this->row( $group['fr'] )->field_hashes ) );
		$this->assertContains( 'acf:box', array_keys( $this->row( $group['fr'] )->field_hashes ) );
		$this->assertNotContains( 'acf:count', array_keys( $this->row( $group['fr'] )->field_hashes ) );

		update_field( 'count', 99, $group['en'] );
		update_field(
			'box',
			array(
				'title' => 'Box title',
				'size'  => 'l',
			),
			$group['en']
		);
		$this->recalc( $group['en'] );
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ), 'Numbers and selects are layout.' );

		update_field( 'subtitle', 'New subtitle', $group['en'] );
		update_field(
			'box',
			array(
				'title' => 'New box title',
				'size'  => 'l',
			),
			$group['en']
		);
		$this->recalc( $group['en'] );
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( array( 'acf:box', 'acf:subtitle' ), $this->row( $group['fr'] )->changed_fields );
	}

	public function test_acf_field_values_are_snapshotted(): void {
		$group = $this->create_group( array( 'en', 'fr' ) );
		update_field( 'subtitle', 'Snapshot me', $group['en'] );

		$this->container()->sync_service()->mark_synced( $group['fr'] );

		$snapshots = $this->container()->snapshot_repository()->for_sync( $this->row( $group['fr'] )->id );
		$this->assertSame( 'Snapshot me', $snapshots['acf:subtitle']['value'] );
	}

	/**
	 * An ACF block as saved in post content.
	 *
	 * @param array<string, mixed> $data Field values.
	 */
	private function banner( array $data ): string {
		$attrs = array(
			'name' => 'tdrift-test/banner',
			'data' => array(
				'heading'     => $data['heading'],
				'_heading'    => 'field_tdrift_heading',
				'intro_size'  => $data['intro_size'],
				'_intro_size' => 'field_tdrift_intro_size',
			),
			'mode' => 'preview',
		);

		return '<!-- wp:tdrift-test/banner ' . wp_json_encode( $attrs ) . ' /-->';
	}

	public function test_acf_block_text_drifts_and_block_layout_does_not(): void {
		$group = $this->create_synced_group(
			array( 'en', 'fr' ),
			array(
				'post_content' => $this->banner(
					array(
						'heading'    => 'Grow your business',
						'intro_size' => 'large',
					)
				),
			)
		);

		wp_update_post(
			array(
				'ID'           => $group['en'],
				'post_content' => $this->banner(
					array(
						'heading'    => 'Grow your business',
						'intro_size' => 'small',
					)
				),
			)
		);
		$this->run_jobs();
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );

		wp_update_post(
			array(
				'ID'           => $group['en'],
				'post_content' => $this->banner(
					array(
						'heading'    => 'Grow faster',
						'intro_size' => 'small',
					)
				),
			)
		);
		$this->run_jobs();
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( array( 'content' ), $this->row( $group['fr'] )->changed_fields );
	}

	/**
	 * An Elementor document with one heading.
	 */
	private function elementor_data( string $title, string $color ): string {
		return (string) wp_json_encode(
			array(
				array(
					'id'       => 'a1',
					'elType'   => 'container',
					'settings' => array( 'background_color' => $color ),
					'elements' => array(
						array(
							'id'         => 'b2',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array(
								'title'       => $title,
								'title_color' => $color,
							),
						),
					),
				),
			)
		);
	}

	public function test_elementor_text_drifts_and_style_does_not(): void {
		$group = $this->create_group( array( 'en', 'fr' ) );
		update_post_meta( $group['en'], '_elementor_edit_mode', 'builder' );
		update_post_meta( $group['en'], '_elementor_data', wp_slash( $this->elementor_data( 'Welcome', '#111111' ) ) );
		$this->container()->sync_service()->mark_synced( $group['fr'] );
		$this->assertArrayHasKey( 'elementor', $this->row( $group['fr'] )->field_hashes );

		update_post_meta( $group['en'], '_elementor_data', wp_slash( $this->elementor_data( 'Welcome', '#ff0000' ) ) );
		$this->recalc( $group['en'] );
		$this->assertSame( Status::InSync, $this->status_of( $group['en'], 'fr' ) );

		update_post_meta( $group['en'], '_elementor_data', wp_slash( $this->elementor_data( 'Bienvenue everyone', '#ff0000' ) ) );
		$this->recalc( $group['en'] );
		$this->assertSame( Status::Outdated, $this->status_of( $group['en'], 'fr' ) );
		$this->assertSame( array( 'elementor' ), $this->row( $group['fr'] )->changed_fields );
	}

	public function test_elementor_field_is_empty_for_posts_not_built_with_elementor(): void {
		$group = $this->create_group( array( 'en', 'fr' ) );
		update_post_meta( $group['en'], '_elementor_data', wp_slash( $this->elementor_data( 'Ignored', '#000' ) ) );

		$this->container()->sync_service()->mark_synced( $group['fr'] );

		$this->assertSame( \TranslationDrift\Domain\Hasher::ABSENT, $this->row( $group['fr'] )->field_hashes['elementor'] );
	}
}
