<?php
/**
 * Front-end review form: injects multi-criteria fields into the WooCommerce
 * reviews area and handles AJAX submission.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Forms;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Reviews\CriteriaRepository;
use NdvReviews\Reviews\ReviewRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the review form and processes submissions over AJAX (no full reload).
 */
class ReviewForm implements Registerable {

	const NONCE_ACTION = 'ndvr_submit_review';
	const AJAX_ACTION  = 'ndvr_submit_review';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

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
	 * @param CriteriaRepository $criteria Criteria repository.
	 * @param ReviewRepository   $reviews  Review repository.
	 * @param AntiSpam           $antispam Anti-spam.
	 * @param Upload             $upload   Upload handler.
	 */
	public function __construct( Settings $settings, CriteriaRepository $criteria, ReviewRepository $reviews, AntiSpam $antispam, Upload $upload ) {
		$this->settings = $settings;
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
		add_filter( 'woocommerce_product_review_comment_form_args', array( $this, 'customize_review_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		// Gate the WooCommerce review form for logged-out users when disabled.
		add_filter( 'pre_option_comment_registration', array( $this, 'maybe_require_login' ) );
	}

	/**
	 * When guest reviews are disabled, tell WooCommerce that login is required.
	 * WooCommerce reads comment_registration to decide whether to show the form
	 * or a "must log in" prompt — we filter it dynamically so we never touch
	 * the site-wide WP Discussion setting.
	 *
	 * @param mixed $pre Existing pre-filter value.
	 * @return mixed '1' to require login, original $pre otherwise.
	 */
	public function maybe_require_login( $pre ) {
		if ( $this->is_active_context() && ! $this->settings->get( 'allow_guest_reviews', true ) ) {
			return '1';
		}
		return $pre;
	}

	/**
	 * Whether we are on a single product page with reviews enabled.
	 *
	 * @return bool
	 */
	private function is_active_context() {
		return function_exists( 'is_product' ) && is_product() && $this->settings->get( 'enable_reviews', true );
	}

	/**
	 * Conditionally enqueue the form assets (only where the form renders).
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->is_active_context() ) {
			return;
		}

		wp_enqueue_style( 'ndvr-reviews', NDVR_URL . 'assets/css/reviews.css', array(), NDVR_VERSION );
		wp_enqueue_script( 'ndvr-reviews', NDVR_URL . 'assets/js/reviews.js', array(), NDVR_VERSION, true );

		wp_localize_script(
			'ndvr-reviews',
			'ndvrReviews',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'siteKey' => $this->settings->get( 'recaptcha_enabled' ) ? (string) $this->settings->get( 'recaptcha_site_key' ) : false,
				'i18n'    => array(
					'submitting' => __( 'Submitting…', 'ndv-reviews' ),
					'thanks'     => __( 'Thank you! Your review has been submitted and is awaiting moderation.', 'ndv-reviews' ),
					'error'      => __( 'Something went wrong. Please try again.', 'ndv-reviews' ),
				),
			)
		);

		if ( $this->settings->get( 'recaptcha_enabled' ) && $this->settings->get( 'recaptcha_site_key' ) ) {
			wp_enqueue_script(
				'ndvr-recaptcha',
				'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $this->settings->get( 'recaptcha_site_key' ) ),
				array(),
				NDVR_VERSION,
				true
			);
		}
	}

	/**
	 * Replace WooCommerce's single rating select with our multi-criteria field set.
	 *
	 * @param array<string,mixed> $args comment_form() args from WooCommerce.
	 * @return array<string,mixed>
	 */
	public function customize_review_form( $args ) {
		$args['comment_field'] = $this->render_fields();

		// Mark the form so our JS can take over submission.
		$args['id_form'] = 'ndvr-review-form';

		return $args;
	}

	/**
	 * Build the markup for our review fields.
	 *
	 * @return string
	 */
	private function render_fields() {
		$criteria  = $this->criteria->get_active();
		$recaptcha = $this->settings->get( 'recaptcha_enabled' ) && $this->settings->get( 'recaptcha_site_key' );

		ob_start();
		?>
		<div class="ndvr-fields">
			<?php if ( ! empty( $criteria ) ) : ?>
				<div class="ndvr-criteria-group">
					<?php foreach ( $criteria as $criterion ) : ?>
						<fieldset class="ndvr-criterion" data-criteria-id="<?php echo esc_attr( $criterion->id ); ?>">
							<legend class="ndvr-criterion-label"><?php echo esc_html( $criterion->name ); ?></legend>
							<div class="ndvr-stars" role="radiogroup" aria-label="<?php echo esc_attr( $criterion->name ); ?>">
								<?php for ( $star = 5; $star >= 1; $star-- ) : ?>
									<?php $field_id = 'ndvr-c' . (int) $criterion->id . '-s' . $star; ?>
									<input
										class="ndvr-star-input"
										type="radio"
										id="<?php echo esc_attr( $field_id ); ?>"
										name="ndvr_criteria[<?php echo esc_attr( $criterion->id ); ?>]"
										value="<?php echo esc_attr( $star ); ?>"
									/>
									<label class="ndvr-star-label" for="<?php echo esc_attr( $field_id ); ?>">
										<span class="screen-reader-text">
											<?php
											/* translators: %d: number of stars. */
											echo esc_html( sprintf( _n( '%d star', '%d stars', $star, 'ndv-reviews' ), $star ) );
											?>
										</span>
									</label>
								<?php endfor; ?>
							</div>
						</fieldset>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p class="ndvr-field ndvr-field-title comment-form-title">
				<label for="ndvr-title"><?php esc_html_e( 'Review title (optional)', 'ndv-reviews' ); ?></label>
				<input id="ndvr-title" name="ndvr_title" type="text" maxlength="150" />
			</p>

			<p class="ndvr-field ndvr-field-comment comment-form-comment">
				<label for="comment"><?php esc_html_e( 'Your review', 'ndv-reviews' ); ?>&nbsp;<span class="required">*</span></label>
				<textarea id="comment" name="comment" cols="45" rows="6" required></textarea>
			</p>

			<fieldset class="ndvr-field ndvr-field-recommend">
				<legend><?php esc_html_e( 'Would you recommend this product?', 'ndv-reviews' ); ?></legend>
				<label><input type="radio" name="ndvr_recommend" value="yes" /> <?php esc_html_e( 'Yes', 'ndv-reviews' ); ?></label>
				<label><input type="radio" name="ndvr_recommend" value="neutral" checked="checked" /> <?php esc_html_e( 'Neutral', 'ndv-reviews' ); ?></label>
				<label><input type="radio" name="ndvr_recommend" value="no" /> <?php esc_html_e( 'No', 'ndv-reviews' ); ?></label>
			</fieldset>

			<?php if ( $this->settings->get( 'photo_uploads' ) ) : ?>
				<div class="ndvr-field ndvr-field-photos">
					<label for="ndvr-photos"><?php esc_html_e( 'Add photos (optional)', 'ndv-reviews' ); ?></label>
					<div class="ndvr-upload-wrapper">
						<input id="ndvr-photos" name="ndvr_photos[]" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple="multiple" />
						<div class="ndvr-upload-zone" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3" ry="3"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
							<span class="ndvr-upload-text"><?php esc_html_e( 'Click or drag photos here', 'ndv-reviews' ); ?></span>
							<span class="ndvr-upload-hint"><?php esc_html_e( 'JPEG · PNG · WEBP', 'ndv-reviews' ); ?></span>
							<span class="ndvr-upload-count"></span>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php
			/**
			 * Fires inside the review form before the consent field (Pro adds the
			 * video field, anonymous toggle, etc.).
			 *
			 * @param \NdvReviews\Reviews\Criteria[] $criteria Active criteria.
			 */
			do_action( 'ndv-reviews/review_form_fields', $criteria );
			?>

			<p class="ndvr-field ndvr-field-consent">
				<label>
					<input type="checkbox" name="ndvr_consent" value="1" required />
					<?php esc_html_e( 'I consent to my review and details being stored and published.', 'ndv-reviews' ); ?>
				</label>
			</p>

			<?php // Honeypot — visually hidden, must stay empty. ?>
			<p class="ndvr-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;">
				<label for="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>"><?php esc_html_e( 'Leave this field empty', 'ndv-reviews' ); ?></label>
				<input type="text" id="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>" name="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
			</p>

			<input type="hidden" name="ndvr_recaptcha_token" value="" <?php echo $recaptcha ? 'data-recaptcha="1"' : ''; ?> />
			<?php wp_nonce_field( self::NONCE_ACTION, 'ndvr_nonce' ); ?>

			<div class="ndvr-form-message" role="status" aria-live="polite"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * AJAX handler: validate, run anti-spam, store the review.
	 *
	 * @return void
	 */
	public function handle_submit() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'ndvr_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Please reload the page.', 'ndv-reviews' ) ), 403 );
		}

		if ( ! $this->settings->get( 'allow_guest_reviews', true ) && ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to submit a review.', 'ndv-reviews' ) ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$input = wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$spam = $this->antispam->check( $input );
		if ( is_wp_error( $spam ) ) {
			wp_send_json_error( array( 'message' => $spam->get_error_message() ), 400 );
		}

		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		if ( ! $product_id && isset( $input['comment_post_ID'] ) ) {
			$product_id = absint( $input['comment_post_ID'] );
		}

		if ( empty( $input['ndvr_consent'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please confirm consent to submit your review.', 'ndv-reviews' ) ), 400 );
		}

		// Criteria scores.
		$criteria = array();
		if ( isset( $input['ndvr_criteria'] ) && is_array( $input['ndvr_criteria'] ) ) {
			foreach ( $input['ndvr_criteria'] as $cid => $val ) {
				$criteria[ absint( $cid ) ] = (float) $val;
			}
		}

		// Photos (uploaded via FormData).
		$media = array();
		if ( $this->settings->get( 'photo_uploads' ) ) {
			$uploaded = $this->upload->handle( 'ndvr_photos', $product_id );
			if ( is_wp_error( $uploaded ) ) {
				wp_send_json_error( array( 'message' => $uploaded->get_error_message() ), 400 );
			}
			$media = $uploaded;
		}

		$user_id = get_current_user_id();

		$result = $this->reviews->create(
			array(
				'product_id' => $product_id,
				'author'     => isset( $input['author'] ) ? $input['author'] : '',
				'email'      => isset( $input['email'] ) ? $input['email'] : '',
				'content'    => isset( $input['comment'] ) ? $input['comment'] : '',
				'title'      => isset( $input['ndvr_title'] ) ? $input['ndvr_title'] : '',
				'recommend'  => isset( $input['ndvr_recommend'] ) ? $input['ndvr_recommend'] : 'neutral',
				'criteria'   => $criteria,
				'media'      => $media,
				'user_id'    => $user_id,
				'source'     => 'onsite',
				'consent'    => ! empty( $input['ndvr_consent'] ),
				'approved'   => 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$this->antispam->record();

		wp_send_json_success(
			array(
				'message' => __( 'Thank you! Your review has been submitted and is awaiting moderation.', 'ndv-reviews' ),
			)
		);
	}
}
