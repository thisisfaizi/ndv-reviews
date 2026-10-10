<?php
/**
 * RR-09 acceptance harness (PRD .agents/prd/RR-09-request-pipeline-v2.md §12).
 *
 * Run inside WordPress with WooCommerce and Rosette Reviews active, on a TEST
 * site, as an administrator:
 *
 *     php boot.php run .agents/qa/rr-09.php 1      (QA site, D:/.devcache/qa-site)
 *     wp eval-file .agents/qa/rr-09.php --user=1   (WP-CLI)
 *
 * Page views that end in exit() (the landing page, the open pixel) run in a
 * child PHP process through NDVR_QA_BOOT (the QA site's boot.php); without it
 * those checks are skipped. Mail is captured, fixtures removed, settings and
 * the DB version restored.
 *
 * Not shipped: `.agents` is in .distignore.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.PHP.DiscouragedPHPFunctions -- QA script, never shipped.

use NdvReviews\Installer;
use NdvReviews\Requests\Scheduler;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NDVR_QA_BOOT' ) && file_exists( dirname( ABSPATH ) . '/boot.php' ) ) {
	define( 'NDVR_QA_BOOT', dirname( ABSPATH ) . '/boot.php' );
}

/**
 * Thrown by the die handler so wp_send_json() returns here.
 */
final class NDVR_QA_RR09_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR09 {

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
	 * Captured mails.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $mails = array();

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
	 * Container services.
	 *
	 * @var object
	 */
	private $scheduler;

	/**
	 * Request repository.
	 *
	 * @var \NdvReviews\Requests\RequestRepository
	 */
	private $requests;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! defined( 'NDVR_API' ) || ! class_exists( '\NdvReviews\Requests\Tracking' ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-09 must be active.' );
			return false;
		}

		$c               = \NdvReviews\Plugin::instance()->container();
		$this->scheduler = $c->get( 'scheduler' );
		$this->requests  = $c->get( 'request_repository' );

		$this->saved['settings'] = get_option( NDVR_OPTION_SETTINGS, false );
		$this->saved['db']       = get_option( NDVR_OPTION_DB_VERSION, false );
		add_filter( 'pre_wp_mail', array( $this, 'capture' ), 1, 2 );
		$this->set_settings(
			array(
				'reminder_enabled'    => true,
				'reminder_status'     => 'completed',
				'reminder_delay_days' => 7,
				'reminder_utm'        => false,
				'reminder_open_pixel' => false,
			)
		);

		try {
			$this->ok( (int) NDVR_API >= 3, 'AC15: NDVR_API is at least 3 (RR-09)' );
			$this->ac1_upgrade();
			$this->ac3_dedupe_and_delay();
			$this->ac2_claim();
			$this->ac4_cooldown();
			$this->ac5_not_ready();
			$this->ac6_tracking();
			$this->ac7_list_rows();
			$this->ac8_ac9_utm_pixel();
			$this->ac10_legacy();
			$this->ac11_erasure();
			$this->ac12_conversion();
			$this->ac13_orphans();
			$this->ac14_settings();
			$this->review_fixes();
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
		}

