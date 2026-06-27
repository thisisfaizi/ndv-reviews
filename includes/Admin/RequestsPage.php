<?php
/**
 * Admin screen: review-reminder settings, test send, and the request log.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Requests\RequestRepository;
use NdvReviews\Requests\Mailer;
use NdvReviews\Requests\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * The Review Reminders screen: configure the email, send a test, and inspect
 * the delivery log with per-row retry.
 */
class RequestsPage implements Registerable {

	const CAPABILITY  = 'manage_woocommerce';
	const PARENT_SLUG = 'ndv-reviews';
	const PAGE_SLUG   = 'ndv-reviews-reminders';
	const NONCE       = 'ndvr_requests';

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
	}

	/**
	 * Add the Review Reminders submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Review Reminders', 'ndv-reviews' ),
			__( 'Review Reminders', 'ndv-reviews' ),
			self::CAPABILITY,
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
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Retry (GET).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ndvr_retry'] ) ) {
			check_admin_referer( self::NONCE );
			$this->scheduler->retry( absint( wp_unslash( $_GET['ndvr_retry'] ) ) );
			$this->notices[] = array(
				'type'    => 'success',
				'message' => __( 'Retry attempted. See the updated status below.', 'ndv-reviews' ),
			);
			return;
		}

		if ( ! isset( $_POST['ndvr_requests_do'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		$do = sanitize_key( wp_unslash( $_POST['ndvr_requests_do'] ) );

		if ( 'save' === $do ) {
			$this->settings->update(
				array(
					'reminder_enabled'    => ! empty( $_POST['reminder_enabled'] ),
					'reminder_status'     => isset( $_POST['reminder_status'] ) ? sanitize_key( wp_unslash( $_POST['reminder_status'] ) ) : 'completed',
					'reminder_delay_days' => isset( $_POST['reminder_delay_days'] ) ? absint( $_POST['reminder_delay_days'] ) : 7,
					'reminder_subject'    => isset( $_POST['reminder_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['reminder_subject'] ) ) : '',
					'from_name'           => isset( $_POST['from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['from_name'] ) ) : '',
					'from_email'          => isset( $_POST['from_email'] ) ? sanitize_email( wp_unslash( $_POST['from_email'] ) ) : '',
					'token_expiry_days'   => isset( $_POST['token_expiry_days'] ) ? absint( $_POST['token_expiry_days'] ) : 60,
				)
			);
			$this->notices[] = array(
				'type'    => 'success',
				'message' => __( 'Settings saved.', 'ndv-reviews' ),
			);
		} elseif ( 'test' === $do ) {
			$to     = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
			$result = $this->mailer->send_test( $to );
			$this->notices[] = is_wp_error( $result )
				? array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
				: array(
					'type'    => 'success',
					/* translators: %s: email address. */
					'message' => sprintf( __( 'Test email sent to %s.', 'ndv-reviews' ), $to ),
				);
		}
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$s        = $this->settings;
		$statuses = wc_get_order_statuses();
		$log      = $this->requests->paginate( 1, 30 );
		$admin    = get_option( 'admin_email' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Review Reminders', 'ndv-reviews' ); ?></h1>

			<?php foreach ( $this->notices as $notice ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endforeach; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable reminders', 'ndv-reviews' ); ?></th>
						<td><label><input type="checkbox" name="reminder_enabled" value="1" <?php checked( (bool) $s->get( 'reminder_enabled' ) ); ?> /> <?php esc_html_e( 'Send a review-request email after an order reaches the chosen status.', 'ndv-reviews' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="reminder_status"><?php esc_html_e( 'Trigger status', 'ndv-reviews' ); ?></label></th>
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
						<th scope="row"><label for="reminder_delay_days"><?php esc_html_e( 'Delay (days)', 'ndv-reviews' ); ?></label></th>
						<td><input type="number" min="0" name="reminder_delay_days" id="reminder_delay_days" value="<?php echo esc_attr( $s->get( 'reminder_delay_days' ) ); ?>" class="small-text" /> <?php esc_html_e( 'days after the trigger status.', 'ndv-reviews' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="reminder_subject"><?php esc_html_e( 'Email subject', 'ndv-reviews' ); ?></label></th>
						<td>
							<input type="text" name="reminder_subject" id="reminder_subject" class="regular-text" value="<?php echo esc_attr( $s->get( 'reminder_subject' ) ); ?>" placeholder="<?php esc_attr_e( 'How was your order?', 'ndv-reviews' ); ?>" />
							<p class="description"><?php esc_html_e( 'Placeholders: {customer_name}, {store_name}, {review_link}.', 'ndv-reviews' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'From', 'ndv-reviews' ); ?></th>
						<td>
							<input type="text" name="from_name" value="<?php echo esc_attr( $s->get( 'from_name' ) ); ?>" placeholder="<?php esc_attr_e( 'From name', 'ndv-reviews' ); ?>" />
							<input type="email" name="from_email" value="<?php echo esc_attr( $s->get( 'from_email' ) ); ?>" placeholder="<?php esc_attr_e( 'from@example.com', 'ndv-reviews' ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="token_expiry_days"><?php esc_html_e( 'Link expiry (days)', 'ndv-reviews' ); ?></label></th>
						<td><input type="number" min="0" name="token_expiry_days" id="token_expiry_days" value="<?php echo esc_attr( $s->get( 'token_expiry_days' ) ); ?>" class="small-text" /></td>
					</tr>
				</table>
				<p><button type="submit" name="ndvr_requests_do" value="save" class="button button-primary"><?php esc_html_e( 'Save settings', 'ndv-reviews' ); ?></button></p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Send a test', 'ndv-reviews' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="email" name="test_email" value="<?php echo esc_attr( $admin ); ?>" class="regular-text" />
				<button type="submit" name="ndvr_requests_do" value="test" class="button"><?php esc_html_e( 'Send test email', 'ndv-reviews' ); ?></button>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Request log', 'ndv-reviews' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'ndv-reviews' ); ?></th>
						<th><?php esc_html_e( 'Order', 'ndv-reviews' ); ?></th>
						<th><?php esc_html_e( 'Email', 'ndv-reviews' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ndv-reviews' ); ?></th>
						<th><?php esc_html_e( 'Scheduled', 'ndv-reviews' ); ?></th>
						<th><?php esc_html_e( 'Sent', 'ndv-reviews' ); ?></th>
						<th><?php esc_html_e( 'Error', 'ndv-reviews' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ndv-reviews' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $log['items'] ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No review requests yet.', 'ndv-reviews' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $log['items'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->id ); ?></td>
							<td><a href="<?php echo esc_url( get_edit_post_link( $row->order_id ) ); ?>">#<?php echo esc_html( $row->order_id ); ?></a></td>
							<td><?php echo esc_html( $row->email ); ?></td>
							<td><span class="ndvr-status ndvr-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status ); ?></span></td>
							<td><?php echo esc_html( $row->scheduled_at ); ?></td>
							<td><?php echo esc_html( $row->sent_at ? $row->sent_at : '—' ); ?></td>
							<td><?php echo esc_html( $row->error ? $row->error : '' ); ?></td>
							<td>
								<?php if ( 'failed' === $row->status || 'scheduled' === $row->status ) : ?>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'ndvr_retry' => $row->id ), admin_url( 'admin.php' ) ), self::NONCE ) ); ?>"><?php esc_html_e( 'Retry', 'ndv-reviews' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
