<?php
/**
 * Read-side review querying (filters, sorting, pagination, enrichment).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Queries approved reviews for front-end display and attaches their criteria
 * scores, media, and meta.
 */
class ReviewQuery {

	/**
	 * Paginate reviews for a product with filters and sorting.
	 *
	 * @param array<string,mixed> $args {
	 *     @type int    $product_id Required.
	 *     @type int    $star       Filter by exact star (1-5), 0 for all.
	 *     @type bool   $verified   Only verified-buyer reviews.
	 *     @type bool   $with_media Only reviews with photos.
	 *     @type string $orderby    recent|helpful|highest|lowest.
	 *     @type int    $page       1-based page.
	 *     @type int    $per_page   Items per page.
	 * }
	 * @return array{items:array<int,array<string,mixed>>,total:int,pages:int,page:int}
	 */
	public function paginate( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'product_id' => 0,
				'star'       => 0,
				'verified'   => false,
				'with_media' => false,
				'orderby'    => 'recent',
				'page'       => 1,
				'per_page'   => 10,
			)
		);

		$product_id = absint( $args['product_id'] );
		$per_page   = max( 1, min( 50, (int) $args['per_page'] ) );
		$page       = max( 1, (int) $args['page'] );

		$query_args = array(
			'post_id'   => $product_id,
			'post_type' => PostTypes::all(), // Restrict to reviewable post types (excludes blog comments store-wide).
			'type__in'  => array( 'review', 'comment' ),
			'status'    => 'approve',
			'number'    => $per_page,
			'offset'    => ( $page - 1 ) * $per_page,
			'meta_query' => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'no_found_rows' => false,
		);

		// Topic/tag filter (S4): restrict to comments carrying a review tag term.
		if ( ! empty( $args['tag'] ) ) {
			$ids = ReviewTags::comment_ids_for_tag( $product_id, sanitize_title( $args['tag'] ) );
			if ( empty( $ids ) ) {
				return array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
					'page'  => $page,
				);
			}
			$query_args['comment__in'] = isset( $query_args['comment__in'] )
				? array_values( array_intersect( $query_args['comment__in'], $ids ) )
				: $ids;
		}

		// Filters.
		$star = (int) $args['star'];
		if ( $star >= 1 && $star <= 5 ) {
			$query_args['meta_query'][] = array(
				'key'     => 'rating',
				'value'   => $star,
				'compare' => '=',
				'type'    => 'NUMERIC',
			);
		}

		if ( ! empty( $args['verified'] ) ) {
			$query_args['meta_query'][] = array(
				'key'     => '_ndvr_verified',
				'value'   => '1',
				'compare' => '=',
			);
		}

		if ( ! empty( $args['with_media'] ) ) {
			$ids = $this->comment_ids_with_media( $product_id );
			if ( empty( $ids ) ) {
				return array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
					'page'  => $page,
				);
			}
			$query_args['comment__in'] = isset( $query_args['comment__in'] )
				? array_values( array_intersect( $query_args['comment__in'], $ids ) )
				: $ids;
		}

		/**
		 * Filter the review query args before they run (Pro extra filters).
		 *
		 * @param array<string,mixed> $query_args WP_Comment_Query args.
		 * @param array<string,mixed> $args       The normalized request args.
		 */
		$query_args = (array) apply_filters( 'ndv-reviews/review_query_args', $query_args, $args );

		// Sorting.
		switch ( $args['orderby'] ) {
			case 'helpful':
				$query_args['meta_key'] = '_ndvr_helpful_up'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$query_args['orderby']  = 'meta_value_num';
				$query_args['order']    = 'DESC';
				break;
			case 'highest':
				$query_args['meta_key'] = 'rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$query_args['orderby']  = 'meta_value_num';
				$query_args['order']    = 'DESC';
				break;
			case 'lowest':
				$query_args['meta_key'] = 'rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$query_args['orderby']  = 'meta_value_num';
				$query_args['order']    = 'ASC';
				break;
			case 'recent':
			default:
				$query_args['orderby'] = 'comment_date_gmt';
				$query_args['order']   = 'DESC';
				break;
		}

		$query    = new \WP_Comment_Query();
		$comments = $query->query( $query_args );

		// Total for pagination (separate count query honoring the same filters).
		$count_args           = $query_args;
		$count_args['count']  = true;
		$count_args['number'] = 0;
		$count_args['offset'] = 0;
		unset( $count_args['no_found_rows'] );
		$total = (int) ( new \WP_Comment_Query() )->query( $count_args );

		$items = array();
		foreach ( (array) $comments as $comment ) {
			$items[] = $this->to_view( $comment );
		}

		/**
		 * Filter the page of review view-models (Pro pins highlighted reviews).
		 *
		 * @param array<int,array<string,mixed>> $items Review view-models.
		 * @param array<string,mixed>            $args  Query args.
		 */
		$items = apply_filters( 'ndv-reviews/review_items', $items, $args );

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
			'page'  => $page,
		);
	}

	/**
	 * Build a display view-model from a comment.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return array<string,mixed>
	 */
	public function to_view( $comment ) {
		$id = (int) $comment->comment_ID;

		/**
		 * Filter a review's displayed author name (Pro anonymous reviews).
		 *
		 * @param string      $author  Author display name.
		 * @param \WP_Comment $comment The review comment.
		 */
		$author = (string) apply_filters( 'ndv-reviews/review_author', $comment->comment_author, $comment );

		return array(
			'id'         => $id,
			'author'     => $author,
			'date'       => $comment->comment_date,
			'content'    => $comment->comment_content,
			'title'      => (string) get_comment_meta( $id, '_ndvr_title', true ),
			'overall'    => (float) get_comment_meta( $id, '_ndvr_overall_rating', true ),
			'rating'     => (int) get_comment_meta( $id, 'rating', true ),
			'recommend'  => (string) get_comment_meta( $id, '_ndvr_recommend', true ),
			'verified'   => (bool) get_comment_meta( $id, '_ndvr_verified', true ),
			'helpful_up' => (int) get_comment_meta( $id, '_ndvr_helpful_up', true ),
			'criteria'   => $this->criteria_scores( $id ),
			'media'      => $this->media( $id ),
		);
	}

	/**
	 * Per-criterion scores for a review.
	 *
	 * @param int $comment_id Comment id.
	 * @return array<int,array{name:string,rating:float}>
	 */
	public function criteria_scores( $comment_id ) {
		global $wpdb;

		$rc       = Db::table( 'review_criteria' );
		$criteria = Db::table( 'criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cr.name AS name, rc.rating AS rating
				FROM {$rc} rc
				INNER JOIN {$criteria} cr ON cr.id = rc.criteria_id
				WHERE rc.comment_id = %d
				ORDER BY cr.position ASC",
				$comment_id
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'name'   => (string) $row->name,
				'rating' => (float) $row->rating,
			);
		}

		return $out;
	}

	/**
	 * Approved media for a review.
	 *
	 * @param int $comment_id Comment id.
	 * @return array<int,array{id:int,url:string,thumb:string}>
	 */
	public function media( $comment_id ) {
		global $wpdb;

		$table = Db::table( 'review_media' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT attachment_id, url FROM `{$table}` WHERE comment_id = %d AND status = 'approved' ORDER BY position ASC", $comment_id ) );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$id    = (int) $row->attachment_id;
			$thumb = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
			$full  = $id ? wp_get_attachment_image_url( $id, 'large' ) : (string) $row->url;
			$out[] = array(
				'id'    => $id,
				'url'   => $full ? $full : (string) $row->url,
				'thumb' => $thumb ? $thumb : (string) $row->url,
			);
		}

		return $out;
	}

	/**
	 * Comment ids (for a product) that have at least one approved media item.
	 *
	 * @param int $product_id Product id.
	 * @return int[]
	 */
	private function comment_ids_with_media( $product_id ) {
		global $wpdb;

		$media = Db::table( 'review_media' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.comment_id
				FROM {$media} m
				INNER JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id
				WHERE c.comment_post_ID = %d AND m.status = 'approved'",
				$product_id
			)
		);

		return array_map( 'absint', (array) $ids );
	}
}
