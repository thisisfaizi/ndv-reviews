<?php
/**
 * Review-request scheduling on Action Scheduler.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Installer;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and sends review reminders via Action Scheduler (the queue
 * WooCommerce already bundles), persisting intent in ndvr_requests so a missed
 * cron can be recovered.
 *
 * RR-09: queue_for_order()/queue_for_email() are the only creators of request
 * rows (dedupe key + INSERT IGNORE), Mailer::check_eligibility() is the single
 * gate, and process() claims a row atomically before it sends, so concurrent
 * jobs, a Retry click or duplicate status events can't send twice.
 */
class Scheduler implements Registerable {

	const SEND_HOOK    = 'ndvr_send_request';
	const RECOVER_HOOK = 'ndvr_requests_recover';
	const GROUP        = 'ndv-reviews';

	/**
	 * Error codes that mean "deliberately not sent": the row ends `cancelled`.
	 * Every other error ends `failed` (retryable).
	 */
	const SKIP_CODES = array(
		'ndvr_no_order',
		'ndvr_order_ineligible',
		'ndvr_no_email',
		'ndvr_unsubscribed',
		'ndvr_no_consent',
		'ndvr_customer_excluded',
		'ndvr_nothing_to_review',
		'ndvr_cooldown',
		'ndvr_followup_disabled',
		'ndvr_followup_filtered',
		'ndvr_already_requested',
		'ndvr_erased',
		'ndvr_expired',
	);

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private $requests;

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private $mailer;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param RequestRepository $requests Request repository.
	 * @param Mailer            $mailer   Mailer.
	 */
	public function __construct( Settings $settings, RequestRepository $requests, Mailer $mailer ) {
		$this->settings = $settings;
		$this->requests = $requests;
		$this->mailer   = $mailer;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::SEND_HOOK, array( $this, 'process' ), 10, 1 );
		add_action( self::RECOVER_HOOK, array( $this, 'recover' ), 10, 0 );

		// The recurring recovery job is (re)scheduled from the admin and on
		// activation only, so storefront requests never pay for the check.
		add_action( 'admin_init', array( __CLASS__, 'ensure_recover_scheduled' ), 20, 0 );

