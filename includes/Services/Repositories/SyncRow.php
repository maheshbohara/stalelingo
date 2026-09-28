<?php
/**
 * A `tdrift_sync` row.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services\Repositories;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Domain\Status;

/**
 * The sync point and cached status of one (source, language) pair.
 *
 * @since 0.1.0
 */
final class SyncRow {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param int                        $id             Row ID.
	 * @param int                        $translation_id Translation post ID, 0 when missing.
	 * @param int                        $source_id      Source post ID.
	 * @param string                     $lang           Language code.
	 * @param string                     $post_type      Post type of the source.
	 * @param int                        $source_rev_id  Source revision at the sync point.
	 * @param array<string, string>|null $field_hashes   Field hashes at the sync point, null without one.
	 * @param list<string>               $changed_fields Fields changed since the sync point.
	 * @param Status                     $status         Cached status.
	 * @param string|null                $synced_at      Sync time (GMT, MySQL format).
	 * @param int                        $synced_by      User who marked it in sync, 0 for the system.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $translation_id,
		public readonly int $source_id,
		public readonly string $lang,
		public readonly string $post_type,
		public readonly int $source_rev_id,
		public readonly ?array $field_hashes,
		public readonly array $changed_fields,
		public readonly Status $status,
		public readonly ?string $synced_at,
		public readonly int $synced_by
	) {
	}

	/**
	 * Builds a row from a database result.
	 *
	 * @since 0.1.0
	 *
	 * @param object $row Database row.
	 */
	public static function from_db( object $row ): self {
		$data   = (array) $row;
		$hashes = isset( $data['field_hashes'] ) ? json_decode( (string) $data['field_hashes'], true ) : null;
		$fields = isset( $data['changed_fields'] ) ? json_decode( (string) $data['changed_fields'], true ) : array();

		return new self(
			(int) $data['id'],
			(int) $data['translation_id'],
			(int) $data['source_id'],
			(string) $data['lang'],
			(string) $data['post_type'],
			(int) $data['source_rev_id'],
			is_array( $hashes ) ? array_map( 'strval', $hashes ) : null,
			is_array( $fields ) ? array_values( array_map( 'strval', $fields ) ) : array(),
			Status::tryFrom( (string) $data['status'] ) ?? Status::Untracked,
			isset( $data['synced_at'] ) ? (string) $data['synced_at'] : null,
			(int) $data['synced_by']
		);
	}
}
