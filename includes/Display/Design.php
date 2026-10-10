<?php
/**
 * Storefront design resolver — turns the Design settings into CSS classes and
 * an accent custom property.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges the merchant's Design choices to the front end without touching
 * markup beyond a root class list and a single inline custom property.
 */
class Design {

	/**
	 * Allowed values per design key (whitelist).
	 *
	 * @var array<string,string[]>
	 */
	private static $allowed = array(
		'design_template' => array( 'list', 'grid' ),
		'design_summary'  => array( 'panel', 'compact' ),
		'design_card'     => array( 'soft', 'bordered', 'flat' ),
		'design_rating'   => array( 'stars', 'hearts', 'thumbs', 'emoji' ),
	);

	/**
	 * Body/wrap classes for the chosen design.
	 *
	 * @param Settings $settings Settings.
	 * @return string Space-separated class list.
	 */
	public static function classes( Settings $settings ) {
		$classes = array();

		foreach ( self::$allowed as $key => $values ) {
			$value = (string) $settings->get( $key );
			if ( ! in_array( $value, $values, true ) ) {
				continue;
			}
			$suffix = str_replace( 'design_', '', $key );
			$classes[] = 'ndvr-' . $suffix . '-' . $value;
		}

		return implode( ' ', $classes );
	}

	/**
	 * The rating-style class only (applied to the front-end body so every star
	 * instance, including widgets, swaps glyphs).
	 *
	 * @param Settings $settings Settings.
	 * @return string
	 */
	public static function rating_class( Settings $settings ) {
		$value = (string) $settings->get( 'design_rating' );

		return in_array( $value, self::$allowed['design_rating'], true ) ? 'ndvr-rating-' . $value : 'ndvr-rating-stars';
	}

	/**
	 * Inline CSS: accent (+ a readable text colour on it), rating and bar
	 * colours, font, and text size as custom properties on :root. Attached to the shared `ndvr-tokens`
	 * handle so every surface that loads one of our stylesheets gets it.
	 *
	 * @param Settings $settings Settings.
	 * @return string
	 */
	public static function inline_css( Settings $settings ) {
		$vars = array();

		$accent = self::sanitize_color( (string) $settings->get( 'design_accent' ) );
		if ( '' !== $accent ) {
			$vars[] = '--ndvr-accent:' . $accent;
			$vars[] = '--ndvr-accent-ink:' . self::ink_for( $accent );
		}

		// Rating icon colour: stars and hearts on every surface (Pro widgets read the same tokens).
		$rating = self::sanitize_color( (string) $settings->get( 'design_rating_color' ) );
		if ( '' !== $rating ) {
			$vars[] = '--ndvr-gold:' . $rating;
			$vars[] = '--ndvr-heart:' . $rating;
		}

		// Rating bars: the star distribution and the criteria breakdown.
		$bar = self::sanitize_color( (string) $settings->get( 'design_bar_color' ) );
		if ( '' !== $bar ) {
			$vars[] = '--ndvr-bar:' . $bar;
		}

		$fonts = self::fonts();
		$font  = (string) $settings->get( 'design_font', 'system' );
		if ( isset( $fonts[ $font ] ) && 'system' !== $font ) {
			$vars[] = '--ndvr-font:' . $fonts[ $font ];
		}

		$scales = self::scales();
		$scale  = (string) $settings->get( 'design_scale', 'normal' );
		if ( isset( $scales[ $scale ] ) && 'normal' !== $scale ) {
			$vars[] = '--ndvr-text:' . $scales[ $scale ];
		}

		return empty( $vars ) ? '' : ':root{' . implode( ';', $vars ) . ';}';
	}

	/**
	 * Font stacks per design_font value.
	 *
	 * @return array<string,string>
	 */
	public static function fonts() {
		return array(
			'system'  => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
			'serif'   => 'Georgia, "Times New Roman", "Iowan Old Style", serif',
			'rounded' => '"Segoe UI Rounded", ui-rounded, "SF Pro Rounded", system-ui, sans-serif',
			'mono'    => 'ui-monospace, "SF Mono", "Cascadia Mono", Consolas, monospace',
		);
	}

	/**
	 * Base text size per design_scale value. Every review surface sizes its
	 * text in em from this one value, so changing it scales the whole UI.
	 *
	 * @return array<string,string>
	 */
	public static function scales() {
		return array(
			'compact' => '14px',
			'normal'  => '15px',
			'large'   => '17px',
		);
	}

	/**
	 * Readable text colour (near-black or white) for a background colour,
	 * picked by WCAG contrast ratio.
	 *
	 * @param string $hex Background hex colour.
	 * @return string
	 */
	public static function ink_for( $hex ) {
		$lum = self::luminance( $hex );
		if ( null === $lum ) {
			return '#ffffff';
		}

		// Contrast against white vs against the ink token (#181a1f, L≈0.0103).
		$on_white = 1.05 / ( $lum + 0.05 );
		$on_dark  = ( $lum + 0.05 ) / 0.0603;

		return $on_white >= $on_dark ? '#ffffff' : '#181a1f';
	}

	/**
	 * WCAG contrast ratio of a colour against white (the review cards'
	 * background). Ratings and bars need at least 3:1 to stay visible.
	 *
	 * @param string $hex Hex colour.
	 * @return float Ratio from 1 to 21 (21 for an invalid colour, so no warning).
	 */
	public static function contrast_on_white( $hex ) {
		$lum = self::luminance( $hex );

		return null === $lum ? 21.0 : round( 1.05 / ( $lum + 0.05 ), 2 );
	}

	/**
	 * Relative luminance of a hex colour (#rgb or #rrggbb).
	 *
	 * @param string $hex Hex colour.
	 * @return float|null Null when the value is not a hex colour.
	 */
	private static function luminance( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return null;
		}

		$channels = array();
		foreach ( array( 0, 2, 4 ) as $i ) {
			$c          = hexdec( substr( $hex, $i, 2 ) ) / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * Validate a hex color.
	 *
	 * @param string $color Raw color.
	 * @return string Sanitized hex, or '' if invalid.
	 */
	public static function sanitize_color( $color ) {
		$color = sanitize_hex_color( $color );

		return $color ? $color : '';
	}
}
