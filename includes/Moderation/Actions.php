<?php
/**
 * Moderation side effects: aggregate recalculation + native Comments column.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Moderation;

use NdvReviews\Support\Registerable;
use NdvReviews\Reviews\RatingCache;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps product aggregates correct when a review's status changes (from our
 * screen or the native Comments screen) and adds a Rating column there.
 */
class Actions implements Registerable {

	/**
	 * Rating cache.
	 *
	 * @var RatingCache
	 */
	private $ratings;

	/**
	 * Constructor.
	 *
	 * @param RatingCache $ratings Rating cache.
	 */
	public function __construct( RatingCache $ratings ) {
		$this->ratings = $ratings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'transition_comment_status', array( $this, 'on_status_change' ), 10, 3 );
		add_filter( 'manage_edit-comments_columns', array( $this, 'add_rating_column' ) );
		add_action( 'manage_comments_custom_column', array( $this, 'render_rating_column' ), 10, 2 );
	}

	/**
	 * Recalculate the product aggregate whenever a review changes status.
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment.
	 * @return void
	 */
	public function on_status_change( $new_status, $old_status, $comment ) {
		if ( ! $this->is_review( $comment ) ) {
			return;
		}

		$this->ratings->recalc_review( (int) $comment->comment_ID );
		$this->ratings->recalc_product( (int) $comment->comment_post_ID );
	}

	/**
	 * Add a Rating column to the native Comments list table.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_rating_column( $columns ) {
		$columns['ndvr_rating'] = __( 'Rating', 'ndv-reviews' );

		return $columns;
	}

	/**
	 * Render the Rating column value.
	 *
	 * @param string $column     Column id.
	 * @param int    $comment_id Comment id.
	 * @return void
	 */
	public function render_rating_column( $column, $comment_id ) {
		if ( 'ndvr_rating' !== $column ) {
			return;
		}

		$rating = (float) get_comment_meta( $comment_id, '_ndvr_overall_rating', true );
		if ( $rating <= 0 ) {
			$rating = (float) get_comment_meta( $comment_id, 'rating', true );
		}

		echo $rating > 0 ? esc_html( number_format_i18n( $rating, 1 ) . ' / 5' ) : '&mdash;';
	}

	/**
	 * Whether a comment is one of our reviews.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return bool
	 */
	private function is_review( $comment ) {
		if ( ! $comment ) {
			return false;
		}

		$type = (string) $comment->comment_type;
		if ( 'review' === $type ) {
			return true;
		}

		// Native Woo reviews use comment_type 'review'; some legacy ones use 'comment'
		// on products. Treat a comment on a product with a rating as a review.
		return 'comment' === $type
			&& 'product' === get_post_type( $comment->comment_post_ID )
			&& '' !== (string) get_comment_meta( $comment->comment_ID, 'rating', true );
	}
}
