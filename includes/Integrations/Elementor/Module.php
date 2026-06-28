<?php
/**
 * Elementor integration module.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Elementor;

use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "NDV Reviews" Elementor widget category, the widgets, and the
 * dynamic tags that power Loop Item bindings. Loads only when Elementor is
 * present; degrades gracefully otherwise (shortcodes/blocks still cover users).
 */
class Module implements Registerable {

	/**
	 * Register hooks (only meaningful when Elementor is active).
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'add_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/dynamic_tags/register', array( $this, 'register_tags' ) );
	}

	/**
	 * Add the NDV Reviews widget category.
	 *
	 * @param \Elementor\Elements_Manager $manager Elements manager.
	 * @return void
	 */
	public function add_category( $manager ) {
		$manager->add_category(
			'ndv-reviews',
			array(
				'title' => __( 'NDV Reviews', 'ndv-reviews' ),
				'icon'  => 'eicon-star',
			)
		);
	}

	/**
	 * Register the widgets.
	 *
	 * @param \Elementor\Widgets_Manager $manager Widgets manager.
	 * @return void
	 */
	public function register_widgets( $manager ) {
		$manager->register( new Widgets\Stars() );
		$manager->register( new Widgets\SummaryWidget() );
		$manager->register( new Widgets\ReviewsWidget() );
		$manager->register( new Widgets\MarqueeWidget() );
	}

	/**
	 * Register the dynamic tags.
	 *
	 * @param \Elementor\Core\DynamicTags\Manager $manager Tags manager.
	 * @return void
	 */
	public function register_tags( $manager ) {
		if ( method_exists( $manager, 'register_group' ) ) {
			$manager->register_group( 'ndv-reviews', array( 'title' => __( 'NDV Reviews', 'ndv-reviews' ) ) );
		}
		$manager->register( new Tags\RatingValue() );
		$manager->register( new Tags\ReviewCount() );
	}

	/**
	 * Resolve the current product id within an Elementor loop/theme-builder
	 * context, with an editor sample fallback (build-plan §20.3/§20.4).
	 *
	 * @return int
	 */
	public static function current_product_id() {
		$id = get_the_ID();

		if ( ! $id && function_exists( 'wc_get_product' ) ) {
			global $product;
			if ( $product instanceof \WC_Product ) {
				$id = $product->get_id();
			}
		}

		if ( ! $id ) {
			$qo = get_queried_object_id();
			if ( $qo ) {
				$id = $qo;
			}
		}

		// Editor/preview fallback: show a sample product so widgets never appear empty.
		if ( ( ! $id || 'product' !== get_post_type( $id ) ) && self::is_edit_mode() ) {
			$sample = get_posts(
				array(
					'post_type'      => 'product',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			if ( ! empty( $sample ) ) {
				$id = (int) $sample[0];
			}
		}

		return (int) $id;
	}

	/**
	 * Whether Elementor is in edit/preview mode.
	 *
	 * @return bool
	 */
	public static function is_edit_mode() {
		return class_exists( '\Elementor\Plugin' )
			&& \Elementor\Plugin::instance()->editor->is_edit_mode();
	}
}
