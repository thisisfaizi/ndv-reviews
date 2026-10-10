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

	use WidgetStyleTrait;

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
		return __( 'Review Section', 'rosette-reviews' );
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
	 * Stylesheets Elementor must load wherever this widget appears (including
	 * cached element output, where render() does not run).
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( 'ndvr-display' );
	}

	/**
	 * Scripts Elementor must load wherever this widget appears.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( 'ndvr-display' );
	}

	/**
	 * Output depends on live review data and carries per-visitor nonces, so it
	 * must never be served from Elementor's element cache.
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * Controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => __( 'Content', 'rosette-reviews' ) ) );
		$this->add_control(
			'product_id',
			array(
				'label'   => __( 'Product ID (0 = current)', 'rosette-reviews' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 0,
			)
		);
		$this->add_control(
			'per_page',
			array(
				'label'   => __( 'Reviews per page', 'rosette-reviews' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 10,
			)
		);
		$this->add_control(
			'orderby',
			array(
				'label'   => __( 'Order by', 'rosette-reviews' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'recent',
				'options' => array(
					'recent'  => __( 'Most recent', 'rosette-reviews' ),
					'helpful' => __( 'Most helpful', 'rosette-reviews' ),
					'highest' => __( 'Highest rated', 'rosette-reviews' ),
					'lowest'  => __( 'Lowest rated', 'rosette-reviews' ),
				),
			)
		);
		$this->add_control(
			'show_summary',
			array(
				'label'        => __( 'Show summary', 'rosette-reviews' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => 'yes',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'style_card',
			array(
				'label' => __( 'Card', 'rosette-reviews' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			array(
				'name'     => 'card_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ndvr-review',
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'card_border',
				'selector' => '{{WRAPPER}} .ndvr-review',
			)
		);
		$this->add_control(
			'card_radius',
			array(
				'label'      => __( 'Border radius', 'rosette-reviews' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .ndvr-review' => 'border-radius: {{SIZE}}{{UNIT}}; overflow: hidden;' ),
			)
		);
		$this->add_responsive_control(
			'card_padding',
			array(
				'label'      => __( 'Padding', 'rosette-reviews' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array( '{{WRAPPER}} .ndvr-review' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_shadow',
				'selector' => '{{WRAPPER}} .ndvr-review',
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'style_content',
			array(
				'label' => __( 'Text & Badges', 'rosette-reviews' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);
		$this->add_color_control( 'author_color', __( 'Author name color', 'rosette-reviews' ), '.ndvr-review-name' );
		$this->add_typography_control( 'author_typography', '.ndvr-review-name' );
		$this->add_color_control( 'verified_color', __( 'Verified badge color', 'rosette-reviews' ), '.ndvr-verified-badge' );
		$this->add_color_control( 'stars_color', __( 'Stars color', 'rosette-reviews' ), array( '.ndvr-review-meta .ndvr-star-full', '.ndvr-review-meta .ndvr-star-half::after' ) );
		$this->add_color_control( 'date_color', __( 'Date color', 'rosette-reviews' ), '.ndvr-review-date' );
		$this->add_typography_control( 'date_typography', '.ndvr-review-date' );
		$this->add_color_control( 'title_color', __( 'Review title color', 'rosette-reviews' ), '.ndvr-review-title' );
		$this->add_typography_control( 'title_typography', '.ndvr-review-title' );
		$this->add_color_control( 'body_color', __( 'Review text color', 'rosette-reviews' ), '.ndvr-review-body' );
		$this->add_typography_control( 'body_typography', '.ndvr-review-body' );
		$this->add_color_control( 'helpful_color', __( 'Helpful button color', 'rosette-reviews' ), '.ndvr-helpful' );
		$this->add_color_control( 'recommend_yes_color', __( 'Recommends color', 'rosette-reviews' ), '.ndvr-recommend-yes' );
		$this->add_color_control( 'recommend_no_color', __( 'Does-not-recommend color', 'rosette-reviews' ), '.ndvr-recommend-no' );
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

		// The summary renders inside the list instance so its distribution rows
		// can filter this list.
		$html = $widgets->reviews(
			array(
				'product_id'   => $product,
				'per_page'     => isset( $settings['per_page'] ) ? (int) $settings['per_page'] : 10,
				'orderby'      => isset( $settings['orderby'] ) ? (string) $settings['orderby'] : 'recent',
				'show_summary' => 'yes' === ( $settings['show_summary'] ?? 'yes' ),
			)
		);
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- widget output is escaped at source.
	}
}
