<?php
/**
 * REST representation of sources and their translations.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Rest;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Admin\Actions;
use TranslationDrift\Admin\StatusView;
use TranslationDrift\Domain\Status;
use TranslationDrift\Providers\TranslationProvider;
use TranslationDrift\Services\Permissions;
use TranslationDrift\Services\Repositories\SyncRow;

/**
 * Turns sync rows into the arrays the REST API returns, and describes them as JSON Schema.
 *
 * Links and titles appear only where the current user may see them.
 *
 * @since 1.0.0
 */
class ItemPresenter {

	/**
	 * Language names keyed by code, loaded once.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $language_names = null;

	/**
	 * Singular post type names keyed by post type, loaded once.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $type_labels = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param TranslationProvider $provider    Provider.
	 * @param Permissions         $permissions Permissions.
	 */
	public function __construct( private TranslationProvider $provider, private Permissions $permissions ) {
	}

	/**
	 * A source with the status of each translation.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post               $source Source post.
	 * @param array<string, SyncRow> $rows   Its rows, keyed by language.
	 * @return array<string, mixed>
	 */
	public function source( \WP_Post $source, array $rows ): array {
		$author = get_userdata( (int) $source->post_author );
		if ( null === $this->type_labels ) {
			$this->type_labels = StatusView::post_type_labels( array_values( get_post_types( array( 'public' => true ) ) ), true );
		}

		$translations = array();
		foreach ( $this->in_language_order( $rows ) as $lang => $row ) {
			$translations[ $lang ] = $this->translation( $row );
		}

		return array(
			'id'              => $source->ID,
			'title'           => $this->title( $source ),
			'post_type'       => $source->post_type,
			'post_type_label' => $this->type_labels[ $source->post_type ] ?? $source->post_type,
			'post_status'     => $source->post_status,
			'source_lang'     => (string) $this->provider->get_language( $source->ID ),
			'author'          => array(
				'id'   => (int) $source->post_author,
				'name' => $author instanceof \WP_User ? $author->display_name : '',
			),
			'modified'        => self::date( $source->post_modified_gmt ),
			'edit_url'        => $this->edit_url( $source->ID ),
			'translations'    => (object) $translations,
		);
	}

	/**
	 * The status of one translation.
	 *
	 * @since 1.0.0
	 *
	 * @param SyncRow $row Sync row.
	 * @return array<string, mixed>
	 */
	public function translation( SyncRow $row ): array {
		$user_id = get_current_user_id();
		$exists  = $row->translation_id > 0;

		return array(
			'lang'           => $row->lang,
			'language'       => $this->language_name( $row->lang ),
			'status'         => $row->status->value,
			'status_label'   => StatusView::label( $row->status ),
			'translation_id' => $row->translation_id,
			'changed_fields' => Status::Outdated === $row->status ? $this->fields( $row->changed_fields ) : array(),
			'synced_at'      => null === $row->synced_at ? null : self::date( $row->synced_at ),
			'edit_url'       => $exists ? $this->edit_url( $row->translation_id ) : null,
			'create_url'     => ! $exists && $this->can_create( $row->post_type ) ? Actions::translation_url( $row ) : null,
			'can_mark'       => $exists && $this->permissions->can_mark_synced( $user_id, $row->translation_id ),
		);
	}

	/**
	 * Field keys with their labels.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $fields Field keys.
	 * @return list<array{key: string, label: string}>
	 */
	public function fields( array $fields ): array {
		return array_map(
			static fn( string $field ): array => array(
				'key'   => $field,
				'label' => StatusView::field_label( $field ),
			),
			$fields
		);
	}

	/**
	 * Display name of a language.
	 *
	 * @since 1.0.0
	 *
	 * @param string $lang Language code.
	 */
	public function language_name( string $lang ): string {
		if ( null === $this->language_names ) {
			$this->language_names = $this->provider->get_language_names();
		}

		return $this->language_names[ $lang ] ?? strtoupper( $lang );
	}

