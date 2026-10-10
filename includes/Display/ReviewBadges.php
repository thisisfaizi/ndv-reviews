<?php
/**
 * Incentive disclosure pill on review cards (RR-00b E9).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Prints "Offered/Received an incentive for reviewing" on any review whose
 * `_ndvr_incentive_offered` meta is set, on full cards and marquee cards.
 *
 * It is a listener on the author-badge actions, not a template block, so it
 * survives theme overrides that keep those actions, and it stays when the
 * add-on that set the meta is removed: it discloses a material connection
 * (FTC 16 CFR 465, EU Omnibus). There is deliberately no switch to hide it.
 * Registered on every request (the "load more" list renders through
 * admin-ajax, where is_admin() is true).
 */
class ReviewBadges implements Registerable {

	/**
	 * Register hooks. Priority 5, before add-on badges at 10.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'ndv-reviews/review_author_badges', array( $this, 'incentive_pill' ), 5 );
		add_action( 'ndv-reviews/marquee_author_badges', array( $this, 'incentive_pill' ), 5 );
	}

	/**
	 * Print the pill when the review was incentivized.
	 *
	 * @param array<string,mixed> $review Review view-model.
	 * @return void
	 */
	public function incentive_pill( $review ) {
		$review = is_array( $review ) ? $review : array();
		$value  = isset( $review['incentive'] ) ? (string) $review['incentive'] : '';
		if ( '' === $value && ! empty( $review['id'] ) ) {
			$value = \NdvReviews\Reviews\ReviewQuery::incentive( (int) $review['id'] );
		}
		if ( '' === $value ) {
			return;
		}

		$default = 'received' === $value
			? __( 'Received an incentive for reviewing', 'rosette-reviews' )
			: __( 'Offered an incentive for reviewing', 'rosette-reviews' );

		/**
		 * Filter the incentive pill text. $review['incentive'] says which value
		 * it is ('offered' or 'received'). An empty or non-string result falls
		 * back to the default text, so the disclosure can't be removed.
		 *
		 * @param string              $label  Default text.
		 * @param array<string,mixed> $review Review view-model.
		 */
		$label = apply_filters( 'ndv-reviews/incentive_label', $default, $review );
		if ( ! is_string( $label ) || '' === trim( $label ) ) {
			$label = $default;
		}

		echo '<span class="ndvr-pill ndvr-incentive-badge">' . esc_html( $label ) . '</span>';
	}
}
