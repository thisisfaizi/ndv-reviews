<?php
/**
 * Exporter: reviews to CSV / JSON (no lock-in).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Importers;

use NdvReviews\Reviews\PostTypes;
use NdvReviews\Reviews\ReviewQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Streams approved and pending reviews on every reviewable post type out as
 * CSV or JSON, in batches so large stores don't exhaust memory. The CSV keeps
 * the importer's column names first, so an export can be re-imported.
 */
class Exporter {

	const BATCH = 200;

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
	 * Column names (CSV header / JSON keys).
	 *
	 * @return string[]
	 */
	private function columns() {
		return array( 'product_id', 'author', 'email', 'rating', 'title', 'content', 'date', 'recommend', 'verified', 'status', 'criteria', 'photos' );
	}

	/**
	 * Yield review rows batch by batch.
	 *
	 * @return \Generator<int,array<string,mixed>>
	 */
	private function rows() {
		$page = 1;

		do {
			$comments = (array) get_comments(
				array(
					'type__in'      => array( 'review', 'comment' ),
					'post_type'     => PostTypes::all(),
					'status'        => array( 'approve', 'hold' ),
					'number'        => self::BATCH,
					'paged'         => $page,
					'orderby'       => 'comment_ID',
					'order'         => 'ASC',
					'no_found_rows' => true,
				)
			);

			$ids      = array_map( 'intval', wp_list_pluck( $comments, 'comment_ID' ) );
			$criteria = $this->query->criteria_scores_bulk( $ids );
			$media    = $this->query->media_bulk( $ids );

			foreach ( $comments as $comment ) {
				$view   = $this->query->to_view( $comment, $criteria, $media );
				$scores = array();
				foreach ( $view['criteria'] as $score ) {
					$scores[ $score['name'] ] = (float) $score['rating'];
				}

				yield array(
					'product_id' => (int) $comment->comment_post_ID,
					'author'     => $view['author'],
					'email'      => $comment->comment_author_email,
					'rating'     => $view['overall'] ? $view['overall'] : $view['rating'],
					'title'      => $view['title'],
					'content'    => $view['content'],
					'date'       => $view['date'],
					'recommend'  => $view['recommend'],
					'verified'   => $view['verified'] ? 1 : 0,
					'status'     => '1' === (string) $comment->comment_approved ? 'approved' : 'pending',
					'criteria'   => $scores,
					'photos'     => array_values( wp_list_pluck( $view['media'], 'url' ) ),
				);
			}

			++$page;
			if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( count( $comments ) === self::BATCH );
	}

	/**
	 * Neutralize a CSV cell that a spreadsheet would run as a formula.
	 *
	 * @param mixed $value Cell value.
	 * @return mixed
	 */
	private function csv_cell( $value ) {
		if ( is_string( $value ) && '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
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

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, $this->columns(), ',', '"', '\\' );
		foreach ( $this->rows() as $row ) {
			$parts = array();
			foreach ( $row['criteria'] as $name => $score ) {
				$parts[] = $name . ': ' . $score;
			}
			$row['criteria'] = implode( '; ', $parts );
			$row['photos']   = implode( ' ', $row['photos'] );

			fputcsv( $out, array_map( array( $this, 'csv_cell' ), array_values( $row ) ), ',', '"', '\\' );
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

		$first = true;
		echo '[';
		foreach ( $this->rows() as $row ) {
			echo ( $first ? "\n" : ",\n" ) . wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
			$first = false;
		}
		echo "\n]";
		exit;
	}
}
