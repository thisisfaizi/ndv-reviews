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
	 * Request log (cooldown and token linkage). Optional so older direct
	 * `new Mailer()` callers keep working; without it there is no cooldown.
	 *
	 * @var RequestRepository|null
	 */
	private $requests;

	/**
	 * Whether the email being built is for a list recipient (no order).
	 *
	 * @var bool
	 */
	private $list_context = false;

	/**
	 * Constructor.
	 *
	 * @param Settings               $settings   Settings.
	 * @param TokenRepository        $tokens     Token repository.
	 * @param Reviewable             $reviewable Reviewable resolver.
	 * @param RequestRepository|null $requests   Request log.
	 */
	public function __construct( Settings $settings, TokenRepository $tokens, Reviewable $reviewable, ?RequestRepository $requests = null ) {
		$this->settings   = $settings;
		$this->tokens     = $tokens;
		$this->reviewable = $reviewable;
		$this->requests   = $requests;
	}

	/**
	 * The single gate for every review request, free and Pro (RR-09).
	 *
	 * Error codes in Scheduler::SKIP_CODES mean "deliberately not sent" (the
	 * row ends cancelled); anything else is a delivery failure.
	 *
	 * @param \WC_Order|null      $order   Order, or null for a list recipient.
	 * @param array<string,mixed> $context {
	 *     Eligibility context.
	 *
	 *     @type string $stage      queue|send.
	 *     @type string $source     auto|manual|followup|campaign|legacy.
	 *     @type string $origin     free|pro.
	 *     @type int    $step       Step number.
	 *     @type int    $request_id Request row id (0 when there is none yet).
	 *     @type bool   $list       Whether this is a list recipient (no order).
	 *     @type string $email      List recipient email.
	 *     @type int[]  $products   List recipient product ids.
	 * }
	 * @return true|\WP_Error
	 */
	public function check_eligibility( $order, array $context = array() ) {
		$context = wp_parse_args(
			$context,
			array(
				'stage'      => 'send',
				'source'     => 'auto',
				'origin'     => 'free',
				'step'       => 1,
				'request_id' => 0,
				'list'       => false,
				'email'      => '',
				'products'   => array(),
			)
		);
		$is_list = ! empty( $context['list'] );
		$order   = $order instanceof \WC_Order ? $order : null;

		$result = $this->eligibility_steps( $order, $context, $is_list );

		/**
		 * Filter the final review-request eligibility decision. Applied last;
		 * a listener that doesn't apply returns $eligible unchanged.
		 *
		 * @param true|\WP_Error      $eligible Decision so far.
		 * @param \WC_Order|null      $order    Order (null for list rows).
		 * @param array<string,mixed> $context  Context (see check_eligibility()).
		 */
		$filtered = apply_filters( 'ndv-reviews/request_eligible', $result, $order, $context );

		return ( true === $filtered || is_wp_error( $filtered ) ) ? $filtered : $result;
	}

	/**
	 * The ordered eligibility steps (see check_eligibility()).
	 *
	 * @param \WC_Order|null      $order   Order.
	 * @param array<string,mixed> $context Context.
	 * @param bool                $is_list Whether this is a list recipient.
	 * @return true|\WP_Error
	 */
	private function eligibility_steps( $order, array $context, $is_list ) {
		if ( ! $is_list ) {
			if ( ! $order ) {
				return new \WP_Error( 'ndvr_no_order', __( 'Order not found.', 'rosette-reviews' ) );
			}

			// The send runs days after the qualifying status change; the order can
			// legitimately move to cancelled/refunded/failed in that window (a
			// customer requesting a refund is the common case). Check the current
			// status every time.
			$ineligible_statuses = (array) apply_filters(
				'ndv-reviews/reminder_ineligible_order_statuses',
				array( 'cancelled', 'refunded', 'failed', 'trash' )
			);
			if ( in_array( $order->get_status(), $ineligible_statuses, true ) ) {
				return new \WP_Error( 'ndvr_order_ineligible', __( 'Order is no longer eligible for a review request.', 'rosette-reviews' ) );
			}
		}

		$email = $is_list ? (string) $context['email'] : (string) $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'ndvr_no_email', __( 'No valid email address.', 'rosette-reviews' ) );
		}

		if ( $this->is_suppressed( $email ) ) {
			return new \WP_Error( 'ndvr_unsubscribed', __( 'Recipient has unsubscribed.', 'rosette-reviews' ) );
		}

		$products = $is_list ? $this->list_products( $email, (array) $context['products'] ) : $this->reviewable->for_order( $order );
		if ( empty( $products ) ) {
			return new \WP_Error( 'ndvr_nothing_to_review', __( 'No reviewable products in this order.', 'rosette-reviews' ) );
		}

		// Cooldown: at send time always; at queue time only for manual sends,
		// which then insert no row at all.
		$check_cooldown = 'send' === $context['stage'] || 'manual' === $context['source'];
		if ( $check_cooldown && $this->requests ) {
			$last = $is_list ? $this->requests->last_sent_at_for_email( $email ) : $this->requests->last_sent_at_for_order( $order->get_id() );

			/**
			 * Filter the minimum time between two review requests to the same
			 * order (or, for list rows, the same email).
			 *
			 * @param int                 $seconds Default 20 hours.
			 * @param array<string,mixed> $context Eligibility context.
			 */
			$cooldown = max( 0, (int) apply_filters( 'ndv-reviews/request_cooldown', 20 * HOUR_IN_SECONDS, $context ) );
			if ( $last && $cooldown > 0 && strtotime( $last . ' UTC' ) > time() - $cooldown ) {
				return new \WP_Error( 'ndvr_cooldown', __( 'A review request was sent to this customer recently.', 'rosette-reviews' ) );
			}
		}

		return true;
	}

	/**
	 * A list recipient's products, minus the ones they already reviewed.
	 *
	 * @param string $email    Recipient email.
	 * @param int[]  $products Requested product ids.
	 * @return int[]
	 */
	private function list_products( $email, array $products ) {
		$out = array();
		foreach ( array_unique( array_map( 'absint', $products ) ) as $product_id ) {
			if ( $product_id && PostTypes::is_reviewable( $product_id ) && ! $this->reviewable->has_reviewed( $email, $product_id ) ) {
				$out[] = $product_id;
			}
		}

		return $out;
	}

	/**
	 * Send the review-request email for an order.
	 *
	 * With `request_id` (the queue, Scheduler::process()) eligibility was
	 * already checked and the token is linked to the row. Without it (the
	 * legacy direct path, used by older Pro versions) this runs the full gate,
	 * cooldown included. Error codes in Scheduler::SKIP_CODES mean "deliberately
	 * not sent"; anything else is a delivery failure.
	 *
	 * @param int                 $order_id Order id.
	 * @param array<string,mixed> $args {
	 *     Optional.
	 *
	 *     @type int    $request_id Request row id.
	 *     @type string $variant    first|followup (UTM content and texts).
	 * }
	 * @return true|\WP_Error
	 */
	public function send_for_order( $order_id, array $args = array() ) {
		$request_id = isset( $args['request_id'] ) ? absint( $args['request_id'] ) : 0;
		$variant    = isset( $args['variant'] ) && 'followup' === $args['variant'] ? 'followup' : 'first';

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $request_id ) {
			$eligible = $this->check_eligibility(
				$order ? $order : null,
				array(
					'stage'  => 'send',
					'source' => 'legacy',
					'origin' => 'pro',
				)
			);
			if ( is_wp_error( $eligible ) ) {
				return $eligible;
			}
		}
		if ( ! $order ) {
			return new \WP_Error( 'ndvr_no_order', __( 'Order not found.', 'rosette-reviews' ) );
		}

		$email    = $order->get_billing_email();
		$products = $this->reviewable->for_order( $order );
		if ( empty( $products ) ) {
			return new \WP_Error( 'ndvr_nothing_to_review', __( 'No reviewable products in this order.', 'rosette-reviews' ) );
		}

		$token = $this->tokens->create_order_token_row( $order->get_id(), $email, $products, $order->get_customer_id() );
		if ( ! $token['id'] ) {
			return new \WP_Error( 'ndvr_token_failed', __( 'The review link could not be created.', 'rosette-reviews' ) );
		}
		if ( $request_id && $this->requests ) {
			$this->requests->set_token( $request_id, $token['id'] );
		}
		$link = $this->tracked_link( $this->build_link( $token['raw'] ), $variant );

		$texts = $this->request_texts(
			array(
				'subject' => $this->subject( $order, $link ),
				'body'    => $this->body( $order, $products, $link, $email ),
			),
			$request_id,
			$order
		);

		$sent = wp_mail( $email, $texts['subject'], $this->with_pixel( $texts['body'], $request_id ), $this->headers( $email ) );

		return $sent ? true : new \WP_Error( 'ndvr_mail_failed', __( 'wp_mail() returned false.', 'rosette-reviews' ) );
	}

	/**
	 * Send the review request to a recipient from an uploaded list (no order).
	 * Eligibility was checked by the queue; reviews through the link are stored
	 * with source `list_link`.
	 *
	 * @param string $email      Recipient email.
	 * @param string $first_name Recipient first name ('' if unknown).
	 * @param int[]  $products   Product ids to review.
	 * @param int    $request_id Request row id.
	 * @return true|\WP_Error
	 */
	public function send_to_list_recipient( $email, $first_name, array $products, $request_id ) {
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'ndvr_no_email', __( 'No valid email address.', 'rosette-reviews' ) );
		}

		$products = $this->list_products( $email, $products );
		if ( empty( $products ) ) {
			return new \WP_Error( 'ndvr_nothing_to_review', __( 'No reviewable products in this order.', 'rosette-reviews' ) );
		}

		// An unsaved order object so the templates (and theme overrides) can use
		// the usual WC_Order getters, as previews do.
		$order = new \WC_Order();
		$order->set_billing_first_name( sanitize_text_field( (string) $first_name ) );
		$order->set_billing_email( $email );

		$token = $this->tokens->create_list_token( $email, $products );
		if ( ! $token['id'] ) {
			return new \WP_Error( 'ndvr_token_failed', __( 'The review link could not be created.', 'rosette-reviews' ) );
		}
		if ( $request_id && $this->requests ) {
			$this->requests->set_token( absint( $request_id ), $token['id'] );
		}
		$link = $this->tracked_link( $this->build_link( $token['raw'] ), 'first' );

		// A list recipient has no order: {order_number} must stay empty, not
		// show the preview's sample number.
		$this->list_context = true;
		$texts              = $this->request_texts(
			array(
				'subject' => $this->subject( $order, $link ),
				'body'    => $this->body( $order, $products, $link, $email, array(), 'list' ),
			),
			absint( $request_id ),
			null
		);
		$this->list_context = false;

		$sent = wp_mail( $email, $texts['subject'], $this->with_pixel( $texts['body'], absint( $request_id ) ), $this->headers( $email ) );

		return $sent ? true : new \WP_Error( 'ndvr_mail_failed', __( 'wp_mail() returned false.', 'rosette-reviews' ) );
	}

	/**
	 * Let features change a request email's subject and body.
	 *
	 * @param array{subject:string,body:string} $texts      Subject and body HTML.
	 * @param int                               $request_id Request row id (0 on the legacy path).
	 * @param \WC_Order|null                    $order      Order (null for list rows).
	 * @return array{subject:string,body:string}
	 */
	private function request_texts( array $texts, $request_id, $order ) {
		$row = ( $request_id && $this->requests ) ? $this->requests->find( $request_id ) : null;

		/**
		 * Filter a review-request email's subject and body HTML.
		 *
		 * @param array{subject:string,body:string} $texts Subject and body.
		 * @param object|null                       $row   Request row (null on the legacy path).
		 * @param \WC_Order|null                    $order Order (null for list rows).
		 */
		$filtered = apply_filters( 'ndv-reviews/request_email_texts', $texts, $row, $order );

		return array(
			'subject' => isset( $filtered['subject'] ) ? (string) $filtered['subject'] : $texts['subject'],
			'body'    => isset( $filtered['body'] ) ? (string) $filtered['body'] : $texts['body'],
		);
	}

	/**
	 * Add the optional UTM tags to a review link.
	 *
	 * @param string $link    Review link.
	 * @param string $variant first|followup.
	 * @return string
	 */
	private function tracked_link( $link, $variant ) {
		if ( ! $this->settings->get( 'reminder_utm' ) ) {
			return $link;
		}

		$tags = array(
			'utm_source'   => 'rosette-reviews',
			'utm_medium'   => 'email',
			'utm_campaign' => 'review-request',
		);
		if ( 'followup' === $variant ) {
			$tags['utm_content'] = 'followup';
		}

		return add_query_arg( $tags, $link );
	}

	/**
	 * Add the optional open-tracking image (off by default) before </body>.
	 *
	 * @param string $html       Email HTML.
	 * @param int    $request_id Request row id (no pixel without one).
	 * @return string
	 */
	private function with_pixel( $html, $request_id ) {
		if ( ! $request_id || ! $this->settings->get( 'reminder_open_pixel' ) ) {
			return $html;
		}

		$img = '<img src="' . esc_url( Tracking::pixel_url( $request_id ) ) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;" />';
		$pos = strripos( $html, '</body>' );

		return false === $pos ? $html . $img : substr( $html, 0, $pos ) . $img . substr( $html, $pos );
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
	 * @param string               $context   order|list (list recipients get their own footer line).
	 * @return string
	 */
	private function body( $order, $products, $link, $email, array $overrides = array(), $context = 'order' ) {
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
				'context'       => 'list' === $context ? 'list' : 'order',
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
			'{order_number}'  => $order->get_id() ? (string) $order->get_order_number() : ( $this->list_context ? '' : '1001' ),
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
