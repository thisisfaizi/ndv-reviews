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
		// Cancel pending review-reminder jobs. These run on Action Scheduler (not
		// wp-cron) — see Requests\Scheduler::SEND_HOOK, group 'ndv-reviews'.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Requests\Scheduler::SEND_HOOK, array(), 'ndv-reviews' );
		}

		flush_rewrite_rules();
	}
}
