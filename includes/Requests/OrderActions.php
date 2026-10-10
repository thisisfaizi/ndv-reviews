<?php
/**
 * "Send review request" from the order screen and the orders list (RR-04).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Support\Caps;
use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * A merchant asks one customer, or a selection, for a review. Every request
 * goes through the RR-09 pipeline (queue_for_order → check_eligibility →
 * process), so the log, cooldown, suppression, consent, exclusions and
 * tracking all apply. Manual sends work with reminders off and don't apply
 * `should_send_reminder`, which gates the automatic path only.
 *
 * WooCommerce verifies its own nonces before these hooks run (order save:
 * HPOS check_admin_referer / legacy woocommerce_meta_nonce; bulk: bulk-orders
 * or bulk-posts); each callback re-checks Caps::manage( 'reminders' ).
 */
class OrderActions implements Registerable {

	const ACTION        = 'ndvr_send_review_request';
	const NOTICE_PREFIX = 'ndvr_order_action_notice_';

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private $requests;

	/**
	 * Constructor.
	 *
	 * @param Scheduler         $scheduler Scheduler.
	 * @param RequestRepository $requests  Request repository.
	 */
	public function __construct( Scheduler $scheduler, RequestRepository $requests ) {
		$this->scheduler = $scheduler;
		$this->requests  = $requests;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_action' ), 10, 2 );
		add_action( 'woocommerce_order_action_' . self::ACTION, array( $this, 'handle_single' ) );

		foreach ( array( 'woocommerce_page_wc-orders', 'edit-shop_order' ) as $screen ) {
			add_filter( 'bulk_actions-' . $screen, array( $this, 'add_bulk_action' ) );
			add_filter( 'handle_bulk_actions-' . $screen, array( $this, 'handle_bulk' ), 10, 3 );
		}

		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Whether the current user may send review requests.
	 *
	 * @return bool
	 */
	private function can() {
		return current_user_can( Caps::manage( 'reminders' ) );
	}

	/**
	 * The "Order actions" item (not for users without plugin rights, nor for
	 * an order without a valid billing email). WooCommerce may pass null.
	 *
	 * @param array<string,string> $actions Actions.
	 * @param \WC_Order|null       $order   Order.
	 * @return array<string,string>
	 */
	public function add_order_action( $actions, $order = null ) {
		$actions = is_array( $actions ) ? $actions : array();
		if ( ! $order instanceof \WC_Order || ! $this->can() || ! is_email( (string) $order->get_billing_email() ) ) {
			return $actions;
		}
		$actions[ self::ACTION ] = __( 'Send review request', 'rosette-reviews' );

		return $actions;
	}

	/**
	 * The bulk action.
	 *
	 * @param array<string,string> $actions Bulk actions.
	 * @return array<string,string>
	 */
	public function add_bulk_action( $actions ) {
		$actions = is_array( $actions ) ? $actions : array();
		if ( $this->can() ) {
			$actions[ self::ACTION ] = __( 'Send review request', 'rosette-reviews' );
		}

		return $actions;
	}

	/**
	 * Queue a manual request and cancel the order's pending automatic and
	 * follow-up rows, so only one email goes out.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $delay Seconds from now.
	 * @return int|\WP_Error
	 */
	private function queue( $order, $delay ) {
		$id = $this->scheduler->queue_for_order(
			$order->get_id(),
			array(
				'source' => 'manual',
				'origin' => 'free',
				'delay'  => max( 0, (int) $delay ),
			)
		);
		if ( ! is_wp_error( $id ) ) {
			$this->requests->cancel_pending_for_order( $order->get_id(), array( 'auto', 'followup' ) );
		}

		return $id;
	}

	/**
	 * Single order: "Order actions" → "Send review request" → "Update".
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function handle_single( $order ) {
		if ( ! $order instanceof \WC_Order || ! $this->can() ) {
			return;
		}

		$number = (string) $order->get_order_number();
		$result = $this->queue( $order, 0 );
		if ( is_wp_error( $result ) ) {
			$this->store_notice(
				'warning',
				sprintf(
					/* translators: 1: order number, 2: reason, such as "unsubscribed". */
					__( 'No review request for order #%1$s: %2$s.', 'rosette-reviews' ),
					$number,
					$this->reason( $result )
				)
			);
			return;
		}

		$order->add_order_note( __( 'Review request queued from the order screen.', 'rosette-reviews' ), 0, true );

		/* translators: %s: order number. */
		$text = sprintf( __( 'Review request queued for order #%s. It goes out within a few minutes.', 'rosette-reviews' ), $number );
		if ( $this->scheduler->order_already_requested( $order ) ) {
			$text .= ' ' . __( 'This order already had a review request from another sender.', 'rosette-reviews' );
		}
		$this->store_notice( 'success', $text );
	}

	/**
	 * Bulk action on the orders list (HPOS or legacy).
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action.
	 * @param int[]  $ids         Order ids.
	 * @return string
	 */
	public function handle_bulk( $redirect_to, $action, $ids ) {
		if ( self::ACTION !== $action || ! $this->can() ) {
			return $redirect_to;
		}

		/**
		 * Filter how many orders one bulk "Send review request" processes.
		 *
		 * @param int $limit Default 200.
		 */
		$limit = max( 1, (int) apply_filters( 'ndv-reviews/manual_bulk_limit', 200 ) );
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		$over  = count( $ids ) > $limit;
		$ids   = array_slice( $ids, 0, $limit );

		$queued  = 0;
		$skipped = array();
		$i       = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) {
				$reason = __( 'order not found', 'rosette-reviews' );
			} elseif ( $this->requests->exists_for_order( $id ) || $this->scheduler->order_already_requested( $order ) ) {
				$reason = $this->label( 'ndvr_already_requested' );
			} else {
				$result = $this->queue( $order, 2 * $i );
				++$i;
				if ( ! is_wp_error( $result ) ) {
					++$queued;
					continue;
				}
				$reason = $this->reason( $result );
			}
			$skipped[ $reason ] = ( $skipped[ $reason ] ?? 0 ) + 1;
		}

		$text = sprintf(
			/* translators: %s: number of review requests. */
			_n( 'Queued %s review request.', 'Queued %s review requests.', $queued, 'rosette-reviews' ),
			number_format_i18n( $queued )
		);
		if ( $skipped ) {
			$parts = array();
			foreach ( $skipped as $reason => $n ) {
				/* translators: 1: number of orders, 2: reason, such as "unsubscribed". */
				$parts[] = sprintf( _x( '%1$s %2$s', 'skipped count and reason', 'rosette-reviews' ), number_format_i18n( $n ), $reason );
			}
			$total = array_sum( $skipped );
			$text .= ' ' . sprintf(
				/* translators: 1: number of skipped orders, 2: list of reasons with counts. */
				_n( 'Skipped %1$s: %2$s.', 'Skipped %1$s: %2$s.', $total, 'rosette-reviews' ),
				number_format_i18n( $total ),
				implode( _x( ', ', 'list separator', 'rosette-reviews' ), $parts )
			);
		}
		if ( $over ) {
			/* translators: %1$s: the bulk limit, such as 200. */
			$text .= ' ' . sprintf( _n( 'Only the first %1$s order was processed. Select up to %1$s at a time.', 'Only the first %1$s orders were processed. Select up to %1$s at a time.', $limit, 'rosette-reviews' ), number_format_i18n( $limit ) );
		}
		$this->store_notice( $queued ? 'success' : 'warning', $text );

		return $redirect_to;
	}

	/**
	 * Short label for a skip reason.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	private function reason( $error ) {
		$label = $this->label( $error->get_error_code() );

		// The notice adds its own full stop.
		return '' !== $label ? $label : rtrim( wp_strip_all_tags( $error->get_error_message() ), '. ' );
	}

	/**
	 * Label for an RR-09 skip code ('' when unknown).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private function label( $code ) {
		switch ( $code ) {
			case 'ndvr_unsubscribed':
				return __( 'unsubscribed', 'rosette-reviews' );
			case 'ndvr_nothing_to_review':
				return __( 'already reviewed everything', 'rosette-reviews' );
			case 'ndvr_order_ineligible':
				return __( 'order status not eligible', 'rosette-reviews' );
			case 'ndvr_no_email':
				return __( 'no valid email', 'rosette-reviews' );
			case 'ndvr_no_consent':
				return __( 'didn\'t agree to review emails', 'rosette-reviews' );
			case 'ndvr_customer_excluded':
				return __( 'customer role excluded', 'rosette-reviews' );
			case 'ndvr_already_requested':
				return __( 'already asked', 'rosette-reviews' );
			case 'ndvr_cooldown':
				$context = array(
					'stage'  => 'queue',
					'source' => 'manual',
					'origin' => 'free',
					'step'   => 1,
				);
				/** This filter is documented in includes/Requests/Mailer.php */
				$seconds = max( 0, (int) apply_filters( 'ndv-reviews/request_cooldown', 20 * HOUR_IN_SECONDS, $context ) );
				$hours   = max( 1, (int) ceil( $seconds / HOUR_IN_SECONDS ) );
				/* translators: %d: hours. */
				return sprintf( _n( 'asked in the last %d hour', 'asked in the last %d hours', $hours, 'rosette-reviews' ), $hours );
		}

		return '';
	}

	/**
	 * Keep a notice for the current user's next orders page.
	 *
	 * @param string $type success|warning.
	 * @param string $text Text.
	 * @return void
	 */
	private function store_notice( $type, $text ) {
		$user = get_current_user_id();
		if ( ! $user ) {
			return;
		}
		$notices   = get_transient( self::NOTICE_PREFIX . $user );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type' => 'success' === $type ? 'success' : 'warning',
			'text' => (string) $text,
		);
		set_transient( self::NOTICE_PREFIX . $user, $notices, 120 );
	}

	/**
	 * Print and forget the stored notices on the order screens.
	 *
	 * @return void
	 */
	public function render_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$user   = get_current_user_id();
		if ( ! $user || ! $screen || ! in_array( (string) $screen->id, array( 'woocommerce_page_wc-orders', 'shop_order', 'edit-shop_order' ), true ) ) {
			return;
		}
		$notices = get_transient( self::NOTICE_PREFIX . $user );
		if ( ! is_array( $notices ) || ! $notices ) {
			return;
		}
		delete_transient( self::NOTICE_PREFIX . $user );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'warning' ),
				esc_html( (string) ( $notice['text'] ?? '' ) )
			);
		}
	}

	/**
	 * The notices stored for a user (tests and integrations).
	 *
	 * @param int $user_id User id.
	 * @return array<int,array{type:string,text:string}>
	 */
	public static function notices( $user_id ) {
		$notices = get_transient( self::NOTICE_PREFIX . absint( $user_id ) );

		return is_array( $notices ) ? $notices : array();
	}
}
