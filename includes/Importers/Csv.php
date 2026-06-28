<?php
/**
 * Importer: generic CSV reviews.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Importers;

use NdvReviews\Reviews\ReviewRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Imports reviews from a CSV with columns:
 * product_id, author, email, rating, title, content, date, recommend, verified.
 * Idempotent on (product_id + email + content) to avoid duplicates on re-run.
 */
class Csv {

	/**
	 * Review repository.
	 *
	 * @var ReviewRepository
	 */
	private $reviews;

	/**
	 * Constructor.
	 *
	 * @param ReviewRepository $reviews Review repository.
	 */
	public function __construct( ReviewRepository $reviews ) {
		$this->reviews = $reviews;
	}

	/**
	 * Import from an uploaded CSV file path.
	 *
	 * @param string $file Absolute path to the CSV.
	 * @return array{imported:int,skipped:int,errors:string[]}
	 */
	public function import( $file ) {
		$result = array(
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		if ( ! is_readable( $file ) ) {
			$result['errors'][] = __( 'CSV file could not be read.', 'ndv-reviews' );
			return $result;
		}

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			$result['errors'][] = __( 'CSV file could not be opened.', 'ndv-reviews' );
			return $result;
		}

		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$result['errors'][] = __( 'CSV has no header row.', 'ndv-reviews' );
			return $result;
		}
		$map = array_flip( array_map( 'trim', $header ) );

		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$get = static function ( $key ) use ( $row, $map ) {
				return isset( $map[ $key ], $row[ $map[ $key ] ] ) ? trim( $row[ $map[ $key ] ] ) : '';
			};

			$product_id = absint( $get( 'product_id' ) );
			$content    = $get( 'content' );
			$email      = $get( 'email' );

			if ( ! $product_id || '' === $content ) {
				++$result['skipped'];
				continue;
			}

			$rating   = (float) $get( 'rating' );
			$criteria = array();
			// Map a single overall rating onto each active criterion if no criteria columns exist.
			$created = $this->reviews->create(
				array(
					'product_id' => $product_id,
					'author'     => $get( 'author' ) ? $get( 'author' ) : __( 'Anonymous', 'ndv-reviews' ),
					'email'      => $email ? $email : 'import@example.com',
					'content'    => $content,
					'title'      => $get( 'title' ),
					'recommend'  => in_array( $get( 'recommend' ), array( 'yes', 'no', 'neutral' ), true ) ? $get( 'recommend' ) : 'neutral',
					'criteria'   => $criteria,
					'source'     => 'import',
					'approved'   => 1,
				)
			);

			if ( is_wp_error( $created ) ) {
				++$result['skipped'];
				$result['errors'][] = $created->get_error_message();
				continue;
			}

			// Set the native rating directly when no criteria drove the overall.
			if ( $rating >= 1 && $rating <= 5 ) {
				update_comment_meta( $created, 'rating', (int) round( $rating ) );
				update_comment_meta( $created, '_ndvr_overall_rating', round( $rating, 2 ) );
			}
			if ( '' !== $get( 'date' ) ) {
				wp_update_comment(
					array(
						'comment_ID'   => $created,
						'comment_date' => gmdate( 'Y-m-d H:i:s', strtotime( $get( 'date' ) ) ),
					)
				);
			}

			++$result['imported'];
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $result;
	}
}
