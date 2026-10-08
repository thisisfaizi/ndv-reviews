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

		/**
		 * Filter the rendered star markup (Pro swaps in hearts/emoji/thumbs styles).
		 *
		 * @param string $out    Star HTML.
		 * @param float  $rating Rating value.
		 */
		return (string) apply_filters( 'ndv-reviews/stars_html', $out, $rating );
	}

	/**
	 * Initials avatar: a coloured circle with the author's first letter.
	 *
	 * Used instead of get_avatar() because reviews rarely carry a Gravatar —
	 * every card would show the same grey silhouette — and because a Gravatar
	 * lookup is an external request (the free plugin makes none by default).
	 * The colour is derived from the name so it is stable across renders; each
	 * palette entry keeps white text at >= 4.5:1.
	 *
	 * @param string $name  Display name.
	 * @param string $class Extra class (e.g. the marquee's sizing class).
	 * @return string Escaped HTML.
	 */
	public static function avatar( $name, $class = '' ) {
		$palette = array( '#0f7d5b', '#2563eb', '#7c3aed', '#be185d', '#c2410c', '#0e7490', '#4f46e5', '#b45309' );
		$name    = trim( (string) $name );
		$color   = $palette[ abs( crc32( $name ) ) % count( $palette ) ];
		$initial = '' !== $name ? ( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 ) ) : '?';

		return sprintf(
			'<span class="%1$s" style="background:%2$s" aria-hidden="true">%3$s</span>',
			esc_attr( trim( 'ndvr-avatar ' . $class ) ),
			esc_attr( $color ),
			esc_html( $initial )
		);
	}
}
