<?php
/**
 * RR-04 acceptance harness (PRD .agents/prd/RR-04-order-screen-requests.md §12).
 *
 *     php boot.php run .agents/qa/rr-04.php 1   (QA site, D:/.devcache/qa-site)
 *
 * AC2 (legacy order storage) runs in a child process with HPOS switched off.
 * Mail is captured with pre_wp_mail. Settings, the suppression list and the
 * HPOS option are restored; fixtures are removed.
 *
 * Not shipped: `.agents` is in .distignore.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification -- QA script, never shipped.

use NdvReviews\Requests\OrderActions;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NDVR_QA_BOOT' ) && file_exists( dirname( ABSPATH ) . '/boot.php' ) ) {
	define( 'NDVR_QA_BOOT', dirname( ABSPATH ) . '/boot.php' );
}

/**
 * The harness.
 */
final class NDVR_QA_RR04 {

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
	 * @var array<string,int[]>
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
	 * Mails sent.
	 *
	 * @var int
	 */
	private $mail = 0;

	/**
	 * Product used in the orders.
	 *
	 * @var int
	 */
	private $product = 0;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( defined( 'NDVR_QA_LEGACY_CHILD' ) ) {
			return $this->legacy_child();
		}
		if ( ! class_exists( OrderActions::class ) || ! function_exists( 'wc_create_order' ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-04 and WooCommerce must be active.' );
			return false;
		}

		$this->setup();
		try {
			$this->ac1_single();
			$this->ac9_null_order();
			$this->ac8_capability();
			$this->ac3_bulk();
			$this->ac4_cooldown();
			$this->ac10_limit();
			$this->ac11_already_asked();
			$this->ac5_to_7_12_with_reminders();
			$this->notice_render();
			$this->review_fixes();
			$this->ac2_legacy();
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
		$this->teardown();
		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->count['fail'] ? 'FAIL' : 'PASS', $this->count['pass'], $this->count['fail'] ) );

