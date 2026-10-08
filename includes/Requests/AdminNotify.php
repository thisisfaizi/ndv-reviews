<?php
/**
 * Email the store admin when a new review arrives.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews are inserted with wp_insert_comment(), which skips WordPress's own
 * "please moderate" email, so without this nobody hears about a new review.
 * Setting `admin_notify`: off | all | pending (default: pending).
 */
class AdminNotify implements Registerable {

	/**
	 * Review sources that never notify: bulk imports and reviews an admin
	 * added by hand (Pro's manual reviews use "admin").
	 */
	const SILENT_SOURCES = array( 'import', 'admin' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Late priority: Pro listeners (auto-approve follow-ups, AI at 30) run first,
		// so the approval state read below is final.
		add_action( 'ndv-reviews/review_created', array( $this, 'notify' ), 40, 1 );
	}

	/**
	 * Send the notification for a new review, if the setting asks for it.
	 *
	 * @param int $comment_id Review comment id.
	 * @return void
	 */
	public function notify( $comment_id ) {
		$mode = (string) $this->settings->get( 'admin_notify', 'pending' );
		if ( 'off' === $mode ) {
			return;
		}

		$comment = get_comment( (int) $comment_id );
		if ( ! $comment ) {
			return;
		}

		$source = (string) get_comment_meta( $comment->comment_ID, '_ndvr_source', true );
		if ( in_array( $source, self::SILENT_SOURCES, true ) ) {
			return;
		}

		$status = (string) $comment->comment_approved;
		if ( 'spam' === $status || 'trash' === $status ) {
			return;
		}
		$pending = '1' !== $status;
		if ( 'pending' === $mode && ! $pending ) {
			return;
		}

		$to = sanitize_email( (string) $this->settings->get( 'admin_notify_email', '' ) );
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return;
		}

		$product = get_the_title( $comment->comment_post_ID );
		$rating  = (float) get_comment_meta( $comment->comment_ID, '_ndvr_overall_rating', true );
		$store   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = $pending
			/* translators: 1: store name, 2: product name. */
			? sprintf( __( '[%1$s] New review awaiting approval: %2$s', 'ndv-reviews' ), $store, $product )
			/* translators: 1: store name, 2: product name. */
			: sprintf( __( '[%1$s] New review: %2$s', 'ndv-reviews' ), $store, $product );

		wp_mail( $to, wp_specialchars_decode( $subject, ENT_QUOTES ), $this->body( $comment, $product, $rating, $pending ), array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Email body.
	 *
	 * @param \WP_Comment $comment Review.
	 * @param string      $product Product name.
	 * @param float       $rating  Overall rating (0 when unknown).
	 * @param bool        $pending Whether the review awaits approval.
	 * @return string
	 */
	private function body( $comment, $product, $rating, $pending ) {
		$id        = (int) $comment->comment_ID;
		$moderate  = add_query_arg(
			array(
				'page'   => 'ndv-reviews-moderation',
				'status' => 'moderated',
			),
			admin_url( 'admin.php' )
		);
		$edit      = add_query_arg(
			array(
				'page'        => 'ndv-reviews-moderation',
				'ndvr_action' => 'edit',
				'review'      => $id,
			),
			admin_url( 'admin.php' )
		);
		$title     = (string) get_comment_meta( $id, '_ndvr_title', true );
		$excerpt   = wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 60 );
		$full      = (int) round( $rating );
		$stars     = $rating > 0 ? str_repeat( '★', $full ) . str_repeat( '☆', max( 0, 5 - $full ) ) : '';
		$verified  = (bool) get_comment_meta( $id, '_ndvr_verified', true );
		$button    = 'display:inline-block;padding:10px 18px;border-radius:6px;text-decoration:none;font-size:14px;';

		ob_start();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head><meta charset="UTF-8" /><meta name="viewport" content="width=device-width, initial-scale=1" /></head>
<body style="margin:0;padding:24px 0;background:#f6f6f6;font-family:Arial,Helvetica,sans-serif;color:#333;">
	<table role="presentation" width="600" cellpadding="0" cellspacing="0" align="center" style="max-width:600px;width:100%;background:#fff;border-radius:10px;">
		<tr><td style="padding:24px 28px;font-size:14px;line-height:1.6;">
			<p style="margin:0 0 4px;color:#777;font-size:12px;"><?php echo esc_html( $pending ? __( 'Awaiting your approval', 'ndv-reviews' ) : __( 'Published', 'ndv-reviews' ) ); ?></p>
			<h1 style="margin:0 0 12px;font-size:18px;color:#111;"><?php echo esc_html( $product ); ?></h1>
			<?php if ( $stars ) : ?>
				<p style="margin:0 0 8px;font-size:20px;color:#f5a623;letter-spacing:2px;" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: rating. */ __( '%s out of 5 stars', 'ndv-reviews' ), number_format_i18n( $rating, 1 ) ) ); ?>"><?php echo esc_html( $stars ); ?> <span style="font-size:13px;color:#555;letter-spacing:0;"><?php echo esc_html( number_format_i18n( $rating, 1 ) ); ?>/5</span></p>
			<?php endif; ?>
			<p style="margin:0 0 12px;color:#555;">
				<?php
				/* translators: %s: reviewer name. */
				echo esc_html( sprintf( __( 'By %s', 'ndv-reviews' ), $comment->comment_author ) );
				if ( $verified ) {
					echo ' · ' . esc_html__( 'Verified buyer', 'ndv-reviews' );
				}
				?>
			</p>
			<?php if ( '' !== $title ) : ?>
				<p style="margin:0 0 6px;font-weight:bold;color:#111;"><?php echo esc_html( $title ); ?></p>
			<?php endif; ?>
			<p style="margin:0 0 20px;"><?php echo esc_html( $excerpt ); ?></p>
			<p style="margin:0;">
				<?php if ( $pending ) : ?>
					<a href="<?php echo esc_url( $moderate ); ?>" style="<?php echo esc_attr( $button ); ?>background:#111;color:#fff;"><?php esc_html_e( 'Review pending reviews', 'ndv-reviews' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( $edit ); ?>" style="<?php echo esc_attr( $button ); ?>background:#eee;color:#111;"><?php esc_html_e( 'Open this review', 'ndv-reviews' ); ?></a>
			</p>
		</td></tr>
		<tr><td style="padding:14px 28px;border-top:1px solid #eee;font-size:11px;color:#999;">
			<?php esc_html_e( 'You can change or turn off these emails under NDV Reviews > Review Reminders.', 'ndv-reviews' ); ?>
		</td></tr>
	</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
