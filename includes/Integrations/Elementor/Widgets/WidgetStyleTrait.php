<?php
/**
 * Shared Style-tab control helpers for the free Elementor widgets.
 *
 * Mirrors the Pro Parts\PartBase::add_align_control() pattern (small, reusable
 * control registration) — these widgets extend \Elementor\Widget_Base directly,
 * so a trait is used instead of a shared base class.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Elementor\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a color control and a typography group control, both wired via
 * Elementor `selectors` (scoped {{WRAPPER}} CSS) — no render-method changes.
 */
trait WidgetStyleTrait {

	/**
	 * Add a color control.
	 *
	 * @param string          $key      Control key.
	 * @param string          $label    Control label.
	 * @param string|string[] $selector One CSS selector, or several to share the
	 *                                  same control (each gets its own
	 *                                  {{WRAPPER}} prefix), without {{WRAPPER}}.
	 * @param string          $prop     CSS property to set (default 'color').
	 * @return void
	 */
	protected function add_color_control( $key, $label, $selector, $prop = 'color' ) {
		$selectors = array_map(
			static function ( $s ) {
				return trim( $s );
			},
			(array) $selector
		);
		$css_selector = implode(
			', ',
			array_map(
				static function ( $s ) {
					return '{{WRAPPER}} ' . $s;
				},
				$selectors
			)
		);

		$this->add_control(
			$key,
			array(
				'label'     => $label,
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					$css_selector => $prop . ': {{VALUE}};',
				),
			)
		);
	}

	/**
	 * Add a typography group control.
	 *
	 * @param string $key      Control key prefix.
	 * @param string $selector CSS selector (without {{WRAPPER}}).
	 * @return void
	 */
	protected function add_typography_control( $key, $selector ) {
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => $key,
				'selector' => '{{WRAPPER}} ' . $selector,
			)
		);
	}
}
