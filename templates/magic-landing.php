<?php
/**
 * Tokenized multi-product review-collection landing content.
 *
 * Override: copy to yourtheme/ndv-reviews/magic-landing.php
 *
 * @var bool                  $valid       Whether the token resolved.
 * @var string                $token       Raw token (re-submitted with each review).
 * @var int[]                 $products    Pending product ids.
 * @var array                 $criteria    Active criteria objects.
 * @var string                $nonce       AJAX nonce.
 * @var string                $ajax_url    admin-ajax URL.
 * @var string                $ajax_action AJAX action name.
 *
 * @package NdvReviews
 */

use NdvReviews\Forms\AntiSpam;

defined( 'ABSPATH' ) || exit;

if ( empty( $valid ) ) :
	?>
	<div class="ndvr-collect-card ndvr-collect-invalid">
		<h1><?php esc_html_e( 'This link has expired', 'ndv-reviews' ); ?></h1>
		<p><?php esc_html_e( 'Your review link is no longer valid. If you still have items to review, please request a fresh link or contact the store.', 'ndv-reviews' ); ?></p>
		<p><a class="ndvr-collect-home" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Return to the store', 'ndv-reviews' ); ?></a></p>
	</div>
	<?php
	return;
endif;

if ( empty( $products ) ) :
	?>
	<div class="ndvr-collect-card">
		<h1><?php esc_html_e( 'All done — thank you!', 'ndv-reviews' ); ?></h1>
		<p><?php esc_html_e( 'You have already reviewed everything from this order. We appreciate your feedback.', 'ndv-reviews' ); ?></p>
		<p><a class="ndvr-collect-home" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Return to the store', 'ndv-reviews' ); ?></a></p>
	</div>
	<?php
	return;
endif;
?>
<div class="ndvr-collect" data-ajax-url="<?php echo esc_url( $ajax_url ); ?>" data-action="<?php echo esc_attr( $ajax_action ); ?>">
	<header class="ndvr-collect-header">
		<h1><?php esc_html_e( 'Share your feedback', 'ndv-reviews' ); ?></h1>
		<p><?php esc_html_e( 'Tell other shoppers what you think. It only takes a minute per item.', 'ndv-reviews' ); ?></p>
	</header>

	<?php foreach ( $products as $ndvr_pid ) : ?>
		<?php
		$ndvr_product = wc_get_product( $ndvr_pid );
		if ( ! $ndvr_product ) {
			continue;
		}
		$ndvr_img = wp_get_attachment_image_url( $ndvr_product->get_image_id(), 'thumbnail' );
		?>
		<form class="ndvr-collect-card ndvr-collect-form" data-product="<?php echo esc_attr( $ndvr_pid ); ?>">
			<div class="ndvr-collect-product">
				<?php if ( $ndvr_img ) : ?>
					<img src="<?php echo esc_url( $ndvr_img ); ?>" alt="" class="ndvr-collect-thumb" />
				<?php endif; ?>
				<span class="ndvr-collect-name"><?php echo esc_html( $ndvr_product->get_name() ); ?></span>
			</div>

			<div class="ndvr-fields">
				<?php if ( ! empty( $criteria ) ) : ?>
					<div class="ndvr-criteria-group">
						<?php foreach ( $criteria as $ndvr_c ) : ?>
							<fieldset class="ndvr-criterion">
								<legend class="ndvr-criterion-label"><?php echo esc_html( $ndvr_c->name ); ?></legend>
								<div class="ndvr-stars" role="radiogroup" aria-label="<?php echo esc_attr( $ndvr_c->name ); ?>">
									<?php for ( $ndvr_s = 5; $ndvr_s >= 1; $ndvr_s-- ) : ?>
										<?php $ndvr_fid = 'p' . (int) $ndvr_pid . '-c' . (int) $ndvr_c->id . '-s' . $ndvr_s; ?>
										<input class="ndvr-star-input" type="radio" id="<?php echo esc_attr( $ndvr_fid ); ?>" name="ndvr_criteria[<?php echo esc_attr( $ndvr_c->id ); ?>]" value="<?php echo esc_attr( $ndvr_s ); ?>" />
										<label class="ndvr-star-label" for="<?php echo esc_attr( $ndvr_fid ); ?>"><span class="screen-reader-text"><?php echo esc_html( $ndvr_s ); ?></span></label>
									<?php endfor; ?>
								</div>
							</fieldset>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<p class="ndvr-field">
					<label><?php esc_html_e( 'Review title (optional)', 'ndv-reviews' ); ?>
						<input type="text" name="ndvr_title" maxlength="150" />
					</label>
				</p>

				<p class="ndvr-field">
					<label><?php esc_html_e( 'Your review', 'ndv-reviews' ); ?> <span class="required">*</span>
						<textarea name="comment" rows="5" required></textarea>
					</label>
				</p>

				<fieldset class="ndvr-field ndvr-field-recommend">
					<legend><?php esc_html_e( 'Would you recommend this product?', 'ndv-reviews' ); ?></legend>
					<label><input type="radio" name="ndvr_recommend" value="yes" /> <?php esc_html_e( 'Yes', 'ndv-reviews' ); ?></label>
					<label><input type="radio" name="ndvr_recommend" value="neutral" checked="checked" /> <?php esc_html_e( 'Neutral', 'ndv-reviews' ); ?></label>
					<label><input type="radio" name="ndvr_recommend" value="no" /> <?php esc_html_e( 'No', 'ndv-reviews' ); ?></label>
				</fieldset>

				<?php if ( $settings->get( 'photo_uploads' ) ) : ?>
					<p class="ndvr-field">
						<label><?php esc_html_e( 'Add photos (optional)', 'ndv-reviews' ); ?>
							<input type="file" name="ndvr_photos[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple="multiple" />
						</label>
					</p>
				<?php endif; ?>

				<p class="ndvr-field ndvr-field-consent">
					<label><input type="checkbox" name="ndvr_consent" value="1" required /> <?php esc_html_e( 'I consent to my review being stored and published.', 'ndv-reviews' ); ?></label>
				</p>

				<p class="ndvr-hp" aria-hidden="true" style="position:absolute;left:-9999px;">
					<input type="text" name="<?php echo esc_attr( AntiSpam::HONEYPOT ); ?>" tabindex="-1" autocomplete="off" />
				</p>

				<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
				<input type="hidden" name="product_id" value="<?php echo esc_attr( $ndvr_pid ); ?>" />
				<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />

				<div class="ndvr-collect-actions">
					<button type="submit" class="ndvr-collect-submit"><?php esc_html_e( 'Submit review', 'ndv-reviews' ); ?></button>
					<span class="ndvr-form-message" role="status" aria-live="polite"></span>
				</div>
			</div>
		</form>
	<?php endforeach; ?>
</div>
