<?php
/**
 * RR-06 acceptance harness (PRD .agents/prd/RR-06-follow-up-reminder.md §12).
 *
 *     php boot.php run .agents/qa/rr-06.php 1   (QA site, D:/.devcache/qa-site)
 *
 * Tests call Scheduler::process() on step-2 rows directly (it doesn't wait for
 * scheduled_at). Mail is captured. Settings and fixtures are restored/removed.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification -- QA script, never shipped.

defined( 'ABSPATH' ) || exit;

/**
 * The harness.
 */
final class NDVR_QA_RR06 {

	/**
	 * Counts.
	 *
	 * @var int[]
	 */
	private $count = array(
		'pass' => 0,
		'fail' => 0,
	);

	/**
	 * Fixtures.
	 *
	 * @var array<string,array>
	 */
	private $fx = array(
		'products' => array(),
		'orders'   => array(),
		'comments' => array(),
	);

	/**
	 * Saved state.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * Captured mail.
	 *
	 * @var array<int,array{subject:string,message:string}>
	 */
	private $mail = array();

	/**
	 * Make the next wp_mail() fail.
	 *
	 * @var bool
	 */
	private $fail_next = false;

	/**
	 * Products.
	 *
	 * @var int[]
	 */
	private $p = array();

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( defined( 'NDVR_QA_RR06_PREVIEW' ) ) {
			return $this->preview_child();
		}
		if ( ! defined( 'NDVR_API' ) || (int) NDVR_API < 6 || ! class_exists( '\NdvReviews\Requests\Followups' ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-06 (NDVR_API 6) must be active.' );
			return false;
		}
		$this->setup();
		try {
			$this->ac1_ac2();
			$this->ac3_ac4();
			$this->ac5_to_7();
			$this->ac8();
			$this->ac9();
			$this->ac10();
			$this->ac11();
			$this->ac12_ac13();
			$this->ac14();
			$this->ac15();
			$this->ac16();
			$this->transparency();
			$this->review_fixes();
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
		$this->teardown();
		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->count['fail'] ? 'FAIL' : 'PASS', $this->count['pass'], $this->count['fail'] ) );

