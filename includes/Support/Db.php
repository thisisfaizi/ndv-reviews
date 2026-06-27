<?php
/**
 * Database helpers (table-name resolution).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Central place to resolve our prefixed custom table names.
 */
class Db {

	/**
	 * Fully-qualified name for one of our custom tables.
	 *
	 * @param string $suffix Table suffix without the prefix, e.g. 'criteria'.
	 * @return string
	 */
	public static function table( $suffix ) {
		global $wpdb;

		return $wpdb->prefix . NDVR_TABLE_PREFIX . $suffix;
	}
}
