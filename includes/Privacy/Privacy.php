<?php
/**
 * GDPR: personal-data export and erasure for reviews.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Privacy;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates reviews (and their media/votes/consent) with WordPress's built-in
 * Personal Data Exporter and Eraser.
 */
class Privacy implements Registerable {

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
	 * Export a user's reviews.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return array{data:array,done:bool}
	 */
	public function export( $email, $page = 1 ) {
		$data     = array();
		$comments = get_comments(
			array(
				'author_email' => $email,
				'type__in'     => array( 'review', 'comment' ),
				'post_type'    => 'product',
				'status'       => 'all',
				'number'       => 0,
			)
		);

		foreach ( (array) $comments as $comment ) {
			$id   = (int) $comment->comment_ID;
			$data[] = array(
				'group_id'    => 'ndvr_reviews',
				'group_label' => __( 'Product Reviews', 'ndv-reviews' ),
				'item_id'     => 'ndvr-review-' . $id,
				'data'        => array(
					array(
						'name'  => __( 'Product', 'ndv-reviews' ),
						'value' => get_the_title( $comment->comment_post_ID ),
					),
					array(
						'name'  => __( 'Rating', 'ndv-reviews' ),
						'value' => (string) get_comment_meta( $id, '_ndvr_overall_rating', true ),
					),
					array(
						'name'  => __( 'Review', 'ndv-reviews' ),
						'value' => $comment->comment_content,
					),
					array(
						'name'  => __( 'Date', 'ndv-reviews' ),
						'value' => $comment->comment_date,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase a user's reviews (anonymize the comment; remove media/votes).
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (1-based).
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( $email, $page = 1 ) {
		global $wpdb;

		$removed  = false;
		$comments = get_comments(
			array(
				'author_email' => $email,
				'type__in'     => array( 'review', 'comment' ),
				'post_type'    => 'product',
				'status'       => 'all',
				'number'       => 0,
			)
		);

		foreach ( (array) $comments as $comment ) {
			$id = (int) $comment->comment_ID;

			wp_update_comment(
				array(
					'comment_ID'           => $id,
					'comment_author'       => __( 'Anonymous', 'ndv-reviews' ),
					'comment_author_email' => '',
					'comment_author_IP'    => '',
					'comment_author_url'   => '',
				)
			);

			$wpdb->delete( Db::table( 'review_media' ), array( 'comment_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Db::table( 'review_votes' ), array( 'comment_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			delete_comment_meta( $id, '_ndvr_country' );
			delete_comment_meta( $id, '_ndvr_consent' );

			$removed = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
