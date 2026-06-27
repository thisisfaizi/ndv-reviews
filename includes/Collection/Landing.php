<?php
/**
 * Tokenized multi-product review-collection landing page.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Collection;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Support\View;
use NdvReviews\Reviews\CriteriaRepository;
use NdvReviews\Reviews\ReviewRepository;
use NdvReviews\Forms\AntiSpam;
use NdvReviews\Forms\Upload;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the no-login review page reached from a tokenized link and processes
 * its submissions (verified=1, source=magic_link), updating token state.
 */
class Landing implements Registerable {

	const NONCE       = 'ndvr_collect';
	const AJAX_ACTION = 'ndvr_collect_submit';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Token repository.
	 *
	 * @var TokenRepository
	 */
	private $tokens;

	/**
	 * Criteria repository.
	 *
	 * @var CriteriaRepository
	 */
	private $criteria;

	/**
	 * Review repository.
	 *
	 * @var ReviewRepository
	 */
	private $reviews;

	/**
	 * Anti-spam.
	 *
	 * @var AntiSpam
	 */
	private $antispam;

	/**
	 * Upload handler.
	 *
	 * @var Upload
	 */
	private $upload;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings Settings.
	 * @param TokenRepository    $tokens   Token repository.
	 * @param CriteriaRepository $criteria Criteria repository.
	 * @param ReviewRepository   $reviews  Review repository.
	 * @param AntiSpam           $antispam Anti-spam.
	 * @param Upload             $upload   Upload handler.
	 */
	public function __construct( Settings $settings, TokenRepository $tokens, CriteriaRepository $criteria, ReviewRepository $reviews, AntiSpam $antispam, Upload $upload ) {
		$this->settings = $settings;
		$this->tokens   = $tokens;
		$this->criteria = $criteria;
		$this->reviews  = $reviews;
		$this->antispam = $antispam;
		$this->upload   = $upload;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
	}

