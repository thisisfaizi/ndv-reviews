<?php
/**
 * Database installer (dbDelta schema + version tracking).
 *
 * @package NdvReviews
 */

namespace NdvReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's custom tables.
 *
 * dbDelta is picky: two spaces after PRIMARY KEY, one column per line,
 * lowercase types, named KEYs. Keep this formatting intact.
 */
class Installer {

	/**
	 * Run install/upgrade if the stored DB version is behind code.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( NDVR_OPTION_DB_VERSION );

		if ( (string) $installed === (string) NDVR_DB_VERSION ) {
			return;
		}

		self::install();
		update_option( NDVR_OPTION_DB_VERSION, NDVR_DB_VERSION );
	}

	/**
	 * Create/upgrade all custom tables via dbDelta.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . NDVR_TABLE_PREFIX;

		foreach ( self::schema( $prefix, $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * The full schema as an array of CREATE TABLE statements.
	 *
	 * @param string $prefix          Fully-qualified table prefix ({$wpdb->prefix}ndvr_).
	 * @param string $charset_collate Charset/collate clause.
	 * @return string[]
	 */
	private static function schema( $prefix, $charset_collate ) {
		$tables = array();

		// Criteria definitions (build-plan §6.1).
		$tables[] = "CREATE TABLE {$prefix}criteria (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			slug varchar(191) NOT NULL,
			scope varchar(20) NOT NULL DEFAULT 'global',
			scope_id bigint(20) unsigned DEFAULT NULL,
			position int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			PRIMARY KEY  (id),
			KEY scope_idx (scope, scope_id)
		) {$charset_collate};";

		// Per-review criteria scores.
		$tables[] = "CREATE TABLE {$prefix}review_criteria (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			comment_id bigint(20) unsigned NOT NULL,
			criteria_id bigint(20) unsigned NOT NULL,
			rating decimal(3,2) NOT NULL,
			PRIMARY KEY  (id),
			KEY comment_idx (comment_id),
			KEY criteria_idx (criteria_id)
		) {$charset_collate};";

		// Review media (photos/videos).
		$tables[] = "CREATE TABLE {$prefix}review_media (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			comment_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL,
			attachment_id bigint(20) unsigned DEFAULT NULL,
			url text DEFAULT NULL,
			position int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'approved',
			PRIMARY KEY  (id),
			KEY comment_idx (comment_id)
		) {$charset_collate};";

		// Review votes.
		$tables[] = "CREATE TABLE {$prefix}review_votes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			comment_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			ip_hash char(64) DEFAULT NULL,
			vote tinyint(4) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_vote (comment_id, user_id, ip_hash)
		) {$charset_collate};";

		// Request/automation queue + log.
		$tables[] = "CREATE TABLE {$prefix}requests (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			customer_id bigint(20) unsigned DEFAULT NULL,
			email varchar(191) DEFAULT NULL,
			phone varchar(32) DEFAULT NULL,
			channel varchar(20) NOT NULL DEFAULT 'email',
			step int(11) NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'scheduled',
			scheduled_at datetime DEFAULT NULL,
			sent_at datetime DEFAULT NULL,
			error text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY order_idx (order_id),
			KEY status_idx (status, scheduled_at)
		) {$charset_collate};";

		// Product Q&A (Pro).
		$tables[] = "CREATE TABLE {$prefix}questions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			author_name varchar(191) DEFAULT NULL,
			question text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			votes int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY product_idx (product_id, status)
		) {$charset_collate};";

		$tables[] = "CREATE TABLE {$prefix}answers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			author_name varchar(191) DEFAULT NULL,
			is_merchant tinyint(4) NOT NULL DEFAULT 0,
			answer text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			votes int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY question_idx (question_id, status)
		) {$charset_collate};";

		// AI enrichment cache (Pro).
		$tables[] = "CREATE TABLE {$prefix}ai_meta (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(20) NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			sentiment decimal(3,2) DEFAULT NULL,
			tags text DEFAULT NULL,
			summary text DEFAULT NULL,
			spam_score decimal(3,2) DEFAULT NULL,
			lang varchar(12) DEFAULT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY obj_idx (object_type, object_id)
		) {$charset_collate};";

		// Standalone collection forms (build-plan §19.9).
		$tables[] = "CREATE TABLE {$prefix}forms (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(191) NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'testimonial',
			fields longtext DEFAULT NULL,
			settings longtext DEFAULT NULL,
			token char(32) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_idx (token)
		) {$charset_collate};";

		// External connections (Google/Facebook/social/marketing).
		$tables[] = "CREATE TABLE {$prefix}connections (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			provider varchar(40) NOT NULL,
			account_ref varchar(191) DEFAULT NULL,
			credentials longtext DEFAULT NULL,
			meta longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'connected',
			last_sync datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY provider_idx (provider)
		) {$charset_collate};";

		// Outbound social/campaign jobs.
		$tables[] = "CREATE TABLE {$prefix}campaigns (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			config longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			stats longtext DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		// Tokenized review-collection links (build-plan §21.9).
		$tables[] = "CREATE TABLE {$prefix}review_tokens (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			order_id bigint(20) unsigned DEFAULT NULL,
			customer_id bigint(20) unsigned DEFAULT NULL,
			email_hash char(64) NOT NULL,
			token_hash char(64) NOT NULL,
			products longtext DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			expires_at datetime DEFAULT NULL,
			used_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_idx (token_hash),
			KEY order_idx (order_id),
			KEY customer_idx (customer_id)
		) {$charset_collate};";

		return $tables;
	}

	/**
	 * Table names this plugin owns (used by uninstall when data removal is opted in).
	 *
	 * @return string[] Fully-qualified table names.
	 */
	public static function table_names() {
		global $wpdb;

		$prefix = $wpdb->prefix . NDVR_TABLE_PREFIX;

		return array(
			$prefix . 'criteria',
			$prefix . 'review_criteria',
			$prefix . 'review_media',
			$prefix . 'review_votes',
			$prefix . 'requests',
			$prefix . 'questions',
			$prefix . 'answers',
			$prefix . 'ai_meta',
			$prefix . 'forms',
			$prefix . 'connections',
			$prefix . 'campaigns',
			$prefix . 'review_tokens',
		);
	}
}
