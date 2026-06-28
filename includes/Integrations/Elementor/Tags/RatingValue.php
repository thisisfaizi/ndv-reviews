<?php
/**
 * Elementor dynamic tag: product rating value.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Elementor\Tags;

use NdvReviews\Integrations\Elementor\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs the current/loop product's average rating (reads cached meta — no
 * per-card query, so it is safe inside Loop Grids).
 */
class RatingValue extends \Elementor\Core\DynamicTags\Tag {

	/**
	 * Tag name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ndvr-rating-value';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Product Rating Value', 'ndv-reviews' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function get_group() {
		return 'ndv-reviews';
	}

	/**
	 * Categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( \Elementor\Modules\DynamicTags\Module::NUMBER_CATEGORY, \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY );
	}

	/**
	 * Render the value.
	 *
	 * @return void
	 */
	public function render() {
		$product_id = Module::current_product_id();
		$value      = (float) get_post_meta( $product_id, '_wc_average_rating', true );
		echo esc_html( $value > 0 ? number_format_i18n( $value, 2 ) : '0' );
	}
}
