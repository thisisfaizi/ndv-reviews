<?php
/**
 * Review source allowlist (RR-00 F1).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Which review sources are written by a customer through one of our forms.
 *
 * Every customer-facing rule (editing, reporting, recovery, incentives) asks
 * this class. Never use a denylist of "import" sources: reviews created outside
 * ReviewRepository::create() (store-written or synced from another site) carry
 * other source tags and must never count as customer-written.
 */
class Sources {

	/**
	 * Sources written by a customer through one of our forms.
	 *
	 * - onsite:     the product review form.
	 * - form:       a standalone collection form ([ndvr-form]).
	 * - magic_link: the emailed review link (order or customer token).
	 * - list_link:  a review link from an uploaded customer list.
	 *
	 * @return string[]
	 */
	public static function interactive() {
		$sources = array( 'onsite', 'form', 'magic_link', 'list_link' );

		/**
		 * Filter the review sources that count as customer-written.
		 *
		 * @param string[] $sources Source slugs (`_ndvr_source` values).
		 */
		$filtered = apply_filters( 'ndv-reviews/interactive_sources', $sources );

		return is_array( $filtered ) ? array_values( array_unique( array_map( 'sanitize_key', $filtered ) ) ) : $sources;
	}

	/**
	 * Whether a source slug is customer-written.
	 *
	 * @param string $source Source slug.
	 * @return bool
	 */
	public static function is_interactive( $source ) {
		return is_string( $source ) && '' !== $source && in_array( $source, self::interactive(), true );
	}

	/**
	 * Whether the customer who wrote a review may change it: the source is
	 * customer-written and the review isn't synced from an external site.
	 *
	 * @param int $comment_id Review comment id.
	 * @return bool
	 */
	public static function is_customer_editable( $comment_id ) {
		$comment_id = absint( $comment_id );
		if ( ! $comment_id ) {
			return false;
		}

		if ( '' !== (string) get_comment_meta( $comment_id, '_ndvr_external_id', true ) ) {
			return false;
		}

		return self::is_interactive( (string) get_comment_meta( $comment_id, '_ndvr_source', true ) );
	}
}
