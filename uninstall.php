<?php
/**
 * Uninstall handler.
 *
 * Respects the "remove data on uninstall" setting (default: keep data).
 * WordPress loads only this file: the main plugin file, its constants and the
 * autoloader are not available, and WooCommerce may or may not be active. The
 * two classes used here are dependency-free at load time (RR-00 F2).
 *
 * QA: define NDVR_UNINSTALL_DRY_RUN to true before including this file to get
 * the list of what would be removed (returned from the include) without
 * changing anything, whatever the setting.
 *
 * @package NdvReviews
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ndvr_dry_run  = defined( 'NDVR_UNINSTALL_DRY_RUN' ) && NDVR_UNINSTALL_DRY_RUN;
$ndvr_settings = get_option( 'ndv_reviews_settings', array() );

if ( ! $ndvr_dry_run && empty( $ndvr_settings['remove_data_on_uninstall'] ) ) {
	return array();
}

if ( ! defined( 'NDVR_TABLE_PREFIX' ) ) {
	define( 'NDVR_TABLE_PREFIX', 'ndvr_' );
}

require_once __DIR__ . '/includes/Installer.php';
require_once __DIR__ . '/includes/Uninstall.php';

return \NdvReviews\Uninstall::run( $ndvr_dry_run );
