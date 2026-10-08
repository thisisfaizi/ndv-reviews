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

// Reviews this plugin created. `_ndvr_overall_rating`/`_ndvr_source` are not
// enough: RatingCache and the WooCommerce backfill also write them onto the
// store's native reviews (source 'import', or 'erased' after a GDPR erasure),
// and those must survive. ReviewRepository::create() is the only writer of
// `_ndvr_recommend`; other sources (Pro manual/external reviews) are never
// 'import'/'erased'.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$ndvr_review_ids = $wpdb->get_col(
	"SELECT DISTINCT comment_id FROM {$wpdb->commentmeta}
	WHERE meta_key = '_ndvr_recommend'
	OR ( meta_key = '_ndvr_source' AND meta_value NOT IN ( 'import', 'erased' ) )"
);
$ndvr_review_ids = array_map( 'intval', (array) $ndvr_review_ids );

// Photos uploaded with those reviews (read before the media table is dropped).
// Pro "admin-created" reviews reference existing media-library items, so their
// attachments are left alone.
$ndvr_attachment_ids = array();
if ( ! empty( $ndvr_review_ids ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $ndvr_prefix . 'review_media' ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table from prefix + literal; ids cast to int.
	$ndvr_rows = $wpdb->get_results( "SELECT comment_id, attachment_id FROM `{$ndvr_prefix}review_media` WHERE attachment_id > 0" );
	$ndvr_keep = array();
	foreach ( (array) $ndvr_rows as $ndvr_row ) {
		$ndvr_cid = (int) $ndvr_row->comment_id;
		if ( ! in_array( $ndvr_cid, $ndvr_review_ids, true ) || get_comment_meta( $ndvr_cid, '_ndvr_admin_created', true ) ) {
			$ndvr_keep[ (int) $ndvr_row->attachment_id ] = true;
		} else {
			$ndvr_attachment_ids[ (int) $ndvr_row->attachment_id ] = true;
		}
	}
	$ndvr_attachment_ids = array_keys( array_diff_key( $ndvr_attachment_ids, $ndvr_keep ) );
}

foreach ( $ndvr_tables as $ndvr_table ) {
	// Table name is built from a hardcoded list + $wpdb->prefix; not user input.
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'ndvr_' . $ndvr_table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
}

// wp_delete_comment() with $force_delete=true also removes each comment's meta.
// Note: this does not recalculate the now-stale WooCommerce product rating
// aggregates (_wc_average_rating/_wc_review_count) — acceptable since the
// store owner has opted to remove the plugin and its review data entirely.
foreach ( $ndvr_review_ids as $ndvr_review_id ) {
	wp_delete_comment( $ndvr_review_id, true );
}
foreach ( $ndvr_attachment_ids as $ndvr_attachment_id ) {
	if ( 'attachment' === get_post_type( $ndvr_attachment_id ) ) {
		wp_delete_attachment( $ndvr_attachment_id, true );
	}
}

// Pending review-reminder jobs. Hook only: with a group, Action Scheduler
// matches the empty args exactly and cancels nothing.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'ndvr_send_request' );
}

// Per-IP rate-limit transients (Forms\AntiSpam) — keyed per-hash, so pattern-match.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ndvr\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ndvr\\_rl\\_%'" );

delete_transient( 'ndv_reviews_activated' );
delete_option( 'ndv_reviews_unsubscribed' );
delete_option( 'ndv_reviews_settings' );
delete_metadata( 'user', 0, 'ndvr_setup_dismissed', '', true );
delete_metadata( 'user', 0, 'ndvr_health_notice_dismissed', '', true );
delete_option( 'ndv_reviews_db_version' );