		return 0 === $this->count['fail'];
	}

	/**
	 * Container.
	 *
	 * @return object
	 */
	private function c() {
		return \NdvReviews\Plugin::instance()->container();
	}

	/**
	 * Settings and mail.
	 *
	 * @return void
	 */
	private function setup() {
		$this->saved['settings'] = get_option( NDVR_OPTION_SETTINGS, null );
		$this->saved['suppress'] = get_option( \NdvReviews\Requests\Mailer::SUPPRESS_OPTION, null );
		$this->set(
			array(
				'reminder_enabled'    => true,
				'reminder_delay_days' => 0,
				'followup_enabled'    => true,
				'followup_delay_days' => 7,
				'followup_subject'    => 'RR06 follow subject for {store_name}',
				'followup_body'       => 'RR06 follow body text, {customer_name}.',
			)
		);
		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) {
				if ( $this->fail_next ) {
					$this->fail_next = false;
					return false;
				}
				$this->mail[] = array(
					'subject' => (string) $atts['subject'],
					'message' => (string) $atts['message'],
				);
				return true;
			},
			1,
			2
		);
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );
		foreach ( array( 'RR06 Teapot', 'RR06 Saucer' ) as $name ) {
			$prod = new \WC_Product_Simple();
			$prod->set_name( $name );
			$prod->set_status( 'publish' );
			$prod->set_regular_price( '10' );
			$prod->set_reviews_allowed( true );
			$id                     = (int) $prod->save();
			$this->p[]              = $id;
			$this->fx['products'][] = $id;
		}
	}

	/**
	 * Merge settings.
	 *
	 * @param array<string,mixed> $v Values.
	 * @return void
	 */
	private function set( array $v ) {
		$this->c()->get( 'settings' )->update( $v );
	}

	/**
	 * An order with both products.
	 *
	 * @return \WC_Order
	 */
	private function order() {
		$order = wc_create_order();
		foreach ( $this->p as $pid ) {
			$order->add_product( wc_get_product( $pid ), 1 );
		}
		$order->set_billing_first_name( 'Quinn' );
		$order->set_billing_email( 'rr06-' . wp_generate_password( 6, false, false ) . '@example.invalid' );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();

		return $order;
	}

	/**
	 * Request rows of an order.
	 *
	 * @param int $order_id Order.
	 * @return array<int,object>
	 */
	private function rows( $order_id ) {
		global $wpdb;
		$t = \NdvReviews\Support\Db::table( 'requests' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE order_id = %d ORDER BY id ASC", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * One row.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	private function row( $id ) {
		return $this->c()->get( 'request_repository' )->find( (int) $id );
	}

	/**
	 * Step-2 rows of an order.
	 *
	 * @param int $order_id Order.
	 * @return array<int,object>
	 */
	private function step2( $order_id ) {
		return array_values(
			array_filter(
				$this->rows( $order_id ),
				static function ( $r ) {
					return 2 === (int) $r->step && 'followup' === $r->source && 'free' === $r->origin;
				}
			)
		);
	}

	/**
	 * Queue and send a free step-1 request.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $source auto|manual.
	 * @return int Request id.
	 */
	private function send_first( $order, $source = 'auto' ) {
		$id = $this->c()->get( 'scheduler' )->queue_for_order( $order->get_id(), array( 'source' => $source ) );
		if ( is_wp_error( $id ) ) {
			$this->line( 'INFO: queue failed: ' . $id->get_error_code() );
			return 0;
		}
		$this->c()->get( 'scheduler' )->process( (int) $id );

		return (int) $id;
	}

	/**
	 * Move an order's sent step-1 rows 8 days into the past, as a real
	 * follow-up comes days later (the 20 h cooldown is checked at send time).
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function age( $order ) {
		global $wpdb;
		$t = \NdvReviews\Support\Db::table( 'requests' );
		$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET sent_at = %s WHERE order_id = %d AND step = 1 AND sent_at IS NOT NULL", gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ), $order->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Pending send actions of a request.
	 *
	 * @param int $id Request.
	 * @return array<int,\ActionScheduler_Action>
	 */
	private function actions( $id ) {
		return as_get_scheduled_actions(
			array(
				'hook'     => \NdvReviews\Requests\Scheduler::SEND_HOOK,
				'args'     => array( 'request_id' => (int) $id ),
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 10,
			)
		);
	}

	/**
	 * Review a product as the order's customer.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $pid   Product.
	 * @return void
	 */
	private function review( $order, $pid ) {
		$id = $this->c()->get( 'reviews' )->create(
			array(
				'product_id' => $pid,
				'author'     => 'Quinn',
				'email'      => $order->get_billing_email(),
				'content'    => 'RR06 review',
				'rating'     => 5,
				'approved'   => 1,
			)
		);
		if ( is_int( $id ) ) {
			$this->fx['comments'][] = $id;
		}
	}

	// ------------------------------------------------------------------

	/**
	 * AC1, AC2.
	 *
	 * @return void
	 */
	private function ac1_ac2() {
		$order = $this->order();
		$first = $this->send_first( $order );
		$sent  = $this->row( $first );
		$s2    = $this->step2( $order->get_id() );
		$this->ok( $sent && 'sent' === $sent->status && 1 === count( $s2 ), 'AC1: sending step 1 queues exactly one free step-2 follow-up row' );
		if ( ! $s2 ) {
			return;
		}
		$expect = strtotime( $sent->sent_at . ' UTC' ) + 7 * DAY_IN_SECONDS;
		$at     = strtotime( $s2[0]->scheduled_at . ' UTC' );
		$this->ok( abs( $at - $expect ) <= 60, 'AC1: scheduled_at = sent_at + 7 days (±60 s)' );
		$acts = $this->actions( (int) $s2[0]->id );
		$ts   = $acts ? reset( $acts )->get_schedule()->get_date()->getTimestamp() : 0;
		$this->ok( 1 === count( $acts ) && abs( $ts - $expect ) <= 60, 'AC1: one pending ndvr_send_request action at that time' );

		$this->age( $order );
		$this->mail = array();
		$this->c()->get( 'scheduler' )->process( (int) $s2[0]->id );
		$mail = end( $this->mail );
		$this->ok( 1 === count( $this->mail ) && false !== strpos( $mail['subject'], 'RR06 follow subject for' ) && false !== strpos( $mail['message'], 'RR06 follow body text, Quinn.' ), 'AC2: the follow-up email uses the follow-up subject and text' );
		$this->ok( 'sent' === $this->row( (int) $s2[0]->id )->status, 'AC2: the step-2 row is sent' );
		$this->ok( 1 === count( $this->step2( $order->get_id() ) ), 'AC2: a sent follow-up queues no further follow-up' );
	}

	/**
	 * AC3, AC4.
	 *
	 * @return void
	 */
	private function ac3_ac4() {
		$o3 = $this->order();
		$this->send_first( $o3 );
		$this->review( $o3, $this->p[0] );
		$this->review( $o3, $this->p[1] );
		$this->age( $o3 );
		$s2         = $this->step2( $o3->get_id() );
		$this->mail = array();
		$this->c()->get( 'scheduler' )->process( (int) $s2[0]->id );
		$r = $this->row( (int) $s2[0]->id );
		$this->ok( 'cancelled' === $r->status && 'No reviewable products in this order.' === $r->error && ! $this->mail, 'AC3: everything reviewed → cancelled "No reviewable products in this order.", no email' );

		$o4 = $this->order();
		$this->send_first( $o4 );
		$this->review( $o4, $this->p[0] );
		$this->age( $o4 );
		$s2         = $this->step2( $o4->get_id() );
		$this->mail = array();
		$this->c()->get( 'scheduler' )->process( (int) $s2[0]->id );
		$m = end( $this->mail );
		$this->ok( $m && false !== strpos( $m['message'], 'RR06 Saucer' ) && false === strpos( $m['message'], 'RR06 Teapot' ), 'AC4: the follow-up lists only the product still to review' );
	}

	/**
	 * AC5–AC7.
	 *
	 * @return void
	 */
	private function ac5_to_7() {
		$sched = $this->c()->get( 'scheduler' );

		$o5 = $this->order();
		$this->send_first( $o5 );
		$this->c()->get( 'mailer' )->suppress( $o5->get_billing_email() );
		$this->age( $o5 );
		$s2 = $this->step2( $o5->get_id() );
		$sched->process( (int) $s2[0]->id );
		$this->ok( 'cancelled' === $this->row( (int) $s2[0]->id )->status && 'Recipient has unsubscribed.' === $this->row( (int) $s2[0]->id )->error, 'AC5: unsubscribed between sends → cancelled' );

		$o6 = $this->order();
		$this->send_first( $o6 );
		$this->age( $o6 );
		$this->set( array( 'followup_enabled' => false ) );
		$s2 = $this->step2( $o6->get_id() );
		$sched->process( (int) $s2[0]->id );
		$this->set( array( 'followup_enabled' => true ) );
		$this->ok( 'cancelled' === $this->row( (int) $s2[0]->id )->status && false !== strpos( (string) $this->row( (int) $s2[0]->id )->error, 'turned off' ), 'AC6: turned off after scheduling → cancelled (ndvr_followup_disabled)' );

		$o7   = $this->order();
		$this->send_first( $o7 );
		$this->age( $o7 );
		$stub = static function () {
			return false;
		};
		add_filter( 'ndv-reviews/should_send_followup', $stub );
		$s2 = $this->step2( $o7->get_id() );
		$sched->process( (int) $s2[0]->id );
		remove_filter( 'ndv-reviews/should_send_followup', $stub );
		$this->ok( 'cancelled' === $this->row( (int) $s2[0]->id )->status && 'Skipped by a site filter.' === $this->row( (int) $s2[0]->id )->error, 'AC7: should_send_followup false → cancelled (ndvr_followup_filtered)' );
	}

	/**
	 * AC8: Pro automation (should_send_reminder false) and a manual send.
	 *
	 * @return void
	 */
	private function ac8() {
		$o    = $this->order();
		$stub = static function () {
			return false;
		};
		add_filter( 'ndv-reviews/should_send_reminder', $stub );
		$this->mail = array();
		$first      = $this->send_first( $o, 'manual' );
		remove_filter( 'ndv-reviews/should_send_reminder', $stub );
		$this->ok( $first && 'sent' === $this->row( $first )->status && 1 === count( $this->mail ), 'AC8: the manual send goes out' );
		$this->ok( ! $this->step2( $o->get_id() ), 'AC8: no follow-up is queued while another sender owns the sequence' );
	}

	/**
	 * AC9: order_already_requested.
	 *
	 * @return void
	 */
	private function ac9() {
		$flag = static function () {
			return true;
		};
		$o    = $this->order();
		add_filter( 'ndv-reviews/order_already_requested', $flag );
		$this->send_first( $o );
		remove_filter( 'ndv-reviews/order_already_requested', $flag );
		$this->ok( ! $this->step2( $o->get_id() ), 'AC9: already requested → no follow-up row' );

		$o2 = $this->order();
		$this->send_first( $o2 );
		$this->age( $o2 );
		$s2 = $this->step2( $o2->get_id() );
		add_filter( 'ndv-reviews/order_already_requested', $flag );
		$this->c()->get( 'scheduler' )->process( (int) $s2[0]->id );
		remove_filter( 'ndv-reviews/order_already_requested', $flag );
		$this->ok( 'cancelled' === $this->row( (int) $s2[0]->id )->status && 'Another sender already asked this customer.' === $this->row( (int) $s2[0]->id )->error, 'AC9: set after scheduling → cancelled (ndvr_already_requested)' );
	}

	/**
	 * AC10: campaign and Pro-origin rows get no free follow-up.
	 *
	 * @return void
	 */
	private function ac10() {
		$pro   = $this->pro_gate_off();
		$sched = $this->c()->get( 'scheduler' );
		$oc    = $this->order();
		$id    = $sched->queue_for_order(
			$oc->get_id(),
			array(
				'source' => 'campaign',
				'meta'   => array( 'campaign_id' => 4242 ),
			)
		);
		$sched->process( (int) $id );
		$op  = $this->order();
		$id2 = $sched->queue_for_order(
			$op->get_id(),
			array(
				'source' => 'auto',
				'origin' => 'pro',
			)
		);
		$sched->process( (int) $id2 );
		$this->ok( 'sent' === $this->row( (int) $id )->status && 'sent' === $this->row( (int) $id2 )->status, 'AC10: the campaign and the Pro row were sent' );
		$this->ok( ! $this->step2( $oc->get_id() ) && ! $this->step2( $op->get_id() ), 'AC10: neither gets a free follow-up' );
		$this->pro_gate_on( $pro );
	}

	/**
	 * Set aside Pro's own send rules (Pro automation is off on this site, and
	 * these checks are about free's rules only).
	 *
	 * @return array<int,array> Removed callbacks by priority.
	 */
	private function pro_gate_off() {
		global $wp_filter;
		$removed = array();
		if ( isset( $wp_filter['ndv-reviews/request_eligible'] ) ) {
			foreach ( $wp_filter['ndv-reviews/request_eligible']->callbacks as $priority => $cbs ) {
				foreach ( $cbs as $cb ) {
					if ( is_array( $cb['function'] ) && is_object( $cb['function'][0] ) && 0 === strpos( get_class( $cb['function'][0] ), 'NdvReviews\\Pro\\' ) ) {
						$removed[] = array( $cb['function'], $priority, $cb['accepted_args'] );
					}
				}
			}
		}
		foreach ( $removed as $r ) {
			remove_filter( 'ndv-reviews/request_eligible', $r[0], $r[1] );
		}

		return $removed;
	}

	/**
	 * Put Pro's send rules back.
	 *
	 * @param array $removed From pro_gate_off().
	 * @return void
	 */
	private function pro_gate_on( array $removed ) {
		foreach ( $removed as $r ) {
			add_filter( 'ndv-reviews/request_eligible', $r[0], $r[1], $r[2] );
		}
	}

	/**
	 * AC11: retry and a second request_sent for the same row.
	 *
	 * @return void
	 */
	private function ac11() {
		$sched = $this->c()->get( 'scheduler' );
		$o     = $this->order();
		$id    = $sched->queue_for_order( $o->get_id(), array( 'source' => 'auto' ) );
		$this->fail_next = true;
		$sched->process( (int) $id );
		$this->ok( 'failed' === $this->row( (int) $id )->status && ! $this->step2( $o->get_id() ), 'AC11: a failed step 1 queues nothing' );
		$sched->retry( (int) $id );
		$this->drain_send( (int) $id );
		$row = $this->row( (int) $id );
		do_action( 'ndv-reviews/request_sent', (int) $id, $row );
		$s2 = $this->step2( $o->get_id() );
		$this->ok( 'sent' === $row->status && 1 === count( $s2 ) && 1 === count( $this->actions( (int) $s2[0]->id ) ), 'AC11: after the retry, one step-2 row and one pending action, even with a second request_sent' );
	}

	/**
	 * Run a request's pending send action(s).
	 *
	 * @param int $id Request.
	 * @return void
	 */
	private function drain_send( $id ) {
		$row = $this->row( $id );
		if ( $row && 'sent' !== $row->status ) {
			foreach ( array_keys( $this->actions( $id ) ) as $aid ) {
				\ActionScheduler::runner()->process_action( $aid, 'rr06-qa' );
			}
		}
		$row = $this->row( $id );
		if ( $row && 'sent' !== $row->status ) {
			$this->c()->get( 'scheduler' )->process( $id );
		}
	}

	/**
	 * AC12, AC13.
	 *
	 * @return void
	 */
	private function ac12_ac13() {
		$mailer = $this->c()->get( 'mailer' );
		$p      = $mailer->preview(
			array(
				'variant'          => 'followup',
				'followup_subject' => 'Unsaved RR06 subject',
				'followup_body'    => 'Unsaved RR06 follow text.',
			)
		);
		$this->ok( 'Unsaved RR06 subject' === $p['subject'] && false !== strpos( $p['html'], 'Unsaved RR06 follow text.' ), 'AC12: preview with variant=followup shows the unsaved follow-up texts' );
		$first = $mailer->preview( array() );
		$this->ok( false === strpos( $first['subject'], 'RR06 follow subject' ) && false === strpos( $first['html'], 'RR06 follow body text' ), 'AC12: without variant it shows the first email' );

		$this->set( array( 'followup_body' => '' ) );
		$def = $mailer->preview( array( 'variant' => 'followup' ) );
		$this->set( array( 'followup_body' => 'RR06 follow body text, {customer_name}.' ) );
		$this->ok( false !== strpos( $def['html'], 'A few days ago we asked how your order from' ), 'Default follow-up text is used when the setting is empty' );

		// AC13: a theme override copied from today's template (no $is_followup).
		$copy = trailingslashit( get_temp_dir() ) . 'rr06-email-request.php';
		copy( NDVR_DIR . 'templates/email-request.php', $copy );
		$swap = static function ( $path, $name ) use ( $copy ) {
			return 'email-request.php' === $name ? $copy : $path;
		};
		add_filter( 'ndv-reviews/template_path', $swap, 10, 2 );
		$o = $this->order();
		$this->send_first( $o );
		$this->age( $o );
		$s2         = $this->step2( $o->get_id() );
		$this->mail = array();
		$this->c()->get( 'scheduler' )->process( (int) $s2[0]->id );
		remove_filter( 'ndv-reviews/template_path', $swap, 10 );
		wp_delete_file( $copy );
		$m = end( $this->mail );
		$this->ok( $m && false !== strpos( $m['message'], 'RR06 follow body text' ), 'AC13: an override without $is_followup still renders the follow-up text' );
	}

	/**
	 * AC14: the delay is clamped on save.
	 *
	 * @return void
	 */
	private function ac14() {
		$page = $this->c()->get( 'admin_requests_page' );
		$s    = $this->c()->get( 'settings' );
		foreach ( array(
			'0'  => 1,
			'99' => 60,
		) as $in => $want ) {
			$_GET     = array( 'page' => \NdvReviews\Admin\RequestsPage::PAGE_SLUG );
			$_POST    = array(
				'ndvr_requests_do'    => 'save',
				'_wpnonce'            => wp_create_nonce( \NdvReviews\Admin\RequestsPage::NONCE ),
				'reminder_enabled'    => '1',
				'reminder_status'     => (string) $s->get( 'reminder_status', 'completed' ),
				'reminder_delay_days' => '0',
				'token_expiry_days'   => (string) $s->get( 'token_expiry_days', 60 ),
				'followup_enabled'    => '1',
				'followup_delay_days' => (string) $in,
				'followup_subject'    => 'RR06 follow subject for {store_name}',
				'followup_body'       => 'RR06 follow body text, {customer_name}.',
				'ndvr_fields'         => array( 'followup_enabled', 'followup_delay_days', 'followup_subject', 'followup_body' ),
			);
			$_REQUEST = $_POST;
			$page->handle_actions();
			$this->ok( $want === (int) $s->get( 'followup_delay_days' ), "AC14: saving {$in} stores {$want}" );
		}
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->ok( false !== strpos( $html, 'name="followup_enabled"' ) && false !== strpos( $html, 'name="followup_delay_days"' ) && false !== strpos( $html, 'Preview follow-up' ) && 1 === substr_count( $html, 'value="followup_body"' ), 'Screen: the four rows, the preview button and one save marker each' );
	}

	/**
	 * AC15: Pro steps pass through untouched.
	 *
	 * @return void
	 */
	private function ac15() {
		$pro   = $this->pro_gate_off();
		$repo  = $this->c()->get( 'request_repository' );
		$this->set( array( 'followup_enabled' => false ) );
		$stub = static function () {
			return false;
		};
		add_filter( 'ndv-reviews/should_send_followup', $stub );
		$ok = true;
		foreach ( array( 'auto', 'followup' ) as $source ) {
			$o  = $this->order();
			$id = $repo->insert_unique(
				array(
					'order_id' => $o->get_id(),
					'email'    => $o->get_billing_email(),
					'step'     => 2,
					'source'   => $source,
					'origin'   => 'pro',
					'meta'     => array( 'variant' => 'followup' ),
				)
			);
			$id  = is_array( $id ) ? (int) $id['id'] : (int) $id;
			$this->c()->get( 'scheduler' )->process( $id );
			$r   = $this->row( $id );
			$ok  = $ok && $r && 'sent' === $r->status;
			if ( ! $r || 'sent' !== $r->status ) {
				$this->line( 'INFO: pro row ' . $source . ': ' . ( $r ? $r->status . ' ' . $r->error : 'missing' ) );
			}
		}
		$f   = $this->c()->get( 'followups' );
		$res = $f->at_send(
			true,
			wc_get_order( end( $this->fx['orders'] ) ),
			array(
				'origin' => 'pro',
				'source' => 'followup',
				'step'   => 3,
				'stage'  => 'send',
			)
		);
		remove_filter( 'ndv-reviews/should_send_followup', $stub );
		$this->set( array( 'followup_enabled' => true ) );
		$this->ok( $ok, 'AC15: origin=pro step-2 rows (source auto and followup) are sent, not cancelled' );
		$this->ok( true === $res, 'AC15: the send-time listener returns true for a Pro step 3' );
		$this->pro_gate_on( $pro );
	}

	/**
	 * AC16: process() never calls follow-up code directly.
	 *
	 * @return void
	 */
	private function ac16() {
		global $wp_filter;
		$saved = isset( $wp_filter['ndv-reviews/request_sent'] ) ? clone $wp_filter['ndv-reviews/request_sent'] : null;
		remove_all_actions( 'ndv-reviews/request_sent' );
		$o = $this->order();
		$this->send_first( $o );
		if ( $saved ) {
			$wp_filter['ndv-reviews/request_sent'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
		$this->ok( ! $this->step2( $o->get_id() ), 'AC16: without request_sent listeners, no follow-up row' );
	}

	/**
	 * RR-03: the transparency fact.
	 *
	 * @return void
	 */
	private function transparency() {
		$f = $this->c()->get( 'transparency' )->facts( 0 );
		$this->ok( true === $f['followup'], 'Transparency: the followup fact follows the setting' );
		$this->ok( 6 <= (int) NDVR_API && array( 'subject', 'body' ) === array_keys( $this->c()->get( 'followups' )->default_texts() ), 'NDVR_API 6 and default_texts() {subject, body}' );
	}

	/**
	 * Code review M1, m2 and harness gaps.
	 *
	 * @return void
	 */
	private function review_fixes() {
		$sched = $this->c()->get( 'scheduler' );

		// A manual send earns a follow-up.
		$om = $this->order();
		$this->send_first( $om, 'manual' );
		$this->ok( 1 === count( $this->step2( $om->get_id() ) ), 'A manual send earns one follow-up' );

		// M1: manual on day 0 → follow-up sent → the later automatic first email is skipped.
		$this->age( $om );
		$s2 = $this->step2( $om->get_id() );
		$sched->process( (int) $s2[0]->id );
		// Both emails went out days ago: outside the 20 h cooldown, so only the
		// new rule can stop the automatic one.
		global $wpdb;
		$wpdb->update( \NdvReviews\Support\Db::table( 'requests' ), array( 'sent_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), array( 'order_id' => $om->get_id() ) );
		$auto = $sched->queue_for_order( $om->get_id(), array( 'source' => 'auto' ) );
		$this->mail = array();
		$sched->process( (int) $auto );
		$row = $this->row( (int) $auto );
		$this->ok( 'sent' === $this->row( (int) $s2[0]->id )->status && $row && 'cancelled' === $row->status && false !== strpos( (string) $row->error, 'and a reminder' ) && ! $this->mail, 'M1: after a manual email and its follow-up, the automatic first email is skipped (' . ( $row ? $row->status . ': ' . $row->error : 'no row' ) . ')' );

		// m2: a follow-up cancelled at send time gives its key back.
		$o2 = $this->order();
		$this->send_first( $o2, 'manual' );
		$this->age( $o2 );
		$this->set( array( 'followup_enabled' => false ) );
		$f1 = $this->step2( $o2->get_id() );
		$sched->process( (int) $f1[0]->id );
		$this->set( array( 'followup_enabled' => true ) );
		global $wpdb;
		$wpdb->update( \NdvReviews\Support\Db::table( 'requests' ), array( 'sent_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'order_id' => $o2->get_id() ) );
		$this->send_first( $o2, 'manual' );
		$pending = array_filter(
			$this->step2( $o2->get_id() ),
			static function ( $r ) {
				return 'scheduled' === $r->status;
			}
		);
		$this->ok( 'cancelled' === $this->row( (int) $f1[0]->id )->status && 1 === count( $pending ), 'm2: after a cancelled follow-up, a later manual send queues a new one' );

		// The unticked checkbox saves as off.
		$page = $this->c()->get( 'admin_requests_page' );
		$st   = $this->c()->get( 'settings' );
		$_GET     = array( 'page' => \NdvReviews\Admin\RequestsPage::PAGE_SLUG );
		$_POST    = array(
			'ndvr_requests_do'    => 'save',
			'_wpnonce'            => wp_create_nonce( \NdvReviews\Admin\RequestsPage::NONCE ),
			'reminder_enabled'    => '1',
			'reminder_status'     => (string) $st->get( 'reminder_status', 'completed' ),
			'reminder_delay_days' => '0',
			'token_expiry_days'   => (string) $st->get( 'token_expiry_days', 60 ),
			'followup_delay_days' => '7',
			'ndvr_fields'         => array( 'followup_enabled', 'followup_delay_days', 'followup_subject', 'followup_body' ),
		);
		$_REQUEST = $_POST;
		$page->handle_actions();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$this->ok( false === $st->get( 'followup_enabled' ), 'Saving with the follow-up box unticked stores false' );
		$this->set(
			array(
				'followup_enabled' => true,
				'followup_subject' => 'RR06 follow subject for {store_name}',
				'followup_body'    => 'RR06 follow body text, {customer_name}.',
			)
		);

		// The preview route (it exits, so in a child).
		if ( defined( 'NDVR_QA_BOOT' ) || file_exists( dirname( ABSPATH ) . '/boot.php' ) ) {
			$boot    = defined( 'NDVR_QA_BOOT' ) ? NDVR_QA_BOOT : dirname( ABSPATH ) . '/boot.php';
			$prepend = dirname( $boot ) . '/rr06-prepend.php';
			file_put_contents( $prepend, "<?php\ndefine( 'NDVR_QA_RR06_PREVIEW', 1 );\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d memory_limit=512M -d ' . escapeshellarg( 'auto_prepend_file=' . $prepend ) . ' ' . escapeshellarg( $boot ) . ' run ' . escapeshellarg( __FILE__ ) . ' 1 2>&1' );
			wp_delete_file( $prepend );
			$this->ok( false !== strpos( $out, 'Typed RR06 preview subject' ) && false !== strpos( $out, 'Typed RR06 preview text' ), 'Preview route: GET variant=followup with POSTed texts shows them' );
		}
	}

	/**
	 * Child: call the preview handler as the admin-post route would.
	 *
	 * @return bool
	 */
	private function preview_child() {
		$_GET     = array(
			'action'   => 'ndvr_reminder_preview',
			'variant'  => 'followup',
			'_wpnonce' => wp_create_nonce( \NdvReviews\Admin\RequestsPage::NONCE ),
		);
		$_POST    = array(
			'followup_subject' => 'Typed RR06 preview subject',
			'followup_body'    => 'Typed RR06 preview text',
		);
		$_REQUEST = $_GET + $_POST;
		\NdvReviews\Plugin::instance()->container()->get( 'admin_requests_page' )->render_preview();

		return true;
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	private function teardown() {
		global $wpdb;
		$t = \NdvReviews\Support\Db::table( 'requests' );
		foreach ( $this->fx['orders'] as $id ) {
			foreach ( $this->rows( $id ) as $row ) {
				as_unschedule_all_actions( \NdvReviews\Requests\Scheduler::SEND_HOOK, array( 'request_id' => (int) $row->id ) );
			}
			$wpdb->delete( $t, array( 'order_id' => $id ) );
			$wpdb->delete( \NdvReviews\Support\Db::table( 'review_tokens' ), array( 'order_id' => $id ) );
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
		foreach ( $this->fx['comments'] as $id ) {
			wp_delete_comment( $id, true );
		}
		foreach ( $this->fx['products'] as $id ) {
			wp_delete_post( $id, true );
		}
		if ( null === $this->saved['settings'] ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved['settings'] );
		}
		if ( null === $this->saved['suppress'] ) {
			delete_option( \NdvReviews\Requests\Mailer::SUPPRESS_OPTION );
		} else {
			update_option( \NdvReviews\Requests\Mailer::SUPPRESS_OPTION, $this->saved['suppress'], false );
		}
	}

	/**
	 * Assert.
	 *
	 * @param bool   $cond Condition.
	 * @param string $msg  Message.
	 * @return void
	 */
	private function ok( $cond, $msg ) {
		++$this->count[ $cond ? 'pass' : 'fail' ];
		$this->line( ( $cond ? 'PASS: ' : 'FAIL: ' ) . $msg );
	}

	/**
	 * Print.
	 *
	 * @param string $msg Message.
	 * @return void
	 */
	private function line( $msg ) {
		echo $msg . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

( new NDVR_QA_RR06() )->run();
