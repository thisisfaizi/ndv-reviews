<?php
/**
 * Standalone testimonial / review form (no order required).
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
 * A shortcode/block form to collect a review or testimonial outside the normal
 * WooCommerce flow (for services, landing pages, etc.). Submissions enter the
 * same moderation queue flagged source=form.
 */
class TestimonialForm implements Registerable {

	const NONCE       = 'ndvr_testimonial';
	const AJAX_ACTION = 'ndvr_testimonial_submit';

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
		add_shortcode( 'ndvr-testimonial', array( $this, 'shortcode' ) );
		add_shortcode( 'ndvr-form', array( $this, 'shortcode' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_submit' ) );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'product_id' => 0,
				'title'      => __( 'Leave a review', 'ndv-reviews' ),
			),
			$atts,
			'ndvr-testimonial'
		);

		$product_id = absint( $atts['product_id'] );
		if ( ! $product_id ) {
			$product_id = (int) get_the_ID();
		}

		wp_enqueue_style( 'ndvr-reviews', NDVR_URL . 'assets/css/reviews.css', array(), NDVR_VERSION );
		wp_enqueue_script( 'ndvr-collect', NDVR_URL . 'assets/js/collect.js', array(), NDVR_VERSION, true );

		return $this->render( $product_id, $atts['title'] );
	}

	/**
	 * Render the form markup.
	 *
	 * @param int    $product_id Product id the review attaches to.
	 * @param string $title      Heading.
	 * @return string
	 */
	private function render( $product_id, $title ) {
		$criteria = $this->criteria->get_active();

		ob_start();
		?>
		<div class="ndvr-collect" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>">
			<form class="ndvr-collect-card ndvr-collect-form" data-product="<?php echo esc_attr( $product_id ); ?>">
				<?php if ( $title ) : ?>
					<h3 class="ndvr-collect-name"><?php echo esc_html( $title ); ?></h3>
				<?php endif; ?>
				<div class="ndvr-fields">
					<?php if ( ! empty( $criteria ) ) : ?>
						<div class="ndvr-criteria-group">
							<?php foreach ( $criteria as $c ) : ?>
								<fieldset class="ndvr-criterion">
									<legend class="ndvr-criterion-label"><?php echo esc_html( $c->name ); ?></legend>
									<div class="ndvr-stars" role="radiogroup" aria-label="<?php echo esc_attr( $c->name ); ?>">
										<?php for ( $s = 5; $s >= 1; $s-- ) : ?>
											<?php $fid = 't-c' . (int) $c->id . '-s' . $s; ?>
											<input class="ndvr-star-input" type="radio" id="<?php echo esc_attr( $fid ); ?>" name="ndvr_criteria[<?php echo esc_attr( $c->id ); ?>]" value="<?php echo esc_attr( $s ); ?>" />
											<label class="ndvr-star-label" for="<?php echo esc_attr( $fid ); ?>"><span class="screen-reader-text"><?php echo esc_html( $s ); ?></span></label>
										<?php endfor; ?>
									</div>
								</fieldset>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<p class="ndvr-field"><label><?php esc_html_e( 'Your name', 'ndv-reviews' ); ?> <span class="required">*</span><input type="text" name="author" required /></label></p>
					<p class="ndvr-field"><label><?php esc_html_e( 'Your email', 'ndv-reviews' ); ?> <span class="required">*</span><input type="email" name="email" required /></label></p>
					<p class="ndvr-field"><label><?php esc_html_e( 'Review title (optional)', 'ndv-reviews' ); ?><input type="text" name="ndvr_title" maxlength="150" /></label></p>
					<p class="ndvr-field"><label><?php esc_html_e( 'Your review', 'ndv-reviews' ); ?> <span class="required">*</span><textarea name="comment" rows="5" required></textarea></label></p>

					<?php if ( $this->settings->get( 'photo_uploads' ) ) : ?>
						<p class="ndvr-field"><label><?php esc_html_e( 'Add photos (optional)', 'ndv-reviews' ); ?><input type="file" name="ndvr_photos[]" accept="image/*" multiple="multiple" /></label></p>
					<?php endif; ?>

					<p class="ndvr-field ndvr-field-consent"><label><input type="checkbox" name="ndvr_consent" value="1" required /> <?php esc_html_e( 'I consent to my review being stored and published.', 'ndv-reviews' ); ?></label></p>

					<p class="ndvr-hp" aria-hidden="true" style="position:absolute;left:-9999px;">
						<input type="text" name="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
					</p>

					<input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>" />
					<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>" />

					<div class="ndvr-collect-actions">
						<button type="submit" class="ndvr-collect-submit"><?php esc_html_e( 'Submit review', 'ndv-reviews' ); ?></button>
						<span class="ndvr-form-message" role="status" aria-live="polite"></span>
					</div>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * AJAX submission handler.
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

		$spam = $this->antispam->check( $input );
		if ( is_wp_error( $spam ) ) {
			wp_send_json_error( array( 'message' => $spam->get_error_message() ), 400 );
		}

		if ( empty( $input['ndvr_consent'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please confirm consent.', 'ndv-reviews' ) ), 400 );
		}

		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;

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

		$result = $this->reviews->create(
			array(
				'product_id' => $product_id,
				'author'     => isset( $input['author'] ) ? $input['author'] : '',
				'email'      => isset( $input['email'] ) ? $input['email'] : '',
				'content'    => isset( $input['comment'] ) ? $input['comment'] : '',
				'title'      => isset( $input['ndvr_title'] ) ? $input['ndvr_title'] : '',
				'criteria'   => $criteria,
				'media'      => $media,
				'user_id'    => get_current_user_id(),
				'source'     => 'form',
				'consent'    => true,
				'approved'   => 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$this->antispam->record();

		wp_send_json_success( array( 'message' => __( 'Thank you! Your review is awaiting moderation.', 'ndv-reviews' ) ) );
	}
}
