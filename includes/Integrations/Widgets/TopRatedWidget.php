<?php
/**
 * Classic widget: top-rated products.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Integrations\Widgets;

use NdvReviews\Display\Html;

defined( 'ABSPATH' ) || exit;

/**
 * Sidebar/footer widget listing the highest-rated products.
 */
class TopRatedWidget extends \WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'ndvr_top_rated',
			__( 'NDV Reviews: Top-Rated Products', 'ndv-reviews' ),
			array( 'description' => __( 'Your highest-rated products.', 'ndv-reviews' ) )
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

		$products = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => max( 1, min( 20, $limit ) ),
				'meta_key'       => '_wc_average_rating', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wc_review_count',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		if ( empty( $products ) ) {
			return;
		}

		wp_enqueue_style( 'ndvr-display', NDVR_URL . 'assets/css/display.css', array(), NDVR_VERSION );

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Top-rated products', 'ndv-reviews' );
		echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<ul class="ndvr-top-rated" style="list-style:none;margin:0;padding:0;">';
		foreach ( $products as $product ) {
			$avg = (float) get_post_meta( $product->ID, '_wc_average_rating', true );
			echo '<li style="padding:8px 0;display:flex;align-items:center;gap:10px;">';
			$thumb = get_the_post_thumbnail( $product->ID, array( 40, 40 ) );
			echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<span><a href="' . esc_url( get_permalink( $product->ID ) ) . '" style="text-decoration:none;">' . esc_html( get_the_title( $product->ID ) ) . '</a><br>';
			echo Html::stars( $avg ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</span></li>';
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
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"><?php esc_html_e( 'Number of products:', 'ndv-reviews' ); ?></label>
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
