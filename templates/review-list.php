<?php
/**
 * Review list + pagination (also used as the AJAX fragment).
 *
 * Override: copy to yourtheme/ndv-reviews/review-list.php
 *
 * @var array<string,mixed> $result     Paginated result from ReviewQuery.
 * @var string              $vote_nonce Helpful-vote nonce.
 *
 * @package NdvReviews
 */

use NdvReviews\Support\View;

defined( 'ABSPATH' ) || exit;

if ( empty( $result['items'] ) ) {
	echo '<p class="ndvr-no-reviews">' . esc_html__( 'No reviews match your selection yet.', 'ndv-reviews' ) . '</p>';
	return;
}
?>
<ol class="ndvr-review-list">
	<?php
	foreach ( $result['items'] as $ndvr_review ) {
		// View::render returns escaped template output.
		echo View::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'review-item.php',
			array(
				'review'     => $ndvr_review,
				'vote_nonce' => $vote_nonce,
			)
		);
	}
	?>
</ol>

<?php if ( ! empty( $result['pages'] ) && $result['pages'] > 1 ) : ?>
	<?php
	// Windowed pager: always show first/last, a run around the current page, and
	// "…" for gaps — avoids rendering one <button> per page (e.g. 50 buttons for
	// a 50-page product).
	$ndvr_total   = (int) $result['pages'];
	$ndvr_current = (int) $result['page'];
	$ndvr_around  = 2; // pages shown on each side of the current page.

	$ndvr_pages_to_show = array( 1, $ndvr_total );
	for ( $ndvr_p = max( 1, $ndvr_current - $ndvr_around ); $ndvr_p <= min( $ndvr_total, $ndvr_current + $ndvr_around ); $ndvr_p++ ) {
		$ndvr_pages_to_show[] = $ndvr_p;
	}
	$ndvr_pages_to_show = array_values( array_unique( $ndvr_pages_to_show ) );
	sort( $ndvr_pages_to_show, SORT_NUMERIC );
	?>
	<nav class="ndvr-pagination" aria-label="<?php esc_attr_e( 'Reviews pages', 'ndv-reviews' ); ?>">
		<?php if ( $ndvr_current > 1 ) : ?>
			<button type="button" class="ndvr-page ndvr-page-prev" data-page="<?php echo esc_attr( $ndvr_current - 1 ); ?>">
				<?php esc_html_e( 'Prev', 'ndv-reviews' ); ?>
			</button>
		<?php endif; ?>
		<?php
		$ndvr_prev_shown = 0;
		foreach ( $ndvr_pages_to_show as $ndvr_p ) :
			if ( $ndvr_prev_shown && $ndvr_p - $ndvr_prev_shown > 1 ) :
				?>
				<span class="ndvr-page-ellipsis" aria-hidden="true">&hellip;</span>
				<?php
			endif;
			$ndvr_prev_shown = $ndvr_p;
			?>
			<button type="button" class="ndvr-page<?php echo $ndvr_current === $ndvr_p ? ' is-current' : ''; ?>" data-page="<?php echo esc_attr( $ndvr_p ); ?>"<?php echo $ndvr_current === $ndvr_p ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( number_format_i18n( $ndvr_p ) ); ?>
			</button>
		<?php endforeach; ?>
		<?php if ( $ndvr_current < $ndvr_total ) : ?>
			<button type="button" class="ndvr-page ndvr-page-next" data-page="<?php echo esc_attr( $ndvr_current + 1 ); ?>">
				<?php esc_html_e( 'Next', 'ndv-reviews' ); ?>
			</button>
		<?php endif; ?>
	</nav>
<?php endif; ?>
