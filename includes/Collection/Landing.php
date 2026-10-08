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
		$valid   = (bool) $row;

		// Once every product is reviewed the token flips to `used`, which
		// resolve() rejects. Show the thank-you state for it rather than
		// "expired"; submissions stay blocked because handle_submit() uses
		// resolve().
		if ( ! $row ) {
			$any = $this->tokens->lookup( $raw );
			if ( $any && 'used' === $any->status ) {
				$valid = true;
			}
		}

		$html = View::render(
			'magic-landing.php',
			array(
				'valid'          => $valid,
				'token'          => $raw,
				'products'       => $pending,
				'criteria'       => $this->criteria->get_active(),
				'settings'       => $this->settings,
				'nonce'          => wp_create_nonce( self::NONCE ),
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'ajax_action'    => self::AJAX_ACTION,
				'default_author' => $row ? $this->default_author( $row ) : '',
				'is_test'        => $row && 'test' === $row->type,
			)
		);

		$this->output_page( $html );
		exit;
	}

	/**
	 * All product ids recorded on a token, whatever their status.
	 *
	 * @param object $row Token row.
	 * @return int[]
	 */
	private function token_products( $row ) {
		$products = json_decode( (string) $row->products, true );
		$out      = array();

		foreach ( is_array( $products ) ? $products : array() as $p ) {
			if ( isset( $p['id'] ) ) {
				$out[] = absint( $p['id'] );
			}
		}

		return $out;
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

		if ( 'test' === $row->type ) {
			wp_send_json_error( array( 'message' => __( 'This is a test link from the store admin. Reviews cannot be submitted from it.', 'ndv-reviews' ) ), 403 );
		}

		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		if ( ! in_array( $product_id, $this->token_products( $row ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This product is not part of your review link.', 'ndv-reviews' ) ), 400 );
		}
		if ( ! in_array( $product_id, $this->pending_products( $row ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This product has already been reviewed.', 'ndv-reviews' ) ), 409 );
		}

		// The resolved token authenticates the request, so the per-IP limit and
		// captcha are skipped; the honeypot still runs.
		$spam = $this->antispam->check( $input, true );
		if ( is_wp_error( $spam ) ) {
			wp_send_json_error( array( 'message' => $spam->get_error_message() ), 400 );
		}

		if ( empty( $input['ndvr_consent'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please confirm consent to submit your review.', 'ndv-reviews' ) ), 400 );
		}

		// A rating-less review displays but is silently excluded from the
		// WooCommerce product average, so at least one valid score is required.
		// Values outside the star range get their own message instead of being
		// dropped silently.
		$raw_scores = isset( $input['ndvr_criteria'] ) && is_array( $input['ndvr_criteria'] ) ? $input['ndvr_criteria'] : array();
		foreach ( $raw_scores as $val ) {
			if ( is_scalar( $val ) && '' !== $val && ( ! is_numeric( $val ) || (float) $val < 0.5 || (float) $val > 5 ) ) {
				wp_send_json_error( array( 'message' => __( 'Please choose a rating between 1 and 5 stars.', 'ndv-reviews' ) ), 400 );
			}
		}
		$criteria = $this->reviews->valid_scores( $raw_scores );

		if ( empty( $criteria ) ) {
			wp_send_json_error( array( 'message' => __( 'Please give a star rating before submitting your review.', 'ndv-reviews' ) ), 400 );
		}

		// Theme overrides of the template may not have the name field, so an
		// empty or missing value falls back to the order's name.
		$author = isset( $input['author'] ) && is_string( $input['author'] ) ? trim( sanitize_text_field( $input['author'] ) ) : '';
		if ( '' === $author ) {
			$author = $this->default_author( $row );
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
				'author'     => mb_substr( $author, 0, 60 ),
				'email'      => $email,
				'content'    => isset( $input['comment'] ) ? $input['comment'] : '',
				'title'      => isset( $input['ndvr_title'] ) ? $input['ndvr_title'] : '',
				'recommend'  => isset( $input['ndvr_recommend'] ) ? $input['ndvr_recommend'] : 'neutral',
				'criteria'   => $criteria,
				'media'      => $media,
				'user_id'    => (int) $row->customer_id,
				'source'     => 'magic_link',
				'order_id'   => (int) $row->order_id,
				'consent'    => ! empty( $input['ndvr_consent'] ),
				'approved'   => 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			// Delete orphaned uploads when body validation fails (no media flood),
			// matching ReviewForm/TestimonialForm's existing behavior.
			foreach ( $media as $orphan_id ) {
				wp_delete_attachment( (int) $orphan_id, true );
			}
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		// Mark verified explicitly: the token proves purchase.
		update_comment_meta( $result, '_ndvr_verified', 1 );
		update_comment_meta( $result, 'verified', 1 );

		$this->tokens->mark_product( (int) $row->id, $product_id, 'reviewed' );

		wp_send_json_success(
			array(
				'message' => 'approved' === wp_get_comment_status( $result )
					? __( 'Thank you. Your review is published.', 'ndv-reviews' )
					: __( 'Thank you. Your review was submitted and is awaiting moderation.', 'ndv-reviews' ),
			)
		);
	}

	/**
	 * Name shown with the review unless the customer types another: billing
	 * first name plus last initial ("Jane D."). Never the account login, which
	 * is often the customer's email address.
	 *
	 * @param object $row Token row.
	 * @return string
	 */
	private function default_author( $row ) {
		$first = '';
		$last  = '';

		if ( ! empty( $row->order_id ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $row->order_id );
			if ( $order ) {
				$first = $order->get_billing_first_name();
				$last  = $order->get_billing_last_name();
			}
		}

		// Customer tokens have no order: use the account's saved names.
		if ( '' === trim( (string) $first ) && ! empty( $row->customer_id ) ) {
			$uid   = (int) $row->customer_id;
			$first = (string) get_user_meta( $uid, 'billing_first_name', true );
			$last  = (string) get_user_meta( $uid, 'billing_last_name', true );
			if ( '' === trim( $first ) ) {
				$first = (string) get_user_meta( $uid, 'first_name', true );
				$last  = (string) get_user_meta( $uid, 'last_name', true );
			}
		}

		$first = trim( sanitize_text_field( (string) $first ) );
		$last  = trim( sanitize_text_field( (string) $last ) );

		if ( '' === $first ) {
			return __( 'Customer', 'ndv-reviews' );
		}
		if ( '' === $last ) {
			return $first;
		}

		$initial = mb_substr( $last, 0, 1 );
		$initial = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initial ) : strtoupper( $initial );

		return $first . ' ' . $initial . '.';
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
		// This standalone page never calls wp_head()/wp_enqueue_scripts, so the
		// shared tokens handle (normally registered by Support\Assets on that
		// hook) must be registered directly here too.
		if ( ! wp_style_is( 'ndvr-tokens', 'registered' ) ) {
			wp_register_style( 'ndvr-tokens', NDVR_URL . 'assets/css/tokens.css', array(), NDVR_VERSION );
		}
		wp_enqueue_style( 'ndvr-collect', NDVR_URL . 'assets/css/collect.css', array( 'ndvr-tokens' ), NDVR_VERSION );
		wp_enqueue_style( 'ndvr-reviews', NDVR_URL . 'assets/css/reviews.css', array( 'ndvr-tokens' ), NDVR_VERSION );
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
