<?php
/**
 * Classic widget: reviews marquee.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Widgets;

use NdvReviews\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Sidebar/footer widget showing the reviews marquee.
 */
class MarqueeWidget extends \WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'ndvr_marquee',
			__( 'NDV Reviews: Marquee', 'ndv-reviews' ),
			array( 'description' => __( 'Scrolling marquee of recent reviews.', 'ndv-reviews' ) )
		);
	}

	/**
	 * Front-end output.
	 *
	 * @param array<string,mixed> $args     Sidebar args.
	 * @param array<string,mixed> $instance Saved instance.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$widgets = Plugin::instance()->container()->get( 'widgets' );
		$html    = $widgets->marquee(
			array(
				'source'     => 'all',
				'direction'  => 'vertical',
				'limit'      => isset( $instance['limit'] ) ? (int) $instance['limit'] : 12,
				'min_rating' => isset( $instance['min_rating'] ) ? (float) $instance['min_rating'] : 0,
			)
		);
		if ( '' === $html ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Settings form.
	 *
	 * @param array<string,mixed> $instance Saved instance.
	 * @return string
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : __( 'What customers say', 'ndv-reviews' );
		$limit = isset( $instance['limit'] ) ? (int) $instance['limit'] : 12;
		$min   = isset( $instance['min_rating'] ) ? (float) $instance['min_rating'] : 0;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'ndv-reviews' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"><?php esc_html_e( 'Number of reviews:', 'ndv-reviews' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>" type="number" value="<?php echo esc_attr( $limit ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'min_rating' ) ); ?>"><?php esc_html_e( 'Minimum rating:', 'ndv-reviews' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'min_rating' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'min_rating' ) ); ?>" type="number" step="0.5" min="0" max="5" value="<?php echo esc_attr( $min ); ?>" />
		</p>
		<?php
		return '';
	}

	/**
	 * Save.
	 *
	 * @param array<string,mixed> $new_instance New values.
	 * @param array<string,mixed> $old_instance Old values.
	 * @return array<string,mixed>
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title'      => sanitize_text_field( $new_instance['title'] ?? '' ),
			'limit'      => absint( $new_instance['limit'] ?? 12 ),
			'min_rating' => (float) ( $new_instance['min_rating'] ?? 0 ),
		);
	}
}
