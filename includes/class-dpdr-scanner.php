<?php
/**
 * Read-only parser and conservative PDF attachment matching.
 *
 * @package Digicyfr_Public_Document_Review
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Scan saved anchors once, then associate File-block hints. */
final class DPDR_Scanner {
	public const MAX_POST_BYTES     = 1048576;
	public const MAX_LINKS_PER_POST = 300;
	public const MAX_URL_BYTES      = 2048;
	private const MAX_DEPTH         = 64;

	/**
	 * Detect an overlong URL before or after site-origin expansion.
	 *
	 * @param mixed $url url input.
	 */
	public static function overlong( $url ): bool {
		if ( ! is_string( $url ) ) {
			return false;
		}
		$extra  = '';
		$origin = wp_parse_url( home_url( '/' ) );
		if ( is_array( $origin ) && str_starts_with( $url, '//' ) ) {
			$extra = (string) ( $origin['scheme'] ?? 'https' ) . ':';
		} elseif ( is_array( $origin ) && str_starts_with( $url, '/' ) ) {
			$extra = (string) ( $origin['scheme'] ?? 'https' ) . '://' . (string) ( $origin['host'] ?? '' ) . ( isset( $origin['port'] ) ? ':' . $origin['port'] : '' );
		}
		return strlen( $extra ) + strlen( $url ) > self::MAX_URL_BYTES;
	}

