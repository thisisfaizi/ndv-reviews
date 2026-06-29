<?php
/**
 * A single review item.
 *
 * Override: copy to yourtheme/ndv-reviews/review-item.php
 *
 * @var array<string,mixed> $review     Review view-model from ReviewQuery.
 * @var string              $vote_nonce Nonce for the helpful-vote action.
 *
 * @package NdvReviews
 */

use NdvReviews\Display\Html;

defined( 'ABSPATH' ) || exit;

if ( empty( $review ) ) {
	return;
}
?>
<li class="ndvr-review" id="ndvr-review-<?php echo esc_attr( $review['id'] ); ?>">
	<div class="ndvr-review-head">
		<div class="ndvr-review-author">
			<?php echo get_avatar( '', 40, '', $review['author'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="ndvr-review-name"><?php echo esc_html( $review['author'] ); ?></span>
			<?php
			/**
			 * Filter: show/hide the "Verified Buyer" badge.
			 * Default: true when the review has _ndvr_verified = 1.
			 * Pro hooks this to respect the "Review Card" setting.
			 *
			 * @param bool                $show   Whether to show the badge.
			 * @param array<string,mixed> $review Review view-model.
			 */
			if ( apply_filters( 'ndv-reviews/show_verified_badge', ! empty( $review['verified'] ), $review ) ) :
			?>
				<span class="ndvr-verified-badge"><?php esc_html_e( 'Verified buyer', 'ndv-reviews' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="ndvr-review-meta">
			<?php
			/**
			 * Filter: show/hide the overall star rating in the card header.
			 *
			 * @param bool                $show   Default true.
			 * @param array<string,mixed> $review Review view-model.
			 */
			if ( apply_filters( 'ndv-reviews/show_overall_stars', true, $review ) ) :
				echo Html::stars( $review['overall'] ? $review['overall'] : $review['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			endif;

			/**
			 * Filter: show/hide the review date.
			 *
			 * @param bool                $show   Default true.
			 * @param array<string,mixed> $review Review view-model.
			 */
			if ( apply_filters( 'ndv-reviews/show_review_date', true, $review ) ) :
			?>
				<time class="ndvr-review-date" datetime="<?php echo esc_attr( $review['date'] ); ?>">
					<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $review['date'] ) ) ); ?>
				</time>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! empty( $review['title'] ) ) : ?>
		<h4 class="ndvr-review-title"><?php echo esc_html( $review['title'] ); ?></h4>
	<?php endif; ?>

	<div class="ndvr-review-body"><?php echo wp_kses_post( wpautop( $review['content'] ) ); ?></div>

	<?php
	/**
	 * Filter: show/hide the criteria pill list (Quality / Value / Service etc.).
	 *
	 * @param bool                $show   Default: true when criteria data exists.
	 * @param array<string,mixed> $review Review view-model.
	 */
	if ( apply_filters( 'ndv-reviews/show_criteria', ! empty( $review['criteria'] ), $review ) ) :
	?>
		<ul class="ndvr-review-criteria">
			<?php foreach ( $review['criteria'] as $ndvr_c ) : ?>
				<li class="ndvr-review-criterion">
					<span class="ndvr-criterion-name"><?php echo esc_html( $ndvr_c['name'] ); ?></span>
					<?php echo Html::stars( $ndvr_c['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $review['media'] ) ) : ?>
		<div class="ndvr-review-media">
			<?php foreach ( $review['media'] as $ndvr_m ) : ?>
				<a class="ndvr-review-photo" href="<?php echo esc_url( $ndvr_m['url'] ); ?>" target="_blank" rel="noopener">
					<img src="<?php echo esc_url( $ndvr_m['thumb'] ); ?>" alt="<?php esc_attr_e( 'Customer photo', 'ndv-reviews' ); ?>" loading="lazy" />
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<div class="ndvr-review-foot">
		<?php
		/**
		 * Filter: show/hide the "Recommends / Does not recommend" badge.
		 *
		 * @param bool                $show   Default: true when recommend value is 'yes' or 'no'.
		 * @param array<string,mixed> $review Review view-model.
		 */
		$_ndvr_has_recommend = in_array( $review['recommend'] ?? '', array( 'yes', 'no' ), true );
		if ( apply_filters( 'ndv-reviews/show_recommend', $_ndvr_has_recommend, $review ) ) :
			if ( 'yes' === $review['recommend'] ) :
		?>
				<span class="ndvr-recommend ndvr-recommend-yes"><?php esc_html_e( 'Recommends this product', 'ndv-reviews' ); ?></span>
			<?php elseif ( 'no' === $review['recommend'] ) : ?>
				<span class="ndvr-recommend ndvr-recommend-no"><?php esc_html_e( 'Does not recommend', 'ndv-reviews' ); ?></span>
		<?php
			endif;
		endif;

		/**
		 * Filter: show/hide the "Helpful" vote button.
		 *
		 * @param bool                $show   Default true.
		 * @param array<string,mixed> $review Review view-model.
		 */
		if ( apply_filters( 'ndv-reviews/show_helpful_button', true, $review ) ) :
		?>
			<button type="button" class="ndvr-helpful" data-comment-id="<?php echo esc_attr( $review['id'] ); ?>" data-nonce="<?php echo esc_attr( $vote_nonce ); ?>">
				<?php esc_html_e( 'Helpful', 'ndv-reviews' ); ?>
				<span class="ndvr-helpful-count">(<?php echo esc_html( number_format_i18n( $review['helpful_up'] ) ); ?>)</span>
			</button>
		<?php endif; ?>
	</div>

	<?php
	/**
	 * Fires after a review item's content (Pro renders video, admin reply, etc.).
	 *
	 * @param array<string,mixed> $review Review view-model.
	 */
	do_action( 'ndv-reviews/review_item_after', $review );
	?>
</li>
