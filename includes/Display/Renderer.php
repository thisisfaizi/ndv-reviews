<?php
/**
 * Front-end review display: takes over the WooCommerce reviews tab.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Support\View;
use NdvReviews\Reviews\ReviewQuery;
use NdvReviews\Reviews\Votes;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the summary box, filter/sort bar, paginated review list, and the
 * review form inside the product reviews tab, with AJAX filtering.
 */
class Renderer implements Registerable {

	const AJAX_ACTION = 'ndvr_list_reviews';
	const NONCE       = 'ndvr_list_reviews';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Summary service.
	 *
	 * @var Summary
	 */
	private $summary;

	/**
	 * Review query.
	 *
	 * @var ReviewQuery
	 */
	private $query;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings.
	 * @param Summary     $summary  Summary service.
	 * @param ReviewQuery $query    Review query.
	 */
	public function __construct( Settings $settings, Summary $summary, ReviewQuery $query ) {
		$this->settings = $settings;
		$this->summary  = $summary;
		$this->query    = $query;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_product_tabs', array( $this, 'take_over_reviews_tab' ), 98 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_list' ) );
	}

	/**
	 * Point the reviews tab at our renderer.
	 *
	 * @param array<string,mixed> $tabs Product tabs.
	 * @return array<string,mixed>
	 */
	public function take_over_reviews_tab( $tabs ) {
		if ( isset( $tabs['reviews'] ) && $this->settings->get( 'enable_reviews', true ) ) {
			$tabs['reviews']['callback'] = array( $this, 'render_reviews_tab' );
		}

		return $tabs;
	}

	/**
	 * Enqueue display assets on product pages.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! ( function_exists( 'is_product' ) && is_product() ) ) {
			return;
		}

		wp_enqueue_style( 'ndvr-display', NDVR_URL . 'assets/css/display.css', array(), NDVR_VERSION );
		wp_enqueue_script( 'ndvr-display', NDVR_URL . 'assets/js/display.js', array(), NDVR_VERSION, true );

		wp_localize_script(
			'ndvr-display',
			'ndvrDisplay',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'action'    => self::AJAX_ACTION,
				'nonce'     => wp_create_nonce( self::NONCE ),
				'voteAction' => Votes::AJAX_ACTION,
			)
		);
	}

	/**
	 * Render the entire reviews tab.
	 *
	 * @return void
	 */
	public function render_reviews_tab() {
		$product_id = get_the_ID();
		$summary    = $this->summary->for_product( $product_id );

		echo '<div id="ndvr-reviews" class="ndvr-reviews-wrap" data-product="' . esc_attr( $product_id ) . '">';

		// Summary (pre-escaped template output).
		echo View::render( 'summary.php', array( 'summary' => $summary ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! empty( $summary['count'] ) ) {
			$this->render_filter_bar();
		}

		$result = $this->query->paginate(
			array(
				'product_id' => $product_id,
				'per_page'   => (int) apply_filters( 'ndv-reviews/per_page', 10 ),
			)
		);

		echo '<div id="ndvr-review-list" class="ndvr-review-list-wrap">';
		echo View::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'review-list.php',
			array(
				'result'     => $result,
				'vote_nonce' => wp_create_nonce( Votes::NONCE_ACTION ),
			)
		);
		echo '</div>';

		$this->render_form( $product_id );

		echo '</div>';
	}

	/**
	 * Render the filter and sort controls.
	 *
	 * @return void
	 */
	private function render_filter_bar() {
		?>
		<div class="ndvr-filter-bar" role="region" aria-label="<?php esc_attr_e( 'Filter reviews', 'ndv-reviews' ); ?>">
			<div class="ndvr-filter-stars" role="group" aria-label="<?php esc_attr_e( 'Filter by star', 'ndv-reviews' ); ?>">
				<button type="button" class="ndvr-filter is-current" data-filter="star" data-value="0"><?php esc_html_e( 'All', 'ndv-reviews' ); ?></button>
				<?php for ( $ndvr_s = 5; $ndvr_s >= 1; $ndvr_s-- ) : ?>
					<button type="button" class="ndvr-filter" data-filter="star" data-value="<?php echo esc_attr( $ndvr_s ); ?>"><?php echo esc_html( $ndvr_s ); ?>★</button>
				<?php endfor; ?>
			</div>
			<label class="ndvr-filter-toggle"><input type="checkbox" data-filter="verified" /> <?php esc_html_e( 'Verified only', 'ndv-reviews' ); ?></label>
			<label class="ndvr-filter-toggle"><input type="checkbox" data-filter="with_media" /> <?php esc_html_e( 'With photos', 'ndv-reviews' ); ?></label>
			<label class="ndvr-sort">
				<span class="screen-reader-text"><?php esc_html_e( 'Sort reviews', 'ndv-reviews' ); ?></span>
				<select data-filter="orderby">
					<option value="recent"><?php esc_html_e( 'Most recent', 'ndv-reviews' ); ?></option>
					<option value="helpful"><?php esc_html_e( 'Most helpful', 'ndv-reviews' ); ?></option>
					<option value="highest"><?php esc_html_e( 'Highest rated', 'ndv-reviews' ); ?></option>
					<option value="lowest"><?php esc_html_e( 'Lowest rated', 'ndv-reviews' ); ?></option>
				</select>
			</label>
		</div>
		<?php
	}

	/**
	 * Render the review form (mirrors WooCommerce's gating, lets our Phase 1
	 * field-injection filter apply).
	 *
	 * @param int $product_id Product id.
	 * @return void
	 */
	private function render_form( $product_id ) {
		if ( ! comments_open( $product_id ) ) {
			return;
		}

		$verification_required = 'yes' === get_option( 'woocommerce_review_rating_verification_required' );
		$can_review            = ! $verification_required
			|| ( is_user_logged_in() && wc_customer_bought_product( '', get_current_user_id(), $product_id ) );

		echo '<div id="ndvr-review-form-wrap" class="ndvr-review-form-wrap">';

		if ( ! $can_review ) {
			echo '<p class="ndvr-verification-required woocommerce-verification-required">' .
				esc_html__( 'Only logged in customers who have purchased this product may leave a review.', 'ndv-reviews' ) .
				'</p>';
			echo '</div>';
			return;
		}

		$commenter    = wp_get_current_commenter();
		$comment_form = array(
			'title_reply'         => esc_html__( 'Add a review', 'ndv-reviews' ),
			'title_reply_before'  => '<span id="reply-title" class="comment-reply-title ndvr-form-title">',
			'title_reply_after'   => '</span>',
			'comment_notes_after' => '',
			'label_submit'        => esc_html__( 'Submit review', 'ndv-reviews' ),
			'logged_in_as'        => '',
			'comment_field'       => '',
		);

		if ( $commenter['comment_author_email'] ) {
			$comment_form['logged_in_as'] = '';
		}

		/** This filter is documented by WooCommerce core. */
		$comment_form = apply_filters( 'woocommerce_product_review_comment_form_args', $comment_form );

		comment_form( $comment_form, $product_id );

		echo '</div>';
	}

	/**
	 * AJAX: return a rendered review-list fragment for the given filters.
	 *
	 * @return void
	 */
	public function ajax_list() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired.', 'ndv-reviews' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$star       = isset( $_POST['star'] ) ? absint( $_POST['star'] ) : 0;
		$verified   = ! empty( $_POST['verified'] );
		$with_media = ! empty( $_POST['with_media'] );
		$orderby    = isset( $_POST['orderby'] ) ? sanitize_key( wp_unslash( $_POST['orderby'] ) ) : 'recent';
		$page       = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->query->paginate(
			array(
				'product_id' => $product_id,
				'star'       => $star,
				'verified'   => $verified,
				'with_media' => $with_media,
				'orderby'    => $orderby,
				'page'       => $page,
				'per_page'   => (int) apply_filters( 'ndv-reviews/per_page', 10 ),
			)
		);

		$html = View::render(
			'review-list.php',
			array(
				'result'     => $result,
				'vote_nonce' => wp_create_nonce( Votes::NONCE_ACTION ),
			)
		);

		wp_send_json_success( array( 'html' => $html ) );
	}
}
