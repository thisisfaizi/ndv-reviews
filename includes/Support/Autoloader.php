<?php
/**
 * Lightweight PSR-4 autoloader (no Composer required at runtime).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a single PSR-4 namespace prefix -> base directory mapping.
 */
class Autoloader {

	/**
	 * Namespace prefix => base directory pairs.
	 *
	 * @var array<string,string>
	 */
	private static $prefixes = array();

	/**
	 * Register the autoloader for a namespace prefix.
	 *
	 * @param string $prefix   Namespace prefix, e.g. "NdvReviews\\".
	 * @param string $base_dir Absolute base directory for that prefix.
	 * @return void
	 */
	public static function register( $prefix, $base_dir ) {
		self::$prefixes[ $prefix ] = rtrim( $base_dir, '/\\' ) . '/';

		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve a fully-qualified class name to a file and require it.
	 *
	 * @param string $class Fully-qualified class name.
	 * @return void
	 */
	public static function load( $class ) {
		foreach ( self::$prefixes as $prefix => $base_dir ) {
			$len = strlen( $prefix );
			if ( 0 !== strncmp( $prefix, $class, $len ) ) {
				continue;
			}

			$relative = substr( $class, $len );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $file ) ) {
				require $file;
				return;
			}

			// The name matched this prefix but the file is not here — keep trying
			// other (e.g. more specific) prefixes such as the Pro add-on's
			// "NdvReviews\Pro\" before giving up. A bare prefix like "NdvReviews\"
			// would otherwise swallow "NdvReviews\Pro\..." classes.
		}
	}
}
