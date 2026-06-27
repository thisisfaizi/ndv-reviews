<?php
/**
 * Review photo uploads.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Forms;

use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and stores review photo uploads through the WordPress media API.
 */
class Upload {

	/**
	 * Settings accessor.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Allowed image mime types.
	 *
	 * @var array<string,string>
	 */
	private $allowed = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'gif'      => 'image/gif',
		'webp'     => 'image/webp',
	);

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings accessor.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Handle the photo uploads attached to a review submission.
	 *
	 * @param string $field   The $_FILES field name (supports multiple).
	 * @param int    $post_id Product id to attach media to.
	 * @return int[]|\WP_Error Array of attachment ids, or WP_Error.
	 */
	public function handle( $field, $post_id ) {
		if ( ! $this->settings->get( 'photo_uploads' ) ) {
			return array();
		}

		if ( empty( $_FILES[ $field ] ) || empty( $_FILES[ $field ]['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$files    = $this->normalize_files( $field );
		$max      = max( 0, (int) $this->settings->get( 'max_photos', 5 ) );
		$max_size = (int) apply_filters( 'ndv-reviews/max_photo_bytes', 5 * MB_IN_BYTES );
		$ids      = array();

		foreach ( $files as $i => $file ) {
			if ( count( $ids ) >= $max ) {
				break;
			}
			if ( empty( $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
				continue;
			}

			// Validate type and size before handing to WordPress.
			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $this->allowed );
			if ( empty( $check['ext'] ) || ! in_array( $check['type'], $this->allowed, true ) ) {
				return new \WP_Error( 'ndvr_upload_type', __( 'Only JPG, PNG, GIF, or WebP images are allowed.', 'ndv-reviews' ) );
			}
			if ( (int) $file['size'] > $max_size ) {
				return new \WP_Error( 'ndvr_upload_size', __( 'One of your images is too large.', 'ndv-reviews' ) );
			}

			// Import the genuine HTTP upload via media_handle_upload(), which uses
			// move_uploaded_file() and passes the is_uploaded_file() test. We expose
			// the single file under a temporary $_FILES key it can read. test_form is
			// disabled because this is our AJAX request, not a standard form post.
			$_FILES['ndvr_photo_tmp'] = array(
				'name'     => $file['name'],
				'type'     => $file['type'],
				'tmp_name' => $file['tmp_name'],
				'error'    => (int) $file['error'],
				'size'     => (int) $file['size'],
			);

			$attachment_id = media_handle_upload( 'ndvr_photo_tmp', $post_id, array(), array( 'test_form' => false ) );

			unset( $_FILES['ndvr_photo_tmp'] );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			$ids[] = (int) $attachment_id;
		}

		return $ids;
	}

	/**
	 * Normalize PHP's awkward multi-file $_FILES structure into a flat list.
	 *
	 * @param string $field Field name.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_files( $field ) {
		// IMPORTANT: do NOT wp_unslash() $_FILES. WordPress does not slash $_FILES,
		// and unslashing corrupts Windows temp paths (C:\...\php123.tmp), which makes
		// is_uploaded_file() fail. Only the file name is sanitized.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_FILES[ $field ] ) ? $_FILES[ $field ] : array();

		if ( empty( $raw ) || ! isset( $raw['name'] ) ) {
			return array();
		}

		$out = array();

		if ( is_array( $raw['name'] ) ) {
			foreach ( $raw['name'] as $i => $name ) {
				$out[] = array(
					'name'     => sanitize_file_name( $name ),
					'type'     => isset( $raw['type'][ $i ] ) ? sanitize_text_field( $raw['type'][ $i ] ) : '',
					'tmp_name' => isset( $raw['tmp_name'][ $i ] ) ? $raw['tmp_name'][ $i ] : '',
					'error'    => isset( $raw['error'][ $i ] ) ? (int) $raw['error'][ $i ] : UPLOAD_ERR_NO_FILE,
					'size'     => isset( $raw['size'][ $i ] ) ? (int) $raw['size'][ $i ] : 0,
				);
			}
		} else {
			$out[] = array(
				'name'     => sanitize_file_name( $raw['name'] ),
				'type'     => isset( $raw['type'] ) ? sanitize_text_field( $raw['type'] ) : '',
				'tmp_name' => isset( $raw['tmp_name'] ) ? $raw['tmp_name'] : '',
				'error'    => isset( $raw['error'] ) ? (int) $raw['error'] : UPLOAD_ERR_NO_FILE,
				'size'     => isset( $raw['size'] ) ? (int) $raw['size'] : 0,
			);
		}

		return $out;
	}
}
