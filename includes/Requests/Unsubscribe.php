<?php
/**
 * Review-request unsubscribe endpoint.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Honors one-click unsubscribe links from reminder emails (HMAC-verified).
 */
class Unsubscribe implements Registerable {

	/**
	 * Mailer (owns the suppression list + key).
	 *
	 * @var Mailer
	 */
	private $mailer;

	/**
	 * Constructor.
	 *
	 * @param Mailer $mailer Mailer.
	 */
	public function __construct( Mailer $mailer ) {
		$this->mailer = $mailer;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ) );
	}

	/**
	 * Handle an unsubscribe request.
	 *
	 * @return void
	 */
	public function maybe_handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- HMAC key is the credential.
		if ( empty( $_GET['ndvr_unsub'] ) || empty( $_GET['ndvr_key'] ) ) {
			return;
		}

		$email = sanitize_email( wp_unslash( $_GET['ndvr_unsub'] ) );
		$key   = sanitize_text_field( wp_unslash( $_GET['ndvr_key'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$ok = is_email( $email ) && hash_equals( $this->mailer->unsub_key( $email ), $key );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		if ( $ok ) {
			$this->mailer->suppress( $email );
			$message = __( 'You have been unsubscribed from review requests.', 'ndv-reviews' );
		} else {
			$message = __( 'This unsubscribe link is invalid or has expired.', 'ndv-reviews' );
		}
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php esc_html_e( 'Unsubscribe', 'ndv-reviews' ); ?></title>
	<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7f9;color:#1f2430;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;}div{background:#fff;border:1px solid #e6e8ec;border-radius:14px;padding:32px 36px;max-width:460px;text-align:center;}a{color:#2563eb;}</style>
</head>
<body>
	<div>
		<h1><?php esc_html_e( 'Review requests', 'ndv-reviews' ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Return to the store', 'ndv-reviews' ); ?></a></p>
	</div>
</body>
</html>
		<?php
		exit;
	}
}
