<?php
/**
 * Field diffs between a sync point and the current source.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Services;

defined( 'ABSPATH' ) || exit;

use TranslationDrift\Admin\StatusView;
use TranslationDrift\Services\Repositories\SnapshotRepository;
use TranslationDrift\Services\Repositories\SyncRow;

/**
 * Shows what changed in a source since its translation was last marked up to date.
 *
 * Title, content and excerpt come from the source revision stored at the sync
 * point. Other fields (custom fields, ACF, Elementor), and every field when the
 * source had no revision, come from the snapshots. Both sides are normalized
 * the same way (normal mode, even in strict mode), so content reads one block
 * per line and formatting noise stays out of the diff.
 *
 * @since 1.0.0
 */
class DiffService {

	/**
	 * Fields that post revisions store.
	 *
	 * @since 1.0.0
	 */
	public const REVISIONED_FIELDS = array( 'title', 'content', 'excerpt' );

	/**
	 * HTML that wp_text_diff() produces and the diff viewer keeps.
	 *
	 * @since 1.0.0
	 */
	private const ALLOWED_HTML = array(
		'table'    => array( 'class' => true ),
		'colgroup' => array(),
		'col'      => array( 'class' => true ),
		'thead'    => array(),
		'tbody'    => array(),
		'tr'       => array(),
		'th'       => array(
			'class' => true,
			'scope' => true,
		),
		'td'       => array(
			'class'   => true,
			'colspan' => true,
		),
		'del'      => array(),
		'ins'      => array(),
		'span'     => array(
			'class'       => true,
			'aria-hidden' => true,
		),
	);

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Fingerprinter      $fingerprinter Fingerprinter.
	 * @param SnapshotRepository $snapshots     Snapshots.
	 */
	public function __construct( private Fingerprinter $fingerprinter, private SnapshotRepository $snapshots ) {
	}

	/**
	 * Diffs of the fields that changed since the sync point.
	 *
	 * @since 1.0.0
	 *
	 * @param SyncRow  $row    Sync row of the translation.
	 * @param \WP_Post $source Current source post.
	 * @param bool     $split  Two columns (old | new) instead of one.
	 * @return list<array{key: string, label: string, available: bool, truncated: bool, diff: string}>
	 *         `available` is false when no copy of the old value was stored; `diff` is sanitized
	 *         table HTML, empty when the difference is formatting only.
	 */
	public function diff( SyncRow $row, \WP_Post $source, bool $split = true ): array {
		$fields = $row->changed_fields;
		if ( array() === $fields ) {
			return array();
		}

		$current   = $this->fingerprinter->values( $source, $fields, false );
		$revision  = $row->source_rev_id > 0 ? get_post( $row->source_rev_id ) : null;
		$revision  = $revision instanceof \WP_Post && 'revision' === $revision->post_type && $revision->post_parent === $source->ID ? $revision : null;
		$from_rev  = null === $revision ? array() : $this->fingerprinter->values( $revision, array_values( array_intersect( $fields, self::REVISIONED_FIELDS ) ), false );
		$snapshots = $this->snapshots->for_sync( $row->id );

		$result = array();
		foreach ( $fields as $field ) {
			$old       = null;
			$truncated = false;
			if ( array_key_exists( $field, $from_rev ) ) {
				$old = $from_rev[ $field ] ?? '';
			} elseif ( isset( $snapshots[ $field ] ) ) {
				$old       = $snapshots[ $field ]['value'];
				$truncated = $snapshots[ $field ]['truncated'];
			} elseif ( $row->source_rev_id > 0 && ! in_array( $field, self::REVISIONED_FIELDS, true ) ) {
				// Snapshots skip fields that had no value at the sync point.
				$old = '';
			}

			$result[] = array(
				'key'       => $field,
				'label'     => StatusView::field_label( $field ),
				'available' => null !== $old,
				'truncated' => $truncated,
				'diff'      => null === $old ? '' : $this->render( $old, (string) ( $current[ $field ] ?? '' ), $split ),
			);
		}//end foreach

		return $result;
	}

	/**
	 * Sanitized wp_text_diff() table, empty when the texts are the same.
	 *
	 * @param string $before Value at the sync point.
	 * @param string $after  Current value.
	 * @param bool   $split  Two columns.
	 */
	private function render( string $before, string $after, bool $split ): string {
		$html = wp_text_diff(
			$before,
			$after,
			array(
				'title_left'      => __( 'At last sync', 'translation-drift' ),
				'title_right'     => __( 'Now', 'translation-drift' ),
				'show_split_view' => $split,
			)
		);

		return '' === $html ? '' : wp_kses( $html, self::ALLOWED_HTML );
	}
}