	/**
	 * Raw token from the request, if present.
	 *
	 * @return string
	 */
	private function request_token() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, token is the credential.
		if ( ! empty( $_GET['ndvr_k'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['ndvr_k'] ) );
		}
		if ( ! empty( $_GET['ndvr_c'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['ndvr_c'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}

	/**
	 * Render the landing page when a token is present.
	 *
	 * @return void
	 */
	public function maybe_render() {
		$raw = $this->request_token();
		if ( '' === $raw ) {
			return;
		}

		$row = $this->tokens->resolve( $raw );

		nocache_headers();

		$pending = $row ? $this->pending_products( $row ) : array();

		$html = View::render(
			'magic-landing.php',
			array(
				'valid'      => (bool) $row,
				'token'      => $raw,
				'products'   => $pending,
				'criteria'   => $this->criteria->get_active(),
				'settings'   => $this->settings,
				'nonce'      => wp_create_nonce( self::NONCE ),
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'ajax_action' => self::AJAX_ACTION,
			)
		);

		$this->output_page( $html );
		exit;
	}

	/**
	 * Pending (not-yet-reviewed) product ids recorded on a token.
	 *
	 * @param object $row Token row.
	 * @return int[]
	 */
	private function pending_products( $row ) {
		$products = json_decode( (string) $row->products, true );
		$products = is_array( $products ) ? $products : array();
		$out      = array();

		foreach ( $products as $p ) {
			if ( isset( $p['id'], $p['status'] ) && 'reviewed' !== $p['status'] ) {
				$out[] = absint( $p['id'] );
			}
		}

		return $out;
	}

	/**
	 * AJAX: store one product review from the landing page.
	 *
	 * @return void
	 */
	public function handle_submit() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired. Please reload.', 'ndv-reviews' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$input = wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$raw = isset( $input['token'] ) ? sanitize_text_field( $input['token'] ) : '';
		$row = $this->tokens->resolve( $raw );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'This link is no longer valid. Please request a fresh one.', 'ndv-reviews' ) ), 410 );
		}

		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		$pending    = $this->pending_products( $row );
		if ( ! in_array( $product_id, $pending, true ) ) {
			wp_send_json_error( array( 'message' => __( 'This product has already been reviewed.', 'ndv-reviews' ) ), 409 );
		}

		$spam = $this->antispam->check( $input );
		if ( is_wp_error( $spam ) ) {
			wp_send_json_error( array( 'message' => $spam->get_error_message() ), 400 );
		}

		if ( empty( $input['ndvr_consent'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please confirm consent to submit your review.', 'ndv-reviews' ) ), 400 );
		}

		$criteria = array();
		if ( isset( $input['ndvr_criteria'] ) && is_array( $input['ndvr_criteria'] ) ) {
			foreach ( $input['ndvr_criteria'] as $cid => $val ) {
				$criteria[ absint( $cid ) ] = (float) $val;
			}
		}

		$media = array();
		if ( $this->settings->get( 'photo_uploads' ) ) {
			$uploaded = $this->upload->handle( 'ndvr_photos', $product_id );
			if ( is_wp_error( $uploaded ) ) {
				wp_send_json_error( array( 'message' => $uploaded->get_error_message() ), 400 );
			}
			$media = $uploaded;
		}

		$email = $this->token_email( $row );

		$result = $this->reviews->create(
			array(
				'product_id' => $product_id,
				'author'     => isset( $input['author'] ) ? $input['author'] : '',
				'email'      => $email,
				'content'    => isset( $input['comment'] ) ? $input['comment'] : '',
				'title'      => isset( $input['ndvr_title'] ) ? $input['ndvr_title'] : '',
				'recommend'  => isset( $input['ndvr_recommend'] ) ? $input['ndvr_recommend'] : 'neutral',
				'criteria'   => $criteria,
				'media'      => $media,
				'user_id'    => (int) $row->customer_id,
				'source'     => 'magic_link',
				'order_id'   => (int) $row->order_id,
				'approved'   => 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		// Mark verified explicitly: the token proves purchase.
		update_comment_meta( $result, '_ndvr_verified', 1 );
		update_comment_meta( $result, 'verified', 1 );

		$this->tokens->mark_product( (int) $row->id, $product_id, 'reviewed' );
		$this->antispam->record();

		wp_send_json_success(
			array(
				'message' => __( 'Thanks! Your review was submitted and is awaiting moderation.', 'ndv-reviews' ),
			)
		);
	}

	/**
	 * Best-effort author email tied to a token (from the order/customer).
	 *
	 * @param object $row Token row.
	 * @return string
	 */
	private function token_email( $row ) {
		if ( ! empty( $row->order_id ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $row->order_id );
			if ( $order && is_email( $order->get_billing_email() ) ) {
				return $order->get_billing_email();
			}
		}

		if ( ! empty( $row->customer_id ) ) {
			$user = get_userdata( (int) $row->customer_id );
			if ( $user && is_email( $user->user_email ) ) {
				return $user->user_email;
			}
		}

		return '';
	}

	/**
	 * Output a minimal standalone HTML page wrapping the landing content.
	 *
	 * @param string $inner Rendered landing markup.
	 * @return void
	 */
	private function output_page( $inner ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php esc_html_e( 'Write a review', 'ndv-reviews' ); ?> — <?php bloginfo( 'name' ); ?></title>
		<?php
		wp_enqueue_style( 'ndvr-collect', NDVR_URL . 'assets/css/collect.css', array(), NDVR_VERSION );
		wp_enqueue_style( 'ndvr-reviews', NDVR_URL . 'assets/css/reviews.css', array(), NDVR_VERSION );
		wp_print_styles( array( 'ndvr-collect', 'ndvr-reviews' ) );
		?>
</head>
<body class="ndvr-collect-body">
	<main class="ndvr-collect-main">
		<?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</main>
	<?php
	wp_enqueue_script( 'ndvr-collect', NDVR_URL . 'assets/js/collect.js', array(), NDVR_VERSION, true );
	wp_print_scripts( array( 'ndvr-collect' ) );
	?>
</body>
</html>
		<?php
	}
}
