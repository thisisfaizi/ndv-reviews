<?php
/**
 * Review-request email body.
 *
 * Override: copy to yourtheme/ndv-reviews/email-request.php
 *
 * @var \WC_Order $order       Order.
 * @var int[]     $products    Reviewable product ids.
 * @var string    $review_link Tokenized review URL.
 * @var string    $unsub_link  Unsubscribe URL.
 *
 * @package NdvReviews
 */

defined( 'ABSPATH' ) || exit;

$ndvr_name  = $order->get_billing_first_name();
$ndvr_store = get_bloginfo( 'name' );
?>
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#f6f6f6;font-family:Arial,Helvetica,sans-serif;color:#333;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6;padding:24px 0;">
		<tr>
			<td align="center">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;max-width:600px;width:100%;">
					<tr>
						<td style="padding:28px 32px 8px;">
							<h1 style="margin:0 0 8px;font-size:22px;color:#111;">
								<?php
								/* translators: %s: customer first name. */
								echo esc_html( sprintf( __( 'Hi %s,', 'ndv-reviews' ), $ndvr_name ) );
								?>
							</h1>
							<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
								<?php
								/* translators: %s: store name. */
								echo esc_html( sprintf( __( 'Thanks for shopping with %s! Your feedback helps other shoppers and takes less than a minute.', 'ndv-reviews' ), $ndvr_store ) );
								?>
							</p>
						</td>
					</tr>

					<tr>
						<td style="padding:0 32px;">
							<?php foreach ( $products as $ndvr_pid ) : ?>
								<?php
								$ndvr_product = wc_get_product( $ndvr_pid );
								if ( ! $ndvr_product ) {
									continue;
								}
								$ndvr_img = wp_get_attachment_image_url( $ndvr_product->get_image_id(), 'thumbnail' );
								?>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0;">
									<tr>
										<?php if ( $ndvr_img ) : ?>
											<td width="64" style="padding:6px 12px 6px 0;">
												<img src="<?php echo esc_url( $ndvr_img ); ?>" width="56" height="56" alt="" style="border-radius:6px;display:block;" />
											</td>
										<?php endif; ?>
										<td style="font-size:14px;color:#333;"><?php echo esc_html( $ndvr_product->get_name() ); ?></td>
									</tr>
								</table>
							<?php endforeach; ?>
						</td>
					</tr>

					<tr>
						<td align="center" style="padding:22px 32px 28px;">
							<a href="<?php echo esc_url( $review_link ); ?>" style="background:#111;color:#fff;text-decoration:none;padding:13px 28px;border-radius:8px;font-size:15px;display:inline-block;">
								<?php esc_html_e( 'Write your review', 'ndv-reviews' ); ?>
							</a>
						</td>
					</tr>

					<tr>
						<td style="padding:0 32px 26px;border-top:1px solid #eee;">
							<p style="margin:14px 0 0;font-size:11px;color:#999;">
								<a href="<?php echo esc_url( $unsub_link ); ?>" style="color:#999;"><?php esc_html_e( 'Unsubscribe from review requests', 'ndv-reviews' ); ?></a>
							</p>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