		$this->cleanup();
		remove_filter( 'pre_wp_mail', array( $this, 'capture' ), 1 );
		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->count['fail'] ? 'FAIL' : 'PASS', $this->count['pass'], $this->count['fail'] ) );

		return 0 === $this->count['fail'];
	}

	/* ------------------------------------------------------------------ */

	/**
	 * AC1: v3 -> v4 on a front-end init; old rows become legacy; dbDelta idempotent.
	 *
	 * @return void
	 */
	private function ac1_upgrade() {
		global $wpdb;
		$table = $wpdb->prefix . 'ndvr_requests';

		$wpdb->insert(
			$table,
			array(
				'order_id'     => 999999,
				'email'        => 'old@example.invalid',
				'channel'      => 'email',
				'status'       => 'sent',
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
			)
		);
		$old = (int) $wpdb->insert_id;

		update_option( NDVR_OPTION_DB_VERSION, '3' );
		Installer::maybe_upgrade();
		$this->ok( (int) NDVR_DB_VERSION === (int) get_option( NDVR_OPTION_DB_VERSION ), 'AC1: v3 upgrades to the code version (v4+)' );

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$need = array( 'source', 'origin', 'dedupe_key', 'token_id', 'claimed_at', 'opened_at', 'reviewed_at', 'meta' );
		$this->ok( ! array_diff( $need, $cols ), 'AC1: every v4 column exists', implode( ',', array_diff( $need, $cols ) ) );

		$row = $this->requests->find( $old );
		$this->ok( $row && 'legacy' === $row->source && 'free' === $row->origin, 'AC1: an old row reads source=legacy, origin=free' );
		$wpdb->delete( $table, array( 'id' => $old ) );

		$queries = array();
		$rec     = function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		add_filter( 'query', $rec );
		Installer::install();
		remove_filter( 'query', $rec );
		$this->ok( 0 === count( preg_grep( '/^\s*ALTER\s+TABLE/i', $queries ) ), 'AC1: a second dbDelta issues no ALTER TABLE' );
	}

	/**
	 * AC3: dedupe + automatic delay.
	 *
	 * @return void
	 */
	private function ac3_dedupe_and_delay() {
		$product = $this->product();
		$order   = $this->order( $product, 'buyer-a' );

		$order->update_status( 'completed' );
		$rows = $this->rows_for_order( $order->get_id() );
		$this->ok( 1 === count( $rows ) && 'auto' === $rows[0]->source, 'AC3: completing an order queues one auto row' );
		if ( $rows ) {
			$due = strtotime( $rows[0]->scheduled_at . ' UTC' );
			$this->ok( abs( $due - ( time() + 7 * DAY_IN_SECONDS ) ) <= 60, 'AC3: scheduled 7 days out (+/-60 s)' );
			$next = as_next_scheduled_action( Scheduler::SEND_HOOK, array( 'request_id' => (int) $rows[0]->id ), Scheduler::GROUP );
			$this->ok( is_int( $next ) && abs( $next - $due ) <= 60, 'AC3: its Action Scheduler job matches' );
		}

		$a = $this->scheduler->queue_for_order( $order->get_id(), array( 'source' => 'auto' ) );
		$b = $this->scheduler->queue_for_order( $order->get_id(), array( 'source' => 'auto' ) );
		$this->ok( $rows && (int) $a === (int) $rows[0]->id && (int) $b === (int) $a, 'AC3: repeated auto queues return the same id' );
		$jobs = as_get_scheduled_actions(
			array(
				'hook'   => Scheduler::SEND_HOOK,
				'args'   => array( 'request_id' => (int) $a ),
				'status' => 'pending',
			),
			'ids'
		);
		$this->ok( 1 === count( $jobs ), 'AC3: exactly one job for the deduped row', 'jobs=' . count( $jobs ) );
	}

	/**
	 * AC2: the claim lets one process send; stuck sends recover.
	 *
	 * @return void
	 */
	private function ac2_claim() {
		$product = $this->product();
		$order   = $this->order( $product, 'buyer-b' );
		$id      = $this->scheduler->queue_for_order( $order->get_id(), array( 'source' => 'auto' ) );
		$this->ok( is_int( $id ), 'AC2: queued', $this->err( $id ) );

		// First "process" stopped right after the claim.
		$this->ok( $this->requests->claim( $id ), 'AC2: the first claim wins' );
		$before = count( $this->mails );
		$this->scheduler->process( $id );
		$this->ok( count( $this->mails ) === $before, 'AC2: a second process on a claimed row sends nothing' );

		// Stuck in sending for 2 h -> failed after recovery -> retry sends once.
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'ndvr_requests', array( 'claimed_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) ), array( 'id' => $id ) );
		$this->requests->recover_stuck( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), 'stuck' );
		$this->ok( 'failed' === $this->requests->find( $id )->status, 'AC2: a send stuck 2 h becomes failed' );

		$this->scheduler->retry( $id );
		$this->scheduler->retry( $id );
		$this->ok( count( $this->mails ) === $before + 1, 'AC2: two retries send exactly one email' );
		$this->ok( 'sent' === $this->requests->find( $id )->status, 'AC2: the row ends sent' );
	}

	/**
	 * AC4: cooldown at send time (converted counts) and at queue time for manual.
	 *
	 * @return void
	 */
	private function ac4_cooldown() {
		$product = $this->product();
		$order   = $this->order( $product, 'buyer-c' );
		$first   = $this->scheduler->queue_for_order( $order->get_id(), array( 'source' => 'auto' ) );
		$this->scheduler->process( $first );
		$this->requests->mark_reviewed( $first ); // Converted still counts.

		$second = $this->scheduler->queue_for_order(
			$order->get_id(),
			array(
				'source'      => 'auto',
				'step'        => 2,
				'skip_checks' => true,
			)
		);
		$this->scheduler->process( $second );
		$row = $this->requests->find( $second );
		$this->ok( $row && 'cancelled' === $row->status, 'AC4: a second send inside 20 h is not sent (cancelled)', $row ? $row->status . ' ' . $row->error : 'missing' );

		$manual = $this->scheduler->queue_for_order( $order->get_id(), array( 'source' => 'manual' ) );
		$this->ok( is_wp_error( $manual ) && 'ndvr_cooldown' === $manual->get_error_code(), 'AC4: a manual queue inside the cooldown returns ndvr_cooldown' );
		$this->ok( 2 === count( $this->rows_for_order( $order->get_id() ) ), 'AC4: and inserts no row' );
	}

	/**
	 * AC5: before the v4 upgrade the queue API refuses and the automatic path
	 * still writes a legacy row that sends.
	 *
	 * @return void
	 */
	private function ac5_not_ready() {
		$product = $this->product();
		$order   = $this->order( $product, 'buyer-d' );

		update_option( NDVR_OPTION_DB_VERSION, '3' );
		set_transient( Installer::BACKOFF_TRANSIENT, 1, 60 ); // Keep init from upgrading mid-test.

		$res = $this->scheduler->queue_for_order( $order->get_id() );
		$this->ok( is_wp_error( $res ) && 'ndvr_not_ready' === $res->get_error_code(), 'AC5: queue_for_order returns ndvr_not_ready before the upgrade' );

		$this->scheduler->on_order_status( $order->get_id() );
		$rows = $this->rows_for_order( $order->get_id() );
		$this->ok( 1 === count( $rows ), 'AC5: the automatic path writes a legacy-shape row' );
		$before = count( $this->mails );
		if ( $rows ) {
			$this->scheduler->process( (int) $rows[0]->id );
		}
		$this->ok( count( $this->mails ) === $before + 1, 'AC5: and it still sends' );

		delete_transient( Installer::BACKOFF_TRANSIENT );
		update_option( NDVR_OPTION_DB_VERSION, (string) NDVR_DB_VERSION );
	}

	/**
	 * AC6: opening the link sets opened_at once; a review converts and fires.
	 *
	 * @return void
	 */
	private function ac6_tracking() {
		$product = $this->product();
		$order   = $this->order( $product, 'buyer-e' );
		$id      = $this->scheduler->queue_for_order( $order->get_id(), array( 'source' => 'auto' ) );
		$this->scheduler->process( $id );
		$row = $this->requests->find( $id );
		$this->ok( $row && (int) $row->token_id > 0, 'AC6: the sent row records its token' );

		$raw = $this->link_token( end( $this->mails ) );
		$this->ok( '' !== $raw, 'AC6: the email carries a token link' );

		if ( defined( 'NDVR_QA_BOOT' ) && '' !== $raw ) {
			$this->child_get( array( 'ndvr_k' => $raw ), 'landing', 'maybe_render' );
			$first = $this->requests->find( $id )->opened_at;
			$this->child_get( array( 'ndvr_k' => $raw ), 'landing', 'maybe_render' );
			$again = $this->requests->find( $id )->opened_at;
			$this->ok( ! empty( $first ) && $first === $again, 'AC6: opening the link sets opened_at once' );
		} else {
			$this->line( 'SKIP: AC6 open (needs NDVR_QA_BOOT for a child process).' );
		}

		$fired = array();
		$hook  = function ( $rid, $cid ) use ( &$fired ) {
			$fired[] = array( (int) $rid, (int) $cid );
		};
		add_action( 'ndv-reviews/request_converted', $hook, 10, 2 );
		$res = $this->submit_landing( $raw, $product, 'Through the link.' );
		remove_action( 'ndv-reviews/request_converted', $hook, 10 );

		$row = $this->requests->find( $id );
		$this->ok( $res['success'], 'AC6: the review submits through the landing', wp_json_encode( $res ) );
		$this->ok( $row && 'converted' === $row->status && ! empty( $row->reviewed_at ), 'AC6: reviewed_at is set and the row is converted' );
		$this->ok( 1 === count( $fired ) && $fired[0][0] === $id, 'AC6: request_converted fired once' );
	}

	/**
	 * AC7: list rows.
	 *
	 * @return void
	 */
	private function ac7_list_rows() {
		$p1    = $this->product( 'List P1' );
		$p2    = $this->product( 'List P2' );
		$email = 'ana-' . wp_generate_password( 5, false, false ) . '@example.invalid';
		$email = strtolower( $email );

		// P2 already reviewed by this email, so it drops out (the PRD's
		// "excluded" case until RR-05 adds exclusions).
		$cid = wp_insert_comment(
			array(
				'comment_post_ID'      => $p2,
				'comment_author'       => 'Ana',
				'comment_author_email' => $email,
				'comment_content'      => 'Earlier review.',
				'comment_type'         => 'review',
				'comment_approved'     => 0,
			)
		);
		$this->fx['comments'][] = (int) $cid;

		$id   = $this->scheduler->queue_for_email( $email, 'Ana', array( $p1, $p2 ), array( 'meta' => array( 'campaign_id' => 9 ) ) );
		$same = $this->scheduler->queue_for_email( $email, 'Ana', array( $p1, $p2 ), array( 'meta' => array( 'campaign_id' => 9 ) ) );
		$this->ok( is_int( $id ) && $id === $same, 'AC7: a second queue for the same email in campaign 9 returns the same id', $this->err( $id ) );

		$before = count( $this->mails );
		$this->scheduler->process( $id );
		$mail = count( $this->mails ) > $before ? end( $this->mails ) : null;
		$this->ok( (bool) $mail, 'AC7: the list email is sent' );
		if ( $mail ) {
			$body = (string) $mail['message'];
			$this->ok( false !== strpos( $body, 'List P1' ) && false === strpos( $body, 'List P2' ), 'AC7: it lists only P1' );
			$this->ok( false !== strpos( $body, 'asked for your review' ), 'AC7: with the list footer' );
		}

		$raw = $mail ? $this->link_token( $mail ) : '';
		$res = $this->submit_landing( $raw, $p1, 'From the list.' );
		$this->ok( $res['success'], 'AC7: the review saves through the link', wp_json_encode( $res ) );
		$review = get_comments(
			array(
				'post_id' => $p1,
				'status'  => 'all',
				'type'    => 'review',
				'number'  => 1,
				'orderby' => 'comment_ID',
				'order'   => 'DESC',
			)
		);
		$review = $review ? $review[0] : null;
		if ( $review ) {
			$this->fx['comments'][] = (int) $review->comment_ID;
		}
		$this->ok( $review && 'list_link' === get_comment_meta( $review->comment_ID, '_ndvr_source', true ) && $email === strtolower( $review->comment_author_email ), 'AC7: source list_link, with the list email' );
		$this->ok( $review && ! get_comment_meta( $review->comment_ID, '_ndvr_verified', true ), 'AC7: not verified (that email never bought P1)' );
	}

	/**
	 * AC8 + AC9: UTM tags and the open pixel.
	 *
	 * @return void
	 */
	private function ac8_ac9_utm_pixel() {
		$this->set_settings(
			array(
				'reminder_utm'        => true,
				'reminder_open_pixel' => true,
			)
		);
		$product = $this->product();
		$order   = $this->order( $product, 'buyer-f' );
		$id      = $this->scheduler->queue_for_order( $order->get_id(), array( 'source' => 'auto' ) );
		$this->scheduler->process( $id );
		$mail = end( $this->mails );
		$body = (string) $mail['message'];

		$this->ok( false !== strpos( $body, 'utm_source=rosette-reviews' ) && false !== strpos( $body, 'utm_medium=email' ) && false !== strpos( $body, 'utm_campaign=review-request' ), 'AC8: the review link carries the three UTM tags' );
		$raw = $this->link_token( $mail );
		$tok = \NdvReviews\Plugin::instance()->container()->get( 'token_repository' )->resolve( $raw );
		$this->ok( (bool) $tok, 'AC8: the tagged link still resolves its token' );

		$pixel = \NdvReviews\Requests\Tracking::pixel_url( $id );
		$this->ok( false !== strpos( $body, esc_url( $pixel ) ), 'AC9: the email HTML contains the pixel' );

		if ( defined( 'NDVR_QA_BOOT' ) ) {
			$bad = $id . '.' . str_repeat( '0', 20 );
			$this->child_get( array( 'ndvr_px' => $bad ), 'request_tracking', 'maybe_serve_pixel' );
			$this->ok( empty( $this->requests->find( $id )->opened_at ), 'AC9: an invalid signature changes no row' );

			$good = (string) wp_parse_args( wp_parse_url( $pixel, PHP_URL_QUERY ) )['ndvr_px'];
			$out  = $this->child_get( array( 'ndvr_px' => $good ), 'request_tracking', 'maybe_serve_pixel' );
			$this->ok( ! empty( $this->requests->find( $id )->opened_at ), 'AC9: a valid signature sets opened_at' );
			$this->ok( 0 === strpos( $out, 'GIF89a' ), 'AC9: the response is the GIF' );
		} else {
			$this->line( 'SKIP: AC9 pixel requests (needs NDVR_QA_BOOT).' );
		}

		$this->set_settings(
			array(
				'reminder_utm'        => false,
				'reminder_open_pixel' => false,
			)
		);
	}

	/**
	 * AC10: the legacy direct path still works.
	 *
	 * @return void
	 */
	private function ac10_legacy() {
		$mailer  = \NdvReviews\Plugin::instance()->container()->get( 'mailer' );
		$product = $this->product();
		$order   = $this->order( $product, 'buyer-g' );
		$order->update_status( 'completed' );

		$this->ok( true === $mailer->send_for_order( $order->get_id() ), 'AC10: send_for_order( $id ) still sends for an eligible order' );

		$order2 = $this->order( $product, 'buyer-h' );
		$mailer->suppress( $order2->get_billing_email() );
		$res = $mailer->send_for_order( $order2->get_id() );
		$this->ok( is_wp_error( $res ) && 'ndvr_unsubscribed' === $res->get_error_code(), 'AC10: and returns ndvr_unsubscribed for a suppressed one' );
		$mailer->hash_suppressed( $order2->get_billing_email() );
	}

	/**
	 * AC11: erasure cancels a pending row; it never sends.
	 *
	 * @return void
	 */
	private function ac11_erasure() {
		$product = $this->product();
		$order   = $this->order( $product, 'buyer-i' );
		$id      = $this->scheduler->queue_for_order( $order->get_id(), array( 'source' => 'auto' ) );
		$email   = $order->get_billing_email();

		\NdvReviews\Plugin::instance()->container()->get( 'privacy' )->erase( $email, 1 );
		$row = $this->requests->find( $id );
		$this->ok( $row && 'cancelled' === $row->status && empty( $row->email ), 'AC11: erasure cancels the pending row and drops the email', $row ? $row->status : '' );

		$before = count( $this->mails );
		$this->scheduler->process( $id );
		$this->ok( count( $this->mails ) === $before, 'AC11: it never sends' );
	}

	/**
	 * AC12: conversion is per order.
	 *
	 * @return void
	 */
	private function ac12_conversion() {
		delete_transient( \NdvReviews\Requests\RequestRepository::STATS_TRANSIENT . 90 );
		$stats = $this->requests->stats( 90 );
		$this->ok( $stats['orders_sent'] > 0 && null !== $stats['conversion'], 'AC12: stats count orders that got a request' );
		$this->ok( abs( $stats['conversion'] - $stats['orders_reviewed'] / $stats['orders_sent'] ) < 0.0001, 'AC12: conversion = orders reviewed / orders sent' );
	}

	/**
	 * AC13: orphans after deactivation.
	 *
	 * @return void
	 */
	private function ac13_orphans() {
		global $wpdb;
		$table   = $wpdb->prefix . 'ndvr_requests';
		$product = $this->product();
		$old     = $this->order( $product, 'buyer-j' );
		$recent  = $this->order( $product, 'buyer-k' );
		$a       = $this->scheduler->queue_for_order( $old->get_id(), array( 'source' => 'auto' ) );
		$b       = $this->scheduler->queue_for_order( $recent->get_id(), array( 'source' => 'auto' ) );
		$wpdb->update( $table, array( 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), array( 'id' => $a ) );
		$wpdb->update( $table, array( 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'id' => $b ) );

		\NdvReviews\Deactivator::deactivate();
		$this->ok( ! as_has_scheduled_action( Scheduler::SEND_HOOK, array( 'request_id' => $b ), Scheduler::GROUP ), 'AC13: deactivation removed the send jobs' );
		\NdvReviews\Activator::activate();
		delete_transient( 'ndv_reviews_activated' );
		$this->ok( (bool) as_has_scheduled_action( Scheduler::RECOVER_HOOK ), 'AC13: after activation the recover job is pending' );

		$this->scheduler->recover();
		$row_a = $this->requests->find( $a );
		$this->ok( 'cancelled' === $row_a->status, 'AC13: the row due 30 days ago is cancelled (expired)', $row_a->status );
		$this->ok( (bool) as_has_scheduled_action( Scheduler::SEND_HOOK, array( 'request_id' => $b ), Scheduler::GROUP ), 'AC13: the row due 1 day ago gets a pending job' );

		$before = count( $this->mails );
		$this->scheduler->process( $b );
		$this->scheduler->process( $b );
		$this->ok( count( $this->mails ) === $before + 1, 'AC13: and sends once' );

		// A storefront request never checks the recover job.
		$this->ok( false === has_action( 'init', array( Scheduler::class, 'ensure_recover_scheduled' ) ), 'AC13: no recover-job check on init' );
	}

	/**
	 * AC14: the Reminders save stores the tracking boxes and leaves others.
	 *
	 * @return void
	 */
	private function ac14_settings() {
		$settings = \NdvReviews\Plugin::instance()->container()->get( 'settings' );
		$settings->update( array( 'transparency_enabled' => 'keep-me' ) );
		$page = \NdvReviews\Plugin::instance()->container()->get( 'admin_requests_page' );

		$post = array(
			'ndvr_requests_do' => 'save',
			'_wpnonce'         => wp_create_nonce( \NdvReviews\Admin\RequestsPage::NONCE ),
			'reminder_enabled' => '1',
			'reminder_utm'     => '1',
			'ndvr_fields'      => array( 'reminder_utm', 'reminder_open_pixel' ),
		);
		$this->post_requests_page( $page, $post );
		$raw = get_option( NDVR_OPTION_SETTINGS );
		$this->ok( true === ( $raw['reminder_utm'] ?? null ), 'AC14: ticking the UTM box stores true' );
		$this->ok( 'keep-me' === ( $raw['transparency_enabled'] ?? '' ), 'AC14: a settings-page key is unchanged' );

		unset( $post['reminder_utm'] );
		$this->post_requests_page( $page, $post );
		$raw = get_option( NDVR_OPTION_SETTINGS );
		$this->ok( false === ( $raw['reminder_utm'] ?? null ), 'AC14: unticking it stores false' );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->ok( false !== strpos( $html, 'name="reminder_utm"' ) && false !== strpos( $html, 'name="ndvr_fields[]" value="reminder_utm"' ), 'AC14: the Tracking section renders with its markers' );
	}

	/**
	 * Code review fixes (code-RR-09.md M1, M2, M3, m2-adjacent).
	 *
	 * @return void
	 */
	private function review_fixes() {
		global $wpdb;
		$table   = $wpdb->prefix . 'ndvr_requests';
		$product = $this->product();

		// M1: an order asked before RR-09 (legacy row) isn't asked again.
		$order = $this->order( $product, 'legacy' );
		$wpdb->insert(
			$table,
			array(
				'order_id'     => $order->get_id(),
				'email'        => $order->get_billing_email(),
				'channel'      => 'email',
				'status'       => 'sent',
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
				'sent_at'      => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
			)
		);
		$legacy = (int) $wpdb->insert_id;
		$id     = $this->scheduler->queue_for_order( $order->get_id(), array( 'source' => 'auto' ) );
		$this->ok( $legacy === $id && 1 === count( $this->rows_for_order( $order->get_id() ) ), 'M1: an order with a legacy request gets no second automatic request' );

		// M2: campaign order rows dedupe per email per campaign; unknown sources refused.
		$email = 'camp-' . wp_generate_password( 4, false, false ) . '@example.invalid';
		$o1    = $this->order( $product, 'camp-a' );
		$o2    = $this->order( $product, 'camp-b' );
		foreach ( array( $o1, $o2 ) as $o ) {
			$o->set_billing_email( $email );
			$o->save();
		}
		$c1 = $this->scheduler->queue_for_order( $o1->get_id(), array( 'source' => 'campaign', 'meta' => array( 'campaign_id' => 77 ) ) );
		$c2 = $this->scheduler->queue_for_order( $o2->get_id(), array( 'source' => 'campaign', 'meta' => array( 'campaign_id' => 77 ) ) );
		$this->ok( is_int( $c1 ) && $c1 === $c2, 'M2: two orders in one campaign for one email share one request', $this->err( $c2 ) );
		$bad = $this->scheduler->queue_for_order( $o1->get_id(), array( 'source' => 'something-else' ) );
		$this->ok( is_wp_error( $bad ) && 'ndvr_bad_source' === $bad->get_error_code(), 'M2: an unknown source is refused' );
		$nocamp = $this->scheduler->queue_for_order( $o1->get_id(), array( 'source' => 'campaign' ) );
		$this->ok( is_wp_error( $nocamp ) && 'ndvr_no_campaign' === $nocamp->get_error_code(), 'M2: a campaign row needs a campaign id' );

		// M3: a failed row of an erased customer never sends, even on Retry.
		$order3 = $this->order( $product, 'erase-failed' );
		$fid    = $this->scheduler->queue_for_order( $order3->get_id(), array( 'source' => 'auto' ) );
		$this->requests->set_status( $fid, 'failed', 'test' );
		\NdvReviews\Plugin::instance()->container()->get( 'privacy' )->erase( $order3->get_billing_email(), 1 );
		$before = count( $this->mails );
		$this->scheduler->retry( $fid );
		$this->scheduler->process( $fid );
		$row = $this->requests->find( $fid );
		$this->ok( count( $this->mails ) === $before && 'cancelled' === $row->status, 'M3: erasure cancels a failed row and a retry sends nothing', $row->status );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * POST to the Reminders page handler.
	 *
	 * @param object              $page Page.
	 * @param array<string,mixed> $post POST data.
	 * @return void
	 */
	private function post_requests_page( $page, array $post ) {
		$_GET                      = array( 'page' => \NdvReviews\Admin\RequestsPage::PAGE_SLUG );
		$_POST                     = $post;
		$_REQUEST                  = array_merge( $_GET, $_POST );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$page->handle_actions();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Submit a landing-page review through the AJAX handler.
	 *
	 * @param string $raw        Token.
	 * @param int    $product_id Product.
	 * @param string $text       Review text.
	 * @return array{success:bool,data:mixed}
	 */
	private function submit_landing( $raw, $product_id, $text ) {
		$criteria = \NdvReviews\Plugin::instance()->container()->get( 'criteria' )->get_active();
		$scores   = array();
		foreach ( (array) $criteria as $criterion ) {
			$scores[ (int) $criterion->id ] = '4';
		}

		$_POST    = array(
			'nonce'         => wp_create_nonce( \NdvReviews\Collection\Landing::NONCE ),
			'token'         => $raw,
			'product_id'    => (string) $product_id,
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $scores,
			'comment'       => $text,
			'author'        => 'QA Buyer',
		);
		$_REQUEST = $_POST;

		$ajax = function () {
			return true;
		};
		$die  = function () {
			return function () {
				throw new NDVR_QA_RR09_Die();
			};
		};
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'wp_die_ajax_handler', $die );

		ob_start();
		try {
			\NdvReviews\Plugin::instance()->container()->get( 'landing' )->handle_submit();
		} catch ( NDVR_QA_RR09_Die $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();

		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );
		$_POST    = array();
		$_REQUEST = array();

		$json = json_decode( $out, true );

		return array(
			'success' => is_array( $json ) && ! empty( $json['success'] ),
			'data'    => is_array( $json ) ? ( $json['data'] ?? null ) : $out,
		);
	}

	/**
	 * A GET request to a service method in a child process (for code that
	 * exits). Built with var_export(), so the code holds single quotes only:
	 * escapeshellarg() on Windows replaces double quotes.
	 *
	 * @param array<string,string> $get     Query args.
	 * @param string               $service Container id.
	 * @param string               $method  Method name.
	 * @return string Output.
	 */
	private function child_get( array $get, $service, $method ) {
		$code = '$_GET = ' . var_export( $get, true ) . '; \\NdvReviews\\Plugin::instance()->container()->get( ' . var_export( (string) $service, true ) . ' )->' . preg_replace( '/[^a-z_]/i', '', $method ) . '();';

		return $this->child( str_replace( array( "\r", "\n" ), ' ', $code ) );
	}

	/**
	 * Run PHP in a child process of the QA site (for code that exits).
	 *
	 * @param string $code PHP code.
	 * @return string Output.
	 */
	private function child( $code ) {
		$cmd = escapeshellarg( PHP_BINARY ) . ' -d memory_limit=512M ' . escapeshellarg( NDVR_QA_BOOT ) . ' eval ' . escapeshellarg( $code ) . ' 2>&1';
		$out = shell_exec( $cmd );
		wp_cache_flush();

		return (string) $out;
	}

	/**
	 * The raw token in a captured email's review link.
	 *
	 * @param array<string,mixed>|false $mail Mail.
	 * @return string
	 */
	private function link_token( $mail ) {
		if ( ! $mail || ! preg_match( '/[?&](?:amp;)?ndvr_k=([A-Za-z0-9]+)/', (string) $mail['message'], $m ) ) {
			return '';
		}

		return $m[1];
	}

	/**
	 * Request rows for an order.
	 *
	 * @param int $order_id Order id.
	 * @return array<int,object>
	 */
	private function rows_for_order( $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ndvr_requests';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE order_id = %d ORDER BY id ASC", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * A reviewable product.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	private function product( $name = 'RR09 QA product' ) {
		$p = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$p->set_reviews_allowed( true );
		$id                     = (int) $p->save();
		$this->fx['products'][] = $id;
		return $id;
	}

	/**
	 * A processing order for a product (completing it is left to the test).
	 *
	 * @param int    $product Product id.
	 * @param string $who     Email label.
	 * @return \WC_Order
	 */
	private function order( $product, $who ) {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $product ), 1 );
		$order->set_billing_first_name( 'QA' );
		$order->set_billing_email( 'rr09-' . $who . '-' . wp_generate_password( 4, false, false ) . '@example.invalid' );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();
		$this->fx['orders'][] = $order->get_id();
		return $order;
	}

	/**
	 * Merge settings through the service (its cache stays in sync).
	 *
	 * @param array<string,mixed> $values Values.
	 * @return void
	 */
	private function set_settings( array $values ) {
		\NdvReviews\Plugin::instance()->container()->get( 'settings' )->update( $values );
	}

	/**
	 * Capture mail.
	 *
	 * @param mixed               $short Short-circuit.
	 * @param array<string,mixed> $atts  Mail.
	 * @return bool
	 */
	public function capture( $short, $atts ) {
		unset( $short );
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * Remove fixtures, restore state.
	 *
	 * @return void
	 */
	private function cleanup() {
		global $wpdb;
		$table = $wpdb->prefix . 'ndvr_requests';
		foreach ( $this->fx['orders'] as $id ) {
			$rows = $this->rows_for_order( $id );
			foreach ( $rows as $row ) {
				as_unschedule_all_actions( Scheduler::SEND_HOOK, array( 'request_id' => (int) $row->id ), Scheduler::GROUP );
			}
			$wpdb->delete( $table, array( 'order_id' => $id ) );
			$wpdb->delete( $wpdb->prefix . 'ndvr_review_tokens', array( 'order_id' => $id ) );
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
		$wpdb->query( "DELETE FROM `{$table}` WHERE order_id = 0 AND dedupe_key LIKE 'c:9:%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}ndvr_review_tokens` WHERE type = 'list'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $this->fx['products'] as $pid ) {
			foreach ( get_comments( array( 'post_id' => $pid, 'status' => 'all', 'fields' => 'ids' ) ) as $cid ) {
				wp_delete_comment( (int) $cid, true );
			}
			$p = wc_get_product( $pid );
			if ( $p ) {
				$p->delete( true );
			}
		}
		foreach ( $this->fx['comments'] as $cid ) {
			wp_delete_comment( $cid, true );
		}
		if ( false === $this->saved['settings'] ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved['settings'] );
		}
		update_option( NDVR_OPTION_DB_VERSION, false === $this->saved['db'] ? (string) NDVR_DB_VERSION : $this->saved['db'] );
		$this->line( 'cleanup: fixtures removed, settings restored' );
	}

	/**
	 * Record a check.
	 *
	 * @param bool   $cond   Passed.
	 * @param string $label  Label.
	 * @param string $detail Detail.
	 * @return void
	 */
	private function ok( $cond, $label, $detail = '' ) {
		++$this->count[ $cond ? 'pass' : 'fail' ];
		$this->line( ( $cond ? 'PASS: ' : 'FAIL: ' ) . $label . ( ! $cond && '' !== $detail ? ' -- ' . $detail : '' ) );
	}

	/**
	 * Describe a value.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private function err( $v ) {
		return is_wp_error( $v ) ? $v->get_error_code() . ': ' . $v->get_error_message() : ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
	}

	/**
	 * Print.
	 *
	 * @param string $text Text.
	 * @return void
	 */
	private function line( $text ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::log( $text );
			return;
		}
		echo esc_html( $text ) . "\n";
	}
}

$ndvr_qa_ok = ( new NDVR_QA_RR09() )->run();
if ( defined( 'WP_CLI' ) && WP_CLI && ! $ndvr_qa_ok ) {
	\WP_CLI::halt( 1 );
}
