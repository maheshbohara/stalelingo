<?php
/**
 * Snapshot encoding.
 *
 * @package TranslationDrift
 */

declare( strict_types=1 );

namespace TranslationDrift\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Encodes snapshot values compactly, capping their size.
 *
 * Values longer than the cap are cut on a UTF-8 character boundary and marked
 * as truncated, so the diff viewer can say so rather than show a false change.
 *
 * @since 0.1.0
 */
final class SnapshotCodec {

	/**
	 * Prefix of the current encoding (raw deflate). Stored in a longblob column.
	 *
	 * @since 0.1.0
	 */
	private const PREFIX = 'z1:';

	/**
	 * Default maximum stored bytes per value, before compression.
	 *
	 * @since 0.1.0
	 */
	public const DEFAULT_MAX_BYTES = 65536;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param int $max_bytes Maximum bytes kept per value.
	 */
	public function __construct( private int $max_bytes = self::DEFAULT_MAX_BYTES ) {
		$this->max_bytes = max( 1, $max_bytes );
	}

	/**
	 * Encodes a value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Normalized value.
	 */
	public function encode( string $value ): string {
		$truncated = strlen( $value ) > $this->max_bytes;
		if ( $truncated ) {
			$value = mb_strcut( $value, 0, $this->max_bytes, 'UTF-8' );
		}

		$payload    = ( $truncated ? '1' : '0' ) . $value;
		$compressed = gzdeflate( $payload, 6 );

		if ( false === $compressed ) {
			return 'r1:' . $payload;
		}

		return self::PREFIX . $compressed;
	}

	/**
	 * Decodes a stored value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $stored Encoded value.
	 * @return array{value: string, truncated: bool}
	 */
	public function decode( string $stored ): array {
		if ( str_starts_with( $stored, 'r1:' ) ) {
			$payload = substr( $stored, 3 );
		} elseif ( str_starts_with( $stored, self::PREFIX ) ) {
			$payload = @gzinflate( substr( $stored, strlen( self::PREFIX ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Corrupt rows fall back to an empty value.
		} else {
			return array(
				'value'     => $stored,
				'truncated' => false,
			);
		}

		if ( false === $payload || '' === $payload ) {
			return array(
				'value'     => '',
				'truncated' => false,
			);
		}

		return array(
			'value'     => substr( $payload, 1 ),
			'truncated' => '1' === $payload[0],
		);
	}
}
