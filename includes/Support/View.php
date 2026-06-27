<?php
/**
 * Template locator/renderer with theme override support.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves templates, preferring a theme override at
 * `yourtheme/ndv-reviews/<name>` before the plugin's own `templates/`.
 */
class View {

	/**
	 * Locate a template file, allowing theme overrides.
	 *
	 * @param string $name Template path relative to templates/, e.g. 'summary.php'.
	 * @return string Absolute path, or '' if not found.
	 */
	public static function locate( $name ) {
		$name      = ltrim( $name, '/' );
		$overrides = trailingslashit( 'ndv-reviews' ) . $name;

		$theme = locate_template( array( $overrides ) );
		if ( $theme ) {
			return $theme;
		}

		$plugin = NDVR_DIR . 'templates/' . $name;

		/**
		 * Filter the resolved template path.
		 *
		 * @param string $plugin Plugin template path.
		 * @param string $name   Template name.
		 */
		$plugin = apply_filters( 'ndv-reviews/template_path', $plugin, $name );

		return is_readable( $plugin ) ? $plugin : '';
	}

	/**
	 * Render a template with variables and return the output.
	 *
	 * @param string              $name Template name.
	 * @param array<string,mixed> $vars Variables extracted into scope.
	 * @return string
	 */
	public static function render( $name, array $vars = array() ) {
		$file = self::locate( $name );
		if ( '' === $file ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );

		ob_start();
		include $file;
		return (string) ob_get_clean();
	}
}
