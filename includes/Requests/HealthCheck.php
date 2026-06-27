<?php
/**
 * Reminder-reliability health check.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces the usual root cause of "reminders never arrive": a misconfigured
 * server cron so Action Scheduler never runs. We warn instead of failing silently.
 */
class HealthCheck implements Registerable {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'maybe_warn' ) );
	}

	/**
	 * Show a warning when reminders are enabled but delivery looks unreliable.
	 *
	 * @return void
	 */
	public function maybe_warn() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->settings->get( 'reminder_enabled' ) ) {
			return;
		}

		$issues = $this->issues();
		if ( empty( $issues ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'NDV Reviews — review reminders may not send reliably:', 'ndv-reviews' ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( $issues as $issue ) {
			echo '<li>' . esc_html( $issue ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Collect any reliability issues.
	 *
	 * @return string[]
	 */
	private function issues() {
		$issues = array();

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$issues[] = __( 'Action Scheduler is not available. Ensure WooCommerce is active.', 'ndv-reviews' );
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$issues[] = __( 'WP-Cron is disabled (DISABLE_WP_CRON). Make sure a real server cron triggers wp-cron.php, or reminders will not fire on time.', 'ndv-reviews' );
		}

		// Overdue scheduled actions are a strong signal the queue is not running.
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$overdue = as_get_scheduled_actions(
				array(
					'hook'         => Scheduler::SEND_HOOK,
					'status'       => 'pending',
					'date'         => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
					'date_compare' => '<',
					'per_page'     => 1,
				),
				'ids'
			);
			if ( ! empty( $overdue ) ) {
				$issues[] = __( 'Some review reminders are more than a day overdue — the background queue does not appear to be running.', 'ndv-reviews' );
			}
		}

		return $issues;
	}
}
