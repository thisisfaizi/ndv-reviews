<?php
/**
 * Registers classic WP_Widget widgets for non-block themes.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations;

use NdvReviews\Support\Registerable;
use NdvReviews\Integrations\Widgets\SummaryWidget;
use NdvReviews\Integrations\Widgets\MarqueeWidget;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the review widgets available in Appearance → Widgets / legacy sidebars,
 * each sharing the same renderer as its block and shortcode counterparts.
 */
class ClassicWidgets implements Registerable {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'widgets_init', array( $this, 'register_widgets' ) );
	}

	/**
	 * Register the widget classes.
	 *
	 * @return void
	 */
	public function register_widgets() {
		register_widget( SummaryWidget::class );
		register_widget( MarqueeWidget::class );
	}
}
