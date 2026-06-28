<?php
/**
 * Elementor widget: reviews marquee.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Elementor\Widgets;

use NdvReviews\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the reviews marquee with source + display controls.
 */
class MarqueeWidget extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ndvr-marquee';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Reviews Marquee', 'ndv-reviews' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-slider-push';
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
			'min_rating',
			array(
				'label'   => __( 'Minimum rating', 'ndv-reviews' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 0,
				'min'     => 0,
				'max'     => 5,
			)
		);
		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Number of reviews', 'ndv-reviews' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 20,
			)
		);
		$this->add_control(
			'speed',
			array(
				'label'   => __( 'Speed (seconds)', 'ndv-reviews' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 40,
			)
		);
		$this->add_control(
			'direction',
			array(
				'label'   => __( 'Direction', 'ndv-reviews' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'horizontal',
				'options' => array(
					'horizontal' => __( 'Horizontal', 'ndv-reviews' ),
					'vertical'   => __( 'Vertical', 'ndv-reviews' ),
				),
			)
		);
		$this->add_control(
			'verified',
			array(
				'label'   => __( 'Verified buyers only', 'ndv-reviews' ),
				'type'    => \Elementor\Controls_Manager::SWITCHER,
				'default' => '',
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
		echo Plugin::instance()->container()->get( 'widgets' )->marquee( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'source'     => 'all',
				'min_rating' => isset( $settings['min_rating'] ) ? (float) $settings['min_rating'] : 0,
				'limit'      => isset( $settings['limit'] ) ? (int) $settings['limit'] : 20,
				'speed'      => isset( $settings['speed'] ) ? (int) $settings['speed'] : 40,
				'direction'  => ( ( $settings['direction'] ?? 'horizontal' ) === 'vertical' ) ? 'vertical' : 'horizontal',
				'verified'   => ! empty( $settings['verified'] ),
			)
		);
	}
}
