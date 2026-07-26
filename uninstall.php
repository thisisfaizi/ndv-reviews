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
	'question_votes',
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

// Review comments + their _ndvr_* meta. Identified by the '_ndvr_overall_rating'
// marker, which ReviewRepository::create() writes on every plugin-created
// review — this avoids touching any comment that isn't ours. wp_delete_comment()
// with $force_delete=true also removes the comment's own meta rows (WP core
// behavior), so no separate commentmeta cleanup is needed.
// Note: this does not recalculate the now-stale WooCommerce product rating
// aggregates (_wc_average_rating/_wc_review_count) — acceptable since the
// store owner has opted to remove the plugin and its review data entirely.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$ndvr_review_ids = $wpdb->get_col( "SELECT DISTINCT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = '_ndvr_overall_rating'" );
foreach ( $ndvr_review_ids as $ndvr_review_id ) {
	wp_delete_comment( (int) $ndvr_review_id, true );
}

// Pending review-reminder jobs (Action Scheduler group 'ndv-reviews').
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'ndvr_send_request', array(), 'ndv-reviews' );
}

// Per-IP rate-limit transients (Forms\AntiSpam) — keyed per-hash, so pattern-match.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ndvr\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ndvr\\_rl\\_%'" );

delete_transient( 'ndv_reviews_activated' );
delete_option( 'ndv_reviews_unsubscribed' );
delete_option( 'ndv_reviews_settings' );
delete_option( 'ndv_reviews_db_version' );
