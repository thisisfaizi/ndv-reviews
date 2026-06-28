<?php
/**
 * Reviewable post-type registry (S1).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves which post types accept reviews. Default is just `product`, so
 * behaviour is identical to before; the merchant can opt other post types in.
 */
class PostTypes {

	/**
	 * The reviewable post types.
	 *
	 * @return string[]
	 */
	public static function all() {
		$types = array( 'product' );

		$stored = get_option( NDVR_OPTION_SETTINGS, array() );
		if ( is_array( $stored ) && ! empty( $stored['reviewable_post_types'] ) && is_array( $stored['reviewable_post_types'] ) ) {
			$types = array_values( array_unique( array_merge( array( 'product' ), array_map( 'sanitize_key', $stored['reviewable_post_types'] ) ) ) );
		}

		/**
		 * Filter the reviewable post types.
		 *
		 * @param string[] $types Post types that accept reviews.
		 */
		$types = (array) apply_filters( 'ndv-reviews/reviewable_post_types', $types );

		return array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
	}

	/**
	 * Whether a post id belongs to a reviewable post type.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function is_reviewable( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return false;
		}

		return in_array( get_post_type( $post_id ), self::all(), true );
	}

	/**
	 * Whether the current singular view is a reviewable post.
	 *
	 * @return bool
	 */
	public static function is_singular_reviewable() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return true;
		}

		return is_singular( self::all() );
	}
}