		$status = (string) $this->settings->get( 'reminder_status', 'completed' );
		add_action( 'woocommerce_order_status_' . $status, array( $this, 'on_order_status' ), 20, 1 );
	}

	/**
	 * Whether an error code means "deliberately not sent".
	 *
	 * @param string $code Error code.
	 * @return bool
	 */
	public static function is_skip_code( $code ) {
		/**
		 * Filter the error codes that end a request as "Not sent" (cancelled)
		 * rather than "Failed".
		 *
		 * @param string[] $codes Codes.
		 */
		$codes = (array) apply_filters( 'ndv-reviews/request_skip_codes', self::SKIP_CODES );

		return in_array( (string) $code, $codes, true );
	}

	/**
	 * Queue a review request for an order: the only way to create an order row.
	 *
	 * @param int                 $order_id Order id.
	 * @param array<string,mixed> $args {
	 *     Optional.
	 *
	 *     @type string $source      auto|manual|followup. Default 'auto'.
	 *     @type string $origin      free|pro. Default 'free'.
	 *     @type int    $step        Step number. Default 1.
	 *     @type int    $delay       Seconds from now. Default 0.
	 *     @type string $variant     first|followup. Default 'first'.
	 *     @type bool   $followup    Whether this row may earn a free follow-up (only
	 *                               free auto/manual rows; forced false otherwise).
	 *     @type array  $meta        Caller data, stored under meta.ext.
	 *     @type bool   $skip_checks Skip the queue-time eligibility check.
	 * }
	 * @return int|\WP_Error Request id (an existing one for a duplicate).
	 */
	public function queue_for_order( $order_id, array $args = array() ) {
		$args = $this->normalize_args( $args, 'auto' );

		if ( ! Installer::is_current( Installer::V_PIPELINE ) ) {
			return new \WP_Error( 'ndvr_not_ready', __( 'The review-request database update has not finished yet.', 'rosette-reviews' ) );
		}
		if ( ! in_array( $args['source'], array( 'auto', 'manual', 'followup', 'campaign' ), true ) ) {
			return new \WP_Error( 'ndvr_bad_source', __( 'Unknown review-request source.', 'rosette-reviews' ) );
		}

		$campaign = isset( $args['meta']['campaign_id'] ) ? absint( $args['meta']['campaign_id'] ) : 0;
		if ( 'campaign' === $args['source'] && ! $campaign ) {
			return new \WP_Error( 'ndvr_no_campaign', __( 'A campaign request needs a campaign id.', 'rosette-reviews' ) );
		}

		$order_id = absint( $order_id );

		// An order already asked before this version (legacy row, including
		// Pro BulkCampaign's direct inserts) keeps that one request: the first
		// automatic request is never queued twice.
		if ( 'free' === $args['origin'] && 'auto' === $args['source'] && 1 === $args['step'] ) {
			$legacy = $this->requests->first_id_for_order( $order_id, array( 'legacy' ) );
			if ( $legacy ) {
				return $legacy;
			}
		}
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$order    = $order instanceof \WC_Order ? $order : null;

		if ( ! $args['skip_checks'] ) {
			$eligible = $this->mailer->check_eligibility(
				$order,
				array(
					'stage'  => 'queue',
					'source' => $args['source'],
					'origin' => $args['origin'],
					'step'   => $args['step'],
				)
			);
			if ( is_wp_error( $eligible ) ) {
				return $eligible;
			}
		}
		if ( ! $order ) {
			return new \WP_Error( 'ndvr_no_order', __( 'Order not found.', 'rosette-reviews' ) );
		}

		$followup = 'free' === $args['origin'] && in_array( $args['source'], array( 'auto', 'manual' ), true )
			&& false !== $args['followup'];

		$dedupe = null;
		if ( in_array( $args['source'], array( 'auto', 'followup' ), true ) ) {
			$dedupe = sprintf( 'o:%d:%s:%d:%s', $order_id, $args['origin'], $args['step'], $args['source'] );
		} elseif ( 'campaign' === $args['source'] ) {
			// Same key as list rows: one email per address per campaign, even
			// for a customer with several orders in it.
			$dedupe = 'c:' . $campaign . ':' . md5( strtolower( (string) $order->get_billing_email() ) );
		}

		return $this->insert_and_schedule(
			array(
				'order_id'    => $order_id,
				'customer_id' => (int) $order->get_customer_id(),
				'email'       => (string) $order->get_billing_email(),
				'step'        => $args['step'],
				'source'      => $args['source'],
				'origin'      => $args['origin'],
				'dedupe_key'  => $dedupe,
				'meta'        => array(
					'followup' => $followup,
					'variant'  => $args['variant'],
					'ext'      => $args['meta'],
				),
			),
			$args['delay']
		);
	}

	/**
	 * Queue a review request for a recipient from an uploaded list (no order).
	 *
	 * @param string              $email      Recipient email.
	 * @param string              $first_name Recipient first name.
	 * @param int[]               $products   Product ids to review.
	 * @param array<string,mixed> $args       As queue_for_order(); `meta.campaign_id` is required.
	 * @return int|\WP_Error Request id (an existing one for the same email in the same campaign).
	 */
	public function queue_for_email( $email, $first_name, array $products, array $args = array() ) {
		$args = $this->normalize_args( $args, 'campaign' );

		if ( ! Installer::is_current( Installer::V_PIPELINE ) ) {
			return new \WP_Error( 'ndvr_not_ready', __( 'The review-request database update has not finished yet.', 'rosette-reviews' ) );
		}

		$campaign = isset( $args['meta']['campaign_id'] ) ? absint( $args['meta']['campaign_id'] ) : 0;
		if ( ! $campaign ) {
			return new \WP_Error( 'ndvr_no_campaign', __( 'A list request needs a campaign id.', 'rosette-reviews' ) );
		}

		$email    = strtolower( sanitize_email( (string) $email ) );
		$products = array_values( array_filter( array_unique( array_map( 'absint', $products ) ) ) );

		if ( ! $args['skip_checks'] ) {
			$eligible = $this->mailer->check_eligibility(
				null,
				array(
					'stage'    => 'queue',
					'source'   => 'campaign',
					'origin'   => $args['origin'],
					'step'     => $args['step'],
					'list'     => true,
					'email'    => $email,
					'products' => $products,
				)
			);
			if ( is_wp_error( $eligible ) ) {
				return $eligible;
			}
		} elseif ( ! is_email( $email ) ) {
			return new \WP_Error( 'ndvr_no_email', __( 'No valid email address.', 'rosette-reviews' ) );
		}

		return $this->insert_and_schedule(
			array(
				'order_id'    => 0,
				'customer_id' => 0,
				'email'       => $email,
				'step'        => $args['step'],
				'source'      => 'campaign',
				'origin'      => $args['origin'],
				'dedupe_key'  => 'c:' . $campaign . ':' . md5( $email ),
				'meta'        => array(
					'followup'   => false,
					'variant'    => 'first',
					'first_name' => sanitize_text_field( (string) $first_name ),
					'products'   => $products,
					'ext'        => $args['meta'],
				),
			),
			$args['delay']
		);
	}

	/**
	 * Fill in and clean queue arguments.
	 *
	 * @param array<string,mixed> $args           Raw args.
	 * @param string              $default_source Default source.
	 * @return array<string,mixed>
	 */
	private function normalize_args( array $args, $default_source ) {
		$args = wp_parse_args(
			$args,
			array(
				'source'      => $default_source,
				'origin'      => 'free',
				'step'        => 1,
				'delay'       => 0,
				'variant'     => 'first',
				'followup'    => null,
				'meta'        => array(),
				'skip_checks' => false,
			)
		);

		$args['source']      = sanitize_key( (string) $args['source'] );
		$args['origin']      = 'pro' === $args['origin'] ? 'pro' : 'free';
		$args['step']        = max( 1, (int) $args['step'] );
		$args['delay']       = max( 0, (int) $args['delay'] );
		$args['variant']     = 'followup' === $args['variant'] ? 'followup' : 'first';
		$args['meta']        = is_array( $args['meta'] ) ? $args['meta'] : array();
		$args['skip_checks'] = ! empty( $args['skip_checks'] );

		return $args;
	}

	/**
	 * Insert a row (deduped) and schedule its send. A duplicate returns the
	 * existing id without scheduling a second job.
	 *
	 * @param array<string,mixed> $row   Row data for RequestRepository::insert_unique().
	 * @param int                 $delay Seconds from now.
	 * @return int|\WP_Error
	 */
	private function insert_and_schedule( array $row, $delay ) {
		$timestamp           = time() + (int) $delay;
		$row['scheduled_at'] = gmdate( 'Y-m-d H:i:s', $timestamp );

		$result = $this->requests->insert_unique( $row );
		if ( ! $result['id'] ) {
			return new \WP_Error( 'ndvr_insert_failed', __( 'The review request could not be saved.', 'rosette-reviews' ) );
		}

		if ( $result['inserted'] ) {
			$this->schedule_send( $result['id'], $timestamp );
		}

		return $result['id'];
	}

	/**
	 * Schedule the send job for a row.
	 *
	 * @param int $request_id Request id.
	 * @param int $timestamp  Unix time.
	 * @return void
	 */
	private function schedule_send( $request_id, $timestamp ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			// The args shape (int request_id) must stay exactly this:
			// recover() looks the job up by it.
			as_schedule_single_action( (int) $timestamp, self::SEND_HOOK, array( 'request_id' => (int) $request_id ), self::GROUP );
		} else {
			// Fallback: process now if Action Scheduler is unavailable.
			$this->process( $request_id );
		}
	}

	/**
	 * On the configured order status, queue the automatic review request.
	 * Repeated status events are harmless: the dedupe key keeps one row.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_order_status( $order_id ) {
		if ( ! $this->settings->get( 'reminder_enabled' ) ) {
			return;
		}

		$order_id = absint( $order_id );

		/**
		 * Allow another module to suppress the built-in review reminder for an
		 * order (for example Pro when its own automation or ESP already sends a
		 * request), so a customer isn't messaged twice.
		 *
		 * @param bool $send     Whether to schedule the free reminder. Default true.
		 * @param int  $order_id Order id.
		 */
		if ( ! $order_id || ! apply_filters( 'ndv-reviews/should_send_reminder', true, $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_email( $order->get_billing_email() ) ) {
			return;
		}

		$delay = max( 0, (int) $this->settings->get( 'reminder_delay_days', 7 ) ) * DAY_IN_SECONDS;

		if ( ! Installer::is_current( Installer::V_PIPELINE ) ) {
			$this->queue_legacy( $order, $delay );
			return;
		}

		$this->queue_for_order(
			$order_id,
			array(
				'source' => 'auto',
				'delay'  => $delay,
			)
		);
	}

	/**
	 * The pre-RR-09 automatic path, used only while the v4 upgrade hasn't run,
	 * so no automatic request is lost in that window.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $delay Seconds from now.
	 * @return void
	 */
	private function queue_legacy( $order, $delay ) {
		if ( $this->requests->exists_for_order( $order->get_id() ) ) {
			return;
		}

		$timestamp  = time() + (int) $delay;
		$request_id = $this->requests->insert(
			array(
				'order_id'     => $order->get_id(),
				'customer_id'  => $order->get_customer_id(),
				'email'        => $order->get_billing_email(),
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			)
		);

		if ( $request_id ) {
			$this->schedule_send( $request_id, $timestamp );
		}
	}

	/**
	 * Action Scheduler callback: claim the row, check eligibility, send, and
	 * record the outcome. Only the caller that wins the claim sends.
	 *
	 * @param int $request_id Request id.
	 * @return void
	 */
	public function process( $request_id ) {
		$request_id = absint( $request_id );

		if ( ! Installer::is_current( Installer::V_PIPELINE ) ) {
			$this->process_legacy( $request_id );
			return;
		}

		if ( ! $this->requests->claim( $request_id ) ) {
			return;
		}

		$row = $this->requests->find( $request_id );
		if ( ! $row ) {
			return;
		}

		// The address was erased for privacy: never send (order rows would
		// otherwise mail the order's billing address).
		if ( empty( $row->email ) ) {
			$this->requests->set_status( $request_id, 'cancelled', __( 'Not sent: the address was erased for privacy.', 'rosette-reviews' ) );
			return;
		}

		$meta    = RequestRepository::meta( $row );
		$is_list = 0 === (int) $row->order_id;
		$order   = $is_list ? null : wc_get_order( (int) $row->order_id );

		$eligible = $this->mailer->check_eligibility(
			$order instanceof \WC_Order ? $order : null,
			array(
				'stage'      => 'send',
				'source'     => (string) $row->source,
				'origin'     => (string) $row->origin,
				'step'       => (int) $row->step,
				'request_id' => $request_id,
				'list'       => $is_list,
				'email'      => (string) $row->email,
				'products'   => isset( $meta['products'] ) ? (array) $meta['products'] : array(),
			)
		);
		if ( is_wp_error( $eligible ) ) {
			$this->finish_with_error( $request_id, $eligible );
			return;
		}

		$result = $is_list
			? $this->mailer->send_to_list_recipient( (string) $row->email, isset( $meta['first_name'] ) ? (string) $meta['first_name'] : '', isset( $meta['products'] ) ? (array) $meta['products'] : array(), $request_id )
			: $this->mailer->send_for_order(
				(int) $row->order_id,
				array(
					'request_id' => $request_id,
					'variant'    => isset( $meta['variant'] ) ? (string) $meta['variant'] : 'first',
				)
			);

		if ( is_wp_error( $result ) ) {
			$this->finish_with_error( $request_id, $result );
			return;
		}

		$this->requests->set_status( $request_id, 'sent' );

		/**
		 * Fires after a review request was sent. Follow-ups (RR-06) and Pro
		 * step chaining listen here; process() calls neither directly.
		 *
		 * @param int    $request_id Request id.
		 * @param object $row        The request row, as now stored.
		 */
		do_action( 'ndv-reviews/request_sent', $request_id, $this->requests->find( $request_id ) );
	}

	/**
	 * End a request that didn't send: "Not sent" for deliberate skips,
	 * "Failed" (retryable) otherwise.
	 *
	 * @param int       $request_id Request id.
	 * @param \WP_Error $error      Reason.
	 * @return void
	 */
	private function finish_with_error( $request_id, \WP_Error $error ) {
		$status = self::is_skip_code( $error->get_error_code() ) ? 'cancelled' : 'failed';
		$this->requests->set_status( $request_id, $status, $error->get_error_message() );
	}

	/**
	 * The pre-RR-09 send, used only while the v4 upgrade hasn't run.
	 *
	 * @param int $request_id Request id.
	 * @return void
	 */
	private function process_legacy( $request_id ) {
		$request = $this->requests->find( $request_id );
		if ( ! $request || in_array( $request->status, array( 'sent', 'cancelled', 'converted' ), true ) ) {
			return;
		}

		$result = $this->mailer->send_for_order( (int) $request->order_id );
		if ( is_wp_error( $result ) ) {
			$this->finish_with_error( $request_id, $result );
			return;
		}

		$this->requests->set_status( $request_id, 'sent' );
	}

	/**
	 * Retry a failed request immediately.
	 *
	 * @param int $request_id Request id.
	 * @return bool False when the request does not exist or has not failed.
	 */
	public function retry( $request_id ) {
		$request = $this->requests->find( $request_id );
		if ( ! $request || 'failed' !== $request->status || empty( $request->email ) ) {
			return false;
		}

		if ( ! Installer::is_current( Installer::V_PIPELINE ) ) {
			$this->requests->set_status( $request_id, 'scheduled' );
		}
		// The claim accepts failed rows, so a double click still sends once.
		$this->process( $request_id );

		return true;
	}

	/**
	 * Hourly recovery job: reset sends that crashed mid-way, and re-queue
	 * scheduled rows whose job is gone (for example after a deactivation),
	 * spread out so a backlog doesn't go out in one burst. Rows due too long
	 * ago are cancelled instead of emailing customers about old orders.
	 *
	 * @return void
	 */
	public function recover() {
		if ( ! Installer::is_current( Installer::V_PIPELINE ) ) {
			return;
		}

		$this->requests->recover_stuck( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), __( 'Interrupted while sending; retry.', 'rosette-reviews' ) );

		// Held-media cleanups the deactivation removed (RR-00b E3).
		\NdvReviews\Moderation\Actions::resweep_held_media( 200 );

		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		/**
		 * Filter how many days overdue a scheduled request may be and still be
		 * sent when its job is re-created.
		 *
		 * @param int $days Default 14.
		 */
		$max_days = max( 1, (int) apply_filters( 'ndv-reviews/request_max_overdue_days', 14 ) );
		$cutoff   = time() - $max_days * DAY_IN_SECONDS;
		$position = 0;

		foreach ( $this->requests->overdue_scheduled( gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS ), 200 ) as $row ) {
			$id = (int) $row->id;
			if ( as_has_scheduled_action( self::SEND_HOOK, array( 'request_id' => $id ), self::GROUP ) ) {
				continue;
			}

			if ( strtotime( $row->scheduled_at . ' UTC' ) < $cutoff ) {
				/* translators: %d: number of days. */
				$this->requests->set_status( $id, 'cancelled', sprintf( __( 'Not sent: it was due more than %d days ago.', 'rosette-reviews' ), $max_days ) );
				continue;
			}

			as_schedule_single_action( time() + 60 * $position, self::SEND_HOOK, array( 'request_id' => $id ), self::GROUP );
			++$position;
		}
	}

	/**
	 * Make sure the hourly recovery job exists. Runs on activation and (at
	 * most hourly) in the admin, never on the storefront.
	 *
	 * @param bool $force Skip the hourly throttle (activation).
	 * @return void
	 */
	public static function ensure_recover_scheduled( $force = false ) {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( true !== $force && ( wp_doing_ajax() || false !== get_transient( 'ndvr_recover_checked' ) ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::RECOVER_HOOK ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, HOUR_IN_SECONDS, self::RECOVER_HOOK, array(), self::GROUP );
		}
		set_transient( 'ndvr_recover_checked', 1, HOUR_IN_SECONDS );
	}
}
