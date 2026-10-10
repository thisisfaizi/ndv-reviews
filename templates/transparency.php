<?php
/**
 * "How reviews work" transparency notice (RR-03).
 *
 * Override: copy to yourtheme/ndv-reviews/transparency.php
 *
 * @var string[] $sentences   Whole sentences, plain text (escaped here).
 * @var string   $extra_html  The merchant's extra text, already through wp_kses().
 * @var bool     $collapsible Wrap in <details> (false on a policy page).
 * @var string   $surface     tab|summary|criteria|reviews|widget|page.
 *
 * @package NdvReviews
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $sentences ) ) {
	return;
}

$ndvr_allowed = array(
	'a'      => array( 'href' => array() ),
	'strong' => array(),
	'em'     => array(),
	'br'     => array(),
);

if ( ! empty( $collapsible ) ) :
	?>
	<details class="ndvr-transparency" data-surface="<?php echo esc_attr( $surface ); ?>">
		<summary><?php esc_html_e( 'How reviews work', 'rosette-reviews' ); ?></summary>
		<div class="ndvr-transparency-body">
			<?php foreach ( $sentences as $ndvr_sentence ) : ?>
				<p><?php echo esc_html( $ndvr_sentence ); ?></p>
			<?php endforeach; ?>
			<?php if ( '' !== (string) $extra_html ) : ?>
				<div class="ndvr-transparency-extra"><?php echo wp_kses( $extra_html, $ndvr_allowed ); ?></div>
			<?php endif; ?>
		</div>
	</details>
<?php else : ?>
	<div class="ndvr-transparency-page">
		<?php foreach ( $sentences as $ndvr_sentence ) : ?>
			<p><?php echo esc_html( $ndvr_sentence ); ?></p>
		<?php endforeach; ?>
		<?php if ( '' !== (string) $extra_html ) : ?>
			<div class="ndvr-transparency-extra"><?php echo wp_kses( $extra_html, $ndvr_allowed ); ?></div>
		<?php endif; ?>
	</div>
<?php endif; ?>
