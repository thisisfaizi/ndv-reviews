<?php
/**
 * Activation handler.
 *
 * @package NdvReviews
 */

namespace NdvReviews;

use NdvReviews\Support\Settings;
use NdvReviews\Reviews\CriteriaRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once on plugin activation: creates tables and seeds default settings.
 */
class Activator {

	/**
	 * Activate the plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		Installer::install();
		update_option( NDVR_OPTION_DB_VERSION, NDVR_DB_VERSION );

		if ( false === get_option( NDVR_OPTION_SETTINGS, false ) ) {
			add_option( NDVR_OPTION_SETTINGS, Settings::defaults() );
		}

		// Seed default rating criteria (Quality / Value / Service) on first install.
		( new CriteriaRepository() )->seed_defaults();

		set_transient( 'ndv_reviews_activated', 1, 60 );

		flush_rewrite_rules();
	}
}
