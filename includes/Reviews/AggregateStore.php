<?php
/**
 * Aggregate-rating store (S2).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for a post's aggregate rating. For WooCommerce
 * products it delegates to the native `_wc_*` meta (so existing behaviour and
 * schema stay byte-identical); for other post types it uses `_ndvr_*` meta.
 * Every aggregate read and write in the plugin funnels through here.
 */
class AggregateStore {

	/**
	 * Read a post's aggregate.
	 *
	 * @param int $post_id Post id.
	 * @return array{average:float,count:int,counts:array<int,int>}
	 */
	public static function get( $post_id ) {
		$post_id = absint( $post_id );

		if ( self::is_product( $post_id ) ) {
			$counts = get_post_meta( $post_id, '_wc_rating_count', true );
			$data   = array(
				'average' => (float) get_post_meta( $post_id, '_wc_average_rating', true ),
				'count'   => (int) get_post_meta( $post_id, '_wc_review_count', true ),
				'counts'  => is_array( $counts ) ? array_map( 'intval', $counts ) : array(),
			);
		} else {
			$counts = get_post_meta( $post_id, '_ndvr_rating_count', true );
			$data   = array(
				'average' => (float) get_post_meta( $post_id, '_ndvr_average_rating', true ),
				'count'   => (int) get_post_meta( $post_id, '_ndvr_review_count', true ),
				'counts'  => is_array( $counts ) ? array_map( 'intval', $counts ) : array(),
			);
		}

		$data['counts'] = array_replace(
			array(
				5 => 0,
				4 => 0,
				3 => 0,
				2 => 0,
				1 => 0,
			),
			$data['counts']
		);

		/**
		 * Filter a post's aggregate rating after it is read.
		 *
		 * @param array $data    {average,count,counts}.
		 * @param int   $post_id Post id.
		 */
		return (array) apply_filters( 'ndv-reviews/aggregate', $data, $post_id );
	}

	/**
	 * Write a post's aggregate.
	 *
	 * @param int                                                     $post_id Post id.
	 * @param array{average:float,count:int,counts:array<int,int>}    $data    Aggregate.
	 * @return void
	 */
	public static function set( $post_id, array $data ) {
		$post_id = absint( $post_id );
		$average = isset( $data['average'] ) ? round( (float) $data['average'], 2 ) : 0;
		$count   = isset( $data['count'] ) ? (int) $data['count'] : 0;
		$counts  = isset( $data['counts'] ) && is_array( $data['counts'] ) ? array_map( 'intval', $data['counts'] ) : array();

		if ( self::is_product( $post_id ) ) {
			update_post_meta( $post_id, '_wc_rating_count', $counts );
			update_post_meta( $post_id, '_wc_average_rating', $average );
			update_post_meta( $post_id, '_wc_review_count', $count );

			if ( class_exists( '\WC_Comments' ) ) {
				\WC_Comments::clear_transients( $post_id );
			}
		} else {
			update_post_meta( $post_id, '_ndvr_rating_count', $counts );
			update_post_meta( $post_id, '_ndvr_average_rating', $average );
			update_post_meta( $post_id, '_ndvr_review_count', $count );
		}
	}

	/**
	 * Whether a post is a WooCommerce product.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private static function is_product( $post_id ) {
		return 'product' === get_post_type( $post_id );
	}
}
