<?php
/**
 * Exporter: reviews to CSV / JSON (no lock-in).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Importers;

use NdvReviews\Reviews\ReviewQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Streams all reviews out as CSV or JSON so users are never locked in.
 */
class Exporter {

	/**
	 * Review query (for enrichment).
	 *
	 * @var ReviewQuery
	 */
	private $query;

	/**
	 * Constructor.
	 *
	 * @param ReviewQuery $query Review query.
	 */
	public function __construct( ReviewQuery $query ) {
		$this->query = $query;
	}

	/**
	 * Gather all approved reviews as rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows() {
		$comments = get_comments(
			array(
				'type__in'  => array( 'review', 'comment' ),
				'post_type' => 'product',
				'status'    => 'approve',
				'number'    => 0,
			)
		);

		$rows = array();
		foreach ( (array) $comments as $comment ) {
			$view   = $this->query->to_view( $comment );
			$rows[] = array(
				'product_id' => (int) $comment->comment_post_ID,
				'author'     => $view['author'],
				'email'      => $comment->comment_author_email,
				'rating'     => $view['overall'] ? $view['overall'] : $view['rating'],
				'title'      => $view['title'],
				'content'    => $view['content'],
				'date'       => $view['date'],
				'recommend'  => $view['recommend'],
				'verified'   => $view['verified'] ? 1 : 0,
			);
		}

		return $rows;
	}

	/**
	 * Send a CSV download and exit.
	 *
	 * @return void
	 */
	public function stream_csv() {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ndv-reviews-export-' . gmdate( 'Ymd' ) . '.csv"' );

		$out  = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$rows = $this->rows();
		fputcsv( $out, array( 'product_id', 'author', 'email', 'rating', 'title', 'content', 'date', 'recommend', 'verified' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Send a JSON download and exit.
	 *
	 * @return void
	 */
	public function stream_json() {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ndv-reviews-export-' . gmdate( 'Ymd' ) . '.json"' );

		echo wp_json_encode( $this->rows(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
