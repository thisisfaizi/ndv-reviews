<?php
/**
 * Small presentational HTML helpers for review display.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable, pre-escaped markup snippets shared by templates and the renderer.
 */
class Html {

	/**
	 * Render a star rating (0-5, supports halves) as accessible markup.
	 *
	 * @param float $rating Rating value.
	 * @return string Escaped HTML.
	 */
	public static function stars( $rating ) {
		$rating = max( 0, min( 5, (float) $rating ) );
		$full   = (int) floor( $rating );
		$half   = ( $rating - $full ) >= 0.25 && ( $rating - $full ) < 0.75;
		if ( ( $rating - $full ) >= 0.75 ) {
			++$full;
		}

		$out = '<span class="ndvr-stars-display" role="img" aria-label="' . esc_attr(
			sprintf(
				/* translators: %s: rating out of 5. */
				__( 'Rated %s out of 5', 'ndv-reviews' ),
				number_format_i18n( $rating, 1 )
			)
		) . '">';

		for ( $i = 1; $i <= 5; $i++ ) {
			$class = 'ndvr-star-empty';
			if ( $i <= $full ) {
				$class = 'ndvr-star-full';
			} elseif ( $half && $i === $full + 1 ) {
				$class = 'ndvr-star-half';
			}
			$out .= '<span class="ndvr-star ' . esc_attr( $class ) . '"></span>';
		}

		$out .= '</span>';

		return $out;
	}
}
