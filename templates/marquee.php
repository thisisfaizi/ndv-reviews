<?php
/**
 * Reviews marquee (Magic UI-style infinite scroll), vanilla CSS/JS.
 *
 * Override: copy to yourtheme/ndv-reviews/marquee.php
 *
 * @var array<int,array<string,mixed>> $items  Review view-models.
 * @var array<string,mixed>            $args   Display args.
 * @var int                            $repeat Number of times to repeat the set.
 *
 * @package NdvReviews
 */

use NdvReviews\Display\Html;

defined( 'ABSPATH' ) || exit;

/**
 * A deterministic, pleasant background colour for an initials avatar, derived
 * from the author name (no Gravatar/email needed — avoids the grey mystery
 * person and never makes an external request).
 */
if ( ! function_exists( 'ndvr_marquee_avatar_color' ) ) {
	function ndvr_marquee_avatar_color( $name ) {
		$palette = array( '#0f7d5b', '#2563eb', '#7c3aed', '#db2777', '#ea580c', '#0891b2', '#4f46e5', '#b45309' );
		$idx     = abs( crc32( (string) $name ) ) % count( $palette );
		return $palette[ $idx ];
	}
}

$ndvr_vertical = ( 'vertical' === $args['direction'] );
$ndvr_classes  = array( 'ndvr-marquee' );
$ndvr_classes[] = $ndvr_vertical ? 'ndvr-marquee-vertical' : 'ndvr-marquee-horizontal';
if ( ! empty( $args['reverse'] ) ) {
	$ndvr_classes[] = 'ndvr-marquee-reverse';
}
if ( ! empty( $args['pause'] ) ) {
	$ndvr_classes[] = 'ndvr-marquee-pause';
}

$ndvr_style = sprintf(
	'--ndvr-duration:%ds;--ndvr-gap:%dpx;',
	max( 5, (int) $args['speed'] ),
	max( 0, (int) $args['gap'] )
);
?>
<div class="<?php echo esc_attr( implode( ' ', $ndvr_classes ) ); ?>" style="<?php echo esc_attr( $ndvr_style ); ?>" role="region" aria-label="<?php esc_attr_e( 'Customer reviews', 'ndv-reviews' ); ?>">
	<div class="ndvr-marquee-track">
		<?php for ( $ndvr_r = 0; $ndvr_r < $repeat; $ndvr_r++ ) : ?>
			<div class="ndvr-marquee-group" <?php echo $ndvr_r > 0 ? 'aria-hidden="true"' : ''; ?>>
				<?php foreach ( $items as $ndvr_review ) : ?>
					<figure class="ndvr-marquee-card">
						<figcaption class="ndvr-marquee-head">
							<?php
							$ndvr_author  = (string) $ndvr_review['author'];
							$ndvr_initial = '' !== $ndvr_author ? mb_substr( $ndvr_author, 0, 1 ) : '?';
							?>
							<span class="ndvr-marquee-avatar" aria-hidden="true" style="background:<?php echo esc_attr( ndvr_marquee_avatar_color( $ndvr_author ) ); ?>;"><?php echo esc_html( $ndvr_initial ); ?></span>
							<span class="ndvr-marquee-name">
								<?php echo esc_html( $ndvr_review['author'] ); ?>
								<?php if ( ! empty( $ndvr_review['verified'] ) ) : ?>
									<span class="ndvr-marquee-verified" title="<?php esc_attr_e( 'Verified buyer', 'ndv-reviews' ); ?>">&#10003;</span>
								<?php endif; ?>
							</span>
						</figcaption>
						<div class="ndvr-marquee-stars">
							<?php echo Html::stars( $ndvr_review['overall'] ? $ndvr_review['overall'] : $ndvr_review['rating'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<blockquote class="ndvr-marquee-body"><?php echo esc_html( wp_trim_words( $ndvr_review['content'], 28 ) ); ?></blockquote>
					</figure>
				<?php endforeach; ?>
			</div>
		<?php endfor; ?>
	</div>
</div>
