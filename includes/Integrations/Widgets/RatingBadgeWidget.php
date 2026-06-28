<?php
/**
 * Classic widget: rating badge (stars + count for a product).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Widgets;

use NdvReviews\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Sidebar/footer widget showing a product's aggregate stars + count.
 */
class RatingBadgeWidget extends \WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'ndvr_rating_badge',
			__( 'NDV Reviews: Rating Badge', 'ndv-reviews' ),
			array( 'description' => __( 'Aggregate stars + review count for a product.', 'ndv-reviews' ) )
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
		$html = Plugin::instance()->container()->get( 'widgets' )->stars( isset( $instance['product_id'] ) ? (int) $instance['product_id'] : 0 );
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
		$title   = isset( $instance['title'] ) ? $instance['title'] : '';
		$product = isset( $instance['product_id'] ) ? (int) $instance['product_id'] : 0;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'ndv-reviews' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'product_id' ) ); ?>"><?php esc_html_e( 'Product ID (0 = current):', 'ndv-reviews' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'product_id' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'product_id' ) ); ?>" type="number" value="<?php echo esc_attr( $product ); ?>" />
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
			'product_id' => absint( $new_instance['product_id'] ?? 0 ),
		);
	}
}
