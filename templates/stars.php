<?php
/**
 * Aggregate star rating + count.
 *
 * Override: copy to yourtheme/ndv-reviews/stars.php
 *
 * @var float $average Average rating.
 * @var int   $count   Review count.
 *
 * @package NdvReviews
 */

use NdvReviews\Display\Html;

defined( 'ABSPATH' ) || exit;
?>
<span class="ndvr-stars-inline">
	<?php echo Html::stars( $average ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<span class="ndvr-stars-count">
		<?php
		if ( $count > 0 ) {
			/* translators: %s: number of reviews. */
			echo esc_html( sprintf( _n( '(%s review)', '(%s reviews)', $count, 'ndv-reviews' ), number_format_i18n( $count ) ) );
		} else {
			esc_html_e( '(No reviews yet)', 'ndv-reviews' );
		}
		?>
	</span>
</span>
