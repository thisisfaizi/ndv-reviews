<?php
/**
 * GDPR: personal-data export and erasure for reviews.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Privacy;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Db;
use NdvReviews\Reviews\PostTypes;
use NdvReviews\Collection\TokenRepository;
use NdvReviews\Requests\Mailer;
use NdvReviews\Requests\RequestRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates reviews (and their media/votes/consent), review-request emails,
 * review links and the unsubscribe list with WordPress's built-in Personal
 * Data Exporter and Eraser.
 */
class Privacy implements Registerable {

	/**
	 * Reviews handled per exporter/eraser page.
	 */
	const PER_PAGE = 50;

	/**
	 * Token repository.
	 *
	 * @var TokenRepository|null
	 */
	private $tokens;

	/**
	 * Mailer (suppression list).
	 *
	 * @var Mailer|null
	 */
	private $mailer;

	/**
	 * Request log.
	 *
	 * @var RequestRepository|null
	 */
	private $requests;

	/**
	 * Constructor. Dependencies are optional so `new Privacy()` keeps working;
	 * without them only the review data is handled.
	 *
	 * @param TokenRepository|null   $tokens   Token repository.
	 * @param Mailer|null            $mailer   Mailer.
	 * @param RequestRepository|null $requests Request log.
	 */
	public function __construct( ?TokenRepository $tokens = null, ?Mailer $mailer = null, ?RequestRepository $requests = null ) {
		$this->tokens   = $tokens;
		$this->mailer   = $mailer;
		$this->requests = $requests;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string,mixed> $exporters Exporters.
	 * @return array<string,mixed>
	 */
	public function register_exporter( $exporters ) {
		$exporters['ndv-reviews'] = array(
			'exporter_friendly_name' => __( 'NDV Reviews', 'ndv-reviews' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string,mixed> $erasers Erasers.
	 * @return array<string,mixed>
	 */
	public function register_eraser( $erasers ) {
		$erasers['ndv-reviews'] = array(
			'eraser_friendly_name' => __( 'NDV Reviews', 'ndv-reviews' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * One page of an email's reviews on every reviewable post type.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return \WP_Comment[]
	 */
	private function reviews_for( $email, $page ) {
		return (array) get_comments(
			array(
				'author_email' => $email,
				'type__in'     => array( 'review', 'comment' ),
				'post_type'    => PostTypes::all(),
				'status'       => 'all',
				'number'       => self::PER_PAGE,
				'paged'        => max( 1, (int) $page ),
				'orderby'      => 'comment_ID',
				'order'        => 'ASC',
			)
		);
	}

	/**
	 * Export a user's reviews, review-request log and unsubscribe status.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return array{data:array,done:bool}
	 */
	public function export( $email, $page = 1 ) {
		global $wpdb;

		$data     = array();
		$comments = $this->reviews_for( $email, $page );
		$rc_table = Db::table( 'review_criteria' );
		$cr_table = Db::table( 'criteria' );
		$media    = Db::table( 'review_media' );

		foreach ( $comments as $comment ) {
			$id     = (int) $comment->comment_ID;
			$fields = array(
				array(
					'name'  => __( 'Product', 'ndv-reviews' ),
					'value' => get_the_title( $comment->comment_post_ID ),
				),
				array(
					'name'  => __( 'Name shown', 'ndv-reviews' ),
					'value' => $comment->comment_author,
				),
				array(
					'name'  => __( 'Rating', 'ndv-reviews' ),
					'value' => (string) get_comment_meta( $id, '_ndvr_overall_rating', true ),
				),
				array(
					'name'  => __( 'Title', 'ndv-reviews' ),
					'value' => (string) get_comment_meta( $id, '_ndvr_title', true ),
				),
				array(
					'name'  => __( 'Review', 'ndv-reviews' ),
					'value' => $comment->comment_content,
				),
				array(
					'name'  => __( 'Date', 'ndv-reviews' ),
					'value' => $comment->comment_date,
				),
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Db::table(), value placeholdered.
			$scores = $wpdb->get_results( $wpdb->prepare( "SELECT c.name, rc.rating FROM `{$rc_table}` rc INNER JOIN `{$cr_table}` c ON c.id = rc.criteria_id WHERE rc.comment_id = %d", $id ) );
			foreach ( (array) $scores as $score ) {
				$fields[] = array(
					/* translators: %s: rating criterion name. */
					'name'  => sprintf( __( 'Rating: %s', 'ndv-reviews' ), $score->name ),
					'value' => (string) (float) $score->rating,
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(), value placeholdered.
			$photos = $wpdb->get_col( $wpdb->prepare( "SELECT url FROM `{$media}` WHERE comment_id = %d ORDER BY position ASC", $id ) );
			foreach ( array_filter( (array) $photos ) as $url ) {
				$fields[] = array(
					'name'  => __( 'Photo', 'ndv-reviews' ),
					'value' => esc_url( $url ),
				);
			}

			$data[] = array(
				'group_id'    => 'ndvr_reviews',
				'group_label' => __( 'Product Reviews', 'ndv-reviews' ),
				'item_id'     => 'ndvr-review-' . $id,
				'data'        => $fields,
			);
		}

		$done = count( $comments ) < self::PER_PAGE;

		// Request log and unsubscribe status are small; add them with the last page.
		if ( $done ) {
			if ( $this->requests ) {
				foreach ( $this->requests->for_email( $email ) as $row ) {
					$data[] = array(
						'group_id'    => 'ndvr_review_requests',
						'group_label' => __( 'Review request emails', 'ndv-reviews' ),
						'item_id'     => 'ndvr-request-' . (int) $row->id,
						'data'        => array(
							array(
								'name'  => __( 'Order', 'ndv-reviews' ),
								'value' => (string) $row->order_id,
							),
							array(
								'name'  => __( 'Status', 'ndv-reviews' ),
								'value' => (string) $row->status,
							),
							array(
								'name'  => __( 'Scheduled', 'ndv-reviews' ),
								'value' => (string) $row->scheduled_at,
							),
							array(
								'name'  => __( 'Sent', 'ndv-reviews' ),
								'value' => (string) $row->sent_at,
							),
						),
					);
				}
			}

			if ( $this->mailer && $this->mailer->is_suppressed( $email ) ) {
				$data[] = array(
					'group_id'    => 'ndvr_review_requests',
					'group_label' => __( 'Review request emails', 'ndv-reviews' ),
					'item_id'     => 'ndvr-unsubscribed',
					'data'        => array(
						array(
							'name'  => __( 'Unsubscribed from review requests', 'ndv-reviews' ),
							'value' => __( 'Yes', 'ndv-reviews' ),
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/**
	 * Erase a user's personal data: anonymize their reviews (removing photos,
	 * votes and identifying meta), drop the address from the request log,
	 * delete their review links, and keep any unsubscribe as a hash only.
	 *
	 * Erased reviews no longer match the email, so every pass reads page 1.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based; unused, see above).
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( $email, $page = 1 ) {
		global $wpdb;

		$removed  = false;
		$comments = $this->reviews_for( $email, 1 );

		foreach ( $comments as $comment ) {
			$id = (int) $comment->comment_ID;

			// Direct update: wp_update_comment() would re-run comment filters
			// and date handling on a row we only want to strip.
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->comments,
				array(
					'comment_author'       => __( 'Anonymous', 'ndv-reviews' ),
					'comment_author_email' => '',
					'comment_author_IP'    => '',
					'comment_author_url'   => '',
					'comment_agent'        => '',
					'user_id'              => 0,
				),
				array( 'comment_ID' => $id ),
				array( '%s', '%s', '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);
			clean_comment_cache( $id );

			$media_table = Db::table( 'review_media' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::table(), value placeholdered.
			$attachment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM `{$media_table}` WHERE comment_id = %d", $id ) );
			foreach ( $attachment_ids as $attachment_id ) {
				// Erasure must remove the photo itself (may carry EXIF/geolocation),
				// not just the row that pointed to it.
				if ( (int) $attachment_id ) {
					wp_delete_attachment( (int) $attachment_id, true );
				}
			}

			$wpdb->delete( $media_table, array( 'comment_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Db::table( 'review_votes' ), array( 'comment_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			delete_comment_meta( $id, '_ndvr_country' );
			delete_comment_meta( $id, '_ndvr_consent' );
			delete_comment_meta( $id, '_ndvr_title' );
			delete_comment_meta( $id, '_ndvr_order_id' );
			update_comment_meta( $id, '_ndvr_source', 'erased' );

			$removed = true;
		}

		$done = count( $comments ) < self::PER_PAGE;

		if ( $done ) {
			if ( $this->requests && $this->requests->anonymize_email( $email ) > 0 ) {
				$removed = true;
			}
			if ( $this->tokens && $this->tokens->delete_for_email( $email ) > 0 ) {
				$removed = true;
			}
			if ( $this->mailer && $this->mailer->hash_suppressed( $email ) ) {
				$removed = true;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}
}
