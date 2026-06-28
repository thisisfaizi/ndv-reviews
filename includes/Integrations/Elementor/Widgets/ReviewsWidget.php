<?php
/**
 * Elementor widget: review list (the "Review Section" all-in-one for Theme
 * Builder single-product templates).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Elementor\Widgets;

use NdvReviews\Plugin;
use NdvReviews\Integrations\Elementor\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the summary + paginated review list for the current/loop product.
 */
class ReviewsWidget extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ndvr-reviews';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Review Section', 'ndv-reviews' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-testimonial';
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
				'label'   => __( 'Product ID (0 = current)', 'ndv-reviews' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 0,
			)
		);
		$this->add_control(
			'per_page',
			array(
				'label'   => __( 'Reviews per page', 'ndv-reviews' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 10,
			)
		);
		$this->add_control(
			'show_summary',
			array(
				'label'        => __( 'Show summary', 'ndv-reviews' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
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
		$widgets  = Plugin::instance()->container()->get( 'widgets' );

		if ( 'yes' === ( $settings['show_summary'] ?? 'yes' ) ) {
			echo $widgets->summary( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $widgets->reviews( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'product_id' => $product,
				'per_page'   => isset( $settings['per_page'] ) ? (int) $settings['per_page'] : 10,
			)
		);
	}
}
