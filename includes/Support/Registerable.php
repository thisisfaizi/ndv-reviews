<?php
/**
 * Registerable service contract.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A service that wires its own WordPress hooks when registered.
 */
interface Registerable {

	/**
	 * Register the service's hooks.
	 *
	 * @return void
	 */
	public function register();
}
