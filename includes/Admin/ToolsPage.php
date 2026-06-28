<?php
/**
 * Admin screen: import / export tools.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Registerable;
use NdvReviews\Importers\WooNative;
use NdvReviews\Importers\Csv;
use NdvReviews\Importers\Exporter;

defined( 'ABSPATH' ) || exit;

/**
 * Import native Woo reviews / a CSV, and export all reviews to CSV or JSON.
 */
class ToolsPage implements Registerable {

	const CAPABILITY = 'manage_woocommerce';
	const PARENT     = 'ndv-reviews';
	const PAGE_SLUG  = 'ndv-reviews-tools';
	const NONCE      = 'ndvr_tools';

	/**
	 * Woo-native importer.
	 *
	 * @var WooNative
	 */
	private $woo;

	/**
	 * CSV importer.
	 *
	 * @var Csv
	 */
	private $csv;

	/**
	 * Exporter.
	 *
	 * @var Exporter
	 */
	private $exporter;

	/**
	 * Notices.
	 *
	 * @var array<int,array{type:string,message:string}>
	 */
	private $notices = array();

	/**
	 * Constructor.
	 *
	 * @param WooNative $woo      Woo-native importer.
	 * @param Csv       $csv      CSV importer.
	 * @param Exporter  $exporter Exporter.
	 */
	public function __construct( WooNative $woo, Csv $csv, Exporter $exporter ) {
		$this->woo      = $woo;
		$this->csv      = $csv;
		$this->exporter = $exporter;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 13 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Add the submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			self::PARENT,
			__( 'Import / Export', 'ndv-reviews' ),
			__( 'Import / Export', 'ndv-reviews' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle tool actions.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['ndvr_tools_do'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$do = sanitize_key( wp_unslash( $_POST['ndvr_tools_do'] ) );

		if ( 'woo_backfill' === $do ) {
			$res = $this->woo->run( 1000 );
			$this->notices[] = array(
				'type'    => 'success',
				/* translators: 1: reviews processed, 2: products updated. */
				'message' => sprintf( __( 'Imported %1$d native reviews across %2$d products.', 'ndv-reviews' ), $res['processed'], $res['products'] ),
			);
		} elseif ( 'export_csv' === $do ) {
			$this->exporter->stream_csv();
		} elseif ( 'export_json' === $do ) {
			$this->exporter->stream_json();
		} elseif ( 'csv_import' === $do ) {
			$this->handle_csv_upload();
		}
	}

	/**
	 * Validate + import an uploaded CSV.
	 *
	 * @return void
	 */
	private function handle_csv_upload() {
		if ( empty( $_FILES['ndvr_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->notices[] = array(
				'type'    => 'error',
				'message' => __( 'Please choose a CSV file.', 'ndv-reviews' ),
			);
			return;
		}

		$name = isset( $_FILES['ndvr_csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['ndvr_csv']['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$check = wp_check_filetype( $name, array( 'csv' => 'text/csv' ) );
		if ( 'csv' !== $check['ext'] ) {
			$this->notices[] = array(
				'type'    => 'error',
				'message' => __( 'Only .csv files are supported.', 'ndv-reviews' ),
			);
			return;
		}

		// $_FILES tmp paths must not be unslashed (Windows path safety).
		$tmp = $_FILES['ndvr_csv']['tmp_name']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$res = $this->csv->import( $tmp );

		$this->notices[] = array(
			'type'    => empty( $res['errors'] ) ? 'success' : 'error',
			/* translators: 1: imported, 2: skipped. */
			'message' => sprintf( __( 'Imported %1$d reviews; skipped %2$d.', 'ndv-reviews' ), $res['imported'], $res['skipped'] ),
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import / Export', 'ndv-reviews' ); ?></h1>

			<?php foreach ( $this->notices as $n ) : ?>
				<div class="notice notice-<?php echo 'error' === $n['type'] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $n['message'] ); ?></p></div>
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Import native WooCommerce reviews', 'ndv-reviews' ); ?></h2>
			<p><?php esc_html_e( 'Enrich existing WooCommerce reviews with NDV Reviews data (verified status, helpful counter, aggregates). Safe to run more than once.', 'ndv-reviews' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<button class="button button-primary" name="ndvr_tools_do" value="woo_backfill"><?php esc_html_e( 'Import / refresh native reviews', 'ndv-reviews' ); ?></button>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Import from CSV', 'ndv-reviews' ); ?></h2>
			<p><?php esc_html_e( 'Columns: product_id, author, email, rating, title, content, date, recommend, verified.', 'ndv-reviews' ); ?></p>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="file" name="ndvr_csv" accept=".csv" />
				<button class="button" name="ndvr_tools_do" value="csv_import"><?php esc_html_e( 'Import CSV', 'ndv-reviews' ); ?></button>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Export', 'ndv-reviews' ); ?></h2>
			<form method="post" style="display:inline;">
				<?php wp_nonce_field( self::NONCE ); ?>
				<button class="button" name="ndvr_tools_do" value="export_csv"><?php esc_html_e( 'Export CSV', 'ndv-reviews' ); ?></button>
			</form>
			<form method="post" style="display:inline;">
				<?php wp_nonce_field( self::NONCE ); ?>
				<button class="button" name="ndvr_tools_do" value="export_json"><?php esc_html_e( 'Export JSON', 'ndv-reviews' ); ?></button>
			</form>

			<hr />

			<h2><?php esc_html_e( 'QR code & shareable review link', 'ndv-reviews' ); ?></h2>
			<p><?php esc_html_e( 'Print a QR on packaging or receipts so customers can scan it and review the product. Enter a product ID to generate its link + QR.', 'ndv-reviews' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="number" name="qr_product" value="<?php echo isset( $_GET['qr_product'] ) ? esc_attr( absint( wp_unslash( $_GET['qr_product'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" placeholder="<?php esc_attr_e( 'Product ID', 'ndv-reviews' ); ?>" />
				<button class="button"><?php esc_html_e( 'Generate', 'ndv-reviews' ); ?></button>
			</form>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$qr_product = isset( $_GET['qr_product'] ) ? absint( wp_unslash( $_GET['qr_product'] ) ) : 0;
			if ( $qr_product && 'product' === get_post_type( $qr_product ) ) {
				$link = add_query_arg( 'ndvr_review', 1, get_permalink( $qr_product ) ) . '#reviews';
				echo '<p style="margin-top:14px;"><strong>' . esc_html__( 'Review link:', 'ndv-reviews' ) . '</strong> <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $link ) . '</a></p>';
				echo '<div style="background:#fff;display:inline-block;padding:14px;border:1px solid #e6e9ef;border-radius:14px;">';
				echo \NdvReviews\Display\Qr::svg( $link, 200 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</div>';
			} elseif ( $qr_product ) {
				echo '<p><em>' . esc_html__( 'That product was not found.', 'ndv-reviews' ) . '</em></p>';
			}
			?>
		</div>
		<?php
	}
}
