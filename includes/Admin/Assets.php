<?php
/**
 * Admin assets: load the NDV Reviews admin skin only on our screens.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the admin stylesheet and adds a body class on NDV Reviews screens so
 * the modern skin applies there and nowhere else in wp-admin.
 */
class Assets implements Registerable {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Whether the current admin request is one of our screens.
	 *
	 * @return bool
	 */
	private function is_our_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 0 === strpos( $page, 'ndv-reviews' );
	}

	/**
	 * Enqueue the admin stylesheet.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->is_our_screen() ) {
			return;
		}

		wp_enqueue_style( 'ndvr-admin', NDVR_URL . 'assets/css/admin.css', array(), NDVR_VERSION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'ndv-reviews-design' === $page ) {
			wp_enqueue_style( 'ndvr-design-admin', NDVR_URL . 'assets/css/design-admin.css', array( 'ndvr-admin' ), NDVR_VERSION );
			wp_enqueue_script( 'ndvr-design-admin', NDVR_URL . 'assets/js/design-admin.js', array(), NDVR_VERSION, true );
		}
	}

	/**
	 * Add our body class on our screens.
	 *
	 * @param string $classes Existing classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		if ( $this->is_our_screen() ) {
			$classes .= ' ndvr-admin';
		}

		return $classes;
	}
}
