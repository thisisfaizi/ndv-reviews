<?php
/**
 * RR-05 acceptance harness (PRD .agents/prd/RR-05-request-exclusions.md §12).
 *
 *     php boot.php run .agents/qa/rr-05.php 1   (QA site, D:/.devcache/qa-site)
 *
 * Mail is captured with pre_wp_mail. Settings, roles and fixtures are
 * restored/removed.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification -- QA script, never shipped.

defined( 'ABSPATH' ) || exit;

/**
 * The harness.
 */
final class NDVR_QA_RR05 {

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
		'terms'    => array(),
		'users'    => array(),
	);

	/**
	 * Saved settings.
	 *
	 * @var mixed
	 */
	private $saved;

	/**
	 * Captured mail bodies.
	 *
	 * @var string[]
	 */
	private $mail = array();

	/**
	 * Ids.
	 *
	 * @var array<string,int>
	 */
	private $id = array();

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! method_exists( '\NdvReviews\Collection\Reviewable', 'is_excluded_product' ) || ! function_exists( 'wc_create_order' ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-05 and WooCommerce must be active.' );
			return false;
		}
		$this->setup();
		try {
			$this->ac1_ac4_ac6();
			$this->ac2_old_token();
			$this->ac3_nothing_left();
			$this->ac5_ac7_roles();
			$this->ac8_pro_origin();
			$this->ac9_save();
			$this->ac10_cache();
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
	 * Fixtures.
	 *
	 * @return void
	 */
	private function setup() {
		$this->saved = get_option( NDVR_OPTION_SETTINGS, null );
		$this->set( array() );
		$this->c()->get( 'settings' )->update(
			array(
				'reminder_enabled'    => false,
				'reminder_delay_days' => 0,
			)
		);
		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) {
				$this->mail[] = (string) $atts['message'];
				return true;
			},
			1,
			2
		);

		$parent = wp_insert_term( 'RR05 Gift cards ' . wp_rand( 100, 999 ), 'product_cat' );
		$child  = wp_insert_term( 'RR05 Gift child ' . wp_rand( 100, 999 ), 'product_cat', array( 'parent' => (int) $parent['term_id'] ) );
		$this->id['parent']  = (int) $parent['term_id'];
		$this->id['child']   = (int) $child['term_id'];
		$this->fx['terms']   = array( $this->id['child'], $this->id['parent'] );
		$this->id['gift']    = $this->product( 'RR05 Gift card', array( $this->id['parent'] ) );
		$this->id['mug']     = $this->product( 'RR05 Blue mug', array() );
		$this->id['childp']  = $this->product( 'RR05 Child-category voucher', array( $this->id['child'] ) );

		add_role( 'rr05_wholesale', 'RR05 Wholesale', array( 'read' => true ) );
		$this->id['user'] = (int) wp_insert_user(
			array(
				'user_login' => 'rr05w' . wp_rand( 1000, 9999 ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'rr05w' . wp_rand( 1000, 9999 ) . '@example.invalid',
				'role'       => 'rr05_wholesale',
			)
		);
		$this->fx['users'][] = $this->id['user'];
	}

	/**
	 * A product in categories.
	 *
	 * @param string $name Name.
	 * @param int[]  $cats Category ids.
	 * @return int
	 */
	private function product( $name, array $cats ) {
		$p = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$p->set_reviews_allowed( true );
		if ( $cats ) {
			$p->set_category_ids( $cats );
		}
		$id                     = (int) $p->save();
		$this->fx['products'][] = $id;

		return $id;
	}

	/**
	 * An order.
	 *
	 * @param int[] $products Products.
	 * @param int   $user     Customer.
	 * @return \WC_Order
	 */
	private function order( array $products, $user = 0 ) {
		$order = wc_create_order( array( 'customer_id' => (int) $user ) );
		foreach ( $products as $pid ) {
			$order->add_product( wc_get_product( $pid ), 1 );
		}
		$order->set_billing_first_name( 'QA' );
		$order->set_billing_email( 'rr05-' . wp_generate_password( 6, false, false ) . '@example.invalid' );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();

		return $order;
	}

	/**
	 * Set the exclusions.
	 *
	 * @param array<string,array> $v Keys without the prefix: cats, products, roles.
	 * @return void
	 */
	private function set( array $v ) {
		$this->c()->get( 'settings' )->update(
			array(
				'reminder_exclude_cats'     => $v['cats'] ?? array(),
				'reminder_exclude_products' => $v['products'] ?? array(),
				'reminder_exclude_roles'    => $v['roles'] ?? array(),
			)
		);
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
	 * AC1, AC4, AC6.
	 *
	 * @return void
	 */
	private function ac1_ac4_ac6() {
		$this->set( array( 'cats' => array( $this->id['parent'] ) ) );
		$order = $this->order( array( $this->id['gift'], $this->id['mug'] ) );
		$rev   = $this->c()->get( 'reviewable' );
		$this->ok( array( $this->id['mug'] ) === $rev->for_order( $order ), 'AC1: for_order() keeps only the mug' );

		$id = $this->c()->get( 'scheduler' )->queue_for_order( $order->get_id(), array( 'source' => 'manual' ) );
		$this->mail = array();
		$this->c()->get( 'scheduler' )->process( (int) $id );
		$body = implode( "\n", $this->mail );
		$this->ok( 1 === count( $this->mail ) && false !== strpos( $body, 'RR05 Blue mug' ) && false === strpos( $body, 'RR05 Gift card' ), 'AC1: the email names the mug and not the gift card' );

		$this->ok( $rev->is_excluded_product( $this->id['childp'] ), 'AC4: a product in a child of an excluded category is excluded' );
		$this->ok( ! $rev->is_excluded_product( $this->id['mug'] ), 'AC4: an uncategorised product is not' );

		$guest = $this->order( array( $this->id['mug'] ) );
		$this->set( array( 'roles' => array( 'rr05_wholesale' ) ) );
		$this->ok( ! $rev->is_excluded_customer( $guest ) && array( $this->id['mug'] ) === $rev->for_order( $guest ), 'AC6: a guest order is never role-excluded' );
		$this->set( array() );
	}

	/**
	 * AC2: a link sent before the exclusion.
	 *
	 * @return void
	 */
	private function ac2_old_token() {
		global $wpdb;
		$order = $this->order( array( $this->id['gift'], $this->id['mug'] ) );
		$id    = $this->c()->get( 'scheduler' )->queue_for_order( $order->get_id(), array( 'source' => 'manual' ) );
		$this->c()->get( 'scheduler' )->process( (int) $id );
		$t   = \NdvReviews\Support\Db::table( 'review_tokens' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order->get_id() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->ok( (bool) $row, 'AC2: the earlier email minted a token' );
		if ( ! $row ) {
			return;
		}
		$landing = $this->c()->get( 'landing' );
		$pending = new \ReflectionMethod( $landing, 'pending_products' );
		$pending->setAccessible( true );
		$tokenp = new \ReflectionMethod( $landing, 'token_products' );
		$tokenp->setAccessible( true );
		$before = $pending->invoke( $landing, $row );
		sort( $before );
		$both = array( $this->id['gift'], $this->id['mug'] );
		sort( $both );
		$this->ok( $both === $before, 'AC2: before the exclusion the link covers both products' );

		$this->set( array( 'cats' => array( $this->id['parent'] ) ) );
		$this->ok( array( $this->id['mug'] ) === $pending->invoke( $landing, $row ), 'AC2: after it, the pending list is only the mug' );
		$this->ok( ! in_array( $this->id['gift'], $tokenp->invoke( $landing, $row ), true ), 'AC2: the gift card is no longer "part of your review link" (submit refuses it)' );
		$this->set( array() );
	}

	/**
	 * AC3: nothing left to review.
	 *
	 * @return void
	 */
	private function ac3_nothing_left() {
		$sched = $this->c()->get( 'scheduler' );

		$this->set( array( 'cats' => array( $this->id['parent'] ) ) );
		$only = $this->order( array( $this->id['gift'] ) );
		$res  = $sched->queue_for_order( $only->get_id(), array( 'source' => 'auto' ) );
		$this->ok( is_wp_error( $res ) && 'ndvr_nothing_to_review' === $res->get_error_code() && ! $this->rows( $only->get_id() ), 'AC3a: an order with only excluded products gets ndvr_nothing_to_review and no row' );

		$this->set( array() );
		$before = $this->order( array( $this->id['gift'] ) );
		$id     = $sched->queue_for_order( $before->get_id(), array( 'source' => 'auto', 'delay' => 3600 ) );
		$this->set( array( 'products' => array( $this->id['gift'] ) ) );
		$this->mail = array();
		$sched->process( (int) $id );
		$row = $this->rows( $before->get_id() );
		$this->ok( ! is_wp_error( $id ) && $row && 'cancelled' === $row[0]->status && 'No reviewable products in this order.' === $row[0]->error && ! $this->mail, 'AC3b: queued before the exclusion → cancelled "No reviewable products in this order.", no email' );
		$this->set( array() );
	}

	/**
	 * AC5, AC7.
	 *
	 * @return void
	 */
	private function ac5_ac7_roles() {
		$this->set( array( 'roles' => array( 'rr05_wholesale' ) ) );
		$order = $this->order( array( $this->id['mug'] ), $this->id['user'] );
		$res   = $this->c()->get( 'scheduler' )->queue_for_order( $order->get_id(), array( 'source' => 'auto' ) );
		$this->ok( is_wp_error( $res ) && 'ndvr_customer_excluded' === $res->get_error_code() && ! $this->rows( $order->get_id() ), 'AC5: a wholesale customer\'s order gets ndvr_customer_excluded and no row' );

		if ( $this->c()->has( 'order_actions' ) ) {
			do_action( 'woocommerce_order_action_ndvr_send_review_request', $order );
			$notices = \NdvReviews\Requests\OrderActions::notices( get_current_user_id() );
			delete_transient( \NdvReviews\Requests\OrderActions::NOTICE_PREFIX . get_current_user_id() );
			$last = end( $notices );
			$this->ok( is_array( $last ) && false !== strpos( $last['text'], 'customer role excluded' ), 'AC5: an RR-04 manual send reports "customer role excluded"' );
		}

		$check = $this->c()->get( 'mailer' )->check_eligibility(
			$order,
			array(
				'stage'  => 'send',
				'origin' => 'pro',
			)
		);
		$this->ok( is_wp_error( $check ) && 'ndvr_customer_excluded' === $check->get_error_code(), 'AC7: check_eligibility( stage send, origin pro ) → ndvr_customer_excluded' );
		$this->mail = array();
		$direct     = $this->c()->get( 'mailer' )->send_for_order( $order->get_id() );
		$this->ok( is_wp_error( $direct ) && 'ndvr_customer_excluded' === $direct->get_error_code() && ! $this->mail, 'AC7: the legacy direct send_for_order() returns it without sending' );
		$this->set( array() );
	}

	/**
	 * AC8: a Pro-origin queue.
	 *
	 * @return void
	 */
	private function ac8_pro_origin() {
		$this->set( array( 'products' => array( $this->id['gift'] ) ) );
		$order = $this->order( array( $this->id['gift'] ) );
		$res   = $this->c()->get( 'scheduler' )->queue_for_order(
			$order->get_id(),
			array(
				'source' => 'auto',
				'origin' => 'pro',
			)
		);
		$this->ok( is_wp_error( $res ) && 'ndvr_nothing_to_review' === $res->get_error_code() && ! $this->rows( $order->get_id() ), 'AC8: origin pro → ndvr_nothing_to_review, no row' );
		$this->line( 'SKIP: AC8 second half (Pro Engine through RR-06P is not built yet).' );
		$this->set( array() );
	}

	/**
	 * AC9: the Reminders save sanitizes.
	 *
	 * @return void
	 */
	private function ac9_save() {
		$page = $this->c()->get( 'admin_requests_page' );
		$s    = $this->c()->get( 'settings' );
		$base = array(
			'ndvr_requests_do'    => 'save',
			'_wpnonce'            => wp_create_nonce( 'ndvr_requests' ),
			'reminder_status'     => (string) $s->get( 'reminder_status', 'completed' ),
			'reminder_delay_days' => '0',
			'token_expiry_days'   => (string) $s->get( 'token_expiry_days', 60 ),
			'ndvr_fields'         => array( 'reminder_exclude_cats', 'reminder_exclude_products', 'reminder_exclude_roles' ),
		);
		$ref = new \ReflectionClass( $page );
		if ( $ref->hasConstant( 'NONCE' ) ) {
			$base['_wpnonce'] = wp_create_nonce( $ref->getConstant( 'NONCE' ) );
		}

		$_GET     = array( 'page' => \NdvReviews\Admin\RequestsPage::PAGE_SLUG );
		$_POST    = $base + array(
			'reminder_exclude_cats'     => array( '0', '-3', 'abc', (string) $this->id['parent'], '999999999' ),
			'reminder_exclude_products' => array( '0', (string) $this->id['mug'], (string) $this->fx['users'][0], 'x' ),
			'reminder_exclude_roles'    => array( 'not_a_role', 'rr05_wholesale', '<b>' ),
		);
		$_REQUEST = $_POST;
		$page->handle_actions();
		$this->ok( array( $this->id['parent'] ) === array_values( (array) $s->get( 'reminder_exclude_cats' ) ), 'AC9: only the real category id is stored' );
		$this->ok( array( $this->id['mug'] ) === array_values( (array) $s->get( 'reminder_exclude_products' ) ), 'AC9: only the real product id is stored' );
		$this->ok( array( 'rr05_wholesale' ) === array_values( (array) $s->get( 'reminder_exclude_roles' ) ), 'AC9: only the real role is stored' );

		$_POST    = $base;
		$_REQUEST = $_POST;
		$page->handle_actions();
		$this->ok( array() === $s->get( 'reminder_exclude_cats' ) && array() === $s->get( 'reminder_exclude_products' ) && array() === $s->get( 'reminder_exclude_roles' ), 'AC9: saving with nothing selected stores [] for all three' );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->ok( false !== strpos( $html, 'Who and what to ask about' ) && false !== strpos( $html, 'name="reminder_exclude_cats[]"' ) && false !== strpos( $html, 'data-action="woocommerce_json_search_products"' ) && false !== strpos( $html, 'name="reminder_exclude_roles[]"' ), 'Screen: the three inputs render under "Who and what to ask about"' );
		$this->ok( 1 === substr_count( $html, 'value="reminder_exclude_cats"' ), 'Screen: the save marker is printed once' );
	}

	/**
	 * AC10: the cache follows the settings in one request.
	 *
	 * @return void
	 */
	private function ac10_cache() {
		$rev   = $this->c()->get( 'reviewable' );
		$order = $this->order( array( $this->id['gift'], $this->id['mug'] ) );
		$this->set( array() );
		$a = $rev->for_order( $order );
		$this->set( array( 'products' => array( $this->id['mug'] ) ) );
		$b = $rev->for_order( $order );
		sort( $a );
		$both = array( $this->id['gift'], $this->id['mug'] );
		sort( $both );
		$this->ok( $both === $a && array( $this->id['gift'] ) === $b, 'AC10: a settings change applies in the same request' );
		$this->set( array() );
	}

	/**
	 * Code review R5-1..R5-3.
	 *
	 * @return void
	 */
	private function review_fixes() {
		$rev = $this->c()->get( 'reviewable' );

		// R5-1: an excluded product doesn't claim the pool it shares.
		$pool = function ( $id ) {
			return (int) $id === $this->id['mug'] ? $this->id['gift'] : $id;
		};
		add_filter( 'ndv-reviews/review_pool_id', $pool, 10, 1 );
		$this->set( array( 'products' => array( $this->id['gift'] ) ) );
		$order = $this->order( array( $this->id['gift'], $this->id['mug'] ) );
		$got   = $rev->for_order( $order );
		remove_filter( 'ndv-reviews/review_pool_id', $pool, 10 );
		$this->set( array() );
		$this->ok( array( $this->id['mug'] ) === $got, 'R5-1: when the excluded product comes first, the product sharing its reviews is still asked about' );

		// R5-2: "-N" never becomes N; nested input is ignored without a warning.
		$warn = false;
		set_error_handler(
			static function () use ( &$warn ) {
				$warn = true;
				return true;
			}
		);
		$prods = \NdvReviews\Requests\Exclusions::sanitize_products( array( '-' . $this->id['mug'], array( 'x' ), (string) $this->id['gift'], '1e3', ' 5' ) );
		$cats  = \NdvReviews\Requests\Exclusions::sanitize_cats( array( '-' . $this->id['parent'], array( 1 ) ) );
		$roles = \NdvReviews\Requests\Exclusions::sanitize_roles( array( array( 'administrator' ), 'rr05_wholesale' ) );
		restore_error_handler();
		$this->ok( array( $this->id['gift'] ) === $prods && array() === $cats && array( 'rr05_wholesale' ) === $roles && ! $warn, 'R5-2: "-id", nested arrays and non-digit strings are dropped, with no warning' );

		// R5-3: the heading row is valid table HTML; the roles fieldset has a legend.
		ob_start();
		\NdvReviews\Requests\Exclusions::render_heading();
		( new \NdvReviews\Requests\Exclusions() )->render_roles( array() );
		$html = (string) ob_get_clean();
		$this->ok( ! preg_match( '#<th[^>]*>\s*<h3#', $html ) && false !== strpos( $html, '<legend' ), 'R5-3: no <h3> inside a <th>; the roles fieldset has a legend' );

		// Preview and test email leave excluded products out.
		$this->set( array( 'products' => array( $this->id['gift'] ) ) );
		$latest = $this->order( array( $this->id['gift'], $this->id['mug'] ) );
		$latest->set_status( 'completed' );
		$latest->save();
		$p = $this->c()->get( 'mailer' )->preview( array() );
		$this->set( array() );
		$this->ok( false === strpos( $p['html'], 'RR05 Gift card' ) && false !== strpos( $p['html'], 'RR05 Blue mug' ), 'The preview built from the latest order leaves the excluded product out' );
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
		foreach ( $this->fx['terms'] as $id ) {
			wp_delete_term( $id, 'product_cat' );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->fx['users'] as $id ) {
			wp_delete_user( $id );
		}
		remove_role( 'rr05_wholesale' );
		if ( null === $this->saved ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved );
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

( new NDVR_QA_RR05() )->run();
