<?php
/**
 * Capability used by the Rosette Reviews admin screens.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One filterable capability for every Rosette Reviews admin screen, so a role can
 * manage reviews without full WooCommerce settings access (Pro's Review Manager).
 * Review moderation itself stays on core's `moderate_comments`.
 */
class Caps {

	/**
	 * Capability required for an Rosette Reviews admin screen.
	 *
	 * @param string $context Screen: overview|criteria|questions|design|reminders|settings|tools.
	 * @return string
	 */
	public static function manage( $context = 'settings' ) {
		/**
		 * Filter the capability required for an Rosette Reviews admin screen.
		 *
		 * @param string $capability Default 'manage_woocommerce'.
		 * @param string $context    Screen: overview|criteria|questions|design|reminders|settings|tools.
		 */
		return (string) apply_filters( 'ndv-reviews/manage_capability', 'manage_woocommerce', $context );
	}
}
