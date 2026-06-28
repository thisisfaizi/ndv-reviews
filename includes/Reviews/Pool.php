<?php
/**
 * Review pooling resolver (S3).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the post a review's aggregate belongs to. Default is identity (the
 * post reviews itself). Pro maps variations / grouped / bundle children and
 * arbitrary product groups onto a parent pool via the filter — and because
 * every aggregate read/write funnels through here, pooling stays consistent.
 */
class Pool {

	/**
	 * Resolve the pool id for a post.
	 *
	 * @param int $post_id Post id being reviewed/displayed.
	 * @return int Pool id (defaults to the same post).
	 */
	public static function resolve_id( $post_id ) {
		$post_id = absint( $post_id );

		/**
		 * Filter the pool id a review aggregates into.
		 *
		 * @param int $pool_id Default: the post itself.
		 * @param int $post_id The original post id.
		 */
		$pool_id = (int) apply_filters( 'ndv-reviews/review_pool_id', $post_id, $post_id );

		return $pool_id > 0 ? $pool_id : $post_id;
	}
}
