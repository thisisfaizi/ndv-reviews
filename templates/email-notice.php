<?php
/**
 * Short branded notice email (store reply, Q&A answer, recovery invite, alert).
 *
 * Override: copy to yourtheme/ndv-reviews/email-notice.php
 *
 * @var string $inner_html    Body HTML, already passed through wp_kses_post().
 * @var string $preheader     Inbox preview text, or ''.
 * @var string $button_url    Call-to-action URL, or ''.
 * @var string $button_label  Its label.
 * @var string $footer_note   Optional footer line, or ''.
 * @var string $unsub_link    Unsubscribe URL for marketing notices, or ''.
 * @var string $store_name    Store name.
 * @var string $logo_url      WooCommerce email header image URL, or ''.
 * @var string $accent        Button background color (#hex).
 * @var string $accent_text   Button text color with readable contrast.
 * @var string $store_address Store address on one line, or ''.
 *
 * @package NdvReviews
 */

defined( 'ABSPATH' ) || exit;

$ndvr_store   = ! empty( $store_name ) ? $store_name : get_bloginfo( 'name' );
$ndvr_accent  = ! empty( $accent ) ? $accent : '#181a1f';
$ndvr_btn_txt = ! empty( $accent_text ) ? $accent_text : '#ffffff';
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
	<?php if ( ! empty( $preheader ) ) : ?>
		<div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;"><?php echo esc_html( $preheader ); ?></div>
	<?php endif; ?>
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
							<?php echo wp_kses_post( $inner_html ); ?>
						</td>
					</tr>
					<?php if ( ! empty( $button_url ) && ! empty( $button_label ) ) : ?>
						<tr>
							<td align="center" style="padding:14px 32px 26px;">
								<a href="<?php echo esc_url( $button_url ); ?>" style="background:<?php echo esc_attr( $ndvr_accent ); ?>;color:<?php echo esc_attr( $ndvr_btn_txt ); ?>;text-decoration:none;padding:13px 28px;border-radius:8px;font-size:15px;font-weight:bold;display:inline-block;">
									<?php echo esc_html( $button_label ); ?>
								</a>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $footer_note ) || ! empty( $store_address ) || ! empty( $unsub_link ) ) : ?>
						<tr>
							<td style="padding:0 32px 26px;border-top:1px solid #eee;">
								<?php if ( ! empty( $footer_note ) ) : ?>
									<p style="margin:14px 0 0;font-size:11px;line-height:1.5;color:#999;"><?php echo esc_html( $footer_note ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $store_address ) ) : ?>
									<p style="margin:14px 0 0;font-size:11px;line-height:1.5;color:#999;"><?php echo esc_html( $ndvr_store . ' · ' . $store_address ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $unsub_link ) ) : ?>
									<p style="margin:14px 0 0;font-size:11px;color:#999;">
										<a href="<?php echo esc_url( $unsub_link ); ?>" style="color:#999;"><?php esc_html_e( 'Unsubscribe from these emails', 'rosette-reviews' ); ?></a>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endif; ?>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
