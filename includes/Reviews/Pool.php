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
 * arbitrary product groups onto a parent pool via the filter, and because
 * every aggregate read/write funnels through here, pooling stays consistent.
 *
 * Invariant: resolve_id( resolve_id( $x ) ) === resolve_id( $x ). A pool post
 * is never itself mapped to another post, so callers may resolve an id that is
 * already resolved.
 */
class Pool {

	/**
	 * Comment meta: the top-level product a review was written for, stored only
	 * when the review lives on a different (pool) post (RR-00b E5).
	 */
	const POOLED_FROM_META = '_ndvr_pooled_from';

	/**
	 * Resolve the pool id for a post.
	 *
	 * @param int $post_id Post id being reviewed/displayed.
	 * @return int Pool id (defaults to the same post).
	 */
	public static function resolve_id( $post_id ) {
		$post_id = absint( $post_id );

		/**
		 * Filter the pool id a review aggregates into. A pool post must never be
		 * mapped onto another post (resolving twice must give the same id).
		 *
		 * @param int $pool_id Default: the post itself.
		 * @param int $post_id The original post id.
		 */
		$pool_id = (int) apply_filters( 'ndv-reviews/review_pool_id', $post_id, $post_id );

		return $pool_id > 0 ? $pool_id : $post_id;
	}

	/**
	 * The top-level product a review is "for": the parent of a variation,
	 * otherwise the post itself.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	public static function origin_id( $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id && 'product_variation' === get_post_type( $post_id ) ) {
			$parent = (int) wp_get_post_parent_id( $post_id );
			return $parent > 0 ? $parent : $post_id;
		}

		return $post_id;
	}

	/**
	 * Reviews stored on a pool post although their origin product no longer
	 * maps to any pool (the products stopped sharing reviews). Reviews whose
	 * origin was deleted, or isn't reviewable, stay where they are.
	 *
	 * @param int $limit  Max ids.
	 * @param int $origin Restrict to one origin product (0 = any).
	 * @return int[] Comment ids.
	 */
	public static function orphans( $limit = 200, $origin = 0 ) {
		global $wpdb;

		$limit  = max( 1, (int) $limit );
		$origin = absint( $origin );

		// 1. The origin products, filtered in PHP (few rows): only those that
		// still exist, take reviews, and no longer map to any pool.
		if ( $origin ) {
			$candidates = array( $origin );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$candidates = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->commentmeta} WHERE meta_key = %s", self::POOLED_FROM_META ) );
		}

		$origins = array();
		foreach ( (array) $candidates as $from ) {
			$from = absint( $from );
			if ( $from && PostTypes::is_reviewable( $from ) && self::resolve_id( $from ) === $from ) {
				$origins[] = (string) $from;
			}
		}
		if ( ! $origins ) {
			return array();
		}

		// 2. Their reviews that sit on another post, with the limit in SQL.
		$placeholders = implode( ', ', array_fill( 0, count( $origins ), '%s' ) );
		$params       = array_merge( array( self::POOLED_FROM_META ), $origins, array( $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per origin.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT c.comment_ID
				FROM {$wpdb->commentmeta} cm
				INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
				WHERE cm.meta_key = %s AND cm.meta_value IN ( {$placeholders} ) AND c.comment_post_ID <> cm.meta_value
				ORDER BY c.comment_ID ASC LIMIT %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Move reviews back to the product they were written for, with every reply
	 * below them, then recount both posts.
	 *
	 * @param int[] $ids Review comment ids (from orphans()).
	 * @return int Top-level reviews moved.
	 */
	public static function restore( array $ids ) {
		global $wpdb;

		$moved   = 0;
		$touched = array();

		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$comment = $id ? get_comment( $id ) : null;
			$origin  = $comment ? absint( get_comment_meta( $id, self::POOLED_FROM_META, true ) ) : 0;
			if ( ! $comment || ! $origin || (int) $comment->comment_post_ID === $origin ) {
				continue;
			}

			$pool_post = (int) $comment->comment_post_ID;
			$family    = array_merge( array( $id ), self::descendants( array( $id ), $pool_post ) );

			foreach ( $family as $member ) {
				$wpdb->update( $wpdb->comments, array( 'comment_post_ID' => $origin ), array( 'comment_ID' => $member ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_comment_cache( $member );
			}
			delete_comment_meta( $id, self::POOLED_FROM_META );

			$touched[ $pool_post ] = true;
			$touched[ $origin ]    = true;
			++$moved;
		}

		if ( $touched ) {
			$ratings = \NdvReviews\Plugin::instance()->container()->get( 'rating_cache' );
			foreach ( array_keys( $touched ) as $post_id ) {
				wp_update_comment_count( $post_id );
				$ratings->recalc_product( $post_id );
			}
		}

		return $moved;
	}

	/**
	 * Every reply below the given comments on a post, level by level (at most
	 * 10 levels, core's maximum thread depth).
	 *
	 * @param int[] $parents Comment ids.
	 * @param int   $post_id Post they live on.
	 * @return int[]
	 */
	private static function descendants( array $parents, $post_id ) {
		$all = array();
		for ( $level = 0; $level < 10 && $parents; $level++ ) {
			$children = get_comments(
				array(
					'parent__in' => $parents,
					'post_id'    => (int) $post_id,
					'status'     => array( 'all', 'spam', 'trash' ), // Held, spam and trashed replies move too.
					'type'       => '',
					'fields'     => 'ids',
				)
			);
			$children = array_values( array_diff( array_map( 'absint', (array) $children ), $all ) );
			$all      = array_merge( $all, $children );
			$parents  = $children;
		}

		return $all;
	}
}
