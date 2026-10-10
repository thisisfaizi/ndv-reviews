<?php
/**
 * One follow-up reminder after a free request was sent (RR-06).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Hangs off RR-09's `ndv-reviews/request_sent`: when a free step-1 request
 * (automatic or manual) went out, one step-2 row is queued N days later,
 * through the same pipeline, so the log, cooldown, suppression, consent,
 * exclusions and tracking apply. The step-2 row is checked again at send
 * time (turned off, a site filter, another sender), and the products are
 * the ones still not reviewed.
 *
 * Never touches Pro rows: Pro's steps are `origin=pro` and pass through the
 * send-time listener unchanged.
 */
class Followups implements Registerable {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private $requests;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings  Settings.
	 * @param Scheduler         $scheduler Scheduler.
	 * @param RequestRepository $requests  Request repository.
	 */
	public function __construct( Settings $settings, Scheduler $scheduler, RequestRepository $requests ) {
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
		$this->requests  = $requests;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'ndv-reviews/request_sent', array( $this, 'on_request_sent' ), 10, 2 );
		add_filter( 'ndv-reviews/request_eligible', array( $this, 'at_send' ), 10, 3 );
		add_filter( 'ndv-reviews/settings_fields', array( $this, 'register_fields' ) );
		add_filter( 'ndv-reviews/transparency_facts', array( $this, 'transparency_fact' ) );
	}

	/**
	 * The built-in follow-up copy (before translation). RR-06P reads it as the
	 * default for its later steps (NDVR_API 6).
	 *
	 * @return array{subject:string,body:string}
	 */
	public function default_texts() {
		return array(
			/* translators: %s: store name. */
			'subject' => __( 'A quick reminder: how was your order from %s?', 'rosette-reviews' ),
			/* translators: %s: store name. */
			'body'    => __( 'A few days ago we asked how your order from %s went. If you have a minute, your review helps other shoppers choose.', 'rosette-reviews' ),
		);
	}

	/**
	 * Days between the first email and the follow-up (1–60).
	 *
	 * @return int
	 */
	private function delay_days() {
		return max( 1, min( 60, (int) $this->settings->get( 'followup_delay_days', 7 ) ) );
	}

	/**
	 * After a request was sent: queue the follow-up when every rule allows.
	 *
	 * @param int         $request_id Request id.
	 * @param object|null $row        The sent row.
	 * @return void
	 */
	public function on_request_sent( $request_id, $row ) {
		unset( $request_id );
		if ( ! is_object( $row ) || ! $this->settings->get( 'followup_enabled' ) ) {
			return;
		}
		$meta = RequestRepository::meta( $row );
		if ( 'free' !== ( $row->origin ?? '' ) || 1 !== (int) ( $row->step ?? 0 )
			|| ! in_array( (string) ( $row->source ?? '' ), array( 'auto', 'manual' ), true )
			|| empty( $meta['followup'] ) ) {
			return;
		}
		$order_id = absint( $row->order_id ?? 0 );
		if ( ! $order_id ) {
			return; // List rows never get a free follow-up.
		}

		/** This filter is documented in includes/Requests/Scheduler.php */
		if ( ! apply_filters( 'ndv-reviews/should_send_reminder', true, $order_id ) ) {
			return; // Pro automation or an ESP owns the sequence.
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $this->scheduler->order_already_requested( $order ) ) {
			return;
		}

		// From the time the first email went out (the row's sent_at).
		$sent  = ! empty( $row->sent_at ) ? strtotime( $row->sent_at . ' UTC' ) : time();
		$due   = ( $sent ? $sent : time() ) + $this->delay_days() * DAY_IN_SECONDS;
		$delay = max( 0, $due - time() );

		$this->scheduler->queue_for_order(
			$order_id,
			array(
				'source'   => 'followup',
				'origin'   => 'free',
				'step'     => 2,
				'delay'    => $delay,
				'variant'  => 'followup',
				'followup' => false,
			)
		);
	}

	/**
	 * Send-time rules for free follow-ups only; every other row (Pro steps
	 * included, whatever their source) passes through unchanged.
	 *
	 * @param true|\WP_Error      $eligible Decision so far.
	 * @param \WC_Order|null      $order    Order.
	 * @param array<string,mixed> $context  Context.
	 * @return true|\WP_Error
	 */
	public function at_send( $eligible, $order, $context ) {
		$context = is_array( $context ) ? $context : array();

		// A manual request sent before the order reached the reminder status
		// may already have earned its follow-up: once the customer got a
		// reminder, the later automatic "first" email would be a third email
		// arriving after the reminder. It is skipped.
		if ( 'free' === ( $context['origin'] ?? '' ) && 'auto' === ( $context['source'] ?? '' ) && 1 === (int) ( $context['step'] ?? 1 )
			&& 'send' === ( $context['stage'] ?? '' ) && ! is_wp_error( $eligible )
			&& $order instanceof \WC_Order && $this->requests->has_sent( $order->get_id(), 'followup', 'free' ) ) {
			return new \WP_Error( 'ndvr_already_requested', __( 'The customer already got a review request and a reminder for this order.', 'rosette-reviews' ) );
		}

		if ( 'free' !== ( $context['origin'] ?? '' ) || 'followup' !== ( $context['source'] ?? '' ) ) {
			return $eligible;
		}
		if ( is_wp_error( $eligible ) || 'send' !== ( $context['stage'] ?? '' ) ) {
			return $eligible;
		}

		if ( ! $this->settings->get( 'followup_enabled' ) ) {
			return new \WP_Error( 'ndvr_followup_disabled', __( 'Follow-ups were turned off after this one was scheduled.', 'rosette-reviews' ) );
		}

		$order_id = $order instanceof \WC_Order ? (int) $order->get_id() : 0;
		$request  = ! empty( $context['request_id'] ) ? $this->requests->find( (int) $context['request_id'] ) : null;

		/**
		 * Filter whether a free follow-up reminder is sent.
		 *
		 * @param bool        $send     Default true.
		 * @param int         $order_id Order id.
		 * @param object|null $request  The follow-up row.
		 */
		if ( ! apply_filters( 'ndv-reviews/should_send_followup', true, $order_id, $request ) ) {
			return new \WP_Error( 'ndvr_followup_filtered', __( 'Skipped by a site filter.', 'rosette-reviews' ) );
		}
		if ( $order instanceof \WC_Order && $this->scheduler->order_already_requested( $order ) ) {
			return new \WP_Error( 'ndvr_already_requested', __( 'Another sender already asked this customer.', 'rosette-reviews' ) );
		}

		return $eligible;
	}

	/**
	 * Transparency fact `followup` (RR-03).
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @return array<string,mixed>
	 */
	public function transparency_fact( $facts ) {
		$facts             = is_array( $facts ) ? $facts : array();
		$facts['followup'] = (bool) $this->settings->get( 'followup_enabled' ) && (bool) $this->settings->get( 'reminder_enabled' );

		return $facts;
	}

	/**
	 * The four settings keys (Reminders page, card `followup`).
	 *
	 * @param array<string,array> $fields Fields.
	 * @return array<string,array>
	 */
	public function register_fields( $fields ) {
		$fields = (array) $fields;

		$fields['followup_enabled']    = array(
			'sanitize' => static function ( $raw ) {
				return ! empty( $raw );
			},
			'default'  => false,
			'page'     => 'reminders',
			'card'     => 'followup',
			'render'   => array( $this, 'render_enabled' ),
		);
		$fields['followup_delay_days'] = array(
			'sanitize' => static function ( $raw ) {
				return max( 1, min( 60, absint( is_scalar( $raw ) ? $raw : 0 ) ) );
			},
			'default'  => 7,
			'page'     => 'reminders',
			'card'     => 'followup',
			'render'   => array( $this, 'render_delay' ),
		);
		$fields['followup_subject']    = array(
			'sanitize' => static function ( $raw ) {
				return is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
			},
			'default'  => '',
			'page'     => 'reminders',
			'card'     => 'followup',
			'render'   => array( $this, 'render_subject' ),
		);
		$fields['followup_body']       = array(
			'sanitize' => static function ( $raw ) {
				return is_scalar( $raw ) ? wp_kses_post( (string) $raw ) : '';
			},
			'default'  => '',
			'page'     => 'reminders',
			'card'     => 'followup',
			'render'   => array( $this, 'render_body' ),
		);

		return $fields;
	}

	/**
	 * The checkbox row.
	 *
	 * @param mixed $value Value.
	 * @return void
	 */
	public function render_enabled( $value ) {
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Follow-up', 'rosette-reviews' ); ?></th>
			<td>
				<label><input type="checkbox" name="followup_enabled" value="1" <?php checked( (bool) $value ); ?> /> <?php esc_html_e( 'Send one reminder if they haven\'t reviewed', 'rosette-reviews' ); ?></label>
				<p class="description"><?php esc_html_e( 'Applies to automatic and manual requests sent after you turn this on. Not sent if the customer has reviewed everything, has unsubscribed, or another sender handles review requests for the order.', 'rosette-reviews' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * The delay row.
	 *
	 * @param mixed $value Value.
	 * @return void
	 */
	public function render_delay( $value ) {
		?>
		<tr>
			<th scope="row"><label for="followup_delay_days"><?php esc_html_e( 'Days after the first email', 'rosette-reviews' ); ?></label></th>
			<td><input type="number" min="1" max="60" name="followup_delay_days" id="followup_delay_days" value="<?php echo esc_attr( (string) max( 1, min( 60, (int) $value ) ) ); ?>" class="small-text" /></td>
		</tr>
		<?php
	}

	/**
	 * The subject row.
	 *
	 * @param mixed $value Value.
	 * @return void
	 */
	public function render_subject( $value ) {
		$default = sprintf( $this->default_texts()['subject'], '{store_name}' );
		?>
		<tr>
			<th scope="row"><label for="followup_subject"><?php esc_html_e( 'Follow-up subject', 'rosette-reviews' ); ?></label></th>
			<td><input type="text" name="followup_subject" id="followup_subject" class="large-text" value="<?php echo esc_attr( (string) $value ); ?>" placeholder="<?php echo esc_attr( $default ); ?>" /></td>
		</tr>
		<?php
	}

	/**
	 * The text row.
	 *
	 * @param mixed $value Value.
	 * @return void
	 */
	public function render_body( $value ) {
		/* translators: %s: the default follow-up text. */
		$default = sprintf( __( "Hi {customer_name},\n\n%s", 'rosette-reviews' ), sprintf( $this->default_texts()['body'], '{store_name}' ) );
		?>
		<tr>
			<th scope="row"><label for="followup_body"><?php esc_html_e( 'Follow-up text', 'rosette-reviews' ); ?></label></th>
			<td>
				<textarea name="followup_body" id="followup_body" class="large-text" rows="5" placeholder="<?php echo esc_attr( $default ); ?>"><?php echo esc_textarea( (string) $value ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Leave empty to use the default shown. Same merge tags as the first email.', 'rosette-reviews' ); ?></p>
			</td>
		</tr>
		<?php
	}
}
