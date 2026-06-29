<?php
/**
 * Review topic/tag substrate (S4).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Stores topic tags on reviews (as repeated comment meta so each tag is
 * independently queryable). Free assigns tags manually in moderation and shows
 * storefront filter pills; Pro AI "smart topics" writes into the SAME store.
 */
class ReviewTags {

	const META = '_ndvr_tag';

	/**
	 * Replace a review's tags.
	 *
	 * @param int      $comment_id Review comment id.
	 * @param string[] $tags       Tag labels/slugs.
	 * @return void
	 */
	public static function set( $comment_id, array $tags ) {
		$comment_id = absint( $comment_id );
		delete_comment_meta( $comment_id, self::META );

		$seen = array();
		foreach ( $tags as $tag ) {
			$slug = sanitize_title( $tag );
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			add_comment_meta( $comment_id, self::META, $slug );
		}
	}

	/**
	 * Add a single tag to a review (used by Pro AI auto-tagging).
	 *
	 * @param int    $comment_id Review comment id.
	 * @param string $tag        Tag.
	 * @return void
	 */
	public static function add( $comment_id, $tag ) {
		$slug = sanitize_title( $tag );
		if ( '' === $slug ) {
			return;
		}
		$existing = (array) get_comment_meta( absint( $comment_id ), self::META, false );
		if ( ! in_array( $slug, $existing, true ) ) {
			add_comment_meta( absint( $comment_id ), self::META, $slug );
		}
	}

	/**
	 * Get a review's tags.
	 *
	 * @param int $comment_id Review comment id.
	 * @return string[]
	 */
	public static function get( $comment_id ) {
		return array_values( array_filter( (array) get_comment_meta( absint( $comment_id ), self::META, false ) ) );
	}

	/**
	 * Distinct tags (with counts) across a post's approved reviews.
	 *
	 * @param int $post_id Post id (0 = store-wide).
	 * @return array<string,int> slug => count.
	 */
	public static function for_post( $post_id ) {
		global $wpdb;

		$post_id = absint( $post_id );

		if ( $post_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT cm.meta_value AS tag, COUNT(*) AS total
					FROM {$wpdb->commentmeta} cm
					INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
					WHERE cm.meta_key = '_ndvr_tag' AND c.comment_approved = '1' AND c.comment_post_ID = %d
					GROUP BY cm.meta_value ORDER BY total DESC",
					$post_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				"SELECT cm.meta_value AS tag, COUNT(*) AS total
				FROM {$wpdb->commentmeta} cm
				INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
				WHERE cm.meta_key = '_ndvr_tag' AND c.comment_approved = '1'
				GROUP BY cm.meta_value ORDER BY total DESC"
			);
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->tag ] = (int) $row->total;
		}

		return $out;
	}

	/**
	 * Approved comment ids carrying a tag (optionally within a post).
	 *
	 * @param int    $post_id Post id (0 = any).
	 * @param string $tag     Tag slug.
	 * @return int[]
	 */
	public static function comment_ids_for_tag( $post_id, $tag ) {
		global $wpdb;

		$post_id = absint( $post_id );
		$tag     = sanitize_title( $tag );
		if ( '' === $tag ) {
			return array();
		}

		if ( $post_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT cm.comment_id
					FROM {$wpdb->commentmeta} cm
					INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
					WHERE cm.meta_key = '_ndvr_tag' AND cm.meta_value = %s AND c.comment_approved = '1' AND c.comment_post_ID = %d",
					$tag,
					$post_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT cm.comment_id
					FROM {$wpdb->commentmeta} cm
					INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
					WHERE cm.meta_key = '_ndvr_tag' AND cm.meta_value = %s AND c.comment_approved = '1'",
					$tag
				)
			);
		}

		return array_map( 'absint', (array) $ids );
	}
}