		return 0 === $this->count['fail'];
	}

	// ------------------------------------------------------------------

	/**
	 * Container.
	 *
	 * @return object
	 */
	private function c() {
		return \NdvReviews\Plugin::instance()->container();
	}

	/**
	 * The service under test.
	 *
	 * @return OrderActions
	 */
	private function oa() {
		return $this->c()->get( 'order_actions' );
	}

	/**
	 * Settings, mail capture, a product.
	 *
	 * @return void
	 */
	private function setup() {
		$this->saved['settings'] = get_option( NDVR_OPTION_SETTINGS, null );
		$this->saved['suppress'] = get_option( \NdvReviews\Requests\Mailer::SUPPRESS_OPTION, null );
		$this->settings(
			array(
				'reminder_enabled'    => false,
				'reminder_status'     => 'completed',
				'reminder_delay_days' => 0,
			)
		);
		add_filter(
			'pre_wp_mail',
			function () {
				++$this->mail;
				return true;
			},
			1
		);
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );

		$p = new \WC_Product_Simple();
		$p->set_name( 'RR04 product' );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$p->set_reviews_allowed( true );
		$this->product            = (int) $p->save();
		$this->fx['products'][]   = $this->product;
		delete_transient( OrderActions::NOTICE_PREFIX . get_current_user_id() );
	}

	/**
	 * Merge settings through the service.
	 *
	 * @param array<string,mixed> $v Values.
	 * @return void
	 */
	private function settings( array $v ) {
		$this->c()->get( 'settings' )->update( $v );
	}

	/**
	 * A paid order with one reviewable product.
	 *
	 * @param string $status Status.
	 * @return \WC_Order
	 */
	private function order( $status = 'processing' ) {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->set_billing_first_name( 'QA' );
		$order->set_billing_email( 'rr04-' . wp_generate_password( 6, false, false ) . '@example.invalid' );
		$order->calculate_totals();
		$order->set_status( $status );
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
	 * Back-date a row's sent_at.
	 *
	 * @param int $id      Row.
	 * @param int $seconds Seconds ago.
	 * @return void
	 */
	private function backdate( $id, $seconds ) {
		global $wpdb;
		$wpdb->update( \NdvReviews\Support\Db::table( 'requests' ), array( 'sent_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ), array( 'id' => $id ) );
		delete_transient( 'ndvr_request_stats_90' );
	}

	/**
	 * The latest stored notice text (and clear them).
	 *
	 * @return string
	 */
	private function take_notice() {
		$all = OrderActions::notices( get_current_user_id() );
		delete_transient( OrderActions::NOTICE_PREFIX . get_current_user_id() );
		$last = end( $all );

		return is_array( $last ) ? (string) $last['text'] : '';
	}

	// ------------------------------------------------------------------

	/**
	 * AC1: single order, HPOS (or whatever storage the site uses).
	 *
	 * @return void
	 */
	private function ac1_single() {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$this->line( 'INFO: order storage is ' . ( $hpos ? 'HPOS' : 'legacy posts' ) );

		$order   = $this->order();
		$actions = apply_filters( 'woocommerce_order_actions', array(), $order );
		$this->ok( isset( $actions[ OrderActions::ACTION ] ) && 'Send review request' === $actions[ OrderActions::ACTION ], 'AC1: the order actions list has "Send review request"' );

		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $order );
		$rows = $this->rows( $order->get_id() );
		$this->ok( 1 === count( $rows ) && 'manual' === $rows[0]->source && 'free' === $rows[0]->origin && 'scheduled' === $rows[0]->status, 'AC1 (' . ( $hpos ? 'HPOS' : 'legacy' ) . '): one manual/free/scheduled row' );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$found = false;
		foreach ( $notes as $note ) {
			$found = $found || ( false !== strpos( $note->content, 'Review request queued from the order screen.' ) && ! $note->customer_note );
		}
		$this->ok( $found, 'AC1: a private order note is added' );
		$notice = $this->take_notice();
		$this->ok( false !== strpos( $notice, 'Review request queued for order #' . $order->get_order_number() . '. It goes out within a few minutes.' ), 'AC1: the success notice names the order' );

		$mail = $this->mail;
		$this->c()->get( 'scheduler' )->process( (int) $rows[0]->id );
		$rows = $this->rows( $order->get_id() );
		$this->ok( $mail + 1 === $this->mail && 'sent' === $rows[0]->status, 'AC1: process() sends one email and the row is sent (reminders off)' );

		// Another sender: the notice says so.
		$other = $this->order();
		$flag  = static function () {
			return true;
		};
		add_filter( 'ndv-reviews/order_already_requested', $flag );
		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $other );
		remove_filter( 'ndv-reviews/order_already_requested', $flag );
		$this->ok( false !== strpos( $this->take_notice(), 'This order already had a review request from another sender.' ), 'Single: the notice mentions another sender' );
	}

	/**
	 * AC9: WooCommerce may pass a null order.
	 *
	 * @return void
	 */
	private function ac9_null_order() {
		$warn    = false;
		$handler = set_error_handler(
			static function () use ( &$warn ) {
				$warn = true;
				return true;
			}
		);
		$in  = array( 'x' => 'X' );
		$out = apply_filters( 'woocommerce_order_actions', $in, null );
		restore_error_handler();
		unset( $handler );
		$this->ok( $in === $out && ! $warn, 'AC9: a null order leaves the actions unchanged, with no warning' );
	}

	/**
	 * AC8: users without plugin rights.
	 *
	 * @return void
	 */
	private function ac8_capability() {
		$deny = static function ( $cap, $context ) {
			return 'reminders' === $context ? 'ndvr_qa_missing_cap' : $cap;
		};
		add_filter( 'ndv-reviews/manage_capability', $deny, 10, 2 );
		$order   = $this->order();
		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $order );
		$url     = 'https://qa.local/wp-admin/admin.php?page=wc-orders';
		$back    = apply_filters( 'handle_bulk_actions-woocommerce_page_wc-orders', $url, OrderActions::ACTION, array( $order->get_id() ) );
		$actions = apply_filters( 'woocommerce_order_actions', array(), $order );
		$bulk    = apply_filters( 'bulk_actions-woocommerce_page_wc-orders', array() );
		remove_filter( 'ndv-reviews/manage_capability', $deny, 10 );

		$this->ok( ! $this->rows( $order->get_id() ), 'AC8: the order action adds no row' );
		$this->ok( $url === $back && ! $this->rows( $order->get_id() ), 'AC8: the bulk handler returns the redirect unchanged, no rows' );
		$this->ok( ! isset( $actions[ OrderActions::ACTION ] ) && ! isset( $bulk[ OrderActions::ACTION ] ), 'AC8: neither the dropdown nor the bulk menu lists the item' );
		$this->take_notice();

		// An order without a valid billing email doesn't list it either.
		$no = $this->order();
		$no->set_billing_email( '' );
		$no->save();
		$a = apply_filters( 'woocommerce_order_actions', array(), $no );
		$this->ok( ! isset( $a[ OrderActions::ACTION ] ), 'AC8: an order without a valid email has no item' );
	}

	/**
	 * AC3: bulk with one reviewed, one unsubscribed, one eligible.
	 *
	 * @return void
	 */
	private function ac3_bulk() {
		$reviewed = $this->order();
		$id       = $this->c()->get( 'reviews' )->create(
			array(
				'product_id' => $this->product,
				'author'     => 'RR04',
				'email'      => $reviewed->get_billing_email(),
				'content'    => 'Already reviewed.',
				'rating'     => 5,
				'approved'   => 1,
			)
		);
		if ( is_int( $id ) ) {
			$this->fx['comments'][] = $id;
		}
		$unsub = $this->order();
		$this->c()->get( 'mailer' )->suppress( $unsub->get_billing_email() );
		$ok = $this->order();

		$url = 'https://qa.local/wp-admin/admin.php?page=wc-orders';
		$ret = apply_filters( 'handle_bulk_actions-woocommerce_page_wc-orders', $url, OrderActions::ACTION, array( $reviewed->get_id(), $unsub->get_id(), $ok->get_id() ) );
		$this->ok( $url === $ret, 'AC3: the redirect is returned' );
		$notice = $this->take_notice();
		$this->ok( 'Queued 1 review request. Skipped 2: 1 already reviewed everything, 1 unsubscribed.' === $notice, 'AC3: "' . $notice . '"' );

		$mail = $this->mail;
		foreach ( array( $reviewed, $unsub, $ok ) as $o ) {
			foreach ( $this->rows( $o->get_id() ) as $row ) {
				$this->c()->get( 'scheduler' )->process( (int) $row->id );
			}
		}
		$this->ok( $mail + 1 === $this->mail, 'AC3: exactly one email goes out' );
	}

	/**
	 * AC4: the 20-hour cooldown.
	 *
	 * @return void
	 */
	private function ac4_cooldown() {
		$order = $this->order();
		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $order );
		$this->take_notice();
		$rows = $this->rows( $order->get_id() );
		$this->c()->get( 'scheduler' )->process( (int) $rows[0]->id );
		$this->backdate( (int) $rows[0]->id, HOUR_IN_SECONDS );

		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $order );
		$notice = $this->take_notice();
		$this->ok( 1 === count( $this->rows( $order->get_id() ) ) && false !== strpos( $notice, 'asked in the last 20 hours' ), 'AC4: a second send within 20 hours inserts nothing: "' . $notice . '"' );

		$this->backdate( (int) $rows[0]->id, 21 * HOUR_IN_SECONDS );
		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $order );
		$this->take_notice();
		$this->ok( 2 === count( $this->rows( $order->get_id() ) ), 'AC4: after 21 hours it queues' );
	}

	/**
	 * AC10: the bulk limit.
	 *
	 * @return void
	 */
	private function ac10_limit() {
		$ids = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$ids[] = 900000000 + $i; // Not real orders: counted as "order not found".
		}
		$small = static function () {
			return 3;
		};
		add_filter( 'ndv-reviews/manual_bulk_limit', $small );
		apply_filters( 'handle_bulk_actions-woocommerce_page_wc-orders', 'x', OrderActions::ACTION, $ids );
		remove_filter( 'ndv-reviews/manual_bulk_limit', $small );
		$notice = $this->take_notice();
		$this->ok( false !== strpos( $notice, 'Skipped 3:' ) && false !== strpos( $notice, 'Only the first 3 orders were processed. Select up to 3 at a time.' ), 'AC10: only the limit is processed and the notice says so: "' . $notice . '"' );

		apply_filters( 'handle_bulk_actions-woocommerce_page_wc-orders', 'x', OrderActions::ACTION, $ids );
		$notice = $this->take_notice();
		$this->ok( false !== strpos( $notice, 'Skipped 200:' ) && false !== strpos( $notice, 'Only the first 200 orders were processed. Select up to 200 at a time.' ), 'AC10: 250 ids → 200 processed (default limit)' );
	}

	/**
	 * AC11: bulk skips orders already asked.
	 *
	 * @return void
	 */
	private function ac11_already_asked() {
		$asked = $this->order();
		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $asked );
		$this->take_notice();
		apply_filters( 'handle_bulk_actions-woocommerce_page_wc-orders', 'x', OrderActions::ACTION, array( $asked->get_id() ) );
		$this->ok( false !== strpos( $this->take_notice(), 'Skipped 1: 1 already asked.' ) && 1 === count( $this->rows( $asked->get_id() ) ), 'AC11: an order with a prior request is skipped as "already asked"' );

		$pro  = $this->order();
		$flag = static function () {
			return true;
		};
		add_filter( 'ndv-reviews/order_already_requested', $flag );
		apply_filters( 'handle_bulk_actions-woocommerce_page_wc-orders', 'x', OrderActions::ACTION, array( $pro->get_id() ) );
		remove_filter( 'ndv-reviews/order_already_requested', $flag );
		$this->ok( false !== strpos( $this->take_notice(), 'already asked' ) && ! $this->rows( $pro->get_id() ), 'AC11: order_already_requested true is skipped the same way' );
	}

	/**
	 * AC5–AC7, AC12 with reminders on.
	 *
	 * @return void
	 */
	private function ac5_to_7_12_with_reminders() {
		$this->settings(
			array(
				'reminder_enabled'    => true,
				'reminder_status'     => 'completed',
				'reminder_delay_days' => 0,
			)
		);
		$this->c()->get( 'scheduler' )->register();
		$sched = $this->c()->get( 'scheduler' );

		// AC5: outside the cooldown the automatic request still runs.
		$o5 = $this->order( 'processing' );
		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $o5 );
		$this->take_notice();
		$m = $this->rows( $o5->get_id() );
		$sched->process( (int) $m[0]->id );
		$this->backdate( (int) $m[0]->id, 2 * DAY_IN_SECONDS );
		$o5->update_status( 'completed' );
		$auto = null;
		foreach ( $this->rows( $o5->get_id() ) as $row ) {
			if ( 'auto' === $row->source ) {
				$auto = $row;
			}
		}
		$mail = $this->mail;
		if ( $auto ) {
			$sched->process( (int) $auto->id );
		}
		$this->ok( $auto && $mail + 1 === $this->mail, 'AC5: after a manual send 2 days ago, completion queues an auto row and it sends' );

		// AC6: inside the cooldown the auto row is cancelled at send time.
		$o6 = $this->order( 'processing' );
		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $o6 );
		$this->take_notice();
		$m = $this->rows( $o6->get_id() );
		$sched->process( (int) $m[0]->id );
		$this->backdate( (int) $m[0]->id, HOUR_IN_SECONDS );
		$o6->update_status( 'completed' );
		$auto = null;
		foreach ( $this->rows( $o6->get_id() ) as $row ) {
			if ( 'auto' === $row->source ) {
				$auto = $row;
			}
		}
		$mail = $this->mail;
		if ( $auto ) {
			$sched->process( (int) $auto->id );
		}
		$after = $auto ? $this->row( (int) $auto->id ) : null;
		$this->ok( $auto && $after && 'cancelled' === $after->status && $mail === $this->mail, 'AC6: the auto row inside the cooldown ends cancelled and sends nothing (' . ( $after ? $after->status . ': ' . $after->error : 'no auto row' ) . ')' );

		// AC7: a pending auto row is cancelled by a manual queue.
		$o7 = $this->order( 'processing' );
		$o7->update_status( 'completed' );
		$pending = $this->rows( $o7->get_id() );
		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $o7 );
		$this->take_notice();
		$rows   = $this->rows( $o7->get_id() );
		$auto   = null;
		$manual = null;
		foreach ( $rows as $row ) {
			if ( 'auto' === $row->source ) {
				$auto = $row;
			} elseif ( 'manual' === $row->source ) {
				$manual = $row;
			}
		}
		$this->ok( 1 === count( $pending ) && $auto && 'cancelled' === $auto->status && $manual && 'scheduled' === $manual->status, 'AC7: the pending auto row is cancelled; the manual row is scheduled' );
		$mail = $this->mail;
		foreach ( $rows as $row ) {
			$sched->process( (int) $row->id );
		}
		$this->ok( $mail + 1 === $this->mail, 'AC7: only the manual row sends' );

		// AC12: status change and the action in the same Update.
		$o12 = $this->order( 'processing' );
		$o12->update_status( 'completed' );
		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $o12 );
		$this->take_notice();
		$auto   = 0;
		$manual = 0;
		foreach ( $this->rows( $o12->get_id() ) as $row ) {
			$auto   += ( 'auto' === $row->source && 'cancelled' === $row->status ) ? 1 : 0;
			$manual += ( 'manual' === $row->source && 'scheduled' === $row->status ) ? 1 : 0;
		}
		$this->ok( 1 === $auto && 1 === $manual, 'AC12: one cancelled auto row and one scheduled manual row' );

		$this->settings( array( 'reminder_enabled' => false ) );
	}

	/**
	 * One request row.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	private function row( $id ) {
		global $wpdb;
		$t = \NdvReviews\Support\Db::table( 'requests' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Notices render on the order screens only, once.
	 *
	 * @return void
	 */
	private function notice_render() {
		$order = $this->order();
		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $order );

		set_current_screen( 'dashboard' );
		ob_start();
		$this->oa()->render_notice();
		$elsewhere = (string) ob_get_clean();

		set_current_screen( 'edit-shop_order' );
		ob_start();
		$this->oa()->render_notice();
		$first = (string) ob_get_clean();
		ob_start();
		$this->oa()->render_notice();
		$second = (string) ob_get_clean();
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->ok( '' === $elsewhere && false !== strpos( $first, 'notice-success' ) && false !== strpos( $first, 'Review request queued for order #' ) && '' === $second, 'Notice: shown once, on an orders screen only' );
	}

	/**
	 * Code review R4-2, R4-3.
	 *
	 * @return void
	 */
	private function review_fixes() {
		$repo  = $this->c()->get( 'request_repository' );
		$order = $this->order();
		$ids   = array();
		foreach ( array(
			'pro'      => array( 'source' => 'auto', 'origin' => 'pro' ),
			'campaign' => array( 'source' => 'campaign', 'origin' => 'free', 'dedupe_key' => 'c:999:' . md5( $order->get_billing_email() ) ),
			'legacy'   => array( 'source' => 'legacy', 'origin' => 'free' ),
			'failed'   => array( 'source' => 'auto', 'origin' => 'free', 'dedupe_key' => 'o:' . $order->get_id() . ':free:1:auto' ),
			'followup' => array( 'source' => 'followup', 'origin' => 'free', 'step' => 2, 'dedupe_key' => 'o:' . $order->get_id() . ':free:2:followup' ),
		) as $name => $data ) {
			$res          = $repo->insert_unique( $data + array( 'order_id' => $order->get_id(), 'email' => $order->get_billing_email() ) );
			$ids[ $name ] = is_array( $res ) ? (int) $res['id'] : (int) $res;
		}
		$repo->set_status( $ids['failed'], 'failed', 'QA failure' );

		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $order );
		$this->take_notice();
		$status = array();
		foreach ( $ids as $name => $id ) {
			$status[ $name ] = $this->row( $id ) ? $this->row( $id )->status : 'missing';
		}
		$this->ok( 'scheduled' === $status['pro'] && 'scheduled' === $status['campaign'] && 'scheduled' === $status['legacy'] && 'failed' === $status['failed'] && 'cancelled' === $status['followup'], 'R4-3: a manual send cancels only the free follow-up; Pro, campaign, legacy and failed rows stay (' . wp_json_encode( $status ) . ')' );

		// R4-2: an error message reason gets one full stop.
		$stop = static function () {
			return new \WP_Error( 'qa_custom_stop', 'Custom stop.' );
		};
		add_filter( 'ndv-reviews/request_eligible', $stop );
		$other = $this->order();
		do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $other );
		remove_filter( 'ndv-reviews/request_eligible', $stop );
		$notice = $this->take_notice();
		$this->ok( false !== strpos( $notice, ': Custom stop.' ) && false === strpos( $notice, '..' ), 'R4-2: "' . $notice . '" ends with one full stop' );
	}

	/**
	 * AC1 on HPOS and AC2 on legacy storage, each in a child process booted
	 * with that storage (WooCommerce picks the data store at load time).
	 *
	 * @return void
	 */
	private function ac2_legacy() {
		if ( ! defined( 'NDVR_QA_BOOT' ) || ! function_exists( 'shell_exec' ) ) {
			$this->line( 'SKIP: AC1/AC2 storage children (needs NDVR_QA_BOOT).' );
			return;
		}
		$was = get_option( 'woocommerce_custom_orders_table_enabled', 'no' );
		foreach ( array( 'hpos' => 'yes', 'legacy' => 'no' ) as $mode => $option ) {
			$this->raw_option( 'woocommerce_custom_orders_table_enabled', $option );
			$prepend = dirname( NDVR_QA_BOOT ) . '/rr04-prepend.php';
			file_put_contents( $prepend, "<?php\ndefine( 'NDVR_QA_LEGACY_CHILD', 1 );\ndefine( 'NDVR_QA_ORDER_MODE', '{$mode}' );\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$cmd = escapeshellarg( PHP_BINARY ) . ' -d memory_limit=512M -d ' . escapeshellarg( 'auto_prepend_file=' . $prepend ) . ' ' . escapeshellarg( NDVR_QA_BOOT ) . ' run ' . escapeshellarg( __FILE__ ) . ' 1 2>&1';
			$out = (string) shell_exec( $cmd );
			wp_delete_file( $prepend );
			$label = 'hpos' === $mode ? 'AC1 (HPOS)' : 'AC2 (legacy)';
			foreach ( preg_split( '/\R/', $out ) as $row ) {
				if ( preg_match( '/^CHILD (PASS|FAIL): (.*)$/', $row, $m ) ) {
					$this->ok( 'PASS' === $m[1], $label . ': ' . $m[2] );
				}
			}
			if ( false === strpos( $out, 'CHILD DONE' ) ) {
				$this->ok( false, $label . ': the child did not finish: ' . substr( $out, 0, 600 ) );
			}
		}
		$this->raw_option( 'woocommerce_custom_orders_table_enabled', (string) $was );
	}

	/**
	 * Write an option row without its hooks: WooCommerce refuses to switch the
	 * order storage while orders are out of sync, and this is a test switch for
	 * a child process only.
	 *
	 * @param string $name  Option.
	 * @param string $value Value.
	 * @return void
	 */
	private function raw_option( $name, $value ) {
		global $wpdb;
		if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) ) ) {
			$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'yes' ) );
		} else {
			$wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => $name ) );
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * The legacy child.
	 *
	 * @return bool
	 */
	private function legacy_child() {
		$say = static function ( $ok, $msg ) {
			echo 'CHILD ' . ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $msg . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		};
		$this->setup();
		try {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			$want = defined( 'NDVR_QA_ORDER_MODE' ) && 'hpos' === NDVR_QA_ORDER_MODE;
			$say( $want === $hpos, 'order storage is ' . ( $hpos ? 'HPOS' : 'legacy posts' ) );
			$order = $this->order();
			global $wpdb;
			$in_table = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order->get_id() ) );
			$say( $want ? $in_table : 'shop_order' === get_post_type( $order->get_id() ), 'the order is stored in ' . ( $want ? 'the wc_orders table' : 'a shop_order post' ) );
			do_action( 'woocommerce_order_action_' . OrderActions::ACTION, $order );
			$rows = $this->rows( $order->get_id() );
			$say( 1 === count( $rows ) && 'manual' === $rows[0]->source && 'scheduled' === $rows[0]->status, 'one manual scheduled row' );
			$mail = $this->mail;
			$this->c()->get( 'scheduler' )->process( (int) $rows[0]->id );
			$say( $mail + 1 === $this->mail && 'sent' === $this->rows( $order->get_id() )[0]->status, 'it sends' );
			$screen = $want ? 'woocommerce_page_wc-orders' : 'edit-shop_order';
			$back   = apply_filters( 'handle_bulk_actions-' . $screen, 'x', OrderActions::ACTION, array( $this->order()->get_id() ) );
			$say( 'x' === $back && false !== strpos( $this->take_notice(), 'Queued 1 review request.' ), 'the ' . $screen . ' bulk action queues' );
		} catch ( \Throwable $e ) {
			$say( false, 'uncaught ' . $e->getMessage() );
		}
		$this->teardown();
		echo "CHILD DONE\n";

		return true;
	}

	/**
	 * Remove fixtures, restore settings.
	 *
	 * @return void
	 */
	private function teardown() {
		global $wpdb;
		$t = \NdvReviews\Support\Db::table( 'requests' );
		foreach ( $this->fx['orders'] as $id ) {
			foreach ( $this->rows( $id ) as $row ) {
				as_unschedule_all_actions( \NdvReviews\Requests\Scheduler::SEND_HOOK, array( (int) $row->id ) );
				as_unschedule_all_actions( \NdvReviews\Requests\Scheduler::SEND_HOOK, array( 'request_id' => (int) $row->id ) );
			}
			$wpdb->delete( $t, array( 'order_id' => $id ) );
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
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
		delete_transient( OrderActions::NOTICE_PREFIX . get_current_user_id() );
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

( new NDVR_QA_RR04() )->run();
