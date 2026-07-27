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
	 * `wc_customer_bought_product()` matches on billing EMAIL as well as user
	 * id — for `onsite`/`form` sources the email is free text the reviewer
	 * typed themselves, so anyone who knows (or guesses/leaks) a real buyer's
	 * email could earn a "Verified buyer" badge with no purchase of their own.
	 * Sources where the email instead came from an actual order record — the
	 * magic-link token flow, an admin-entered review, or an import — aren't
	 * spoofable this way, so they keep the original email-or-user_id check.
	 *
	 * @param string $email      Reviewer email.
	 * @param int    $user_id    Reviewer user id (0 for guests).
	 * @param int    $product_id Product id.
	 * @param string $source     Review source tag (onsite|form|magic_link|admin|import|...).
	 * @return bool
	 */
	public function is_verified( $email, $user_id, $product_id, $source = '' ) {
		$email      = sanitize_email( (string) $email );
		$user_id    = absint( $user_id );
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return false;
		}

		if ( ! function_exists( 'wc_customer_bought_product' ) ) {
			return false;
		}

		if ( in_array( $source, array( 'onsite', 'form' ), true ) ) {
			if ( ! $user_id ) {
				// Anonymous/guest self-reported email: nothing here is trustworthy.
				return false;
			}
			// Logged in: check the account's own order history only — never the
			// free-text email, which could belong to someone else entirely.
			$email = '';
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
