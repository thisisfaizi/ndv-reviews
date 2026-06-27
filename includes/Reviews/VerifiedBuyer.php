<?php
/**
 * Verified-buyer detection.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Determines whether a reviewer actually purchased the product, using
 * WooCommerce's own order-history lookup (HPOS-safe).
 */
class VerifiedBuyer {

	/**
	 * Whether the given user/email has bought the product.
	 *
	 * @param string $email      Reviewer email.
	 * @param int    $user_id    Reviewer user id (0 for guests).
	 * @param int    $product_id Product id.
	 * @return bool
	 */
	public function is_verified( $email, $user_id, $product_id ) {
		$email      = sanitize_email( (string) $email );
		$user_id    = absint( $user_id );
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return false;
		}

		if ( ! function_exists( 'wc_customer_bought_product' ) ) {
			return false;
		}

		$verified = wc_customer_bought_product( $email, $user_id, $product_id );

		/**
		 * Filter the verified-buyer result.
		 *
		 * @param bool   $verified   Whether the reviewer is a verified buyer.
		 * @param string $email      Reviewer email.
		 * @param int    $user_id    Reviewer user id.
		 * @param int    $product_id Product id.
		 */
		return (bool) apply_filters( 'ndv-reviews/is_verified_buyer', $verified, $email, $user_id, $product_id );
	}
}
