<?php
/**
 * Status labels and badges.
 *
 * @package Stalelingo
 */

declare( strict_types=1 );

namespace Stalelingo\Admin;

defined( 'ABSPATH' ) || exit;

use Stalelingo\Domain\Status;

/**
 * Renders statuses and field names for the admin UI.
 *
 * A badge never relies on color alone: it shows the language code and a
 * symbol, and carries the full status as screen-reader text and a tooltip.
 *
 * @since 1.0.0
 */
final class StatusView {

	/**
	 * Human-readable status.
	 *
	 * @since 1.0.0
	 *
	 * @param Status $status Status.
	 */
	public static function label( Status $status ): string {
		return match ( $status ) {
			Status::InSync    => __( 'Up to date', 'stalelingo' ),
			Status::Outdated  => __( 'Outdated', 'stalelingo' ),
			Status::Missing   => __( 'Missing', 'stalelingo' ),
			Status::Untracked => __( 'Not tracked yet', 'stalelingo' ),
		};
	}

	/**
	 * A symbol that tells statuses apart without color.
	 *
	 * @since 1.0.0
	 *
	 * @param Status $status Status.
	 */
	public static function symbol( Status $status ): string {
		return match ( $status ) {
			Status::InSync    => '✓',
			Status::Outdated  => '!',
			Status::Missing   => '–',
			Status::Untracked => '?',
		};
	}

	/**
	 * HTML of one language badge. Already escaped.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $lang   Language code.
	 * @param Status      $status Status.
	 * @param string|null $url    Link to the translation (edit or create), if any.
	 */
	public static function badge( string $lang, Status $status, ?string $url = null ): string {
		/* translators: 1: language code, e.g. FR. 2: translation status, e.g. Outdated. */
		$full  = sprintf( __( '%1$s: %2$s', 'stalelingo' ), strtoupper( $lang ), self::label( $status ) );
		$inner = sprintf(
			'<span aria-hidden="true">%1$s <span class="stalelingo-badge-symbol">%2$s</span></span><span class="screen-reader-text">%3$s</span>',
			esc_html( strtoupper( $lang ) ),
			esc_html( self::symbol( $status ) ),
			esc_html( $full )
		);
		$class = 'stalelingo-badge stalelingo-badge-' . str_replace( '_', '-', $status->value );

		if ( null !== $url && '' !== $url ) {
			return sprintf( '<a class="%1$s" href="%2$s" title="%3$s">%4$s</a>', esc_attr( $class ), esc_url( $url ), esc_attr( $full ), $inner );
		}

		return sprintf( '<span class="%1$s" title="%2$s">%3$s</span>', esc_attr( $class ), esc_attr( $full ), $inner );
	}

	/**
	 * Post type names, with the slug added where two types share a name
	 * (e.g. "Events (events)" and "Events (mec-events)").
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $types    Post type names.
	 * @param bool         $singular Singular names instead of plural ones.
	 * @return array<string, string> Labels keyed by post type.
	 */
	public static function post_type_labels( array $types, bool $singular = false ): array {
		$labels = array();
		foreach ( $types as $type ) {
			$object          = get_post_type_object( $type );
			$labels[ $type ] = null === $object ? $type : (string) ( $singular ? $object->labels->singular_name : $object->labels->name );
		}

		$counts = array_count_values( $labels );
		foreach ( $labels as $type => $label ) {
			if ( $counts[ $label ] > 1 ) {
				/* translators: 1: post type name, e.g. Events. 2: post type key, e.g. mec-events. */
				$labels[ $type ] = sprintf( __( '%1$s (%2$s)', 'stalelingo' ), $label, $type );
			}
		}

		return $labels;
	}

	/**
	 * Human-readable name of a tracked field key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $field Field key, e.g. 'title', 'meta:subtitle', 'acf:hero_text'.
	 */
	public static function field_label( string $field ): string {
		$labels = array(
			'title'          => __( 'Title', 'stalelingo' ),
			'content'        => __( 'Content', 'stalelingo' ),
			'excerpt'        => __( 'Excerpt', 'stalelingo' ),
			'slug'           => __( 'Slug', 'stalelingo' ),
			'featured_image' => __( 'Featured image', 'stalelingo' ),
			'elementor'      => __( 'Elementor content', 'stalelingo' ),
		);

		if ( isset( $labels[ $field ] ) ) {
			$label = $labels[ $field ];
		} elseif ( str_starts_with( $field, 'acf:' ) ) {
			$acf_field = function_exists( 'acf_get_field' ) ? acf_get_field( substr( $field, 4 ) ) : false;
			$name      = is_array( $acf_field ) && ! empty( $acf_field['label'] ) ? (string) $acf_field['label'] : substr( $field, 4 );
			/* translators: %s: ACF field label. */
			$label = sprintf( __( 'Field “%s”', 'stalelingo' ), $name );
		} elseif ( str_starts_with( $field, 'meta:' ) ) {
			/* translators: %s: custom field (post meta) key. */
			$label = sprintf( __( 'Custom field “%s”', 'stalelingo' ), substr( $field, 5 ) );
		} else {
			$label = $field;
		}

		/**
		 * Filters the label shown for a tracked field.
		 *
		 * @since 1.0.0
		 *
		 * @param string $label Label.
		 * @param string $field Field key.
		 */
		return (string) apply_filters( 'stalelingo_field_label', $label, $field );
	}
}
