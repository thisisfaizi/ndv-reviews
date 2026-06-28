<?php
/**
 * Importer: backfill native WooCommerce reviews into NDV Reviews.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Importers;

use NdvReviews\Reviews\RatingCache;
use NdvReviews\Reviews\VerifiedBuyer;

defined( 'ABSPATH' ) || exit;

/**
 * Native Woo reviews are already comments — this just enriches them with our
 * meta (overall rating, helpful seed, verified, source) and recalculates
 * product aggregates. Idempotent: re-running skips already-imported reviews.
 */
class WooNative {

	/**
	 * Rating cache.
	 *
	 * @var RatingCache
	 */
	private $ratings;

	/**
	 * Verified-buyer helper.
	 *
	 * @var VerifiedBuyer
	 */
	private $verified;

	/**
	 * Constructor.
	 *
	 * @param RatingCache   $ratings  Rating cache.
	 * @param VerifiedBuyer $verified Verified-buyer helper.
	 */
	public function __construct( RatingCache $ratings, VerifiedBuyer $verified ) {
		$this->ratings  = $ratings;
		$this->verified = $verified;
	}

	/**
	 * Run the backfill.
	 *
	 * @param int $limit Max reviews to process this run.
	 * @return array{processed:int,products:int}
	 */
	public function run( $limit = 500 ) {
		$comments = get_comments(
			array(
				'type__in'  => array( 'review', 'comment' ),
				'post_type' => 'product',
				'status'    => 'approve',
				'number'    => max( 1, (int) $limit ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_ndvr_source',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$products = array();

		foreach ( (array) $comments as $comment ) {
			$id = (int) $comment->comment_ID;

			update_comment_meta( $id, '_ndvr_source', 'import' );

			if ( '' === (string) get_comment_meta( $id, '_ndvr_helpful_up', true ) ) {
				update_comment_meta( $id, '_ndvr_helpful_up', 0 );
			}

			$is_verified = $this->verified->is_verified( $comment->comment_author_email, (int) $comment->user_id, (int) $comment->comment_post_ID );
			update_comment_meta( $id, '_ndvr_verified', $is_verified ? 1 : 0 );

			$this->ratings->recalc_review( $id );
			$products[ (int) $comment->comment_post_ID ] = true;
		}

		foreach ( array_keys( $products ) as $product_id ) {
			$this->ratings->recalc_product( $product_id );
		}

		return array(
			'processed' => count( (array) $comments ),
			'products'  => count( $products ),
		);
	}
}
