<?php
/**
 * Rating aggregation + caching.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Computes per-review overall ratings and product-level aggregates, keeping
 * WooCommerce's native rating meta in sync so schema and the native UI work.
 */
class RatingCache {

	/**
	 * Recompute and cache a single review's overall rating from its criteria.
	 *
	 * Stores the precise decimal in `_ndvr_overall_rating` and a WooCommerce-
	 * compatible integer in the native `rating` comment meta.
	 *
	 * @param int $comment_id Review comment id.
	 * @return float The decimal overall rating (0 if none).
	 */
	public function recalc_review( $comment_id ) {
		global $wpdb;

		$comment_id = absint( $comment_id );
		$table      = Db::table( 'review_criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(rating) FROM `{$table}` WHERE comment_id = %d", $comment_id ) );

		if ( null === $avg ) {
			// No criteria scores: fall back to any existing native rating meta.
			$avg = (float) get_comment_meta( $comment_id, 'rating', true );
		}

		$decimal = round( (float) $avg, 2 );
		$integer = (int) max( 1, min( 5, round( $decimal ) ) );

		update_comment_meta( $comment_id, '_ndvr_overall_rating', $decimal );
		if ( $decimal > 0 ) {
			update_comment_meta( $comment_id, 'rating', $integer );
		}

		return $decimal;
	}

	/**
	 * Recompute a product's aggregate rating from its approved reviews and
	 * write the WooCommerce lookup meta (average, counts, review count).
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	public function recalc_product( $product_id ) {
		global $wpdb;

		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return;
		}

		// Distribution of integer ratings across approved review comments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS rating, COUNT(*) AS total
				FROM {$wpdb->commentmeta} cm
				INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
				WHERE cm.meta_key = 'rating'
				AND c.comment_post_ID = %d
				AND c.comment_approved = '1'
				AND c.comment_type IN ( 'review', 'comment' )
				GROUP BY meta_value",
				$product_id
			)
		);

		$counts = array(
			1 => 0,
			2 => 0,
			3 => 0,
			4 => 0,
			5 => 0,
		);
		$sum    = 0;
		$total  = 0;

		foreach ( (array) $results as $row ) {
			$star = (int) $row->rating;
			if ( $star < 1 || $star > 5 ) {
				continue;
			}
			$counts[ $star ] += (int) $row->total;
			$sum             += $star * (int) $row->total;
			$total           += (int) $row->total;
		}

		$average = $total > 0 ? round( $sum / $total, 2 ) : 0;

		// Write through the aggregate store: products keep the native `_wc_*`
		// meta (byte-identical); other post types use `_ndvr_*` meta.
		AggregateStore::set(
			$product_id,
			array(
				'average' => $average,
				'count'   => $total,
				'counts'  => $counts,
			)
		);
	}
}
