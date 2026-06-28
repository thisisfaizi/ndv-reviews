<?php
/**
 * QR code rendering (SVG) for review-collection links.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

use NdvReviews\Vendor\QrEncoder;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a scannable QR code as an inline SVG (no images, no network).
 */
class Qr {

	/**
	 * Build an SVG QR for the given data.
	 *
	 * @param string $data   Data to encode (kept short, e.g. a URL).
	 * @param int    $px     Pixel size of the rendered square.
	 * @param int    $margin Quiet-zone modules.
	 * @return string SVG markup, or '' if the data could not be encoded.
	 */
	public static function svg( $data, $px = 220, $margin = 4 ) {
		$matrix = QrEncoder::matrix( (string) $data );
		if ( null === $matrix ) {
			return '';
		}

		$count = count( $matrix );
		$dim   = $count + $margin * 2;
		$rects = '';

		for ( $r = 0; $r < $count; $r++ ) {
			for ( $c = 0; $c < $count; $c++ ) {
				if ( $matrix[ $r ][ $c ] ) {
					$rects .= '<rect x="' . ( $c + $margin ) . '" y="' . ( $r + $margin ) . '" width="1" height="1"/>';
				}
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %2$d %2$d" shape-rendering="crispEdges" role="img" aria-label="%3$s"><rect width="%2$d" height="%2$d" fill="#fff"/><g fill="#000">%4$s</g></svg>',
			(int) $px,
			(int) $dim,
			esc_attr__( 'QR code', 'ndv-reviews' ),
			$rects
		);
	}
}
