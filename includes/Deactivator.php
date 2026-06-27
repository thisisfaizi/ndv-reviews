<?php
/**
 * Deactivation handler.
 *
 * @package NdvReviews
 */

namespace NdvReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on deactivation. Non-destructive: never drops data here (uninstall does,
 * and only if the user opted in).
 */
class Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Clear any scheduled Action Scheduler / cron hooks added in later phases.
		wp_clear_scheduled_hook( 'ndv_reviews_daily' );

		flush_rewrite_rules();
	}
}
