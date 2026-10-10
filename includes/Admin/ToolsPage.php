<?php
/**
 * Admin screen: import / export tools.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Caps;
use NdvReviews\Support\Registerable;
use NdvReviews\Importers\WooNative;
use NdvReviews\Importers\Csv;
use NdvReviews\Importers\Exporter;

defined( 'ABSPATH' ) || exit;

/**
 * Import native Woo reviews / a CSV, and export all reviews to CSV or JSON.
 */
class ToolsPage implements Registerable {

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * WooCommerce's product search box for the QR picker, on this screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
	}

	/**
	 * Add the submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			self::PARENT,
			__( 'Import / Export', 'rosette-reviews' ),
			__( 'Import / Export', 'rosette-reviews' ),
			Caps::manage( 'tools' ),
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
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Caps::manage( 'tools' ) ) ) {
			return;
		}

		$do = sanitize_key( wp_unslash( $_POST['ndvr_tools_do'] ) );

		if ( 'woo_backfill' === $do ) {
			$res = $this->woo->run( 1000 );
			$this->notices[] = array(
				'type'    => 'success',
				/* translators: 1: reviews processed, 2: products updated. */
				'message' => sprintf( __( 'Imported %1$d native reviews across %2$d products.', 'rosette-reviews' ), $res['processed'], $res['products'] ),
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
				'message' => __( 'Please choose a CSV file.', 'rosette-reviews' ),
			);
			return;
		}

		$name = isset( $_FILES['ndvr_csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['ndvr_csv']['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$check = wp_check_filetype( $name, array( 'csv' => 'text/csv' ) );
		if ( 'csv' !== $check['ext'] ) {
			$this->notices[] = array(
				'type'    => 'error',
				'message' => __( 'Only .csv files are supported.', 'rosette-reviews' ),
			);
			return;
		}

		// $_FILES tmp paths must not be unslashed (Windows path safety).
		$tmp = $_FILES['ndvr_csv']['tmp_name']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$res = $this->csv->import( $tmp );

		if ( ! empty( $res['errors'] ) && 0 === $res['imported'] && 0 === $res['skipped'] ) {
			// The file itself could not be read.
			$this->notices[] = array(
				'type'    => 'error',
				'message' => implode( ' ', $res['errors'] ),
			);
			return;
		}

		/* translators: 1: imported, 2: skipped. */
		$message = sprintf( __( 'Imported %1$d reviews; skipped %2$d.', 'rosette-reviews' ), $res['imported'], $res['skipped'] );
		if ( ! empty( $res['reasons'] ) ) {
			$parts = array();
			foreach ( $res['reasons'] as $reason => $count ) {
				/* translators: 1: number of rows, 2: reason. */
				$parts[] = sprintf( __( '%1$d %2$s', 'rosette-reviews' ), $count, $reason );
			}
			/* translators: %s: list of skip reasons with counts. */
			$message .= ' ' . sprintf( __( 'Skipped rows: %s.', 'rosette-reviews' ), implode( '; ', $parts ) );
		}

		$this->notices[] = array(
			'type'    => 0 === $res['imported'] && $res['skipped'] > 0 ? 'error' : 'success',
			'message' => $message,
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Caps::manage( 'tools' ) ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import / Export', 'rosette-reviews' ); ?></h1>

			<?php foreach ( $this->notices as $n ) : ?>
				<div class="notice notice-<?php echo 'error' === $n['type'] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $n['message'] ); ?></p></div>
			<?php endforeach; ?>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php esc_html_e( 'Import native WooCommerce reviews', 'rosette-reviews' ); ?></h2></div>
				<p class="description"><?php esc_html_e( 'Enrich existing WooCommerce reviews with Rosette Reviews data (verified status, helpful counter, aggregates). Safe to run more than once.', 'rosette-reviews' ); ?></p>
				<form method="post">
					<?php wp_nonce_field( self::NONCE ); ?>
					<button class="button button-primary" name="ndvr_tools_do" value="woo_backfill"><?php esc_html_e( 'Import / refresh native reviews', 'rosette-reviews' ); ?></button>
				</form>
			</div>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php esc_html_e( 'Import from CSV', 'rosette-reviews' ); ?></h2></div>
				<p class="description"><?php esc_html_e( 'Columns: product_id, author, email, rating (1-5), title, content, date, recommend (yes/no/neutral), verified (1/0), status (approved/pending, optional). Rows already imported are skipped, so the same file can be imported again safely.', 'rosette-reviews' ); ?></p>
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="file" name="ndvr_csv" accept=".csv" />
					<button class="button" name="ndvr_tools_do" value="csv_import"><?php esc_html_e( 'Import CSV', 'rosette-reviews' ); ?></button>
				</form>
			</div>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php esc_html_e( 'Export', 'rosette-reviews' ); ?></h2></div>
				<p class="description"><?php esc_html_e( 'Approved and pending reviews with criteria scores and photo URLs. The CSV can be imported again with the importer above.', 'rosette-reviews' ); ?></p>
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( self::NONCE ); ?>
					<button class="button" name="ndvr_tools_do" value="export_csv"><?php esc_html_e( 'Export CSV', 'rosette-reviews' ); ?></button>
				</form>
				<form method="post" style="display:inline;">
					<?php wp_nonce_field( self::NONCE ); ?>
					<button class="button" name="ndvr_tools_do" value="export_json"><?php esc_html_e( 'Export JSON', 'rosette-reviews' ); ?></button>
				</form>
			</div>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php esc_html_e( 'QR code & shareable review link', 'rosette-reviews' ); ?></h2></div>
				<p class="description"><?php esc_html_e( 'Print a QR code on packaging or receipts. Scanning it opens the product page with the review form in view.', 'rosette-reviews' ); ?></p>
				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$qr_product = isset( $_GET['qr_product'] ) ? absint( wp_unslash( $_GET['qr_product'] ) ) : 0;
				$qr_valid   = $qr_product && 'product' === get_post_type( $qr_product ) && 'publish' === get_post_status( $qr_product );
				?>
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
					<label class="screen-reader-text" for="ndvr-qr-product"><?php esc_html_e( 'Product', 'rosette-reviews' ); ?></label>
					<select id="ndvr-qr-product" name="qr_product" class="wc-product-search" style="min-width:320px;" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'rosette-reviews' ); ?>" data-action="woocommerce_json_search_products" data-allow_clear="true">
						<?php if ( $qr_valid ) : ?>
							<option value="<?php echo esc_attr( $qr_product ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( get_the_title( $qr_product ) ) ); ?></option>
						<?php endif; ?>
					</select>
					<button class="button"><?php esc_html_e( 'Generate', 'rosette-reviews' ); ?></button>
				</form>
				<?php
				if ( $qr_valid ) {
					$link = add_query_arg( 'ndvr_review', 1, get_permalink( $qr_product ) ) . '#reviews';
					$svg  = \NdvReviews\Display\Qr::svg( $link, 200 );
					echo '<p style="margin-top:14px;"><strong>' . esc_html__( 'Review link:', 'rosette-reviews' ) . '</strong> <a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $link ) . '</a></p>';
					if ( '' === $svg ) {
						echo '<p class="ndvr-qr-error"><em>' . esc_html(
							sprintf(
								/* translators: %d: number of characters. */
								__( 'This link is too long to fit in a QR code (%d characters). Use a shorter product permalink, or share the link directly.', 'rosette-reviews' ),
								strlen( $link )
							)
						) . '</em></p>';
					} else {
						echo '<div class="ndvr-qr-box">';
						echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by Qr::svg() from numeric rects; label escaped there.
						echo '</div>';
						$download = 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data: URI for the download link.
						echo '<p><a class="button" download="' . esc_attr( 'review-qr-' . $qr_product . '.svg' ) . '" href="' . esc_url( $download, array( 'data' ) ) . '">' . esc_html__( 'Download SVG', 'rosette-reviews' ) . '</a></p>';
					}
				} elseif ( $qr_product ) {
					echo '<p><em>' . esc_html__( 'That product was not found or is not published.', 'rosette-reviews' ) . '</em></p>';
				}
				?>
			</div>
		</div>
		<?php
	}
}
