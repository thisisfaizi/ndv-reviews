<?php
/**
 * RR-07 acceptance harness (PRD .agents/prd/RR-07-checkout-consent.md §12).
 *
 *     php boot.php run .agents/qa/rr-07.php 1   (QA site, D:/.devcache/qa-site)
 *
 * Classic and block saves are fired through their WooCommerce actions with
 * built $_POST / WP_REST_Request data. Settings, options and fixtures are
 * restored/removed.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification -- QA script, never shipped.

use NdvReviews\Requests\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * The harness.
 */
final class NDVR_QA_RR07 {

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
		'posts'    => array(),
		'users'    => array(),
	);

	/**
	 * Saved state.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * Mails.
	 *
	 * @var int
	 */
	private $mail = 0;

	/**
	 * Product.
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
		if ( ! class_exists( Consent::class ) || ! function_exists( 'wc_create_order' ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-07 and WooCommerce must be active.' );
			return false;
		}
		$this->setup();
		try {
			$this->ac1_off();
			$this->ac2_classic_optin();
			$this->ac3_block_optin();
			$this->ac4_optout();
			$this->ac5_switch();
			$this->ac6_legacy();
			$this->ac7_ac8_refusal();
			$this->ac9_admin_lines();
			$this->ac10_privacy();
			$this->ac11_enabled_at();
			$this->ac12_pro_origin();
			$this->ac13_block_support();
			$this->ac14_save();
			$this->ac15_customer_copy();
			$this->ac16_label();
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
	 * The consent service.
	 *
	 * @return Consent
	 */
	private function consent() {
		return $this->c()->get( 'consent' );
	}

	/**
	 * Setup.
	 *
	 * @return void
	 */
	private function setup() {
		$this->saved['settings'] = get_option( NDVR_OPTION_SETTINGS, null );
		$this->saved['enabled']  = get_option( Consent::ENABLED_OPTION, null );
		$this->set( array( 'consent_mode' => 'off', 'reminder_enabled' => true, 'reminder_delay_days' => 0 ) );
		add_filter(
			'pre_wp_mail',
			function () {
				++$this->mail;
				return true;
			},
			1
		);
		$p = new \WC_Product_Simple();
		$p->set_name( 'RR07 product' );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$p->set_reviews_allowed( true );
		$this->product          = (int) $p->save();
		$this->fx['products'][] = $this->product;
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
	 * An order.
	 *
	 * @param int $created_ts Created timestamp (0 = now).
	 * @return \WC_Order
	 */
	private function order( $created_ts = 0 ) {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $this->product ), 1 );
		$order->set_billing_first_name( 'QA' );
		$order->set_billing_email( 'rr07-' . wp_generate_password( 6, false, false ) . '@example.invalid' );
		$order->calculate_totals();
		if ( $created_ts ) {
			$order->set_date_created( $created_ts );
		}
		$order->set_status( 'processing' );
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();

		return $order;
	}

	/**
	 * Fire the classic save with POST values.
	 *
	 * @param \WC_Order $order  Order.
	 * @param bool|null $ticked Ticked, unticked, or null (box not shown).
	 * @return void
	 */
	private function classic( $order, $ticked ) {
		$_POST = array();
		if ( null !== $ticked ) {
			$_POST['ndvr_review_consent_shown'] = '1';
			if ( $ticked ) {
				$_POST['ndvr_review_consent'] = '1';
			}
		}
		do_action( 'woocommerce_checkout_create_order', $order, array() );
		$order->save();
		$_POST = array();
	}

	/**
	 * Fire the block save.
	 *
	 * @param \WC_Order $order Order.
	 * @param mixed     $value Field value, or 'absent'.
	 * @return void
	 */
	private function block( $order, $value ) {
		$req = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		if ( 'absent' !== $value ) {
			$req->set_param( 'additional_fields', array( Consent::FIELD_ID => $value ) );
		}
		do_action( 'woocommerce_store_api_checkout_update_order_from_request', $order, $req );
		$order->save();
	}

	/**
	 * Fresh read of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return \WC_Order
	 */
	private function fresh( $order ) {
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Request rows of an order.
	 *
	 * @param int $id Order.
	 * @return array<int,object>
	 */
	private function rows( $id ) {
		global $wpdb;
		$t = \NdvReviews\Support\Db::table( 'requests' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE order_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Queue for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return int|\WP_Error
	 */
	private function queue( $order ) {
		return $this->c()->get( 'scheduler' )->queue_for_order( $order->get_id(), array( 'source' => 'auto' ) );
	}

	// ------------------------------------------------------------------

	/**
	 * AC1.
	 *
	 * @return void
	 */
	private function ac1_off() {
		ob_start();
		do_action( 'woocommerce_after_order_notes', WC()->checkout() );
		$html = (string) ob_get_clean();
		$this->ok( false === strpos( $html, 'ndvr_review_consent' ), 'AC1: mode off prints no checkbox' );
		$registered = false;
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
			$fields     = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class )->get_additional_fields();
			$registered = isset( $fields[ Consent::FIELD_ID ] );
		}
		$this->ok( ! $registered, 'AC1: the block field is not registered when off' );
		$o = $this->order();
		$this->ok( ! is_wp_error( $this->queue( $o ) ), 'AC1: sends are unchanged' );
	}

	/**
	 * AC2.
	 *
	 * @return void
	 */
	private function ac2_classic_optin() {
		$this->set( array( 'consent_mode' => 'optin' ) );
		ob_start();
		do_action( 'woocommerce_after_order_notes', WC()->checkout() );
		$html = (string) ob_get_clean();
		$this->ok( false !== strpos( $html, 'name="ndvr_review_consent"' ) && false === strpos( $html, 'checked' ) && false !== strpos( $html, 'Email me a link to review my purchase' ) && false !== strpos( $html, 'name="ndvr_review_consent_shown" value="1"' ), 'AC2: an unticked checkbox with the default text and the hidden marker' );

		$yes = $this->order();
		$this->classic( $yes, true );
		$f = $this->fresh( $yes );
		$this->ok( 'yes' === $f->get_meta( Consent::META ) && '' !== $f->get_meta( Consent::META_AT ) && 'Email me a link to review my purchase' === $f->get_meta( Consent::META_TEXT ) && 'classic' === $f->get_meta( Consent::META_VIA ), 'AC2: ticked stores yes, time, text and classic' );
		$id = $this->queue( $f );
		$m  = $this->mail;
		if ( ! is_wp_error( $id ) ) {
			$this->c()->get( 'scheduler' )->process( (int) $id );
		}
		$this->ok( ! is_wp_error( $id ) && $m + 1 === $this->mail, 'AC2: and it is queued and sent' );

		$no = $this->order();
		$this->classic( $no, false );
		$res = $this->queue( $this->fresh( $no ) );
		$this->ok( 'no' === $this->fresh( $no )->get_meta( Consent::META ) && is_wp_error( $res ) && 'ndvr_no_consent' === $res->get_error_code() && ! $this->rows( $no->get_id() ), 'AC2: unticked stores no; queue_for_order → ndvr_no_consent, no row' );

		$none = $this->order();
		$this->classic( $none, null );
		$this->ok( '' === (string) $this->fresh( $none )->get_meta( Consent::META ), 'Classic: a checkout that did not show the box records nothing' );
	}

	/**
	 * AC3.
	 *
	 * @return void
	 */
	private function ac3_block_optin() {
		$o = $this->order();
		$this->block( $o, true );
		$f = $this->fresh( $o );
		$this->ok( 'yes' === $f->get_meta( Consent::META ) && 'block' === $f->get_meta( Consent::META_VIA ), 'AC3: block true records yes / block' );
		$o2 = $this->order();
		$this->block( $o2, false );
		$this->ok( 'no' === $this->fresh( $o2 )->get_meta( Consent::META ), 'AC3: block false records no' );
		$o3 = $this->order();
		$this->block( $o3, 'absent' );
		$this->ok( '' === (string) $this->fresh( $o3 )->get_meta( Consent::META ), 'AC3: no key and no _wc_other meta records nothing' );
		$o4 = $this->order();
		$o4->update_meta_data( Consent::WC_META, '1' );
		$o4->save();
		$this->block( $o4, 'absent' );
		$this->ok( '' === (string) $this->fresh( $o4 )->get_meta( Consent::META ), 'M1: a _wc_other value WooCommerce copied onto the order (no key in the request) records nothing' );
	}

	/**
	 * AC4.
	 *
	 * @return void
	 */
	private function ac4_optout() {
		$this->set( array( 'consent_mode' => 'optout' ) );
		$t = $this->order();
		$this->classic( $t, true );
		$r = $this->queue( $this->fresh( $t ) );
		$this->ok( 'no' === $this->fresh( $t )->get_meta( Consent::META ) && is_wp_error( $r ) && 'ndvr_no_consent' === $r->get_error_code(), 'AC4: opt-out ticked records no and is skipped' );
		$u = $this->order();
		$this->classic( $u, false );
		$this->ok( 'not_objected' === $this->fresh( $u )->get_meta( Consent::META ) && ! is_wp_error( $this->queue( $this->fresh( $u ) ) ), 'AC4: unticked records not_objected (never yes) and sends' );
		$n = $this->order();
		$this->ok( ! is_wp_error( $this->queue( $n ) ), 'AC4: an order with no record sends' );
		$this->saved['not_objected'] = $u->get_id();
	}

	/**
	 * AC5.
	 *
	 * @return void
	 */
	private function ac5_switch() {
		$order = wc_get_order( $this->saved['not_objected'] );
		global $wpdb;
		$wpdb->delete( \NdvReviews\Support\Db::table( 'requests' ), array( 'order_id' => $order->get_id() ) );
		foreach ( array( 'skip', 'send' ) as $legacy ) {
			$this->set(
				array(
					'consent_mode'          => 'optin',
					'consent_legacy_orders' => $legacy,
				)
			);
			$r = $this->queue( $order );
			$this->ok( ! $this->consent()->allows( $order ) && is_wp_error( $r ) && 'ndvr_no_consent' === $r->get_error_code(), "AC5: not_objected under opt-out isn't a yes under opt-in (legacy {$legacy})" );
		}
		$yes = $this->order();
		$this->classic( $yes, true );
		$this->ok( $this->consent()->allows( $this->fresh( $yes ) ), 'AC5: a yes still sends' );
		$this->set( array( 'consent_legacy_orders' => 'skip' ) );
	}

	/**
	 * AC6.
	 *
	 * @return void
	 */
	private function ac6_legacy() {
		update_option( Consent::ENABLED_OPTION, time() - HOUR_IN_SECONDS, false );
		$old = $this->order( time() - 5 * DAY_IN_SECONDS );
		$this->set( array( 'consent_legacy_orders' => 'skip' ) );
		$this->ok( 'legacy' === $this->consent()->status( $old ) && ! $this->consent()->allows( $old ), 'AC6: an order before consent was on, no record, "Don\'t send" → skipped' );
		$this->set( array( 'consent_legacy_orders' => 'send' ) );
		$this->ok( $this->consent()->allows( $old ), 'AC6: with "Send" → sent' );
		$this->set( array( 'consent_legacy_orders' => 'skip' ) );
		$admin = $this->order();
		$r     = $this->queue( $admin );
		$this->ok( is_wp_error( $r ) && 'ndvr_no_consent' === $r->get_error_code(), 'AC6: an admin-created order after it (no record) → ndvr_no_consent' );
	}

	/**
	 * AC7, AC8.
	 *
	 * @return void
	 */
	private function ac7_ac8_refusal() {
		$no = $this->order();
		$this->classic( $no, false );
		$this->set( array( 'consent_mode' => 'off' ) );
		$this->ok( ! $this->consent()->allows( $this->fresh( $no ) ), 'AC7: a recorded no still blocks with consent off' );
		do_action( 'woocommerce_order_action_ndvr_send_review_request', $this->fresh( $no ) );
		$notices = \NdvReviews\Requests\OrderActions::notices( get_current_user_id() );
		delete_transient( \NdvReviews\Requests\OrderActions::NOTICE_PREFIX . get_current_user_id() );
		$last = end( $notices );
		$this->ok( is_array( $last ) && false !== strpos( $last['text'], 'didn\'t agree to review emails' ) && ! $this->rows( $no->get_id() ), 'AC8: an RR-04 manual send reports "didn\'t agree to review emails", no row' );
		$this->saved['declined'] = $no->get_id();
		$this->set( array( 'consent_mode' => 'optin' ) );
	}

	/**
	 * AC9.
	 *
	 * @return void
	 */
	private function ac9_admin_lines() {
		$cases = array(
			'yes'          => 'Agreed at checkout on',
			'no'           => 'Declined at checkout on',
			'not_objected' => 'Didn\'t opt out at checkout on',
			'erased'       => 'Answer erased on request',
		);
		$all   = true;
		foreach ( $cases as $value => $want ) {
			$o = $this->order();
			$o->update_meta_data( Consent::META, $value );
			$o->update_meta_data( Consent::META_AT, gmdate( 'Y-m-d H:i:s' ) );
			$o->save();
			ob_start();
			do_action( 'woocommerce_admin_order_data_after_billing_address', $o );
			$out = (string) ob_get_clean();
			if ( false === strpos( $out, esc_html( $want ) ) ) {
				$all = false;
				$this->line( "INFO: {$value}: " . wp_strip_all_tags( $out ) );
			}
		}
		$legacy = $this->order( time() - 5 * DAY_IN_SECONDS );
		ob_start();
		do_action( 'woocommerce_admin_order_data_after_billing_address', $legacy );
		$l = (string) ob_get_clean();
		delete_option( Consent::ENABLED_OPTION );
		$none = $this->order();
		ob_start();
		do_action( 'woocommerce_admin_order_data_after_billing_address', $none );
		$n = (string) ob_get_clean();
		$this->ok( $all && false !== strpos( $l, 'Order placed before consent was turned on' ) && false !== strpos( $n, 'No answer recorded' ) && false !== strpos( $n, 'Review emails:' ), 'AC9: the six "Review emails:" lines' );

		$hidden = apply_filters( 'woocommerce_admin_shipping_fields', array( Consent::FIELD_ID => array( 'label' => 'x' ), 'company' => array() ), $none, 'edit' );
		$this->ok( ! isset( $hidden[ Consent::FIELD_ID ] ) && isset( $hidden['company'] ), 'Spike 4: WooCommerce\'s editable copy of the field is kept off the order screen' );
	}

	/**
	 * AC10.
	 *
	 * @return void
	 */
	private function ac10_privacy() {
		$o = $this->order();
		$this->classic( $o, true );
		$o = $this->fresh( $o );
		$o->update_meta_data( Consent::WC_META, '1' );
		$o->save();
		$email = $o->get_billing_email();
		$ex    = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$er    = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$this->ok( isset( $ex['ndv-reviews-consent'], $er['ndv-reviews-consent'] ), 'AC10: exporter and eraser are registered' );
		$out = call_user_func( $ex['ndv-reviews-consent']['callback'], $email, 1 );
		$this->ok( 1 === count( $out['data'] ) && 'Agreed' === $out['data'][0]['data'][1]['value'], 'AC10: one export item per answered order' );
		$no = $this->order();
		$no->update_meta_data( Consent::META, 'not_objected' );
		$no->save();
		$exp2 = call_user_func( $ex['ndv-reviews-consent']['callback'], $no->get_billing_email(), 1 );
		$this->ok( 'Did not object' === ( $exp2['data'][0]['data'][1]['value'] ?? '' ), 'AC9: the exporter labels not_objected "Did not object"' );

		call_user_func( $er['ndv-reviews-consent']['callback'], $email, 1 );
		$f = $this->fresh( $o );
		$this->ok( 'erased' === $f->get_meta( Consent::META ) && '' === (string) $f->get_meta( Consent::META_AT ) && '' === (string) $f->get_meta( Consent::META_TEXT ) && '' === (string) $f->get_meta( Consent::META_VIA ) && '' === (string) $f->get_meta( Consent::WC_META ), 'AC10: erasure sets erased and deletes the rest' );
		$this->set( array( 'consent_legacy_orders' => 'send' ) );
		$this->ok( ! $this->consent()->allows( $f ), 'AC10: an erased answer is skipped even with legacy "Send"' );
		$this->set( array( 'consent_legacy_orders' => 'skip' ) );
	}

	/**
	 * AC11.
	 *
	 * @return void
	 */
	private function ac11_enabled_at() {
		$this->set( array( 'consent_mode' => 'off' ) );
		delete_option( Consent::ENABLED_OPTION );
		$this->set( array( 'consent_mode' => 'optin' ) );
		$a = (int) get_option( Consent::ENABLED_OPTION );
		update_option( Consent::ENABLED_OPTION, $a - 100, false );
		$this->set( array( 'consent_mode' => 'optout' ) );
		$b = (int) get_option( Consent::ENABLED_OPTION );
		$this->set( array( 'consent_mode' => 'off' ) );
		$this->set( array( 'consent_mode' => 'optin' ) );
		$c = (int) get_option( Consent::ENABLED_OPTION );
		$this->ok( $a > 0 && $a - 100 === $b && $c >= $a, 'AC11: set on off → opt-in, unchanged on opt-in → opt-out, set again on off → opt-in' );
	}

	/**
	 * AC12.
	 *
	 * @return void
	 */
	private function ac12_pro_origin() {
		$order = wc_get_order( $this->saved['declined'] );
		$r     = $this->c()->get( 'mailer' )->check_eligibility( $order, array( 'stage' => 'send', 'origin' => 'pro' ) );
		$this->ok( is_wp_error( $r ) && 'ndvr_no_consent' === $r->get_error_code(), 'AC12: check_eligibility( send, pro ) → ndvr_no_consent' );
	}

	/**
	 * AC13.
	 *
	 * @return void
	 */
	private function ac13_block_support() {
		$this->ok( ! Consent::block_field_supported( '8.8.0' ) && Consent::block_field_supported( '8.9.0' ), 'AC13: block support from 8.9' );
		$page                = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'RR07 checkout',
				'post_content' => '<!-- wp:woocommerce/checkout /-->',
			)
		);
		$this->fx['posts'][] = $page;
		$pid                 = static function () use ( $page ) {
			return $page;
		};
		$stub                = static function () {
			return false;
		};
		add_filter( 'woocommerce_get_checkout_page_id', $pid );
		add_filter( 'ndv-reviews/consent_block_supported', $stub );
		ob_start();
		$this->consent()->render_mode( 'optin' );
		$html = (string) ob_get_clean();
		remove_filter( 'ndv-reviews/consent_block_supported', $stub );
		ob_start();
		$this->consent()->render_mode( 'optin' );
		$ok = (string) ob_get_clean();
		remove_filter( 'woocommerce_get_checkout_page_id', $pid );
		$this->ok( false !== strpos( $html, 'needs WooCommerce 8.9 or later' ) && false === strpos( $ok, 'needs WooCommerce 8.9' ), 'AC13: the warning shows only when the block field is unsupported and checkout uses the block' );
	}

	/**
	 * AC14.
	 *
	 * @return void
	 */
	private function ac14_save() {
		$page = $this->c()->get( 'admin_requests_page' );
		$s    = $this->c()->get( 'settings' );
		$_GET = array( 'page' => \NdvReviews\Admin\RequestsPage::PAGE_SLUG );
		$_POST = array(
			'ndvr_requests_do'      => 'save',
			'_wpnonce'              => wp_create_nonce( \NdvReviews\Admin\RequestsPage::NONCE ),
			'reminder_enabled'      => '1',
			'reminder_status'       => (string) $s->get( 'reminder_status', 'completed' ),
			'reminder_delay_days'   => '0',
			'token_expiry_days'     => (string) $s->get( 'token_expiry_days', 60 ),
			'consent_mode'          => 'yes_please',
			'consent_label_optin'   => str_repeat( 'a', 300 ),
			'consent_label_optout'  => '',
			'consent_legacy_orders' => 'maybe',
			'ndvr_fields'           => array( 'consent_mode', 'consent_label_optin', 'consent_label_optout', 'consent_legacy_orders' ),
		);
		$_REQUEST = $_POST;
		$page->handle_actions();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$this->ok( 'off' === $s->get( 'consent_mode' ) && 200 === strlen( (string) $s->get( 'consent_label_optin' ) ) && 'skip' === $s->get( 'consent_legacy_orders' ), 'AC14: a bogus mode stores off; a 300-character label is cut to 200; a bogus legacy value stores skip' );
		$this->set( array( 'consent_mode' => 'optin', 'consent_label_optin' => '' ) );
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->ok( false !== strpos( $html, 'name="consent_mode"' ) && false !== strpos( $html, 'name="consent_legacy_orders"' ) && 1 === substr_count( $html, 'value="consent_mode"' ), 'Screen: the consent rows and one save marker each' );
	}

	/**
	 * AC15: no customer-side copy.
	 *
	 * @return void
	 */
	private function ac15_customer_copy() {
		$uid                 = (int) wp_insert_user(
			array(
				'user_login' => 'rr07c' . wp_rand( 1000, 9999 ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'rr07c' . wp_rand( 1000, 9999 ) . '@example.invalid',
				'role'       => 'customer',
			)
		);
		$this->fx['users'][] = $uid;
		$prev_user           = get_current_user_id();
		wp_set_current_user( $uid );
		if ( ! WC()->session ) {
			WC()->initialize_session();
		}
		$prev_customer  = WC()->customer;
		WC()->customer  = new \WC_Customer( $uid );
		WC()->customer->update_meta_data( Consent::WC_META, '1' );
		WC()->customer->save();
		do_action( 'woocommerce_store_api_checkout_update_draft', new \WP_REST_Request( 'PUT', '/wc/store/v1/checkout' ) );
		$meta  = get_user_meta( $uid, Consent::WC_META, true );
		$draft = WC()->session ? WC()->session->get( Consent::SESSION_KEY, null ) : null;
		$this->ok( '' === $meta, 'AC15: the customer-side copy is deleted on the draft PATCH' );
		$this->ok( true === $draft && '1' === $this->consent()->default_value( null, 'other', WC()->customer ), 'AC15: the open checkout keeps the choice through the session' );
		WC()->session->set( Consent::SESSION_KEY, null );
		$this->ok( null === $this->consent()->default_value( null, 'other', WC()->customer ), 'AC15: a new session has no value, so the box starts unticked' );
		$this->line( 'SKIP: AC15 GET /wc/store/v1/checkout round trip (needs a cart session in a real browser request; Build spike 6).' );
		WC()->customer = $prev_customer;
		wp_set_current_user( $prev_user );
	}

	/**
	 * AC16: the recorded label is the one registered for the block.
	 *
	 * @return void
	 */
	private function ac16_label() {
		$c   = new Consent( $this->c()->get( 'settings' ) );
		$tr  = static function ( $value, $key ) {
			return 'consent_label_optin' === $key ? 'A' : $value;
		};
		add_filter( 'ndv-reviews/translate_setting', $tr, 10, 2 );
		$ref = new \ReflectionProperty( $c, 'block_label' );
		$ref->setAccessible( true );
		$ref->setValue( $c, $c->label( 'optin' ) );
		remove_filter( 'ndv-reviews/translate_setting', $tr, 10 );
		$tr2 = static function ( $value, $key ) {
			return 'consent_label_optin' === $key ? 'B' : $value;
		};
		add_filter( 'ndv-reviews/translate_setting', $tr2, 10, 2 );
		$o   = $this->order();
		$req = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$req->set_param( 'additional_fields', array( Consent::FIELD_ID => true ) );
		$c->save_block( $o, $req );
		$o->save();
		remove_filter( 'ndv-reviews/translate_setting', $tr2, 10 );
		$this->ok( 'A' === $this->fresh( $o )->get_meta( Consent::META_TEXT ), 'AC16: the block record stores the label registered on woocommerce_init ("A")' );
	}

	/**
	 * RR-03 facts.
	 *
	 * @return void
	 */
	private function transparency() {
		$this->set( array( 'consent_mode' => 'optin', 'consent_legacy_orders' => 'skip' ) );
		$f = $this->c()->get( 'transparency' )->facts( 0 );
		$this->ok( 'optin' === $f['consent'] && 'skip' === $f['consent_legacy'], 'Transparency: consent facts follow the settings' );
	}

	/**
	 * Code review M2, M3, m1, m2, m4, m5 and harness gaps.
	 *
	 * @return void
	 */
	private function review_fixes() {
		$this->set( array( 'consent_mode' => 'optin' ) );

		// m1: no "(optional)" next to the classic box.
		ob_start();
		do_action( 'woocommerce_after_order_notes', WC()->checkout() );
		$html = (string) ob_get_clean();
		$this->ok( false !== strpos( $html, 'name="ndvr_review_consent"' ) && false === strpos( $html, 'class="optional"' ), 'm1: the classic checkbox shows no "(optional)", so the recorded text is what was shown' );

		// M2a: WooCommerce's own order eraser path erases our answer too.
		$o = $this->order();
		$this->classic( $o, true );
		do_action( 'woocommerce_privacy_before_remove_order_personal_data', $this->fresh( $o ) );
		$f = $this->fresh( $o );
		$this->ok( 'erased' === $f->get_meta( Consent::META ) && '' === (string) $f->get_meta( Consent::META_TEXT ), 'M2: WooCommerce\'s order eraser hook erases the answer' );

		// M2b: an account's orders with another billing email are found.
		$uid                 = (int) wp_insert_user(
			array(
				'user_login' => 'rr07e' . wp_rand( 1000, 9999 ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'rr07e' . wp_rand( 1000, 9999 ) . '@example.invalid',
				'role'       => 'customer',
			)
		);
		$this->fx['users'][] = $uid;
		$acc                 = wc_create_order( array( 'customer_id' => $uid ) );
		$acc->add_product( wc_get_product( $this->product ), 1 );
		$acc->set_billing_email( 'other-billing-' . wp_rand( 1000, 9999 ) . '@example.invalid' );
		$acc->update_meta_data( Consent::META, 'yes' );
		$acc->save();
		$this->fx['orders'][] = (int) $acc->get_id();
		$er                   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$res                  = call_user_func( $er['ndv-reviews-consent']['callback'], get_userdata( $uid )->user_email, 1 );
		$this->ok( 'erased' === $this->fresh( $acc )->get_meta( Consent::META ) && ! empty( $res['items_removed'] ), 'M2: the eraser also finds the account\'s orders under another billing email' );

		// M3: no customer-side copy can be stored.
		update_user_meta( $uid, Consent::WC_META, '1' );
		$keys = apply_filters( 'woocommerce_customer_allowed_session_meta_keys', array( Consent::WC_META, 'x' ), null );
		$this->ok( '' === get_user_meta( $uid, Consent::WC_META, true ) && ! in_array( Consent::WC_META, $keys, true ) && in_array( 'x', $keys, true ), 'M3: our field is never written to user meta or kept in the session' );

		// m4: the session value goes back to WooCommerce as '1' / '0'.
		if ( ! WC()->session ) {
			WC()->initialize_session();
		}
		WC()->session->set( Consent::SESSION_KEY, true );
		$v = $this->consent()->default_value( null, 'other', new \WC_Customer() );
		WC()->session->set( Consent::SESSION_KEY, null );
		$this->ok( '1' === $v, 'm4: default_value() returns \'1\'' );

		// m2: no dangling "on" without a time; the store time zone is used.
		$y = $this->order();
		$y->update_meta_data( Consent::META, 'yes' );
		$y->save();
		ob_start();
		$this->consent()->render_admin_line( $this->fresh( $y ) );
		$line = wp_strip_all_tags( (string) ob_get_clean() );
		$this->ok( false !== strpos( $line, 'Agreed at checkout' ) && false === strpos( $line, ' on ' ), 'm2: an answer without a time says "Agreed at checkout" with no dangling "on"' );
		$tz = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Pacific/Auckland' );
		$y->update_meta_data( Consent::META_AT, '2026-01-01 23:30:00' );
		$y->save();
		ob_start();
		$this->consent()->render_admin_line( $this->fresh( $y ) );
		$line = wp_strip_all_tags( (string) ob_get_clean() );
		update_option( 'timezone_string', $tz );
		$this->ok( false !== strpos( $line, '2026' ) && ( false !== strpos( $line, 'January 2, 2026' ) || false !== strpos( $line, '2026-01-02' ) || false !== strpos( $line, '02/01/2026' ) || false !== strpos( $line, '01/02/2026' ) ), 'm2: the date is in the store time zone (' . $line . ')' );

		// m5: a store that never turned consent on shows no line.
		$this->set( array( 'consent_mode' => 'off' ) );
		$saved = get_option( Consent::ENABLED_OPTION, null );
		delete_option( Consent::ENABLED_OPTION );
		ob_start();
		$this->consent()->render_admin_line( $this->order() );
		$none = (string) ob_get_clean();
		if ( null !== $saved ) {
			update_option( Consent::ENABLED_OPTION, $saved, false );
		}
		$this->ok( '' === $none, 'm5: no "Review emails:" line when consent was never turned on' );

		// First-ever save (the settings option doesn't exist yet).
		$all = get_option( NDVR_OPTION_SETTINGS, null );
		delete_option( NDVR_OPTION_SETTINGS );
		delete_option( Consent::ENABLED_OPTION );
		$this->c()->get( 'settings' )->update( array( 'consent_mode' => 'optin' ) );
		$first = (int) get_option( Consent::ENABLED_OPTION, 0 );
		if ( null === $all ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $all );
		}
		$this->ok( $first > 0, 'The first-ever settings save that turns consent on records when' );

		// The legacy direct send path refuses a declined order too.
		$this->set( array( 'consent_mode' => 'optin' ) );
		$declined = wc_get_order( $this->saved['declined'] );
		$r        = $this->c()->get( 'mailer' )->send_for_order( $declined->get_id() );
		$this->ok( is_wp_error( $r ) && 'ndvr_no_consent' === $r->get_error_code(), 'Legacy send_for_order() on a declined order → ndvr_no_consent' );
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
		foreach ( $this->fx['products'] as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $this->fx['posts'] as $id ) {
			wp_delete_post( $id, true );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->fx['users'] as $id ) {
			wp_delete_user( $id );
		}
		if ( null === $this->saved['settings'] ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved['settings'] );
		}
		if ( null === $this->saved['enabled'] ) {
			delete_option( Consent::ENABLED_OPTION );
		} else {
			update_option( Consent::ENABLED_OPTION, $this->saved['enabled'], false );
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

( new NDVR_QA_RR07() )->run();
