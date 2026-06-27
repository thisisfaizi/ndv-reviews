<?php
/**
 * Helpful voting on reviews.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Records one "helpful" vote per user/IP per review and caches the count.
 */
class Votes implements Registerable {

	const NONCE_ACTION = 'ndvr_vote';
	const AJAX_ACTION  = 'ndvr_vote';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle' ) );
	}

	/**
	 * AJAX handler: record a helpful vote.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired.', 'ndv-reviews' ) ), 403 );
		}

		$comment_id = isset( $_POST['comment_id'] ) ? absint( wp_unslash( $_POST['comment_id'] ) ) : 0;
		$comment    = get_comment( $comment_id );

		if ( ! $comment || '1' !== (string) $comment->comment_approved ) {
			wp_send_json_error( array( 'message' => __( 'Review not found.', 'ndv-reviews' ) ), 404 );
		}

		$result = $this->vote( $comment_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 );
		}

		wp_send_json_success(
			array(
				'count'   => $result,
				'message' => __( 'Thanks for your feedback!', 'ndv-reviews' ),
			)
		);
	}

	/**
	 * Record a vote and return the new helpful count.
	 *
	 * @param int $comment_id Comment id.
	 * @return int|\WP_Error
	 */
	public function vote( $comment_id ) {
		global $wpdb;

		$user_id = get_current_user_id();
		$ip_hash = $user_id ? null : $this->ip_hash();
		$table   = Db::table( 'review_votes' );

		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (comment_id, user_id, ip_hash, vote, created_at) VALUES (%d, %d, %s, 1, %s)",
				$comment_id,
				$user_id,
				null === $ip_hash ? '' : $ip_hash,
				current_time( 'mysql' )
			)
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'ndvr_already_voted', __( 'You have already marked this review as helpful.', 'ndv-reviews' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE comment_id = %d AND vote = 1", $comment_id ) );

		update_comment_meta( $comment_id, '_ndvr_helpful_up', $count );

		return $count;
	}

	/**
	 * Salted hash of the visitor IP (never stored raw).
	 *
	 * @return string
	 */
	private function ip_hash() {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return wp_hash( $ip );
	}
}
