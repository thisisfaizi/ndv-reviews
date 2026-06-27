<?php
/**
 * Review-request scheduling on Action Scheduler.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and sends review reminders via Action Scheduler (the battle-tested
 * queue WooCommerce already bundles) — persisting intent in ndvr_requests so a
 * missed cron can be recovered. Reliability is the headline feature.
 */
class Scheduler implements Registerable {

	const SEND_HOOK = 'ndvr_send_request';

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

		$status = (string) $this->settings->get( 'reminder_status', 'completed' );
		add_action( 'woocommerce_order_status_' . $status, array( $this, 'on_order_status' ), 20, 1 );
	}

	/**
	 * On the configured order status, schedule a review request (idempotently).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function on_order_status( $order_id ) {
		if ( ! $this->settings->get( 'reminder_enabled' ) ) {
			return;
		}

		$order_id = absint( $order_id );
		if ( ! $order_id || $this->requests->exists_for_order( $order_id ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_email( $order->get_billing_email() ) ) {
			return;
		}

		$delay_days = max( 0, (int) $this->settings->get( 'reminder_delay_days', 7 ) );
		$timestamp  = time() + ( $delay_days * DAY_IN_SECONDS );

		$request_id = $this->requests->insert(
			array(
				'order_id'     => $order_id,
				'customer_id'  => $order->get_customer_id(),
				'email'        => $order->get_billing_email(),
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			)
		);

		if ( ! $request_id ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, self::SEND_HOOK, array( 'request_id' => $request_id ), 'ndv-reviews' );
		} else {
			// Fallback: process now if Action Scheduler is unavailable.
			$this->process( $request_id );
		}
	}

	/**
	 * Action Scheduler callback: send the request and record the outcome.
	 *
	 * @param int $request_id Request id.
	 * @return void
	 */
	public function process( $request_id ) {
		$request = $this->requests->find( $request_id );
		if ( ! $request || 'sent' === $request->status ) {
			return;
		}

		$result = $this->mailer->send_for_order( (int) $request->order_id );

		if ( is_wp_error( $result ) ) {
			$this->requests->set_status( $request_id, 'failed', $result->get_error_message() );
			return;
		}

		$this->requests->set_status( $request_id, 'sent' );
	}

	/**
	 * Retry a failed request immediately.
	 *
	 * @param int $request_id Request id.
	 * @return void
	 */
	public function retry( $request_id ) {
		$this->requests->set_status( $request_id, 'scheduled' );
		$this->process( $request_id );
	}
}
