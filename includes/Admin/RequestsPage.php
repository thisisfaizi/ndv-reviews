<?php
/**
 * Admin screen: review-reminder settings, test send, notifications and the request log.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Caps;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Requests\RequestRepository;
use NdvReviews\Requests\Mailer;
use NdvReviews\Requests\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * The Review Reminders screen: configure the email, preview and test it, choose
 * new-review notifications, and inspect the delivery log with retry for
 * failed sends.
 */
class RequestsPage implements Registerable {

	const PARENT_SLUG    = 'ndv-reviews';
	const PAGE_SLUG      = 'ndv-reviews-reminders';
	const NONCE          = 'ndvr_requests';
	const PREVIEW_ACTION = 'ndvr_reminder_preview';
	const PER_PAGE       = 30;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private $requests;

	/**
	 * Mailer.
	 *
	 * @var Mailer
	 */
	private $mailer;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Notices.
	 *
	 * @var array<int,array{type:string,message:string}>
	 */
	private $notices = array();

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings  Settings.
	 * @param RequestRepository $requests  Request repository.
	 * @param Mailer            $mailer    Mailer.
	 * @param Scheduler         $scheduler Scheduler.
	 */
	public function __construct( Settings $settings, RequestRepository $requests, Mailer $mailer, Scheduler $scheduler ) {
		$this->settings  = $settings;
		$this->requests  = $requests;
		$this->mailer    = $mailer;
		$this->scheduler = $scheduler;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 12 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_post_' . self::PREVIEW_ACTION, array( $this, 'render_preview' ) );
	}

