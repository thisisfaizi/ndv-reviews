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
			<?php if ( ! empty( $review['verified'] ) ) : ?>
				<span class="ndvr-verified-badge"><?php esc_html_e( 'Verified buyer', 'ndv-reviews' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="ndvr-review-meta">
			<?php echo Html::stars( $review['overall'] ? $review['overall'] : $review['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<time class="ndvr-review-date" datetime="<?php echo esc_attr( $review['date'] ); ?>">
				<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $review['date'] ) ) ); ?>
			</time>
		</div>
	</div>

	<?php if ( ! empty( $review['title'] ) ) : ?>
		<h4 class="ndvr-review-title"><?php echo esc_html( $review['title'] ); ?></h4>
	<?php endif; ?>

	<div class="ndvr-review-body"><?php echo wp_kses_post( wpautop( $review['content'] ) ); ?></div>

	<?php if ( ! empty( $review['criteria'] ) ) : ?>
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
		<?php if ( 'yes' === $review['recommend'] ) : ?>
			<span class="ndvr-recommend ndvr-recommend-yes"><?php esc_html_e( 'Recommends this product', 'ndv-reviews' ); ?></span>
		<?php elseif ( 'no' === $review['recommend'] ) : ?>
			<span class="ndvr-recommend ndvr-recommend-no"><?php esc_html_e( 'Does not recommend', 'ndv-reviews' ); ?></span>
		<?php endif; ?>

		<button type="button" class="ndvr-helpful" data-comment-id="<?php echo esc_attr( $review['id'] ); ?>" data-nonce="<?php echo esc_attr( $vote_nonce ); ?>">
			<?php esc_html_e( 'Helpful', 'ndv-reviews' ); ?>
			<span class="ndvr-helpful-count">(<?php echo esc_html( number_format_i18n( $review['helpful_up'] ) ); ?>)</span>
		</button>
	</div>
</li>
