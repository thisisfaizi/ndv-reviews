<?php
/**
 * Review summary box.
 *
 * Override: copy to yourtheme/ndv-reviews/summary.php
 *
 * @var array<string,mixed> $summary Summary data from Display\Summary.
 *
 * @package NdvReviews
 */

use NdvReviews\Display\Html;

defined( 'ABSPATH' ) || exit;

if ( empty( $summary ) || empty( $summary['count'] ) ) {
	return;
}

$ndvr_total = (int) $summary['count'];
?>
<div class="ndvr-summary">
	<div class="ndvr-summary-overall">
		<div class="ndvr-summary-average"><?php echo esc_html( number_format_i18n( $summary['average'], 1 ) ); ?></div>
		<?php
		// Html::stars returns pre-escaped markup.
		echo Html::stars( $summary['average'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="ndvr-summary-count">
			<?php
			/* translators: %s: number of reviews. */
			echo esc_html( sprintf( _n( '%s review', '%s reviews', $ndvr_total, 'ndv-reviews' ), number_format_i18n( $ndvr_total ) ) );
			?>
		</div>
		<?php if ( ! empty( $summary['recommend'] ) ) : ?>
			<div class="ndvr-summary-recommend">
				<?php
				/* translators: %d: percentage of reviewers who recommend. */
				echo esc_html( sprintf( __( '%d%% would recommend', 'ndv-reviews' ), (int) $summary['recommend'] ) );
				?>
			</div>
		<?php endif; ?>
	</div>

	<div class="ndvr-summary-bars">
		<?php for ( $ndvr_star = 5; $ndvr_star >= 1; $ndvr_star-- ) : ?>
			<?php
			$ndvr_n   = isset( $summary['distribution'][ $ndvr_star ] ) ? (int) $summary['distribution'][ $ndvr_star ] : 0;
			$ndvr_pct = $ndvr_total > 0 ? round( ( $ndvr_n / $ndvr_total ) * 100 ) : 0;
			?>
			<div class="ndvr-bar-row">
				<span class="ndvr-bar-label">
					<?php
					/* translators: %d: star count. */
					echo esc_html( sprintf( _n( '%d star', '%d stars', $ndvr_star, 'ndv-reviews' ), $ndvr_star ) );
					?>
				</span>
				<span class="ndvr-bar-track"><span class="ndvr-bar-fill" style="width:<?php echo esc_attr( $ndvr_pct ); ?>%"></span></span>
				<span class="ndvr-bar-count"><?php echo esc_html( number_format_i18n( $ndvr_n ) ); ?></span>
			</div>
		<?php endfor; ?>
	</div>

	<?php if ( ! empty( $summary['criteria'] ) ) : ?>
		<div class="ndvr-summary-criteria">
			<h4><?php esc_html_e( 'Rating breakdown', 'ndv-reviews' ); ?></h4>
			<?php foreach ( $summary['criteria'] as $ndvr_criterion ) : ?>
				<div class="ndvr-criterion-row">
					<span class="ndvr-criterion-name"><?php echo esc_html( $ndvr_criterion['name'] ); ?></span>
					<span class="ndvr-bar-track"><span class="ndvr-bar-fill" style="width:<?php echo esc_attr( ( $ndvr_criterion['average'] / 5 ) * 100 ); ?>%"></span></span>
					<span class="ndvr-criterion-score"><?php echo esc_html( number_format_i18n( $ndvr_criterion['average'], 1 ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
