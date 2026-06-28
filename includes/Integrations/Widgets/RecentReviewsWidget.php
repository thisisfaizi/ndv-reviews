<?php
/**
 * Classic widget: recent reviews across the store.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Widgets;

use NdvReviews\Plugin;
use NdvReviews\Display\Html;

defined( 'ABSPATH' ) || exit;

/**
 * Sidebar/footer widget listing the most recent reviews store-wide.
 */
class RecentReviewsWidget extends \WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'ndvr_recent_reviews',
			__( 'NDV Reviews: Recent Reviews', 'ndv-reviews' ),
			array( 'description' => __( 'The latest reviews across your store.', 'ndv-reviews' ) )
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
		$limit = isset( $instance['limit'] ) ? (int) $instance['limit'] : 5;
		$items = Plugin::instance()->container()->get( 'widgets' )->recent( $limit );
		if ( empty( $items ) ) {
			return;
		}

		wp_enqueue_style( 'ndvr-display', NDVR_URL . 'assets/css/display.css', array(), NDVR_VERSION );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Recent reviews', 'ndv-reviews' );
		echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<ul class="ndvr-recent-list" style="list-style:none;margin:0;padding:0;">';
		foreach ( $items as $review ) {
			echo '<li class="ndvr-recent-item" style="padding:10px 0;border-bottom:1px solid #eee;">';
			echo Html::stars( $review['overall'] ? $review['overall'] : $review['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div style="font-size:.9em;margin-top:4px;">' . esc_html( wp_trim_words( $review['content'], 14 ) ) . '</div>';
			echo '<div style="font-size:.78em;color:#888;">— ' . esc_html( $review['author'] ) . '</div>';
			echo '</li>';
		}
		echo '</ul>';

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Settings form.
	 *
	 * @param array<string,mixed> $instance Saved instance.
	 * @return string
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		$limit = isset( $instance['limit'] ) ? (int) $instance['limit'] : 5;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'ndv-reviews' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"><?php esc_html_e( 'Number of reviews:', 'ndv-reviews' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>" type="number" value="<?php echo esc_attr( $limit ); ?>" />
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
			'title' => sanitize_text_field( $new_instance['title'] ?? '' ),
			'limit' => absint( $new_instance['limit'] ?? 5 ),
		);
	}
}
