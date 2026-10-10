<?php
/**
 * Admin assets: load the Rosette Reviews admin skin only on our screens.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the admin stylesheet and adds a body class on Rosette Reviews screens so
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
		add_action( 'admin_enqueue_scripts', array( $this, 'menu_badge' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Pending-review count on the top-level menu: one red bubble pinned to the
	 * corner of the menu icon. Inline, the count wraps onto its own line (the
	 * label nearly fills the sidebar) and WordPress 7's Modern scheme paints it
	 * the same blue as the open menu item. Printed on every admin screen because
	 * the menu is; a few bytes, no file request.
	 *
	 * @return void
	 */
	public function menu_badge() {
		$item = '#adminmenu #toplevel_page_' . DashboardPage::MENU_SLUG;

		wp_register_style( 'ndvr-admin-menu', false, array(), NDVR_VERSION );
		wp_enqueue_style( 'ndvr-admin-menu' );
		wp_add_inline_style(
			'ndvr-admin-menu',
			$item . ' .awaiting-mod{position:absolute;top:3px;left:20px;z-index:2;min-width:16px;height:16px;margin:0;padding:0 4px;'
			. 'border-radius:8px;background:#d63638;color:#fff;font-size:10px;font-weight:600;line-height:16px;box-sizing:border-box}'
			. $item . ':hover .awaiting-mod,' . $item . '.current .awaiting-mod,' . $item . '.wp-has-current-submenu .awaiting-mod{background:#d63638;color:#fff}'
		);
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
