<?php
/**
 * Moderation side effects: aggregate recalculation + native Comments column.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Moderation;

use NdvReviews\Support\Registerable;
use NdvReviews\Reviews\Pool;
use NdvReviews\Reviews\RatingCache;
use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps product aggregates correct when a review's status changes (from our
 * screen or the native Comments screen) and adds a Rating column there.
 */
class Actions implements Registerable {

	/**
	 * Action Scheduler hook that removes a held (spam or trashed) review's uploads.
	 */
	const MEDIA_CLEANUP_HOOK = 'ndvr_media_cleanup';

	/**
	 * Option holding the held-media re-sweep position (last comment id seen).
	 */
	const RESWEEP_CURSOR_OPTION = 'ndv_reviews_media_resweep_cursor';

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
		add_action( 'transition_comment_status', array( $this, 'schedule_media_cleanup' ), 20, 3 );
		add_action( self::MEDIA_CLEANUP_HOOK, array( $this, 'cleanup_held_media' ), 10, 1 );
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
		$this->ratings->recalc_product( Pool::resolve_id( (int) $comment->comment_post_ID ) );
	}

	/**
	 * When a review moves to spam or trash, schedule the removal of its
	 * uploads after a retention period (RR-00b E3). Core purges trashed comments
	 * after EMPTY_TRASH_DAYS, but never purges spam, so spam uploads would
	 * otherwise stay forever.
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment.
	 * @return void
	 */
	public function schedule_media_cleanup( $new_status, $old_status, $comment ) {
		global $wpdb;

		unset( $old_status );
		if ( ! in_array( $new_status, array( 'spam', 'trash' ), true ) || ! $this->is_review( $comment ) ) {
			return;
		}

		// Nothing to clean up for a review without media.
		$media = Db::table( 'review_media' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Db::table().
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$media}` WHERE comment_id = %d LIMIT 1", (int) $comment->comment_ID ) ) ) {
			return;
		}

		self::queue_media_cleanup( (int) $comment->comment_ID );
	}

	/**
	 * Schedule one cleanup job for a review, unless one is already pending.
	 *
	 * @param int $comment_id Review comment id.
	 * @return void
	 */
	public static function queue_media_cleanup( $comment_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$args = array( 'comment_id' => absint( $comment_id ) );
		if ( as_has_scheduled_action( self::MEDIA_CLEANUP_HOOK, $args, 'ndv-reviews' ) ) {
			return;
		}

		as_schedule_single_action( time() + DAY_IN_SECONDS * self::held_media_days(), self::MEDIA_CLEANUP_HOOK, $args, 'ndv-reviews' );
	}

	/**
	 * Days a spam or trashed review keeps its uploads.
	 *
	 * @return int
	 */
	public static function held_media_days() {
		/**
		 * Filter how many days the uploads of a spam or trashed review are kept.
		 *
		 * @param int $days Default 7.
		 */
		return max( 0, (int) apply_filters( 'ndv-reviews/held_media_days', 7 ) );
	}

	/**
	 * Action Scheduler job: delete a review's uploads if it is still spam or
	 * trashed. A restored review keeps them.
	 *
	 * @param int $comment_id Review comment id.
	 * @return void
	 */
	public function cleanup_held_media( $comment_id ) {
		global $wpdb;

		$comment_id = absint( $comment_id );
		$status     = $comment_id ? wp_get_comment_status( $comment_id ) : false;
		if ( ! in_array( $status, array( 'spam', 'trash' ), true ) ) {
			return;
		}

		$media = Db::table( 'review_media' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attachment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM `{$media}` WHERE comment_id = %d", $comment_id ) );
		$wpdb->delete( $media, array( 'comment_id' => $comment_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( (array) $attachment_ids as $attachment_id ) {
			self::delete_photo_if_unused( (int) $attachment_id, $comment_id );
		}
	}

	/**
	 * Re-create missing cleanup jobs for held reviews that still have media
	 * (deactivation removes the jobs). Called by the hourly recovery job.
	 *
	 * @param int $limit Max reviews per run.
	 * @return int Jobs scheduled.
	 */
	public static function resweep_held_media( $limit = 200 ) {
		global $wpdb;

		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return 0;
		}

		$media  = Db::table( 'review_media' );
		$limit  = max( 1, (int) $limit );
		$cursor = (int) get_option( self::RESWEEP_CURSOR_OPTION, 0 );

		// A cursor walks through every held review over successive runs, so a
		// store with more than $limit of them is still fully covered.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Db::table().
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.comment_id FROM `{$media}` m
				INNER JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id
				WHERE c.comment_approved IN ('spam','trash') AND m.comment_id > %d
				ORDER BY m.comment_id ASC LIMIT %d",
				$cursor,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$ids = array_map( 'intval', (array) $ids );
		update_option( self::RESWEEP_CURSOR_OPTION, count( $ids ) < $limit ? 0 : (int) end( $ids ), false );

		$scheduled = 0;
		foreach ( (array) $ids as $id ) {
			$args = array( 'comment_id' => (int) $id );
			if ( ! as_has_scheduled_action( self::MEDIA_CLEANUP_HOOK, $args, 'ndv-reviews' ) ) {
				self::queue_media_cleanup( (int) $id );
				++$scheduled;
			}
		}

		return $scheduled;
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
		$columns['ndvr_rating'] = __( 'Rating', 'rosette-reviews' );

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
