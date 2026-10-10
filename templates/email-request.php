<?php
/**
 * Review-request email.
 *
 * Override: copy to yourtheme/ndv-reviews/email-request.php
 *
 * @var \WC_Order $order         Order (unsaved sample order in previews without orders).
 * @var int[]     $products      Reviewable product ids.
 * @var string    $review_link   Tokenized review URL.
 * @var string    $unsub_link    Unsubscribe URL.
 * @var string    $intro_html    The merchant's reminder text (HTML), or '' for the built-in copy.
 * @var string    $store_name    Store name.
 * @var string    $logo_url      WooCommerce email header image URL, or ''.
 * @var string    $accent        Button background color (#hex).
 * @var string    $accent_text   Button text color with readable contrast.
 * @var string    $store_address Store address on one line, or ''.
 * @var string    $context       order|list (a recipient from an uploaded list has no order).
 * @var bool      $is_followup   Whether this is the follow-up reminder (RR-06). A follow-up always
 *                               passes a non-empty $intro_html, so overrides that don't read this still work.
 *
 * @package NdvReviews
 */

defined( 'ABSPATH' ) || exit;

$ndvr_name    = $order->get_billing_first_name();
$ndvr_store   = isset( $store_name ) ? $store_name : get_bloginfo( 'name' );
$ndvr_accent  = ! empty( $accent ) ? $accent : '#181a1f';
$ndvr_btn_txt = ! empty( $accent_text ) ? $accent_text : '#ffffff';
$ndvr_intro   = isset( $intro_html ) ? $intro_html : '';
/* translators: %s: store name. */
$ndvr_preheader = sprintf( __( 'Review the items from your recent %s order.', 'rosette-reviews' ), $ndvr_store );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="x-apple-disable-message-reformatting" />
	<title><?php echo esc_html( $ndvr_store ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f6f6f6;font-family:Arial,Helvetica,sans-serif;color:#333;">
	<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;"><?php echo esc_html( $ndvr_preheader ); ?></div>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6;padding:24px 0;">
		<tr>
			<td align="center">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;overflow:hidden;max-width:600px;width:100%;">
					<tr>
						<td align="center" style="padding:24px 32px 4px;">
							<?php if ( ! empty( $logo_url ) ) : ?>
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $ndvr_store ); ?>" style="max-width:200px;max-height:80px;height:auto;display:block;border:0;" />
							<?php else : ?>
								<p style="margin:0;font-size:16px;font-weight:bold;color:#111;"><?php echo esc_html( $ndvr_store ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td style="padding:20px 32px 8px;font-size:15px;line-height:1.6;color:#333;">
							<?php if ( '' !== $ndvr_intro ) : ?>
								<?php echo wp_kses_post( $ndvr_intro ); ?>
							<?php else : ?>
								<h1 style="margin:0 0 8px;font-size:22px;color:#111;">
									<?php
									/* translators: %s: customer first name. */
									echo esc_html( '' !== $ndvr_name ? sprintf( __( 'Hi %s,', 'rosette-reviews' ), $ndvr_name ) : __( 'Hello,', 'rosette-reviews' ) );
									?>
								</h1>
								<p style="margin:0 0 16px;">
									<?php
									if ( isset( $context ) && 'list' === $context ) {
										/* translators: %s: store name. */
										echo esc_html( sprintf( __( '%s would like to hear what you think of the products below. Your review helps other shoppers choose.', 'rosette-reviews' ), $ndvr_store ) );
									} else {
										/* translators: %s: store name. */
										echo esc_html( sprintf( __( 'Thank you for your order from %s. Could you tell other shoppers what you think of it? Your review helps them choose.', 'rosette-reviews' ), $ndvr_store ) );
									}
									?>
								</p>
							<?php endif; ?>
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
							<a href="<?php echo esc_url( $review_link ); ?>" style="background:<?php echo esc_attr( $ndvr_accent ); ?>;color:<?php echo esc_attr( $ndvr_btn_txt ); ?>;text-decoration:none;padding:13px 28px;border-radius:8px;font-size:15px;font-weight:bold;display:inline-block;">
								<?php esc_html_e( 'Write your review', 'rosette-reviews' ); ?>
							</a>
						</td>
					</tr>

					<tr>
						<td style="padding:0 32px 26px;border-top:1px solid #eee;">
							<?php if ( ! empty( $store_address ) ) : ?>
								<p style="margin:14px 0 0;font-size:11px;line-height:1.5;color:#999;"><?php echo esc_html( $ndvr_store . ' · ' . $store_address ); ?></p>
							<?php endif; ?>
							<p style="margin:14px 0 0;font-size:11px;color:#999;">
								<?php
								if ( isset( $context ) && 'list' === $context ) {
									/* translators: %s: store name. */
									echo esc_html( sprintf( __( 'You\'re receiving this because %s asked for your review.', 'rosette-reviews' ), $ndvr_store ) );
								} else {
									esc_html_e( 'You received this email because you placed an order with us.', 'rosette-reviews' );
								}
								?>
								<a href="<?php echo esc_url( $unsub_link ); ?>" style="color:#999;"><?php esc_html_e( 'Unsubscribe from review requests', 'rosette-reviews' ); ?></a>
							</p>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
