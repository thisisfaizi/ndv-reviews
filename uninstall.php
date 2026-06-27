<?php
/**
 * Uninstall handler.
 *
 * Respects the "remove data on uninstall" setting (default: keep data).
 *
 * @package NdvReviews
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ndvr_settings = get_option( 'ndv_reviews_settings', array() );

if ( empty( $ndvr_settings['remove_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$ndvr_prefix = $wpdb->prefix . 'ndvr_';
$ndvr_tables = array(
	'criteria',
	'review_criteria',
	'review_media',
	'review_votes',
	'requests',
	'questions',
	'answers',
	'ai_meta',
	'forms',
	'connections',
	'campaigns',
	'review_tokens',
);

foreach ( $ndvr_tables as $ndvr_table ) {
	// Table name is built from a hardcoded list + $wpdb->prefix; not user input.
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'ndvr_' . $ndvr_table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
}

delete_option( 'ndv_reviews_settings' );
delete_option( 'ndv_reviews_db_version' );
