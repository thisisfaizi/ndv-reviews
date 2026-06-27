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
	<nav class="ndvr-pagination" aria-label="<?php esc_attr_e( 'Reviews pages', 'ndv-reviews' ); ?>">
		<?php for ( $ndvr_p = 1; $ndvr_p <= (int) $result['pages']; $ndvr_p++ ) : ?>
			<button type="button" class="ndvr-page<?php echo (int) $result['page'] === $ndvr_p ? ' is-current' : ''; ?>" data-page="<?php echo esc_attr( $ndvr_p ); ?>"<?php echo (int) $result['page'] === $ndvr_p ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( number_format_i18n( $ndvr_p ) ); ?>
			</button>
		<?php endfor; ?>
	</nav>
<?php endif; ?>
