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
	 * Inline CSS setting the accent custom property on our roots.
	 *
	 * @param Settings $settings Settings.
	 * @return string
	 */
	public static function inline_css( Settings $settings ) {
		$accent = self::sanitize_color( (string) $settings->get( 'design_accent' ) );
		if ( '' === $accent ) {
			return '';
		}

		return ':root{--ndvr-accent:' . $accent . ';}';
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
