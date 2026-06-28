<?php
/**
 * Elementor dynamic tag: product review count.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Elementor\Tags;

use NdvReviews\Integrations\Elementor\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Outputs the current/loop product's review count (cached meta — loop-safe).
 */
class ReviewCount extends \Elementor\Core\DynamicTags\Tag {

	/**
	 * Tag name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ndvr-review-count';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Product Review Count', 'ndv-reviews' );
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
		echo esc_html( (string) (int) get_post_meta( $product_id, '_wc_review_count', true ) );
	}
}
