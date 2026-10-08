<?php
/**
 * Moderation side effects: aggregate recalculation + native Comments column.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Moderation;

use NdvReviews\Support\Registerable;
use NdvReviews\Reviews\RatingCache;
use NdvReviews\Support\Db;

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
		add_action( 'delete_comment', array( $this, 'on_delete' ), 10, 2 );
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

		// 'delete' fires after the row is gone: only the product needs updating,
		// recalculating the review would write meta for a comment that no longer exists.
		if ( 'delete' !== $new_status ) {
			$this->ratings->recalc_review( (int) $comment->comment_ID );
		}
		$this->ratings->recalc_product( (int) $comment->comment_post_ID );
	}

	/**
	 * Remove a permanently deleted review's scores, votes, and photos.
	 *
	 * Runs on `delete_comment` (before core removes the comment's meta) so the
	 * admin-created flag is still readable.
	 *
	 * @param int         $comment_id Comment id.
	 * @param \WP_Comment $comment    Comment.
	 * @return void
	 */
	public function on_delete( $comment_id, $comment = null ) {
		global $wpdb;

		$comment_id = absint( $comment_id );
		$media      = Db::table( 'review_media' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attachment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM `{$media}` WHERE comment_id = %d", $comment_id ) );

		$wpdb->delete( Db::table( 'review_criteria' ), array( 'comment_id' => $comment_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Db::table( 'review_votes' ), array( 'comment_id' => $comment_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $media, array( 'comment_id' => $comment_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( (array) $attachment_ids as $attachment_id ) {
			self::delete_photo_if_unused( (int) $attachment_id, $comment_id );
		}
	}

	/**
	 * Delete a review photo's attachment once no review references it.
	 *
	 * Call after removing the review_media row. Photos on reviews added through
	 * Pro's Manual Reviews screen are existing media-library items picked by an
	 * admin, so they are never deleted.
	 *
	 * @param int $attachment_id Attachment id.
	 * @param int $comment_id    Review the photo was removed from.
	 * @return void
	 */
	public static function delete_photo_if_unused( $attachment_id, $comment_id ) {
		global $wpdb;

		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return;
		}
		if ( get_comment_meta( $comment_id, '_ndvr_admin_created', true ) ) {
			return;
		}

		$media = Db::table( 'review_media' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$still_used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$media}` WHERE attachment_id = %d", $attachment_id ) );
		if ( $still_used > 0 ) {
			return;
		}

		wp_delete_attachment( $attachment_id, true );
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
