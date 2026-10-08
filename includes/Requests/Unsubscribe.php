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
 * Honors unsubscribe links from reminder emails (HMAC-verified).
 *
 * A GET only shows a confirm button: mail security scanners open every link
 * in a message, and would otherwise unsubscribe people who never clicked. The
 * unsubscribe itself happens on POST — from that button, or from a mail
 * client's RFC 8058 one-click request to the List-Unsubscribe URL.
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the HMAC key in the URL is the credential; one-click POSTs from mail clients (RFC 8058) cannot carry a nonce.
		if ( empty( $_GET['ndvr_unsub'] ) || empty( $_GET['ndvr_key'] ) ) {
			return;
		}

		$email = sanitize_email( wp_unslash( $_GET['ndvr_unsub'] ) );
		$key   = sanitize_text_field( wp_unslash( $_GET['ndvr_key'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$ok      = is_email( $email ) && hash_equals( $this->mailer->unsub_key( $email ), $key );
		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$confirm = false;
		if ( ! $ok ) {
			status_header( 400 );
			$message = __( 'This unsubscribe link is invalid or has expired.', 'ndv-reviews' );
		} elseif ( $is_post ) {
			$this->mailer->suppress( $email );
			$message = __( 'You have been unsubscribed from review requests.', 'ndv-reviews' );
		} elseif ( $this->mailer->is_suppressed( $email ) ) {
			$message = __( 'You are already unsubscribed from review requests.', 'ndv-reviews' );
		} else {
			$confirm = true;
			/* translators: %s: email address. */
			$message = sprintf( __( 'Stop sending review request emails to %s?', 'ndv-reviews' ), $email );
		}
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php esc_html_e( 'Unsubscribe', 'ndv-reviews' ); ?></title>
	<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7f9;color:#1f2430;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:16px;box-sizing:border-box;}div{background:#fff;border:1px solid #e6e8ec;border-radius:14px;padding:32px 36px;max-width:460px;text-align:center;}a{color:#2563eb;}button{font:inherit;background:#1f2430;color:#fff;border:0;border-radius:8px;padding:11px 22px;cursor:pointer;}</style>
</head>
<body>
	<div>
		<h1><?php esc_html_e( 'Review requests', 'ndv-reviews' ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
		<?php if ( $confirm ) : ?>
			<form method="post" action="<?php echo esc_url( $this->mailer->unsubscribe_link( $email ) ); ?>">
				<p><button type="submit"><?php esc_html_e( 'Unsubscribe', 'ndv-reviews' ); ?></button></p>
			</form>
		<?php endif; ?>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Return to the store', 'ndv-reviews' ); ?></a></p>
	</div>
</body>
</html>
		<?php
		exit;
	}
}