	/**
	 * Post title as plain text.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Post.
	 */
	public function title( \WP_Post $post ): string {
		return html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Edit link when the current user may edit the post, otherwise null.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function edit_url( int $post_id ): ?string {
		$url = get_edit_post_link( $post_id, 'raw' );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * A GMT MySQL date as ISO 8601 in UTC.
	 *
	 * @since 1.0.0
	 *
	 * @param string $gmt Date, `Y-m-d H:i:s` in GMT.
	 */
	public static function date( string $gmt ): ?string {
		$time = strtotime( $gmt . ' UTC' );

		return false === $time || str_starts_with( $gmt, '0000' ) ? null : gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}

	/**
	 * Rows sorted in the site's language order.
	 *
	 * @param array<string, SyncRow> $rows Rows keyed by language.
	 * @return array<string, SyncRow>
	 */
	private function in_language_order( array $rows ): array {
		$order = array_flip( $this->provider->get_languages() );
		uksort(
			$rows,
			static function ( $a, $b ) use ( $order ): int {
				$by_order = ( $order[ $a ] ?? PHP_INT_MAX ) <=> ( $order[ $b ] ?? PHP_INT_MAX );

				return 0 !== $by_order ? $by_order : strcmp( (string) $a, (string) $b );
			}
		);

		return $rows;
	}

	/**
	 * Whether the current user may create posts of a type.
	 *
	 * @param string $post_type Post type.
	 */
	private function can_create( string $post_type ): bool {
		$type = get_post_type_object( $post_type );

		return null !== $type && current_user_can( $type->cap->create_posts );
	}

	/**
	 * JSON Schema of a translation's status.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public static function translation_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lang'           => array(
					'description' => __( 'Language code.', 'translation-drift' ),
					'type'        => 'string',
				),
				'language'       => array(
					'description' => __( 'Language name.', 'translation-drift' ),
					'type'        => 'string',
				),
				'status'         => array(
					'description' => __( 'Translation status.', 'translation-drift' ),
					'type'        => 'string',
					'enum'        => self::statuses(),
				),
				'status_label'   => array(
					'description' => __( 'Translation status, human-readable.', 'translation-drift' ),
					'type'        => 'string',
				),
				'translation_id' => array(
					'description' => __( 'Translation post ID, 0 when the translation is missing.', 'translation-drift' ),
					'type'        => 'integer',
				),
				'changed_fields' => self::fields_schema( __( 'Fields changed in the source since the translation was last marked up to date.', 'translation-drift' ) ),
				'synced_at'      => array(
					'description' => __( 'When the translation was last marked up to date (UTC).', 'translation-drift' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
				),
				'edit_url'       => array(
					'description' => __( 'Edit link, when the current user may edit the translation.', 'translation-drift' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'uri',
				),
				'create_url'     => array(
					'description' => __( 'Link that starts a missing translation.', 'translation-drift' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'uri',
				),
				'can_mark'       => array(
					'description' => __( 'Whether the current user may mark the translation as up to date.', 'translation-drift' ),
					'type'        => 'boolean',
				),
			),
		);
	}

	/**
	 * JSON Schema of a source with its translations.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public static function source_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'              => array(
					'description' => __( 'Source post ID.', 'translation-drift' ),
					'type'        => 'integer',
				),
				'title'           => array(
					'description' => __( 'Source title.', 'translation-drift' ),
					'type'        => 'string',
				),
				'post_type'       => array(
					'description' => __( 'Post type.', 'translation-drift' ),
					'type'        => 'string',
				),
				'post_type_label' => array(
					'description' => __( 'Post type name.', 'translation-drift' ),
					'type'        => 'string',
				),
				'post_status'     => array(
					'description' => __( 'Post status of the source.', 'translation-drift' ),
					'type'        => 'string',
				),
				'source_lang'     => array(
					'description' => __( 'Language code of the source.', 'translation-drift' ),
					'type'        => 'string',
				),
				'author'          => array(
					'description' => __( 'Author of the source.', 'translation-drift' ),
					'type'        => 'object',
					'properties'  => array(
						'id'   => array( 'type' => 'integer' ),
						'name' => array( 'type' => 'string' ),
					),
				),
				'modified'        => array(
					'description' => __( 'When the source last changed (UTC).', 'translation-drift' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
				),
				'edit_url'        => array(
					'description' => __( 'Edit link, when the current user may edit the source.', 'translation-drift' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'uri',
				),
				'translations'    => array(
					'description'          => __( 'Status of each translation, keyed by language code.', 'translation-drift' ),
					'type'                 => 'object',
					'additionalProperties' => self::translation_schema(),
				),
			),
		);
	}

	/**
	 * JSON Schema of a list of fields.
	 *
	 * @since 1.0.0
	 *
	 * @param string $description Description.
	 * @return array<string, mixed>
	 */
	public static function fields_schema( string $description ): array {
		return array(
			'description' => $description,
			'type'        => 'array',
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'key'   => array( 'type' => 'string' ),
					'label' => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * Status values.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public static function statuses(): array {
		return array_map( static fn( Status $s ): string => $s->value, Status::cases() );
	}
}