	/**
	 * Add the Review Reminders submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Review Reminders', 'rosette-reviews' ),
			__( 'Review Reminders', 'rosette-reviews' ),
			Caps::manage( 'reminders' ),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle save / test-send / retry.
	 *
	 * @return void
	 */
	public function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		// Retry (GET).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ndvr_retry'] ) ) {
			check_admin_referer( self::NONCE );
			if ( ! current_user_can( Caps::manage( 'reminders' ) ) ) {
				return;
			}
			$retried         = $this->scheduler->retry( absint( wp_unslash( $_GET['ndvr_retry'] ) ) );
			$this->notices[] = $retried
				? array(
					'type'    => 'success',
					'message' => __( 'Retry attempted. See the updated status below.', 'rosette-reviews' ),
				)
				: array(
					'type'    => 'error',
					'message' => __( 'Only failed requests can be retried.', 'rosette-reviews' ),
				);
			return;
		}

		if ( ! isset( $_POST['ndvr_requests_do'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Caps::manage( 'reminders' ) ) ) {
			return;
		}
		$do = sanitize_key( wp_unslash( $_POST['ndvr_requests_do'] ) );

		if ( 'save' === $do ) {
			// Keys features registered for the Reminders page (RR-00 F6) are
			// saved with this form; other pages' keys are left as stored.
			$registered = SettingsFields::sanitize_page( 'reminders', $_POST );
			$this->settings->update(
				array(
					'reminder_enabled'    => ! empty( $_POST['reminder_enabled'] ),
					'reminder_status'     => isset( $_POST['reminder_status'] ) ? sanitize_key( wp_unslash( $_POST['reminder_status'] ) ) : 'completed',
					'reminder_delay_days' => isset( $_POST['reminder_delay_days'] ) ? absint( $_POST['reminder_delay_days'] ) : 7,
					'reminder_subject'    => isset( $_POST['reminder_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['reminder_subject'] ) ) : '',
					'reminder_body'       => isset( $_POST['reminder_body'] ) ? wp_kses_post( wp_unslash( $_POST['reminder_body'] ) ) : '',
					'from_name'           => isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '',
					'from_email'          => isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '',
					'token_expiry_days'   => isset( $_POST['token_expiry_days'] ) ? absint( $_POST['token_expiry_days'] ) : 60,
				) + $registered
			);
			$this->notices[] = array(
				'type'    => 'success',
				'message' => __( 'Settings saved.', 'rosette-reviews' ),
			);
		} elseif ( 'save_notify' === $do ) {
			$mode = isset( $_POST['admin_notify'] ) ? sanitize_key( wp_unslash( $_POST['admin_notify'] ) ) : 'pending';
			$this->settings->update(
				array(
					'admin_notify'       => in_array( $mode, array( 'off', 'all', 'pending' ), true ) ? $mode : 'pending',
					'admin_notify_email' => isset( $_POST['admin_notify_email'] ) ? sanitize_email( wp_unslash( $_POST['admin_notify_email'] ) ) : '',
				)
			);
			$this->notices[] = array(
				'type'    => 'success',
				'message' => __( 'Notification settings saved.', 'rosette-reviews' ),
			);
		} elseif ( 'test' === $do ) {
			$to              = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
			$result          = $this->mailer->send_test( $to );
			$this->notices[] = is_wp_error( $result )
				? array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
				: array(
					'type'    => 'success',
					/* translators: %s: email address. */
					'message' => sprintf( __( 'Test email sent to %s.', 'rosette-reviews' ), $to ),
				);
		}
	}

	/**
	 * Preview the reminder email (with the subject/body as currently typed,
	 * saved or not) in a new tab. The email HTML is shown in a sandboxed
	 * iframe so nothing in it runs with wp-admin privileges.
	 *
	 * @return void
	 */
	public function render_preview() {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Caps::manage( 'reminders' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to preview this email.', 'rosette-reviews' ), 403 );
		}

		$overrides = array();
		if ( isset( $_POST['reminder_subject'] ) ) {
			$overrides['subject'] = sanitize_text_field( wp_unslash( $_POST['reminder_subject'] ) );
		}
		if ( isset( $_POST['reminder_body'] ) ) {
			$overrides['body'] = wp_kses_post( wp_unslash( $_POST['reminder_body'] ) );
		}

		$preview = $this->mailer->preview( $overrides );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex,nofollow" />
	<title><?php esc_html_e( 'Reminder email preview', 'rosette-reviews' ); ?></title>
	<style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;color:#1d2327;}header{padding:14px 20px;background:#fff;border-bottom:1px solid #dcdcde;}header p{margin:4px 0;font-size:13px;}iframe{display:block;width:100%;height:calc(100vh - 110px);border:0;background:#fff;}</style>
</head>
<body>
	<header>
		<p><strong><?php esc_html_e( 'Subject:', 'rosette-reviews' ); ?></strong> <?php echo esc_html( $preview['subject'] ); ?></p>
		<p>
			<?php
			echo esc_html(
				$preview['sample']
					? __( 'Built from sample data because there is no completed order yet. The button links to your shop.', 'rosette-reviews' )
					: __( 'Built from your most recent completed order. In the preview the button links to your shop; real emails link to the customer\'s review page.', 'rosette-reviews' )
			);
			?>
		</p>
	</header>
	<iframe sandbox="" title="<?php esc_attr_e( 'Email preview', 'rosette-reviews' ); ?>" srcdoc="<?php echo htmlspecialchars( $preview['html'], ENT_QUOTES, 'UTF-8', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- double-encode so entities already escaped in the email stay text inside srcdoc (esc_attr() would not re-encode them). ?>"></iframe>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Translated label for a request status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			'scheduled' => __( 'Scheduled', 'rosette-reviews' ),
			'sending'   => __( 'Sending', 'rosette-reviews' ),
			'sent'      => __( 'Sent', 'rosette-reviews' ),
			'failed'    => __( 'Failed', 'rosette-reviews' ),
			'cancelled' => __( 'Not sent', 'rosette-reviews' ),
			'converted' => __( 'Reviewed', 'rosette-reviews' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Translated label for where a request came from.
	 *
	 * @param object $row Request row.
	 * @return string
	 */
	private function source_label( $row ) {
		$source = isset( $row->source ) ? (string) $row->source : 'legacy';
		if ( isset( $row->origin ) && 'pro' === $row->origin && 'campaign' !== $source ) {
			return __( 'Pro automation', 'rosette-reviews' );
		}

		$labels = array(
			'auto'     => __( 'Automatic', 'rosette-reviews' ),
			'manual'   => __( 'Manual', 'rosette-reviews' ),
			'followup' => __( 'Follow-up', 'rosette-reviews' ),
			'campaign' => __( 'Campaign', 'rosette-reviews' ),
			'legacy'   => __( 'Earlier version', 'rosette-reviews' ),
		);

		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Caps::manage( 'reminders' ) ) ) {
			return;
		}

		$s        = $this->settings;
		$statuses = wc_get_order_statuses();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$log      = $this->requests->paginate( $paged, self::PER_PAGE );
		$pages    = (int) ceil( $log['total'] / self::PER_PAGE );
		$admin    = get_option( 'admin_email' );
		$notify   = (string) $s->get( 'admin_notify', 'pending' );
		/* translators: %s: store name. */
		$default_subject = sprintf( __( 'How was your order from %s?', 'rosette-reviews' ), get_bloginfo( 'name' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Review Reminders', 'rosette-reviews' ); ?></h1>

			<?php foreach ( $this->notices as $notice ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endforeach; ?>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php esc_html_e( 'Reminder settings', 'rosette-reviews' ); ?></h2></div>
				<form method="post">
					<?php wp_nonce_field( self::NONCE ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable reminders', 'rosette-reviews' ); ?></th>
							<td><label><input type="checkbox" name="reminder_enabled" value="1" <?php checked( (bool) $s->get( 'reminder_enabled' ) ); ?> /> <?php esc_html_e( 'Send a review-request email after an order reaches the chosen status.', 'rosette-reviews' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="reminder_status"><?php esc_html_e( 'Trigger status', 'rosette-reviews' ); ?></label></th>
							<td>
								<select name="reminder_status" id="reminder_status">
									<?php foreach ( $statuses as $key => $label ) : ?>
										<?php $slug = str_replace( 'wc-', '', $key ); ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s->get( 'reminder_status' ), $slug ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="reminder_delay_days"><?php esc_html_e( 'Delay (days)', 'rosette-reviews' ); ?></label></th>
							<td><input type="number" min="0" name="reminder_delay_days" id="reminder_delay_days" value="<?php echo esc_attr( $s->get( 'reminder_delay_days' ) ); ?>" class="small-text" /> <?php esc_html_e( 'days after the trigger status.', 'rosette-reviews' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="reminder_subject"><?php esc_html_e( 'Email subject', 'rosette-reviews' ); ?></label></th>
							<td>
								<input type="text" name="reminder_subject" id="reminder_subject" class="large-text" value="<?php echo esc_attr( $s->get( 'reminder_subject' ) ); ?>" placeholder="<?php echo esc_attr( $default_subject ); ?>" />
								<p class="description"><?php esc_html_e( 'Leave empty to use the default shown. Merge tags: {customer_name}, {store_name}, {order_number}, {review_link}.', 'rosette-reviews' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="reminder_body"><?php esc_html_e( 'Email text', 'rosette-reviews' ); ?></label></th>
							<td>
								<textarea name="reminder_body" id="reminder_body" class="large-text" rows="7" placeholder="<?php echo esc_attr( __( "Hi {customer_name},\n\nThank you for your order from {store_name}. Could you tell other shoppers what you think of it? Your review helps them choose.", 'rosette-reviews' ) ); ?>"><?php echo esc_textarea( (string) $s->get( 'reminder_body' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'The text above the product list. Your store logo or name, the ordered products, the "Write your review" button and the unsubscribe link are always added around it. Leave empty to use the default text shown.', 'rosette-reviews' ); ?></p>
								<p class="description"><?php esc_html_e( 'Merge tags: {customer_name} (billing first name), {store_name}, {order_number}, {review_link} (the customer\'s review page URL). Line breaks become paragraphs; basic HTML such as links and bold text is allowed.', 'rosette-reviews' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'From', 'rosette-reviews' ); ?></th>
							<td>
								<input type="text" name="from_name" value="<?php echo esc_attr( $s->get( 'from_name' ) ); ?>" placeholder="<?php esc_attr_e( 'From name', 'rosette-reviews' ); ?>" />
								<input type="email" name="from_email" value="<?php echo esc_attr( $s->get( 'from_email' ) ); ?>" placeholder="<?php esc_attr_e( 'from@example.com', 'rosette-reviews' ); ?>" />
								<p class="description"><?php esc_html_e( 'Leave both empty to use the WordPress default sender.', 'rosette-reviews' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="token_expiry_days"><?php esc_html_e( 'Link expiry (days)', 'rosette-reviews' ); ?></label></th>
							<td>
								<input type="number" min="0" name="token_expiry_days" id="token_expiry_days" value="<?php echo esc_attr( $s->get( 'token_expiry_days' ) ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'How long the review link in each email keeps working. 0 = never expires. Applies to emails sent from now on.', 'rosette-reviews' ); ?></p>
							</td>
						</tr>
					</table>
					<?php \NdvReviews\Requests\Tracking::render_section( $s->all() ); ?>
					<p>
						<button type="submit" name="ndvr_requests_do" value="save" class="button button-primary"><?php esc_html_e( 'Save settings', 'rosette-reviews' ); ?></button>
						<button type="submit" class="button" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=' . self::PREVIEW_ACTION ) ); ?>" formtarget="_blank"><?php esc_html_e( 'Preview email', 'rosette-reviews' ); ?></button>
					</p>
				</form>
			</div>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php esc_html_e( 'Send a test', 'rosette-reviews' ); ?></h2></div>
				<p class="description"><?php esc_html_e( 'Sends the saved email, built from your most recent completed order (or sample data if there is none). Its review link works for 24 hours and shows the real review page, but reviews cannot be submitted from it.', 'rosette-reviews' ); ?></p>
				<form method="post">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="email" name="test_email" value="<?php echo esc_attr( $admin ); ?>" class="regular-text" />
					<button type="submit" name="ndvr_requests_do" value="test" class="button"><?php esc_html_e( 'Send test email', 'rosette-reviews' ); ?></button>
				</form>
			</div>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php esc_html_e( 'New review notifications', 'rosette-reviews' ); ?></h2></div>
				<form method="post">
					<?php wp_nonce_field( self::NONCE ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Email me when a review is posted', 'rosette-reviews' ); ?></th>
							<td>
								<fieldset>
									<label><input type="radio" name="admin_notify" value="pending" <?php checked( 'pending', $notify ); ?> /> <?php esc_html_e( 'Only reviews awaiting approval', 'rosette-reviews' ); ?></label><br />
									<label><input type="radio" name="admin_notify" value="all" <?php checked( 'all', $notify ); ?> /> <?php esc_html_e( 'Every new review', 'rosette-reviews' ); ?></label><br />
									<label><input type="radio" name="admin_notify" value="off" <?php checked( 'off', $notify ); ?> /> <?php esc_html_e( 'Off', 'rosette-reviews' ); ?></label>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Imported reviews and reviews added by an admin do not send an email.', 'rosette-reviews' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="admin_notify_email"><?php esc_html_e( 'Send to', 'rosette-reviews' ); ?></label></th>
							<td><input type="email" name="admin_notify_email" id="admin_notify_email" class="regular-text" value="<?php echo esc_attr( (string) $s->get( 'admin_notify_email', '' ) ); ?>" placeholder="<?php echo esc_attr( $admin ); ?>" />
								<p class="description"><?php esc_html_e( 'Leave empty to use the site admin email shown.', 'rosette-reviews' ); ?></p>
							</td>
						</tr>
					</table>
					<p><button type="submit" name="ndvr_requests_do" value="save_notify" class="button button-primary"><?php esc_html_e( 'Save notification settings', 'rosette-reviews' ); ?></button></p>
				</form>
			</div>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php esc_html_e( 'Request log', 'rosette-reviews' ); ?></h2></div>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Order', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Email', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Source', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Status', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Scheduled', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Sent', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Link opened', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Reviewed', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Note', 'rosette-reviews' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'rosette-reviews' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $log['items'] ) ) : ?>
							<tr><td colspan="11"><?php esc_html_e( 'No review requests yet.', 'rosette-reviews' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $log['items'] as $row ) : ?>
							<?php
							$order_obj  = $row->order_id ? wc_get_order( (int) $row->order_id ) : null;
							$order_link = $order_obj ? $order_obj->get_edit_order_url() : '';
							?>
							<tr>
								<td><?php echo esc_html( $row->id ); ?></td>
								<td>
									<?php if ( $order_link ) : ?>
										<a href="<?php echo esc_url( $order_link ); ?>">#<?php echo esc_html( $order_obj->get_order_number() ); ?></a>
									<?php elseif ( ! $row->order_id ) : ?>
										<?php esc_html_e( 'List', 'rosette-reviews' ); ?>
									<?php else : ?>
										#<?php echo esc_html( $row->order_id ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $row->email ? $row->email : '—' ); ?></td>
								<td><?php echo esc_html( $this->source_label( $row ) ); ?></td>
								<td><span class="ndvr-status ndvr-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $this->status_label( $row->status ) ); ?></span></td>
								<td><?php echo esc_html( $row->scheduled_at ); ?></td>
								<td><?php echo esc_html( $row->sent_at ? $row->sent_at : '—' ); ?></td>
								<td><?php echo esc_html( ! empty( $row->opened_at ) ? $row->opened_at : '—' ); ?></td>
								<td><?php echo esc_html( ! empty( $row->reviewed_at ) ? $row->reviewed_at : '—' ); ?></td>
								<td><?php echo esc_html( $row->error ? $row->error : '' ); ?></td>
								<td>
									<?php if ( 'failed' === $row->status ) : ?>
										<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'ndvr_retry' => $row->id, 'paged' => $paged ), admin_url( 'admin.php' ) ), self::NONCE ) ); ?>"><?php esc_html_e( 'Retry', 'rosette-reviews' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num">
								<?php
								/* translators: %s: number of requests. */
								echo esc_html( sprintf( _n( '%s request', '%s requests', $log['total'], 'rosette-reviews' ), number_format_i18n( $log['total'] ) ) );
								?>
							</span>
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
										'format'    => '',
										'current'   => $paged,
										'total'     => $pages,
										'prev_text' => '&lsaquo;',
										'next_text' => '&rsaquo;',
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
