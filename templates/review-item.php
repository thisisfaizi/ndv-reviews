<?php
/**
 * A single review item.
 *
 * Override: copy to yourtheme/ndv-reviews/review-item.php
 *
 * Overrides should keep these hooks so features keep working (RR-00 F5):
 * - action ndv-reviews/review_author_badges (disclosure pills render here)
 * - action ndv-reviews/review_meta_after
 * - filter ndv-reviews/review_title_html
 * - filter ndv-reviews/review_body_html
 * - action ndv-reviews/review_body_after
 * - action ndv-reviews/review_foot_end
 * - action ndv-reviews/review_item_after
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
		<?php echo Html::avatar( $review['author'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in Html::avatar(). ?>
		<div class="ndvr-review-byline">
			<div class="ndvr-review-author">
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
					<span class="ndvr-verified-badge"><?php esc_html_e( 'Verified buyer', 'rosette-reviews' ); ?></span>
				<?php endif; ?>
				<?php
				/**
				 * Fires inside the author line, after the verified badge — for
				 * small inline badges (e.g. Pro's "Top reviewer").
				 *
				 * @param array<string,mixed> $review Review view-model.
				 */
				do_action( 'ndv-reviews/review_author_badges', $review );
				?>
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
					<time class="ndvr-review-date" datetime="<?php echo esc_attr( mysql2date( 'c', $review['date'], false ) ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $review['date'] ) ); ?></time>
				<?php endif; ?>
				<?php
				/**
				 * Fires at the end of the meta line, after the date (for example "Edited").
				 *
				 * @param array<string,mixed> $review Review view-model.
				 */
				do_action( 'ndv-reviews/review_meta_after', $review );
				?>
			</div>
		</div>
	</div>

	<?php
	if ( ! empty( $review['title'] ) ) :
		/**
		 * Filter the escaped review title HTML (for example search highlighting).
		 * The result is passed through wp_kses_post().
		 *
		 * @param string              $html   Escaped title.
		 * @param array<string,mixed> $review Review view-model.
		 */
		$ndvr_title_html = (string) apply_filters( 'ndv-reviews/review_title_html', esc_html( $review['title'] ), $review );
		?>
		<h4 class="ndvr-review-title"><?php echo wp_kses_post( $ndvr_title_html ); ?></h4>
	<?php endif; ?>

	<?php
	/**
	 * Filter the sanitized review body HTML (for example search highlighting).
	 * The result is passed through wp_kses_post().
	 *
	 * @param string              $html   Body HTML.
	 * @param array<string,mixed> $review Review view-model.
	 */
	$ndvr_body_html = (string) apply_filters( 'ndv-reviews/review_body_html', wp_kses_post( wpautop( $review['content'] ) ), $review );
	?>
	<div class="ndvr-review-body"><?php echo wp_kses_post( $ndvr_body_html ); ?></div>

	<?php
	/**
	 * Fires after the review body, before the criteria list.
	 *
	 * @param array<string,mixed> $review Review view-model.
	 */
	do_action( 'ndv-reviews/review_body_after', $review );
	?>

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
				<a class="ndvr-review-photo" href="<?php echo esc_url( $ndvr_m['url'] ); ?>" target="_blank" rel="noopener" data-elementor-open-lightbox="no">
					<img src="<?php echo esc_url( $ndvr_m['thumb'] ); ?>" alt="<?php esc_attr_e( 'Customer photo', 'rosette-reviews' ); ?>" loading="lazy" />
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
		$ndvr_has_recommend = in_array( $review['recommend'] ?? '', array( 'yes', 'no' ), true );
		if ( apply_filters( 'ndv-reviews/show_recommend', $ndvr_has_recommend, $review ) ) :
			if ( 'yes' === $review['recommend'] ) :
		?>
				<span class="ndvr-recommend ndvr-recommend-yes"><?php esc_html_e( 'Recommends this product', 'rosette-reviews' ); ?></span>
			<?php elseif ( 'no' === $review['recommend'] ) : ?>
				<span class="ndvr-recommend ndvr-recommend-no"><?php esc_html_e( 'Does not recommend', 'rosette-reviews' ); ?></span>
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
				<svg class="ndvr-helpful-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 10v11H3V10h4z"/><path d="M7 10l4-8a3 3 0 0 1 3 3v4h5.5a2 2 0 0 1 2 2.3l-1.4 8A2 2 0 0 1 18.1 21H7"/></svg>
				<?php esc_html_e( 'Helpful', 'rosette-reviews' ); ?>
				<span class="ndvr-helpful-count">(<?php echo esc_html( number_format_i18n( $review['helpful_up'] ) ); ?>)</span>
			</button>
		<?php endif; ?>
		<?php
		/**
		 * Fires at the end of the review footer (for example a "Report" link).
		 *
		 * @param array<string,mixed> $review Review view-model.
		 */
		do_action( 'ndv-reviews/review_foot_end', $review );
		?>
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
