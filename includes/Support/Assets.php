<?php
/**
 * Serve minified assets in production.
 *
 * Rewrites the src of any `ndvr-*` style/script handle to its `.min` sibling
 * when that file exists and SCRIPT_DEBUG is off. One filter covers every enqueue
 * across the free plugin AND the Pro add-on (all handles share the `ndvr-`
 * prefix), so no per-enqueue edits are needed. Falls back to the source file
 * when a `.min` is absent, so nothing 404s.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Loader-src filter that points our handles at the built .min files.
 */
class Assets implements Registerable {

	/**
	 * Handle prefix we own (free + Pro).
	 */
	const PREFIX = 'ndvr-';

	/**
	 * Cache of resolved src rewrites (keyed by original src).
	 *
	 * @var array<string,string>
	 */
	private $cache = array();

	/**
	 * Register the loader filters.
	 *
	 * @return void
	 */
	public function register() {
		// Skip entirely when debugging — always serve readable source then.
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return;
		}
		add_filter( 'style_loader_src', array( $this, 'minify' ), 10, 2 );
		add_filter( 'script_loader_src', array( $this, 'minify' ), 10, 2 );
	}

	/**
	 * Swap a handle's src to its .min sibling when available.
	 *
	 * @param string $src    Asset URL (may carry a ?ver= query).
	 * @param string $handle Registered handle.
	 * @return string
	 */
	public function minify( $src, $handle ) {
		if ( 0 !== strpos( (string) $handle, self::PREFIX ) || ! is_string( $src ) || '' === $src ) {
			return $src;
		}
		if ( isset( $this->cache[ $src ] ) ) {
			return $this->cache[ $src ];
		}

		$this->cache[ $src ] = $src; // Default: unchanged.

		// Split off any query string (?ver=…) before touching the path.
		$parts = explode( '?', $src, 2 );
		$url   = $parts[0];
		$query = isset( $parts[1] ) ? '?' . $parts[1] : '';

		if ( ! preg_match( '/\.(css|js)$/', $url, $m ) || false !== strpos( $url, '.min.' ) ) {
			return $src;
		}

		$min_url  = preg_replace( '/\.(css|js)$/', '.min.$1', $url );
		$min_path = $this->url_to_path( $min_url );

		if ( '' !== $min_path && file_exists( $min_path ) ) {
			$this->cache[ $src ] = $min_url . $query;
		}

		return $this->cache[ $src ];
	}

	/**
	 * Map a content-dir URL to its filesystem path (no request input involved).
	 *
	 * @param string $url Asset URL under wp-content.
	 * @return string Absolute path, or '' if it isn't under wp-content.
	 */
	private function url_to_path( $url ) {
		$content_url = content_url();
		if ( 0 !== strpos( $url, $content_url ) ) {
			return '';
		}

		return WP_CONTENT_DIR . substr( $url, strlen( $content_url ) );
	}
}
