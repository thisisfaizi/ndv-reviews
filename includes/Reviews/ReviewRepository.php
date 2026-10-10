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
	 *     @type array<int,float>    $criteria   Map of criteria_id => rating (0.5-5). Only active
	 *                                           criteria count; at least one valid score is required
	 *                                           unless `rating` is given.
	 *     @type float               $rating     Overall rating (1-5) for callers with no criteria
	 *                                           scores (CSV import). Used only when no valid
	 *                                           criteria score is present.
	 *     @type int[]               $media      Attachment ids for photos.
	 *     @type int                 $user_id    Reviewer user id (0 guest).
	 *     @type string              $source     Source tag (onsite|qr|form|...).
	 *     @type int                 $order_id   Optional originating order id.
	 *     @type bool                $approved   Whether to approve immediately.
	 *     @type array<int,string>   $answers    Review-question answers, field id => value (RR-11).
	 *     @type bool                $answers_present Whether the form carried the questions
	 *                                           (default true); required answers are checked
	 *                                           only then, and only for customer-written sources.
	 * }
	 * @return int|\WP_Error New comment id, or WP_Error on failure.
	 */
	public function create( array $data ) {
		$product_id = isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0;
		if ( ! $product_id || ! PostTypes::is_reviewable( $product_id ) ) {
			return new \WP_Error( 'ndvr_invalid_product', __( 'This item cannot be reviewed.', 'rosette-reviews' ) );
		}

		// Aggregates (and the comment itself) attach to the pool — identity by
		// default, a parent for variations/grouped/bundle/group pools (Pro).
		$pool_id = Pool::resolve_id( $product_id );

		$content = isset( $data['content'] ) ? trim( wp_kses_post( $data['content'] ) ) : '';
		if ( '' === $content ) {
			return new \WP_Error( 'ndvr_empty_content', __( 'Please write your review.', 'rosette-reviews' ) );
		}
		// Minimum length (RR-12): customer-written sources only.
		$long_enough = self::check_length( $content, $data );
		if ( is_wp_error( $long_enough ) ) {
			return $long_enough;
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
			return new \WP_Error( 'ndvr_missing_author', __( 'Please enter your name.', 'rosette-reviews' ) );
		}
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'ndvr_missing_email', __( 'Please enter a valid email address.', 'rosette-reviews' ) );
		}

		// A review without a rating would display but be left out of the product
		// average, so it is rejected here — every caller (forms, landing, import)
		// goes through this check before anything is stored.
		$scores       = $this->valid_scores( isset( $data['criteria'] ) ? (array) $data['criteria'] : array() );
		$plain_rating = 0.0;
		if ( empty( $scores ) ) {
			$plain_rating = isset( $data['rating'] ) && is_numeric( $data['rating'] ) ? (float) $data['rating'] : 0.0;
			if ( $plain_rating < 1 || $plain_rating > 5 ) {
				return new \WP_Error( 'ndvr_missing_rating', __( 'Please give a star rating before submitting your review.', 'rosette-reviews' ) );
			}
		}

		// Review questions (RR-11): cleaned for every source; required ones only
		// for customer-written sources whose form rendered them.
		$fields  = \NdvReviews\Plugin::instance()->container()->get( 'review_fields' );
		$answers = $fields->sanitize( isset( $data['answers'] ) && is_array( $data['answers'] ) ? $data['answers'] : array() );
		$checked = $fields->validate( $answers, isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'onsite', ! array_key_exists( 'answers_present', $data ) || ! empty( $data['answers_present'] ) );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		/**
		 * Filter whether a new review is auto-approved (Pro auto-approve rules).
		 *
		 * @param bool                $approved Whether to approve immediately.
		 * @param array<string,mixed> $data     Submitted data.
		 */
		$approved = (int) (bool) apply_filters( 'ndv-reviews/should_approve', ! empty( $data['approved'] ), $data );

		/**
		 * Allow rejecting a review before it is stored (Pro profanity/banned-word).
		 *
		 * @param true|\WP_Error      $ok   Pass true to allow.
		 * @param array<string,mixed> $data Submitted data.
		 */
		$validation = apply_filters( 'ndv-reviews/validate_review', true, $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$commentdata = array(
			'comment_post_ID'      => $pool_id,
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_author_url'   => '',
			// Same as core comments: the submitter's IP aids spam moderation (erased by the GDPR eraser).
			'comment_author_IP'    => isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-fA-F:., ]/', '', wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- stripped to IP characters, as core does.
			'comment_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 254 ) : '',
			'comment_content'      => $content,
			'comment_type'         => 'review',
			'comment_parent'       => 0,
			'user_id'              => $user_id,
			'comment_approved'     => $approved,
		);

		// Insert without triggering duplicate/flood checks meant for blog comments.
		$comment_id = wp_insert_comment( wp_filter_comment( $commentdata ) );

		if ( ! $comment_id ) {
			return new \WP_Error( 'ndvr_insert_failed', __( 'Could not save your review. Please try again.', 'rosette-reviews' ) );
		}

		// Criteria scores (already validated above).
		$this->save_criteria_scores( $comment_id, $scores );

		if ( $answers ) {
			$fields->save_answers( $comment_id, $answers );
		}

		// Media.
		if ( ! empty( $data['media'] ) ) {
			$this->save_media( $comment_id, (array) $data['media'], isset( $data['source'] ) ? sanitize_key( $data['source'] ) : 'onsite' );
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

		// Seed the helpful counter to 0 so unvoted reviews sort as 0 under "Most
		// helpful" rather than after every review that has the meta.
		update_comment_meta( $comment_id, '_ndvr_helpful_up', 0 );

		// Log consent (timestamp only — we don't store the raw IP) for GDPR.
		if ( ! empty( $data['consent'] ) ) {
			update_comment_meta( $comment_id, '_ndvr_consent', current_time( 'mysql', true ) );
		}

		if ( ! empty( $data['order_id'] ) ) {
			update_comment_meta( $comment_id, '_ndvr_order_id', absint( $data['order_id'] ) );
		}

		// Stored on another product's pool: remember the product it was written
		// for (the parent, for a variation), so it can move back if the
		// products stop sharing reviews (RR-00b E5).
		$origin = Pool::origin_id( $product_id );
		if ( $origin !== $pool_id ) {
			update_comment_meta( $comment_id, Pool::POOLED_FROM_META, $origin );
		}

		$is_verified = $this->verified->is_verified( $email, $user_id, $product_id, $source );
		update_comment_meta( $comment_id, '_ndvr_verified', $is_verified ? 1 : 0 );
		if ( $is_verified ) {
			update_comment_meta( $comment_id, 'verified', 1 );
		}

		// Compute caches (aggregate recalculated on the pool id). A plain overall
		// rating is written directly: recalc_review() would fall back to the
		// integer `rating` meta and lose a decimal value such as 4.5.
		if ( empty( $scores ) ) {
			update_comment_meta( $comment_id, 'rating', (int) max( 1, min( 5, round( $plain_rating ) ) ) );
			update_comment_meta( $comment_id, '_ndvr_overall_rating', round( $plain_rating, 2 ) );
		} else {
			$this->ratings->recalc_review( $comment_id );
		}
		if ( $approved ) {
			$this->ratings->recalc_product( $pool_id );
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
	 * Characters in review text as the minimum-length rule counts them (RR-12).
	 *
	 * @param string $html Review text (kses'd).
	 * @return int
	 */
	public static function content_length( $html ) {
		return ReviewLength::count( $html );
	}

	/**
	 * The minimum-length rule for a submission (RR-12; interactive sources only).
	 *
	 * @param string              $content Review text (kses'd).
	 * @param array<string,mixed> $data    Submission data (`source`, `product_id` …).
	 * @return true|\WP_Error `ndvr_too_short` when below the minimum.
	 */
	public static function check_length( $content, array $data ) {
		return ReviewLength::check( $content, $data );
	}

	/**
	 * Keep only scores for active criteria within 0.5-5.
	 *
	 * Public so the forms can reject a rating-less submission before storing
	 * any uploaded photo.
	 *
	 * @param array<int|string,mixed> $scores Map criteria_id => rating (raw input).
	 * @return array<int,float> Valid criteria_id => rating.
	 */
	public function valid_scores( array $scores ) {
		$active = array();
		foreach ( $this->criteria->get_active() as $criterion ) {
			$active[ (int) $criterion->id ] = true;
		}

		$out = array();
		foreach ( $scores as $criteria_id => $rating ) {
			$criteria_id = absint( $criteria_id );
			if ( ! isset( $active[ $criteria_id ] ) || ! is_numeric( $rating ) ) {
				continue;
			}
			$rating = (float) $rating;
			if ( $rating >= 0.5 && $rating <= 5 ) {
				$out[ $criteria_id ] = round( $rating, 2 );
			}
		}

		return $out;
	}

	/**
	 * Save per-criterion scores for a review.
	 *
	 * @param int              $comment_id Review comment id.
	 * @param array<int,float> $scores     Map criteria_id => rating, from valid_scores().
	 * @return void
	 */
	private function save_criteria_scores( $comment_id, array $scores ) {
		global $wpdb;

		$table = Db::table( 'review_criteria' );

		foreach ( $scores as $criteria_id => $rating ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'comment_id'  => $comment_id,
					'criteria_id' => (int) $criteria_id,
					'rating'      => $rating,
				),
				array( '%d', '%d', '%f' )
			);
		}
	}

	/**
	 * Attach uploaded media to a review.
	 *
	 * @param int    $comment_id     Review comment id.
	 * @param int[]  $attachment_ids Validated attachment ids.
	 * @param string $source         Review source (passed to the media status filter).
	 * @return void
	 */
	private function save_media( $comment_id, array $attachment_ids, $source = '' ) {
		$this->attach_media(
			$comment_id,
			$attachment_ids,
			'image',
			array(
				'origin' => 'create',
				'source' => (string) $source,
			)
		);
	}

	/**
	 * Attach media-library items to a review (RR-00b E1): the one writer of
	 * review_media rows. Never fires review_created and never sends mail, so
	 * imports stay silent.
	 *
	 * @param int                 $comment_id Review comment id.
	 * @param int[]               $ids        Attachment ids.
	 * @param string              $type       image|video (filter ndv-reviews/media_types).
	 * @param array<string,mixed> $context    Passed to ndv-reviews/review_media_status, merged over
	 *                                        comment_id, type, source and origin ('api').
	 * @return int Rows inserted.
	 */
	public function attach_media( $comment_id, array $ids, $type = 'image', array $context = array() ) {
		global $wpdb;

		$comment_id = absint( $comment_id );
		$comment    = $comment_id ? get_comment( $comment_id ) : null;
		if ( ! $comment || ! $this->is_review_comment( $comment ) ) {
			return 0;
		}

		/**
		 * Filter the media types a review can hold.
		 *
		 * @param string[] $types Default image and video.
		 */
		$types = (array) apply_filters( 'ndv-reviews/media_types', array( 'image', 'video' ) );
		$type  = sanitize_key( (string) $type );
		if ( ! in_array( $type, $types, true ) ) {
			return 0;
		}

		$table   = Db::table( 'review_media' );
		$context = array_merge(
			array(
				'comment_id' => $comment_id,
				'type'       => $type,
				'source'     => (string) get_comment_meta( $comment_id, '_ndvr_source', true ),
				'origin'     => 'api',
			),
			$context
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Db::table().
		$existing = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM `{$table}` WHERE comment_id = %d", $comment_id ) ) );
		$position = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( MAX( position ), -1 ) + 1 FROM `{$table}` WHERE comment_id = %d", $comment_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$inserted = 0;
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $attachment_id ) {
			if ( ! $attachment_id || in_array( $attachment_id, $existing, true ) || 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}
			$mime = (string) get_post_mime_type( $attachment_id );
			if ( 0 !== strpos( $mime, $type . '/' ) ) {
				continue;
			}

			/**
			 * Filter a review media item's moderation status (Pro image moderation).
			 *
			 * @param string              $status        approved|pending|rejected.
			 * @param int                 $attachment_id Attachment id.
			 * @param array<string,mixed> $context       comment_id, type, source, origin.
			 */
			$status = (string) apply_filters( 'ndv-reviews/review_media_status', 'approved', $attachment_id, $context );

			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'comment_id'    => $comment_id,
					'type'          => $type,
					'attachment_id' => $attachment_id,
					'url'           => wp_get_attachment_url( $attachment_id ),
					'position'      => $position,
					'status'        => in_array( $status, array( 'approved', 'pending', 'rejected' ), true ) ? $status : 'approved',
				),
				array( '%d', '%s', '%d', '%s', '%d', '%s' )
			);
			if ( $ok ) {
				++$position;
				++$inserted;
				$existing[] = $attachment_id;
			}
		}

		return $inserted;
	}

	/**
	 * Whether a comment is a review this plugin can attach media to.
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return bool
	 */
	private function is_review_comment( $comment ) {
		if ( 'review' === $comment->comment_type ) {
			return true;
		}

		return 'comment' === $comment->comment_type && PostTypes::is_reviewable( (int) $comment->comment_post_ID );
	}
}
