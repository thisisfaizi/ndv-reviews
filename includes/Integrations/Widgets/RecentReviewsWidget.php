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
			__( 'Rosette Reviews: Recent Reviews', 'rosette-reviews' ),
			array( 'description' => __( 'The latest reviews across your store.', 'rosette-reviews' ) )
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

		Plugin::instance()->container()->get( 'widgets' )->enqueue( 'stars' );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Recent reviews', 'rosette-reviews' );
		echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<ul class="ndvr-recent-list">';
		foreach ( $items as $review ) {
			echo '<li class="ndvr-recent-item">';
			echo Html::stars( $review['overall'] ? $review['overall'] : $review['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="ndvr-recent-text">' . esc_html( wp_trim_words( $review['content'], 14 ) ) . '</div>';
			echo '<div class="ndvr-recent-author">&mdash; ' . esc_html( $review['author'] );
			/** This action is documented in templates/marquee.php (compact cards: the incentive disclosure prints here). */
			do_action( 'ndv-reviews/marquee_author_badges', $review );
			echo '</div>';
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
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'rosette-reviews' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"><?php esc_html_e( 'Number of reviews:', 'rosette-reviews' ); ?></label>
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
