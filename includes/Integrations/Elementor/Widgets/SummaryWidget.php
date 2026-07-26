<?php
/**
 * Elementor widget: review summary.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Elementor\Widgets;

use NdvReviews\Plugin;
use NdvReviews\Integrations\Elementor\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a product's review summary (average, distribution, criteria bars).
 */
class SummaryWidget extends \Elementor\Widget_Base {

	use WidgetStyleTrait;

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ndvr-summary';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Review Summary', 'ndv-reviews' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-review';
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
		$this->end_controls_section();

		$this->start_controls_section(
			'style',
			array(
				'label' => __( 'Style', 'ndv-reviews' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_color_control( 'average_color', __( 'Overall number color', 'ndv-reviews' ), '.ndvr-summary-average' );
		$this->add_typography_control( 'average_typography', '.ndvr-summary-average' );
		$this->add_color_control( 'bar_fill_color', __( 'Bar fill color', 'ndv-reviews' ), '.ndvr-bar-fill' );
		$this->add_color_control( 'bar_track_color', __( 'Bar track color', 'ndv-reviews' ), '.ndvr-bar-track', 'background' );
		$this->add_color_control( 'criterion_label_color', __( 'Criteria label color', 'ndv-reviews' ), '.ndvr-criterion-name' );
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'box_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ndvr-summary',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'box_border',
				'selector' => '{{WRAPPER}} .ndvr-summary',
			)
		);
		$this->add_control(
			'box_radius',
			array(
				'label'      => __( 'Border radius', 'ndv-reviews' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .ndvr-summary' => 'border-radius: {{SIZE}}{{UNIT}}; overflow: hidden;' ),
			)
		);
		$this->add_responsive_control(
			'box_padding',
			array(
				'label'      => __( 'Padding', 'ndv-reviews' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array( '{{WRAPPER}} .ndvr-summary' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'box_shadow',
				'selector' => '{{WRAPPER}} .ndvr-summary',
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
		echo Plugin::instance()->container()->get( 'widgets' )->summary( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
