<?php
/**
 * Elementor widget: aggregate star rating.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Elementor\Widgets;

use NdvReviews\Plugin;
use NdvReviews\Integrations\Elementor\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a product's aggregate stars + count; loop/theme-builder aware.
 */
class Stars extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ndvr-stars';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Star Rating', 'ndv-reviews' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-star';
	}

	/**
	 * Categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'ndv-reviews' );
	}

	/**
	 * Controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => __( 'Content', 'ndv-reviews' ) ) );
		$this->add_control(
			'product_id',
			array(
				'label'       => __( 'Product ID (0 = current)', 'ndv-reviews' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$product  = ! empty( $settings['product_id'] ) ? (int) $settings['product_id'] : Module::current_product_id();
		echo Plugin::instance()->container()->get( 'widgets' )->stars( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
