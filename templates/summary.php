<?php
/**
 * Review summary box.
 *
 * Override: copy to yourtheme/ndv-reviews/summary.php
 *
 * @var array<string,mixed> $summary    Summary data from Display\Summary.
 * @var bool                $filterable Optional. Render the distribution rows as star-filter buttons
 *                                      (only where a filterable list follows, e.g. the reviews tab).
 * @var string              $form_id    Optional. Id of the review form on this page; adds a
 *                                      "Write a review" control pointing at it.
 *
 * @package NdvReviews
 */

use NdvReviews\Display\Html;

defined( 'ABSPATH' ) || exit;

if ( empty( $summary ) ) {
	return;
}

$ndvr_total      = (int) $summary['count'];
$ndvr_filterable = ! empty( $filterable );
$ndvr_form_id    = ! empty( $form_id ) ? (string) $form_id : '';

if ( ! $ndvr_total ) :
	?>
	<div class="ndvr-summary ndvr-summary-empty">
		<p class="ndvr-summary-empty-text"><?php esc_html_e( 'No reviews yet.', 'ndv-reviews' ); ?></p>
		<?php if ( '' !== $ndvr_form_id ) : ?>
			<a class="ndvr-write-review" href="#<?php echo esc_attr( $ndvr_form_id ); ?>" data-ndvr-write-review><?php esc_html_e( 'Write the first review', 'ndv-reviews' ); ?></a>
		<?php endif; ?>
	</div>
	<?php
	return;
endif;
?>
<div class="ndvr-summary">
	<h3 class="ndvr-eyebrow ndvr-summary-eyebrow"><?php esc_html_e( 'Customer reviews', 'ndv-reviews' ); ?></h3>
	<div class="ndvr-summary-overall">
		<div class="ndvr-summary-average"><?php echo esc_html( number_format_i18n( $summary['average'], 1 ) ); ?></div>
		<?php
		// Html::stars returns pre-escaped markup.
		echo Html::stars( $summary['average'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="ndvr-summary-count">
			<?php
			/* translators: %s: number of reviews. */
			echo esc_html( sprintf( _n( 'Based on %s review', 'Based on %s reviews', $ndvr_total, 'ndv-reviews' ), number_format_i18n( $ndvr_total ) ) );
			?>
		</div>
		<?php if ( ! empty( $summary['verified'] ) ) : ?>
			<div class="ndvr-summary-verified">
				<?php
				$ndvr_verified = (int) $summary['verified'];
				/* translators: %s: number of reviews from verified buyers. */
				echo esc_html( sprintf( _n( '%s from a verified buyer', '%s from verified buyers', $ndvr_verified, 'ndv-reviews' ), number_format_i18n( $ndvr_verified ) ) );
				?>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $summary['recommend'] ) ) : ?>
			<div class="ndvr-summary-recommend">
				<?php
				/* translators: %d: percentage of reviewers who recommend. */
				echo esc_html( sprintf( __( '%d%% would recommend', 'ndv-reviews' ), (int) $summary['recommend'] ) );
				?>
			</div>
		<?php endif; ?>
		<?php if ( '' !== $ndvr_form_id ) : ?>
			<a class="ndvr-write-review" href="#<?php echo esc_attr( $ndvr_form_id ); ?>" data-ndvr-write-review><?php esc_html_e( 'Write a review', 'ndv-reviews' ); ?></a>
		<?php endif; ?>
	</div>

	<div class="ndvr-summary-bars"<?php echo $ndvr_filterable ? ' role="group" aria-label="' . esc_attr__( 'Rating distribution. Select a row to show only those reviews.', 'ndv-reviews' ) . '"' : ''; ?>>
		<?php for ( $ndvr_star = 5; $ndvr_star >= 1; $ndvr_star-- ) : ?>
			<?php
			$ndvr_n     = isset( $summary['distribution'][ $ndvr_star ] ) ? (int) $summary['distribution'][ $ndvr_star ] : 0;
			$ndvr_pct   = $ndvr_total > 0 ? (int) round( ( $ndvr_n / $ndvr_total ) * 100 ) : 0;
			/* translators: %d: star count. */
			$ndvr_label = sprintf( _n( '%d star', '%d stars', $ndvr_star, 'ndv-reviews' ), $ndvr_star );
			/* translators: 1: star label e.g. "5 stars", 2: review count, 3: percentage. */
			$ndvr_aria  = sprintf( __( '%1$s: %2$s reviews, %3$s%%', 'ndv-reviews' ), $ndvr_label, number_format_i18n( $ndvr_n ), number_format_i18n( $ndvr_pct ) );
			$ndvr_tag   = ( $ndvr_filterable && $ndvr_n > 0 ) ? 'button' : 'div';
			?>
			<<?php echo esc_html( $ndvr_tag ); ?> class="ndvr-bar-row"<?php echo 'button' === $ndvr_tag ? ' type="button" data-filter="star" data-value="' . esc_attr( $ndvr_star ) . '" aria-pressed="false" aria-label="' . esc_attr( $ndvr_aria ) . '"' : ''; ?> title="<?php echo esc_attr( $ndvr_aria ); ?>">
				<span class="ndvr-bar-label"><?php echo esc_html( $ndvr_label ); ?></span>
				<span class="ndvr-bar-track" aria-hidden="true"><span class="ndvr-bar-fill" style="width:<?php echo esc_attr( $ndvr_pct ); ?>%"></span></span>
				<span class="ndvr-bar-count"><?php echo esc_html( number_format_i18n( $ndvr_pct ) ); ?>%</span>
			</<?php echo esc_html( $ndvr_tag ); ?>>
		<?php endfor; ?>
	</div>

	<?php if ( ! empty( $summary['criteria'] ) ) : ?>
		<div class="ndvr-summary-criteria">
			<h4><?php esc_html_e( 'Rating breakdown', 'ndv-reviews' ); ?></h4>
			<?php foreach ( $summary['criteria'] as $ndvr_criterion ) : ?>
				<div class="ndvr-criterion-row">
					<span class="ndvr-criterion-name"><?php echo esc_html( $ndvr_criterion['name'] ); ?></span>
					<span class="ndvr-bar-track" aria-hidden="true"><span class="ndvr-bar-fill" style="width:<?php echo esc_attr( ( $ndvr_criterion['average'] / 5 ) * 100 ); ?>%"></span></span>
					<?php /* translators: %s: average score out of 5. */ ?>
					<span class="ndvr-criterion-score" aria-label="<?php echo esc_attr( sprintf( __( '%s out of 5', 'ndv-reviews' ), number_format_i18n( $ndvr_criterion['average'], 1 ) ) ); ?>"><?php echo esc_html( number_format_i18n( $ndvr_criterion['average'], 1 ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
