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
use NdvReviews\Reviews\PostTypes;

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
	 * Error codes ndvr_no_order, ndvr_order_ineligible, ndvr_unsubscribed and
	 * ndvr_nothing_to_review mean "deliberately not sent" (see
	 * Scheduler::process()); anything else is a delivery failure.
	 *
	 * @param int $order_id Order id.
	 * @return true|\WP_Error
	 */
	public function send_for_order( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return new \WP_Error( 'ndvr_no_order', __( 'Order not found.', 'rosette-reviews' ) );
		}

		// The send is scheduled `reminder_delay_days` ahead of the qualifying
		// status change and fires later via Action Scheduler — the order can
		// legitimately move to cancelled/refunded/failed in that window (a
		// customer requesting a refund is the common case). Re-check the
		// *current* status at send time rather than trusting the status that
		// was true when this was originally scheduled.
		$ineligible_statuses = (array) apply_filters(
			'ndv-reviews/reminder_ineligible_order_statuses',
			array( 'cancelled', 'refunded', 'failed', 'trash' )
		);
		if ( in_array( $order->get_status(), $ineligible_statuses, true ) ) {
			return new \WP_Error( 'ndvr_order_ineligible', __( 'Order is no longer eligible for a review request.', 'rosette-reviews' ) );
		}

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'ndvr_no_email', __( 'Order has no valid email.', 'rosette-reviews' ) );
		}

		if ( $this->is_suppressed( $email ) ) {
			return new \WP_Error( 'ndvr_unsubscribed', __( 'Recipient has unsubscribed.', 'rosette-reviews' ) );
		}

		$products = $this->reviewable->for_order( $order );
		if ( empty( $products ) ) {
			return new \WP_Error( 'ndvr_nothing_to_review', __( 'No reviewable products in this order.', 'rosette-reviews' ) );
		}

		$token = $this->tokens->create_order_token( $order_id, $email, $products, $order->get_customer_id() );
		$link  = $this->build_link( $token );

		$subject = $this->subject( $order, $link );
		$body    = $this->body( $order, $products, $link, $email );

		$sent = wp_mail( $email, $subject, $body, $this->headers( $email ) );

		return $sent ? true : new \WP_Error( 'ndvr_mail_failed', __( 'wp_mail() returned false.', 'rosette-reviews' ) );
	}

	/**
	 * Send a short branded notice email (RR-00 F9): a store reply, a Q&A
	 * answer, a recovery invite, or a merchant alert.
	 *
	 * @param string              $to         Recipient.
	 * @param string              $subject    Plain-text subject.
	 * @param string              $inner_html Body HTML (passed through wp_kses_post()).
	 * @param array<string,mixed> $args {
	 *     Optional.
	 *
	 *     @type bool   $customer     Whether the recipient is a customer (default true).
	 *                                Customer notices honour the unsubscribe list.
	 *     @type bool   $marketing    Whether to add an unsubscribe link and the
	 *                                List-Unsubscribe headers (default false; implies customer).
	 *     @type string $preheader    Inbox preview text.
	 *     @type string $button_url   Optional call-to-action URL.
	 *     @type string $button_label Its label.
	 *     @type string $footer_note  Optional line above the store address.
	 * }
	 * @return true|\WP_Error
	 */
	public function send_notice( $to, $subject, $inner_html, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'customer'     => true,
				'marketing'    => false,
				'preheader'    => '',
				'button_url'   => '',
				'button_label' => '',
				'footer_note'  => '',
			)
		);

		$to = sanitize_email( (string) $to );
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'ndvr_no_email', __( 'The notice has no valid recipient.', 'rosette-reviews' ) );
		}

		$marketing = ! empty( $args['marketing'] );
		$customer  = $marketing || ! empty( $args['customer'] );
		if ( $customer && $this->is_suppressed( $to ) ) {
			return new \WP_Error( 'ndvr_unsubscribed', __( 'Recipient has unsubscribed.', 'rosette-reviews' ) );
		}

		// Subjects are plain text: no tags, no line breaks, no HTML entities.
		$subject = trim( preg_replace( '/[\r\n]+/', ' ', html_entity_decode( wp_strip_all_tags( (string) $subject ), ENT_QUOTES, 'UTF-8' ) ) );
		$accent  = sanitize_hex_color( (string) $this->settings->get( 'design_accent', '#181a1f' ) );
		$accent  = $accent ? $accent : '#181a1f';

		$body = View::render(
			'email-notice.php',
			array(
				'inner_html'    => wp_kses_post( (string) $inner_html ),
				'preheader'     => (string) $args['preheader'],
				'button_url'    => (string) $args['button_url'],
				'button_label'  => (string) $args['button_label'],
				'footer_note'   => (string) $args['footer_note'],
				'unsub_link'    => $marketing ? $this->unsubscribe_link( $to ) : '',
				'store_name'    => $this->store_name(),
				'logo_url'      => (string) get_option( 'woocommerce_email_header_image', '' ),
				'accent'        => $accent,
				'accent_text'   => $this->readable_text_color( $accent ),
				'store_address' => $customer ? $this->store_address() : '',
			)
		);
		if ( '' === $body ) {
			$body = wp_kses_post( (string) $inner_html );
		}

		$sent = wp_mail( $to, $subject, $body, $this->headers( $marketing ? $to : '' ) );

		return $sent ? true : new \WP_Error( 'ndvr_mail_failed', __( 'wp_mail() returned false.', 'rosette-reviews' ) );
	}

	/**
	 * Send a test reminder to an address: the real template, built from the
	 * most recent completed order when there is one. Its link is a short-lived
	 * test token that opens the real landing page but cannot save reviews.
	 *
	 * @param string $to Recipient.
	 * @return true|\WP_Error
	 */
	public function send_test( $to ) {
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'ndvr_test_email', __( 'Enter a valid email address.', 'rosette-reviews' ) );
		}

		$sample = $this->sample();
		if ( $sample['real'] ) {
			$token = $this->tokens->create_test_token( $sample['order']->get_id(), $sample['order']->get_billing_email(), $sample['products'] );
			$link  = $this->build_link( $token );
		} else {
			$link = $sample['link'];
		}

		$subject = $sample['real']
			/* translators: %s: email subject. */
			? sprintf( __( '[Test] %s', 'rosette-reviews' ), $this->subject( $sample['order'], $link ) )
			/* translators: %s: email subject. */
			: sprintf( __( '[Test, sample data: no completed orders yet] %s', 'rosette-reviews' ), $this->subject( $sample['order'], $link ) );
		$body    = $this->body( $sample['order'], $sample['products'], $link, $to );

		$sent = wp_mail( $to, $subject, $body, $this->headers( $to ) );

		return $sent ? true : new \WP_Error( 'ndvr_mail_failed', __( 'wp_mail() returned false — check your SMTP/mail configuration.', 'rosette-reviews' ) );
	}

	/**
	 * Render the email for the admin preview, optionally with unsaved subject
	 * and body text. Creates no token: the button links to the shop.
	 *
	 * @param array<string,string> $overrides Optional `subject` / `body`.
	 * @return array{subject:string,html:string,sample:bool}
	 */
	public function preview( array $overrides = array() ) {
		$sample = $this->sample();

		return array(
			'subject' => $this->subject( $sample['order'], $sample['link'], $overrides ),
			'html'    => $this->body( $sample['order'], $sample['products'], $sample['link'], (string) get_option( 'admin_email' ), $overrides ),
			'sample'  => ! $sample['real'],
		);
	}

	/**
	 * Data for a test or preview email: the latest completed order with its
	 * reviewable items, or an unsaved sample order and recent products.
	 *
	 * @return array{order:\WC_Order,products:int[],link:string,real:bool}
	 */
	private function sample() {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );

		$orders = wc_get_orders(
			array(
				'limit'   => 1,
				'status'  => array( 'wc-completed' ),
				'orderby' => 'date',
				'order'   => 'DESC',
				'type'    => 'shop_order',
			)
		);
		$order = ! empty( $orders ) ? $orders[0] : null;

		if ( $order instanceof \WC_Order ) {
			$products = array();
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product && PostTypes::is_reviewable( $item->get_product_id() ) ) {
					$products[ $item->get_product_id() ] = $item->get_product_id();
				}
			}
			if ( $products ) {
				return array(
					'order'    => $order,
					'products' => array_values( $products ),
					'link'     => $shop,
					'real'     => true,
				);
			}
		}

		// No completed order yet: an unsaved order object so templates (and
		// theme overrides) can call the usual WC_Order getters.
		$order = new \WC_Order();
		$order->set_billing_first_name( __( 'Alex', 'rosette-reviews' ) );
		$order->set_billing_last_name( __( 'Sample', 'rosette-reviews' ) );
		$order->set_billing_email( 'customer@example.com' );

		$products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 2,
				'fields'         => 'ids',
			)
		);

		return array(
			'order'    => $order,
			'products' => array_map( 'absint', $products ),
			'link'     => $shop,
			'real'     => false,
		);
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
	 * Build an unsubscribe URL for an email. The same URL serves the visible
	 * link (GET shows a confirm button) and the List-Unsubscribe one-click POST.
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
	 * @param \WC_Order            $order     Order.
	 * @param string               $link      Review link.
	 * @param array<string,string> $overrides Optional unsaved `subject`.
	 * @return string
	 */
	private function subject( $order, $link = '', array $overrides = array() ) {
		$custom = isset( $overrides['subject'] ) ? $overrides['subject'] : (string) $this->settings->get( 'reminder_subject' );
		$custom = trim( $custom );
		if ( '' !== $custom ) {
			// Subjects are plain text: keep merge-tag values off new lines.
			return trim( preg_replace( '/[\r\n]+/', ' ', $this->replace_tokens( $custom, $order, $link, false ) ) );
		}

		/* translators: %s: store name. */
		return sprintf( __( 'How was your order from %s?', 'rosette-reviews' ), $this->store_name() );
	}

	/**
	 * The email body HTML: the branded template wrapping either the merchant's
	 * reminder text or the built-in copy.
	 *
	 * @param \WC_Order            $order     Order.
	 * @param int[]                $products  Reviewable product ids.
	 * @param string               $link      Review link.
	 * @param string               $email     Recipient email.
	 * @param array<string,string> $overrides Optional unsaved `body`.
	 * @return string
	 */
	private function body( $order, $products, $link, $email, array $overrides = array() ) {
		$custom = isset( $overrides['body'] ) ? $overrides['body'] : (string) $this->settings->get( 'reminder_body' );
		$custom = trim( $custom );
		$intro  = '' !== $custom
			? wpautop( wp_kses_post( $this->replace_tokens( $custom, $order, $link, true ) ) )
			: '';

		$accent = sanitize_hex_color( (string) $this->settings->get( 'design_accent', '#181a1f' ) );
		$accent = $accent ? $accent : '#181a1f';

		$rendered = View::render(
			'email-request.php',
			array(
				'order'        => $order,
				'products'     => $products,
				'review_link'  => $link,
				'unsub_link'   => $this->unsubscribe_link( $email ),
				'settings'     => $this->settings,
				'intro_html'   => $intro,
				'store_name'   => $this->store_name(),
				'logo_url'     => (string) get_option( 'woocommerce_email_header_image', '' ),
				'accent'       => $accent,
				'accent_text'  => $this->readable_text_color( $accent ),
				'store_address' => $this->store_address(),
			)
		);

		if ( '' !== $rendered ) {
			return $rendered;
		}

		// Fallback if the template is missing.
		$body = '' !== $intro ? $intro : wpautop(
			esc_html(
				sprintf(
					/* translators: 1: customer first name, 2: review link. */
					__( "Hi %1\$s,\n\nCould you review what you bought? Your review link:\n%2\$s", 'rosette-reviews' ),
					$order->get_billing_first_name(),
					$link
				)
			)
		);

		return $body;
	}

	/**
	 * Replace merge tags. Values are escaped for HTML when the text is a body.
	 *
	 * @param string    $text   Template text.
	 * @param \WC_Order $order  Order.
	 * @param string    $link   Review link.
	 * @param bool      $html   Escape values for HTML.
	 * @return string
	 */
	private function replace_tokens( $text, $order, $link = '', $html = false ) {
		$values = array(
			'{customer_name}' => $order->get_billing_first_name(),
			'{store_name}'    => $this->store_name(),
			'{order_number}'  => $order->get_id() ? (string) $order->get_order_number() : '1001',
			'{review_link}'   => $link,
		);

		if ( $html ) {
			foreach ( $values as $tag => $value ) {
				$values[ $tag ] = '{review_link}' === $tag ? esc_url( $value ) : esc_html( $value );
			}
		}

		return strtr( $text, $values );
	}

	/**
	 * Store name as shown in emails.
	 *
	 * @return string
	 */
	private function store_name() {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * The WooCommerce store address as one line ('' when not set).
	 *
	 * @return string
	 */
	private function store_address() {
		$parts = array(
			get_option( 'woocommerce_store_address', '' ),
			get_option( 'woocommerce_store_address_2', '' ),
			trim( get_option( 'woocommerce_store_postcode', '' ) . ' ' . get_option( 'woocommerce_store_city', '' ) ),
		);

		$country = (string) get_option( 'woocommerce_default_country', '' );
		$code    = strtok( $country, ':' );
		if ( $code && function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();
			$parts[]   = isset( $countries[ $code ] ) ? $countries[ $code ] : '';
		}

		$parts = array_filter( array_map( 'trim', array_map( 'strval', $parts ) ) );

		// Without a street address the country alone says nothing useful.
		return '' === trim( (string) get_option( 'woocommerce_store_address', '' ) ) ? '' : implode( ', ', $parts );
	}

	/**
	 * Black or white, whichever reads better on the given background.
	 *
	 * @param string $hex #rrggbb or #rgb.
	 * @return string
	 */
	private function readable_text_color( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$channels = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$c          = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

		// Contrast against white vs against near-black (#111111, L ~ 0.0056).
		return ( 1.05 / ( $luminance + 0.05 ) ) >= ( ( $luminance + 0.05 ) / 0.0556 ) ? '#ffffff' : '#111111';
	}

	/**
	 * Mail headers (HTML, from, one-click unsubscribe).
	 *
	 * @param string $recipient Recipient email (for the unsubscribe URL).
	 * @return string[]
	 */
	private function headers( $recipient = '' ) {
		$from_name  = trim( (string) $this->settings->get( 'from_name' ) );
		$from_email = trim( (string) $this->settings->get( 'from_email' ) );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( '' !== $from_name && is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		if ( is_email( $recipient ) ) {
			// RFC 2369 + RFC 8058: mail clients show an unsubscribe control and
			// POST to this URL; Unsubscribe handles that POST without a page.
			$headers[] = 'List-Unsubscribe: <' . esc_url_raw( $this->unsubscribe_link( $recipient ) ) . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		return $headers;
	}

	/**
	 * Whether an email has unsubscribed. Entries are plain addresses, or an
	 * HMAC (prefix "h:") once the address was erased for privacy.
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	public function is_suppressed( $email ) {
		$list = (array) get_option( self::SUPPRESS_OPTION, array() );

		return in_array( strtolower( trim( $email ) ), $list, true ) || in_array( $this->suppression_hash( $email ), $list, true );
	}

	/**
	 * Add an email to the suppression list.
	 *
	 * @param string $email Email.
	 * @return void
	 */
	public function suppress( $email ) {
		$email = strtolower( trim( $email ) );
		if ( $this->is_suppressed( $email ) ) {
			return;
		}

		$list   = (array) get_option( self::SUPPRESS_OPTION, array() );
		$list[] = $email;
		update_option( self::SUPPRESS_OPTION, $list, false );
	}

	/**
	 * Replace a plain address on the suppression list with its hash, so the
	 * opt-out keeps working after a privacy erasure without storing the email.
	 *
	 * @param string $email Email.
	 * @return bool Whether the address was on the list.
	 */
	public function hash_suppressed( $email ) {
		$email = strtolower( trim( $email ) );
		$list  = (array) get_option( self::SUPPRESS_OPTION, array() );
		$index = array_search( $email, $list, true );
		if ( false === $index ) {
			return false;
		}

		$list[ $index ] = $this->suppression_hash( $email );
		update_option( self::SUPPRESS_OPTION, array_values( array_unique( $list ) ), false );

		return true;
	}

	/**
	 * Hashed form of a suppression-list entry.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private function suppression_hash( $email ) {
		return 'h:' . $this->unsub_key( $email );
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
