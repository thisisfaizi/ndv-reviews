<?php
/**
 * Reminder-reliability health check.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Support\Caps;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Warns when review reminders are actually stuck: reminders past their send
 * time that the background queue has not run. A disabled WP-Cron alone is not
 * a problem (many hosts run a real server cron), so it is only mentioned as a
 * likely cause once reminders are overdue.
 */
class HealthCheck implements Registerable {

	const DISMISS_META = 'ndvr_health_notice_dismissed';
	const NONCE        = 'ndvr_health_dismiss';

	/**
	 * How late a pending reminder must be before it counts as stuck.
	 */
	const GRACE = HOUR_IN_SECONDS;

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
		add_action( 'admin_notices', array( $this, 'maybe_warn_upgrade' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss' ) );
	}

	/**
	 * Show the last database-update failure (RR-00 F3) on our screens and the
	 * Plugins list. Not dismissible: it clears itself once an update succeeds.
	 *
	 * @return void
	 */
	public function maybe_warn_upgrade() {
		if ( ! current_user_can( Caps::manage() ) || ! $this->on_relevant_screen() ) {
			return;
		}

		$error = get_option( \NdvReviews\Installer::ERROR_OPTION, '' );
		if ( ! is_string( $error ) || '' === $error ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: the database error message. */
					__( 'Rosette Reviews couldn\'t finish a database update: %s. It will retry within an hour.', 'rosette-reviews' ),
					rtrim( $error, '. ' )
				)
			)
		);
	}

	/**
	 * Whether the current admin screen is a plugin screen or the Plugins list.
	 *
	 * @return bool
	 */
	private function on_relevant_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}

		return 'plugins' === $screen->id || false !== strpos( (string) $screen->id, 'ndv-reviews' );
	}

	/**
	 * Show a warning when reminders are enabled and delivery is stuck.
	 *
	 * @return void
	 */
	public function maybe_warn() {
		if ( ! current_user_can( Caps::manage( 'reminders' ) ) || ! $this->settings->get( 'reminder_enabled' ) || ! $this->on_relevant_screen() ) {
			return;
		}

		$issues = $this->issues();
		if ( empty( $issues ) ) {
			return;
		}

		// A dismissal hides this set of issues only; a different problem shows again.
		$signature = md5( implode( '|', $issues ) );
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) === $signature ) {
			return;
		}

		$dismiss = wp_nonce_url( add_query_arg( 'ndvr_health_dismiss', $signature ), self::NONCE );

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Rosette Reviews: review reminders are not being sent on time.', 'rosette-reviews' ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( $issues as $issue ) {
			echo '<li>' . esc_html( $issue ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=ndv-reviews-reminders' ) ) . '">' . esc_html__( 'Open the reminder log', 'rosette-reviews' ) . '</a> · <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'rosette-reviews' ) . '</a></p></div>';
	}

	/**
	 * Store a dismissal for the current user.
	 *
	 * @return void
	 */
	public function maybe_dismiss() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( empty( $_GET['ndvr_health_dismiss'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Caps::manage( 'reminders' ) ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META, sanitize_key( wp_unslash( $_GET['ndvr_health_dismiss'] ) ) );
		wp_safe_redirect( remove_query_arg( array( 'ndvr_health_dismiss', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Collect reliability issues, based on what the queue is actually doing.
	 *
	 * @return string[]
	 */
	private function issues() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array( __( 'Action Scheduler is not available, so reminders cannot be queued. Make sure WooCommerce is active.', 'rosette-reviews' ) );
		}

		$overdue = as_get_scheduled_actions(
			array(
				'hook'         => Scheduler::SEND_HOOK,
				'status'       => 'pending',
				'date'         => gmdate( 'Y-m-d H:i:s', time() - self::GRACE ),
				'date_compare' => '<',
				'per_page'     => 100,
			),
			'ids'
		);
		if ( empty( $overdue ) ) {
			return array();
		}

		$issues = array(
			sprintf(
				/* translators: %d: number of reminders. */
				_n(
					'%d review reminder is more than an hour past its send time. The background queue (Action Scheduler, run by WP-Cron) does not appear to be running.',
					'%d review reminders are more than an hour past their send time. The background queue (Action Scheduler, run by WP-Cron) does not appear to be running.',
					count( $overdue ),
					'rosette-reviews'
				),
				count( $overdue )
			),
		);

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$issues[] = __( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). A server cron job must request wp-cron.php regularly, for example every 5 minutes.', 'rosette-reviews' );
		}

		return $issues;
	}
}
