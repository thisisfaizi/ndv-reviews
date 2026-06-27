<?php
/**
 * Review repository — creates reviews as comments plus custom-table extras.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Persists reviews. A review is a WordPress comment (comment_type 'review',
 * matching native WooCommerce) with criteria scores, media, and meta stored in
 * our custom tables.
 */
class ReviewRepository {

	/**
	 * Rating cache helper.
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
	 * Criteria repository.
	 *
	 * @var CriteriaRepository
	 */
	private $criteria;

	/**
	 * Constructor.
	 *
	 * @param RatingCache        $ratings  Rating cache.
	 * @param VerifiedBuyer      $verified Verified-buyer helper.
	 * @param CriteriaRepository $criteria Criteria repository.
	 */
	public function __construct( RatingCache $ratings, VerifiedBuyer $verified, CriteriaRepository $criteria ) {
		$this->ratings  = $ratings;
		$this->verified = $verified;
		$this->criteria = $criteria;
	}

	/**
	 * Create a review.
	 *
	 * @param array<string,mixed> $data {
	 *     Review data.
	 *
	 *     @type int                 $product_id Required. Product being reviewed.
	 *     @type string              $author     Reviewer display name.
	 *     @type string              $email      Reviewer email.
	 *     @type string              $content    Review body.
	 *     @type string              $title      Optional review title.
	 *     @type string              $recommend  yes|neutral|no.
	 *     @type array<int,float>    $criteria   Map of criteria_id => rating (0.5-5).
	 *     @type int[]               $media      Attachment ids for photos.
	 *     @type int                 $user_id    Reviewer user id (0 guest).
	 *     @type string              $source     Source tag (onsite|qr|form|...).
	 *     @type int                 $order_id   Optional originating order id.
	 *     @type bool                $approved   Whether to approve immediately.
	 * }
	 * @return int|\WP_Error New comment id, or WP_Error on failure.
	 */
	public function create( array $data ) {
		$product_id = isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0;
		if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			return new \WP_Error( 'ndvr_invalid_product', __( 'Invalid product.', 'ndv-reviews' ) );
		}

		$content = isset( $data['content'] ) ? trim( wp_kses_post( $data['content'] ) ) : '';
		if ( '' === $content ) {
			return new \WP_Error( 'ndvr_empty_content', __( 'Please write your review.', 'ndv-reviews' ) );
		}

		$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;
		$author  = isset( $data['author'] ) ? sanitize_text_field( $data['author'] ) : '';
		$email   = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';

		if ( $user_id ) {
			$user   = get_userdata( $user_id );
			$author = $author ? $author : ( $user ? $user->display_name : '' );
			$email  = $email ? $email : ( $user ? $user->user_email : '' );
		}

		if ( '' === $author ) {
			return new \WP_Error( 'ndvr_missing_author', __( 'Please enter your name.', 'ndv-reviews' ) );
		}
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'ndvr_missing_email', __( 'Please enter a valid email address.', 'ndv-reviews' ) );
		}

		$approved = ! empty( $data['approved'] ) ? 1 : 0;

		$commentdata = array(
			'comment_post_ID'      => $product_id,
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_content'      => $content,
			'comment_type'         => 'review',
			'comment_parent'       => 0,
			'user_id'              => $user_id,
			'comment_approved'     => $approved,
		);

		// Insert without triggering duplicate/flood checks meant for blog comments.
		$comment_id = wp_insert_comment( wp_filter_comment( $commentdata ) );

		if ( ! $comment_id ) {
			return new \WP_Error( 'ndvr_insert_failed', __( 'Could not save your review. Please try again.', 'ndv-reviews' ) );
		}

		// Criteria scores.
		$this->save_criteria_scores( $comment_id, isset( $data['criteria'] ) ? (array) $data['criteria'] : array() );

		// Media.
		if ( ! empty( $data['media'] ) ) {
			$this->save_media( $comment_id, (array) $data['media'] );
		}

		// Meta.
		$recommend = isset( $data['recommend'] ) ? sanitize_key( $data['recommend'] ) : 'neutral';
		if ( ! in_array( $recommend, array( 'yes', 'neutral', 'no' ), true ) ) {
			$recommend = 'neutral';
		}
		update_comment_meta( $comment_id, '_ndvr_recommend', $recommend );

		if ( ! empty( $data['title'] ) ) {
			update_comment_meta( $comment_id, '_ndvr_title', sanitize_text_field( $data['title'] ) );
		}

		$source = isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'onsite';
		update_comment_meta( $comment_id, '_ndvr_source', $source );

		if ( ! empty( $data['order_id'] ) ) {
			update_comment_meta( $comment_id, '_ndvr_order_id', absint( $data['order_id'] ) );
		}

		$is_verified = $this->verified->is_verified( $email, $user_id, $product_id );
		update_comment_meta( $comment_id, '_ndvr_verified', $is_verified ? 1 : 0 );
		if ( $is_verified ) {
			update_comment_meta( $comment_id, 'verified', 1 );
		}

		// Compute caches.
		$this->ratings->recalc_review( $comment_id );
		if ( $approved ) {
			$this->ratings->recalc_product( $product_id );
		}

		/**
		 * Fires after a review is created.
		 *
		 * @param int                 $comment_id The new review comment id.
		 * @param array<string,mixed> $data       The submitted data.
		 */
		do_action( 'ndv-reviews/review_created', $comment_id, $data );

		return $comment_id;
	}

	/**
	 * Save per-criterion scores for a review, clamped and validated.
	 *
	 * @param int               $comment_id Review comment id.
	 * @param array<int,mixed>  $scores     Map criteria_id => rating.
	 * @return void
	 */
	private function save_criteria_scores( $comment_id, array $scores ) {
		global $wpdb;

		$valid_ids = array();
		foreach ( $this->criteria->get_active() as $criterion ) {
			$valid_ids[ $criterion->id ] = true;
		}

		$table = Db::table( 'review_criteria' );

		foreach ( $scores as $criteria_id => $rating ) {
			$criteria_id = absint( $criteria_id );
			$rating      = (float) $rating;

			if ( ! isset( $valid_ids[ $criteria_id ] ) || $rating < 0.5 || $rating > 5 ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'comment_id'  => $comment_id,
					'criteria_id' => $criteria_id,
					'rating'      => round( $rating, 2 ),
				),
				array( '%d', '%d', '%f' )
			);
		}
	}

	/**
	 * Attach uploaded media to a review.
	 *
	 * @param int   $comment_id    Review comment id.
	 * @param int[] $attachment_ids Validated attachment ids.
	 * @return void
	 */
	private function save_media( $comment_id, array $attachment_ids ) {
		global $wpdb;

		$table    = Db::table( 'review_media' );
		$position = 0;

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'comment_id'    => $comment_id,
					'type'          => 'image',
					'attachment_id' => $attachment_id,
					'url'           => wp_get_attachment_url( $attachment_id ),
					'position'      => $position++,
					'status'        => 'approved',
				),
				array( '%d', '%s', '%d', '%s', '%d', '%s' )
			);
		}
	}
}
