<?php
/**
 * Review-request tracking settings and the optional open pixel (RR-09).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Admin\SettingsFields;
use NdvReviews\Installer;
use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Tracking" settings (UTM tags, open pixel; both off by
 * default) and answers the pixel URL. Link opens and reviews are recorded by
 * the landing page and need no pixel.
 */
class Tracking implements Registerable {

	/**
	 * Query var carrying "<request id>.<hmac>".
	 */
	const PIXEL_VAR = 'ndvr_px';

	/**
	 * Request log.
	 *
	 * @var RequestRepository
	 */
	private $requests;

	/**
	 * Constructor.
	 *
	 * @param RequestRepository $requests Request log.
	 */
	public function __construct( RequestRepository $requests ) {
		$this->requests = $requests;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'ndv-reviews/settings_fields', array( $this, 'register_fields' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve_pixel' ), 1 );
	}

	/**
	 * The two Tracking settings (Reminders page, card "tracking").
	 *
	 * @param array<string,array<string,mixed>> $fields Registered fields.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_fields( $fields ) {
		$fields = (array) $fields;

		$fields['reminder_utm'] = array(
			'sanitize' => array( __CLASS__, 'sanitize_bool' ),
			'default'  => false,
			'page'     => 'reminders',
			'card'     => 'tracking',
			'render'   => array( $this, 'render_utm' ),
		);

		$fields['reminder_open_pixel'] = array(
			'sanitize' => array( __CLASS__, 'sanitize_bool' ),
			'default'  => false,
			'page'     => 'reminders',
			'card'     => 'tracking',
			'render'   => array( $this, 'render_pixel' ),
		);

		return $fields;
	}

	/**
	 * Checkbox sanitizer: absent (null) is false.
	 *
	 * @param mixed $raw Posted value or null.
	 * @return bool
	 */
	public static function sanitize_bool( $raw ) {
		return null !== $raw && '' !== $raw && '0' !== (string) $raw;
	}

	/**
	 * Render the UTM checkbox.
	 *
	 * @param mixed $value Current value.
	 * @return void
	 */
	public function render_utm( $value ) {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Link tags', 'rosette-reviews' ); ?></th>
			<td>
				<label><input type="checkbox" name="reminder_utm" value="1" <?php checked( (bool) $value ); ?> /> <?php esc_html_e( 'Add UTM tags to the review link', 'rosette-reviews' ); ?></label>
				<p class="description"><?php esc_html_e( 'Adds utm_source=rosette-reviews, utm_medium=email and utm_campaign=review-request, so your analytics can count these visits.', 'rosette-reviews' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the open-pixel checkbox.
	 *
	 * @param mixed $value Current value.
	 * @return void
	 */
	public function render_pixel( $value ) {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Email opens', 'rosette-reviews' ); ?></th>
			<td>
				<label><input type="checkbox" name="reminder_open_pixel" value="1" <?php checked( (bool) $value ); ?> /> <?php esc_html_e( 'Count email opens with a tracking image', 'rosette-reviews' ); ?></label>
				<p class="description"><?php esc_html_e( 'Adds a 1×1 image to the email. Some mail apps block it, and some privacy laws need consent for it. Link clicks and reviews are counted without it.', 'rosette-reviews' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Signed pixel URL for a request.
	 *
	 * @param int $request_id Request id.
	 * @return string
	 */
	public static function pixel_url( $request_id ) {
		$request_id = absint( $request_id );

		return add_query_arg( self::PIXEL_VAR, $request_id . '.' . self::signature( $request_id ), home_url( '/' ) );
	}

	/**
	 * HMAC for a request id (first 20 hex characters).
	 *
	 * @param int $request_id Request id.
	 * @return string
	 */
	private static function signature( $request_id ) {
		return substr( wp_hash( 'ndvr_px|' . absint( $request_id ) ), 0, 20 );
	}

	/**
	 * Answer a pixel request: a valid signature records the first open (one
	 * UPDATE); every request gets the same transparent GIF. Public and
	 * nonce-less by design (an email client can't send a nonce); the HMAC is
	 * the credential, and nothing but opened_at can change.
	 *
	 * @return void
	 */
	public function maybe_serve_pixel() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public tracking image, HMAC-signed.
		if ( ! isset( $_GET[ self::PIXEL_VAR ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public tracking image, HMAC-signed.
		$raw   = sanitize_text_field( wp_unslash( $_GET[ self::PIXEL_VAR ] ) );
		$parts = explode( '.', $raw, 2 );
		$id    = absint( $parts[0] );
		$sig   = isset( $parts[1] ) ? (string) $parts[1] : '';

		if ( $id && '' !== $sig && hash_equals( self::signature( $id ), $sig ) && Installer::is_current( Installer::V_PIPELINE ) ) {
			$this->requests->mark_opened( $id );
		}

		// A 1x1 transparent GIF.
		$gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fixed image bytes.

		nocache_headers();
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: ' . strlen( $gif ) );
		echo $gif; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed image bytes.
		exit;
	}

	/**
	 * Print the "Tracking" section inside the Reminders form: the field
	 * markers (outside any table, so the HTML stays valid), then the rows.
	 *
	 * @param array<string,mixed> $settings Current settings.
	 * @return void
	 */
	public static function render_section( array $settings ) {
		if ( ! SettingsFields::card_fields( 'reminders', 'tracking' ) ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Tracking', 'rosette-reviews' ); ?></h3>
		<?php SettingsFields::markers( 'reminders', 'tracking' ); ?>
		<table class="form-table" role="presentation">
			<?php SettingsFields::render_card_fields( 'reminders', 'tracking', $settings, false ); ?>
		</table>
		<?php
	}
}
