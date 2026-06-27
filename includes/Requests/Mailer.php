<?php
/**
 * Builds and sends review-request emails.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Support\Settings;
use NdvReviews\Support\View;
use NdvReviews\Collection\TokenRepository;
use NdvReviews\Collection\Reviewable;

defined( 'ABSPATH' ) || exit;

/**
 * Composes the reminder email (with a tokenized review link) and sends it
 * through WordPress mail, honoring the unsubscribe suppression list.
 */
class Mailer {

	const SUPPRESS_OPTION = 'ndv_reviews_unsubscribed';

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
	 * Reviewable resolver.
	 *
	 * @var Reviewable
	 */
	private $reviewable;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings   Settings.
	 * @param TokenRepository $tokens     Token repository.
	 * @param Reviewable      $reviewable Reviewable resolver.
	 */
	public function __construct( Settings $settings, TokenRepository $tokens, Reviewable $reviewable ) {
		$this->settings   = $settings;
		$this->tokens     = $tokens;
		$this->reviewable = $reviewable;
	}

	/**
	 * Send the review-request email for an order.
	 *
	 * @param int $order_id Order id.
	 * @return true|\WP_Error
	 */
	public function send_for_order( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return new \WP_Error( 'ndvr_no_order', __( 'Order not found.', 'ndv-reviews' ) );
		}

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'ndvr_no_email', __( 'Order has no valid email.', 'ndv-reviews' ) );
		}

		if ( $this->is_suppressed( $email ) ) {
			return new \WP_Error( 'ndvr_unsubscribed', __( 'Recipient has unsubscribed.', 'ndv-reviews' ) );
		}

		$products = $this->reviewable->for_order( $order );
		if ( empty( $products ) ) {
			return new \WP_Error( 'ndvr_nothing_to_review', __( 'No reviewable products in this order.', 'ndv-reviews' ) );
		}

		$token = $this->tokens->create_order_token( $order_id, $email, $products, $order->get_customer_id() );
		$link  = $this->build_link( $token );

		$subject = $this->subject( $order );
		$body    = $this->body( $order, $products, $link, $email );

		$sent = wp_mail( $email, $subject, $body, $this->headers() );

		return $sent ? true : new \WP_Error( 'ndvr_mail_failed', __( 'wp_mail() returned false.', 'ndv-reviews' ) );
	}

	/**
	 * Send a test reminder to an address (uses the most recent order if any).
	 *
	 * @param string $to Recipient.
	 * @return true|\WP_Error
	 */
	public function send_test( $to ) {
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'ndvr_test_email', __( 'Enter a valid email address.', 'ndv-reviews' ) );
		}

		$subject = '[' . get_bloginfo( 'name' ) . '] ' . __( 'Test review request', 'ndv-reviews' );
		$link    = $this->build_link( 'TEST-TOKEN' );
		$body    = wpautop(
			esc_html__( 'This is a test of your NDV Reviews review-request email. The button below would link customers to their review form.', 'ndv-reviews' ) .
			'<br><br><a href="' . esc_url( $link ) . '">' . esc_html__( 'Write your review', 'ndv-reviews' ) . '</a>'
		);

		$sent = wp_mail( $to, $subject, $body, $this->headers() );

		return $sent ? true : new \WP_Error( 'ndvr_mail_failed', __( 'wp_mail() returned false — check your SMTP/mail configuration.', 'ndv-reviews' ) );
	}

	/**
	 * Build the public review-collection URL for a token.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public function build_link( $token ) {
		return add_query_arg( 'ndvr_k', rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * Build an unsubscribe URL for an email.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public function unsubscribe_link( $email ) {
		return add_query_arg(
			array(
				'ndvr_unsub' => rawurlencode( $email ),
				'ndvr_key'   => $this->unsub_key( $email ),
			),
			home_url( '/' )
		);
	}

	/**
	 * The email subject (custom or default).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function subject( $order ) {
		$custom = trim( (string) $this->settings->get( 'reminder_subject' ) );
		if ( '' !== $custom ) {
			return $this->replace_tokens( $custom, $order );
		}

		/* translators: %s: store name. */
		return sprintf( __( 'How was your order from %s?', 'ndv-reviews' ), get_bloginfo( 'name' ) );
	}

	/**
	 * The email body HTML.
	 *
	 * @param \WC_Order $order    Order.
	 * @param int[]     $products Reviewable product ids.
	 * @param string    $link     Review link.
	 * @param string    $email    Recipient email.
	 * @return string
	 */
	private function body( $order, $products, $link, $email ) {
		$rendered = View::render(
			'email-request.php',
			array(
				'order'       => $order,
				'products'    => $products,
				'review_link' => $link,
				'unsub_link'  => $this->unsubscribe_link( $email ),
				'settings'    => $this->settings,
			)
		);

		if ( '' !== $rendered ) {
			return $rendered;
		}

		// Fallback if the template is missing.
		$custom = trim( (string) $this->settings->get( 'reminder_body' ) );
		$body   = '' !== $custom ? $this->replace_tokens( $custom, $order, $link ) : sprintf(
			/* translators: 1: customer first name, 2: review link. */
			__( "Hi %1\$s,\n\nThanks for your purchase! Please take a moment to review your items:\n%2\$s", 'ndv-reviews' ),
			$order->get_billing_first_name(),
			$link
		);

		return wpautop( wp_kses_post( $body ) );
	}

	/**
	 * Replace template placeholders.
	 *
	 * @param string         $text  Template text.
	 * @param \WC_Order      $order Order.
	 * @param string         $link  Optional review link.
	 * @return string
	 */
	private function replace_tokens( $text, $order, $link = '' ) {
		return strtr(
			$text,
			array(
				'{customer_name}' => $order->get_billing_first_name(),
				'{store_name}'    => get_bloginfo( 'name' ),
				'{review_link}'   => $link,
			)
		);
	}

	/**
	 * Mail headers (HTML + from).
	 *
	 * @return string[]
	 */
	private function headers() {
		$from_name  = trim( (string) $this->settings->get( 'from_name' ) );
		$from_email = trim( (string) $this->settings->get( 'from_email' ) );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( '' !== $from_name && is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		return $headers;
	}

	/**
	 * Whether an email has unsubscribed.
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	public function is_suppressed( $email ) {
		$list = (array) get_option( self::SUPPRESS_OPTION, array() );

		return in_array( strtolower( trim( $email ) ), $list, true );
	}

	/**
	 * Add an email to the suppression list.
	 *
	 * @param string $email Email.
	 * @return void
	 */
	public function suppress( $email ) {
		$email = strtolower( trim( $email ) );
		$list  = (array) get_option( self::SUPPRESS_OPTION, array() );
		if ( ! in_array( $email, $list, true ) ) {
			$list[] = $email;
			update_option( self::SUPPRESS_OPTION, $list, false );
		}
	}

	/**
	 * HMAC key validating an unsubscribe link.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public function unsub_key( $email ) {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'auth' ) );
	}
}
