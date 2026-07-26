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
	 *     @type int    $product_id Required unless $category is set.
	 *     @type int|string $category  Product category (term_id or slug) — restricts to reviews for
	 *                                  products in this category. Ignored if $product_id is set.
	 *     @type int    $star       Filter by exact star (1-5), 0 for all.
	 *     @type float  $min_rating Filter by star >= this value (0 = no minimum). Takes effect at the
	 *                              DB level, before $per_page is applied, so a low-yield filter never
	 *                              starves a limited result set the way an in-PHP post-filter would.
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
				'category'   => 0,
				'star'       => 0,
				'min_rating' => 0,
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

		// Category filter: restrict to reviews on products in this product_cat term
		// (only meaningful when no single product_id is already set).
		if ( ! $product_id && ! empty( $args['category'] ) ) {
			$cat_product_ids = $this->product_ids_for_category( $args['category'] );
			if ( empty( $cat_product_ids ) ) {
				return array(
					'items' => array(),
					'total' => 0,
					'pages' => 0,
					'page'  => $page,
				);
			}
			$query_args['post__in'] = $cat_product_ids;
		}

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

		// Minimum-rating filter — applied in the DB query (meta_query), not as an
		// in-PHP post-filter, so it narrows the result set BEFORE $per_page cuts it
		// off. A post-filter would silently under-fill (or empty out) a small
		// $per_page window whenever the most-recent rows happened to fall short of
		// $min_rating, even though enough qualifying reviews existed further back.
		$min_rating = (float) $args['min_rating'];
		if ( $min_rating > 0 ) {
			$query_args['meta_query'][] = array(
				'key'     => '_ndvr_overall_rating',
				'value'   => $min_rating,
				'compare' => '>=',
				'type'    => 'DECIMAL(3,2)',
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

		// Batch-fetch criteria + media for the whole page (2 queries) instead of
		// per-review (2N queries) — avoids the N+1 that to_view() would otherwise
		// cause when called in a loop.
		$page_ids     = array_map( 'absint', wp_list_pluck( (array) $comments, 'comment_ID' ) );
		$criteria_map = $this->criteria_scores_bulk( $page_ids );
		$media_map    = $this->media_bulk( $page_ids );

		$items = array();
		foreach ( (array) $comments as $comment ) {
			$items[] = $this->to_view( $comment, $criteria_map, $media_map );
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
	 * @param \WP_Comment               $comment      Comment.
	 * @param array<int,array>|null $criteria_map Pre-fetched `comment_id => criteria rows` map from
	 *                                             criteria_scores_bulk() (avoids an N+1 query when
	 *                                             called from paginate()). Null falls back to a
	 *                                             per-comment query for standalone callers.
	 * @param array<int,array>|null $media_map    Same, from media_bulk().
	 * @return array<string,mixed>
	 */
	public function to_view( $comment, ?array $criteria_map = null, ?array $media_map = null ) {
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
			'criteria'   => null !== $criteria_map ? ( $criteria_map[ $id ] ?? array() ) : $this->criteria_scores( $id ),
			'media'      => null !== $media_map ? ( $media_map[ $id ] ?? array() ) : $this->media( $id ),
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
	 * Per-criterion scores for MULTIPLE reviews in one query (avoids N+1 in paginate()).
	 *
	 * @param int[] $comment_ids Comment ids.
	 * @return array<int,array<int,array{name:string,rating:float}>> comment_id => criteria rows.
	 */
	public function criteria_scores_bulk( array $comment_ids ) {
		$comment_ids = array_values( array_unique( array_map( 'absint', $comment_ids ) ) );
		if ( empty( $comment_ids ) ) {
			return array();
		}

		global $wpdb;
		$rc       = Db::table( 'review_criteria' );
		$criteria = Db::table( 'criteria' );

		$placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rc.comment_id AS comment_id, cr.name AS name, rc.rating AS rating
				FROM {$rc} rc
				INNER JOIN {$criteria} cr ON cr.id = rc.criteria_id
				WHERE rc.comment_id IN ({$placeholders})
				ORDER BY rc.comment_id ASC, cr.position ASC",
				$comment_ids
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->comment_id ][] = array(
				'name'   => (string) $row->name,
				'rating' => (float) $row->rating,
			);
		}

		return $out;
	}

	/**
	 * Approved media for MULTIPLE reviews in one query (avoids N+1 in paginate()).
	 *
	 * @param int[] $comment_ids Comment ids.
	 * @return array<int,array<int,array{id:int,url:string,thumb:string}>> comment_id => media rows.
	 */
	public function media_bulk( array $comment_ids ) {
		$comment_ids = array_values( array_unique( array_map( 'absint', $comment_ids ) ) );
		if ( empty( $comment_ids ) ) {
			return array();
		}

		global $wpdb;
		$table = Db::table( 'review_media' );

		$placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_id, attachment_id, url FROM `{$table}`
				WHERE comment_id IN ({$placeholders}) AND status = 'approved'
				ORDER BY comment_id ASC, position ASC",
				$comment_ids
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$id    = (int) $row->attachment_id;
			$thumb = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
			$full  = $id ? wp_get_attachment_image_url( $id, 'large' ) : (string) $row->url;
			$out[ (int) $row->comment_id ][] = array(
				'id'    => $id,
				'url'   => $full ? $full : (string) $row->url,
				'thumb' => $thumb ? $thumb : (string) $row->url,
			);
		}

		return $out;
	}

	/**
	 * Resolve a product_cat term (by id or slug) to its published product ids.
	 *
	 * @param int|string $category Term id or slug.
	 * @return int[]
	 */
	private function product_ids_for_category( $category ) {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'product_cat',
						'field'    => is_numeric( $category ) ? 'term_id' : 'slug',
						'terms'    => is_numeric( $category ) ? absint( $category ) : sanitize_title( $category ),
					),
				),
			)
		);

		return array_map( 'absint', (array) $ids );
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