	/**
	 * Normalize only the documented URL components for exact comparison.
	 *
	 * @param mixed $url url input.
	 */
	public static function normalize( $url ): string {
		if ( ! is_string( $url ) || '' === $url || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return '';
		}
		$origin = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $origin ) || empty( $origin['host'] ) ) {
			return '';
		}
		if ( str_starts_with( $url, '//' ) ) {
			$url = strtolower( (string) $origin['scheme'] ) . ':' . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			$url = strtolower( (string) $origin['scheme'] ) . '://' . strtolower( (string) $origin['host'] ) . ( isset( $origin['port'] ) ? ':' . $origin['port'] : '' ) . $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! preg_match( '/^[a-z0-9.\-:\[\]]+$/', $host ) ) {
			return '';
		}
		$path       = (string) ( $parts['path'] ?? '' );
		$query      = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$normalized = $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $path . $query;
		return strlen( $normalized ) <= self::MAX_URL_BYTES ? $normalized : '';
	}

	/**
	 * Test only the URL path, excluding query and fragment.
	 *
	 * @param string $url url input.
	 */
	public static function pdf_path( string $url ): bool {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) && 1 === preg_match( '/\.pdf$/i', $path );
	}

	/**
	 * A lookup result is only a hint until both type and MIME are checked.
	 *
	 * @param mixed $id id input.
	 */
	public static function valid_pdf_id( $id ): bool {
		return is_numeric( $id ) && (int) $id > 0 && 'attachment' === get_post_type( (int) $id ) && 'application/pdf' === get_post_mime_type( (int) $id );
	}

	/**
	 * Check whether a normalized URL has the site's host and port.
	 *
	 * @param string $url url input.
	 */
	private static function local( string $url ): bool {
		$site = wp_parse_url( home_url( '/' ) );
		$link = wp_parse_url( $url );
		return is_array( $site ) && is_array( $link ) && strtolower( (string) ( $site['host'] ?? '' ) ) === strtolower( (string) ( $link['host'] ?? '' ) ) && (int) ( $site['port'] ?? 0 ) === (int) ( $link['port'] ?? 0 );
	}

	/**
	 * Classify one link, keeping uncertain IDs as hints only.
	 *
	 * @param string $url url input.
	 * @param array  $ids ids input.
	 */
	private static function classify( string $url, array $ids ): array {
		$valid  = array_values( array_filter( $ids, array( __CLASS__, 'valid_pdf_id' ) ) );
		$lookup = attachment_url_to_postid( $url );
		$lookup = self::valid_pdf_id( $lookup ) ? (int) $lookup : 0;
		if ( count( $valid ) > 1 || ( 1 === count( $valid ) && $lookup > 0 && $lookup !== (int) $valid[0] ) ) {
			return array(
				'state'         => 'ambiguous',
				'attachment_id' => 0,
			);
		}
		$id = 1 === count( $valid ) ? (int) $valid[0] : $lookup;
		if ( $id > 0 ) {
			$current = self::normalize( wp_get_attachment_url( $id ) );
			if ( '' !== $current && $url === $current ) {
				return array(
					'state'         => 'matched',
					'attachment_id' => $id,
				);
			}
			if ( array() !== $valid ) {
				$page = self::normalize( get_attachment_link( $id ) );
				return array(
					'state'         => '' !== $page && $url === $page ? 'attachment_page' : 'id_url_mismatch',
					'attachment_id' => 0,
				);
			}
		}
		return array(
			'state'         => self::local( $url ) ? 'unmatched_local' : 'unmatched_external',
			'attachment_id' => 0,
		);
	}

	/**
	 * Parse a bounded published source into de-duplicated link rows.
	 *
	 * @param WP_Post $post post input.
	 */
	public static function scan_post( WP_Post $post ): array {
		$content = $post->post_content;
		if ( strlen( $content ) > self::MAX_POST_BYTES ) {
			return array(
				'rows' => array(),
				'skip' => 'post_too_large',
			);
		}
		$raw         = array();
		$file_counts = array();
		$hints       = array();
		$skip        = '';
		try {
			$walk = static function ( array $blocks, int $depth ) use ( &$walk, &$raw, &$file_counts, &$hints, &$skip ): void {
				if ( $depth > self::MAX_DEPTH ) {
					$skip = 'blocks_too_deep';
					return;
				}
				foreach ( $blocks as $block ) {
					if ( '' !== $skip ) {
						return;
					}
					if ( 'core/file' === ( $block['blockName'] ?? '' ) ) {
						$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
						$id    = isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ? (int) $attrs['id'] : 0;
						$own   = new WP_HTML_Tag_Processor( (string) ( $block['innerHTML'] ?? '' ) );
						$found = false;
						while ( $own->next_tag( 'a' ) ) {
							$own_href = $own->get_attribute( 'href' );
							if ( self::overlong( $own_href ) ) {
								$skip = 'url_too_long';
								return;
							}
							$url = self::normalize( $own_href );
							if ( '' === $url ) {
								continue;
							}
							$found               = true;
							$file_counts[ $url ] = ( $file_counts[ $url ] ?? 0 ) + 1;
							if ( $id > 0 ) {
								$hints[ $url ][ $id ] = $id;
							}
						}
						if ( ! $found && isset( $attrs['href'] ) ) {
							if ( self::overlong( $attrs['href'] ) ) {
								$skip = 'url_too_long';
								return;
							}
							$url = self::normalize( $attrs['href'] );
							if ( '' !== $url ) {
								$raw[ $url ]         = ( $raw[ $url ] ?? 0 ) + 1;
								$file_counts[ $url ] = ( $file_counts[ $url ] ?? 0 ) + 1;
								if ( $id > 0 ) {
									$hints[ $url ][ $id ] = $id;
								}
							}
						}
					}
					$walk( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $depth + 1 );
				}
			};
			$walk( parse_blocks( $content ), 1 );
			if ( '' !== $skip ) {
				return array(
					'rows' => array(),
					'skip' => $skip,
				);
			}
			if ( array_sum( $raw ) > self::MAX_LINKS_PER_POST ) {
				return array(
					'rows' => array(),
					'skip' => 'too_many_links',
				);
			}
			$processor   = new WP_HTML_Tag_Processor( $content );
			$occurrences = 0;
			while ( $processor->next_tag( 'a' ) ) {
				$href = $processor->get_attribute( 'href' );
				if ( self::overlong( $href ) ) {
					return array(
						'rows' => array(),
						'skip' => 'url_too_long',
					);
				}
				$url = self::normalize( $href );
				if ( '' === $url ) {
					continue;
				}
				$raw[ $url ] = ( $raw[ $url ] ?? 0 ) + 1;
				++$occurrences;
				if ( $occurrences > self::MAX_LINKS_PER_POST ) {
					return array(
						'rows' => array(),
						'skip' => 'too_many_links',
					);
				}
			}
			if ( array_sum( $raw ) > self::MAX_LINKS_PER_POST ) {
				return array(
					'rows' => array(),
					'skip' => 'too_many_links',
				);
			}
			$rows = array();
			foreach ( $raw as $url => $count ) {
				$ids = array_values( $hints[ $url ] ?? array() );
				if ( count( $ids ) > 10 ) {
					return array(
						'rows' => array(),
						'skip' => 'too_many_hints',
					);
				}
				$has_pdf_hint = false;
				foreach ( $ids as $id ) {
					$has_pdf_hint = $has_pdf_hint || self::valid_pdf_id( $id );
				}
				if ( ! self::pdf_path( $url ) && ! $has_pdf_hint ) {
					continue;
				}
				$match      = self::classify( $url, $ids );
				$file_count = min( $count, (int) ( $file_counts[ $url ] ?? 0 ) );
				$rows[]     = array(
					'href'                  => $url,
					'normalized_url_sha256' => hash( 'sha256', $url ),
					'attachment_id'         => $match['attachment_id'],
					'hint_ids_json'         => wp_json_encode( $ids ),
					'match_state'           => $match['state'],
					'occurrence_count'      => $count,
					'source_kind'           => 0 === $file_count ? 'html' : ( $file_count === $count ? 'file' : 'both' ),
				);
			}
			if ( count( $rows ) > self::MAX_LINKS_PER_POST ) {
				return array(
					'rows' => array(),
					'skip' => 'too_many_links',
				);
			}
			return array(
				'rows' => $rows,
				'skip' => '',
			);
		} catch ( Throwable $error ) {
			return array(
				'rows' => array(),
				'skip' => 'parse_failed',
			);
		}
	}
}
