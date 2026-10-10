<?php
/**
 * RR-00b acceptance harness (PRD .agents/prd/RR-00b-extension-points.md §12).
 *
 *     php boot.php run .agents/qa/rr-00b.php 1      (QA site, D:/.devcache/qa-site)
 *     wp eval-file .agents/qa/rr-00b.php --user=1   (WP-CLI)
 *
 * Test site only. Fixtures (products, orders, reviews, attachments, theme
 * template overrides) are removed at the end. Page views that exit (the
 * landing page) run in a child process through NDVR_QA_BOOT.
 *
 * Not shipped: `.agents` is in .distignore.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions -- QA script, never shipped.

use NdvReviews\Reviews\Pool;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NDVR_QA_BOOT' ) && file_exists( dirname( ABSPATH ) . '/boot.php' ) ) {
	define( 'NDVR_QA_BOOT', dirname( ABSPATH ) . '/boot.php' );
}

/**
 * Thrown by die handlers so handlers that exit return here.
 */
final class NDVR_QA_00b_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR00b {

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
		'products'    => array(),
		'orders'      => array(),
		'comments'    => array(),
		'attachments' => array(),
		'users'       => array(),
	);

	/**
	 * Template overrides written into the theme.
	 *
	 * @var string[]
	 */
	private $overrides = array();

	/**
	 * Container.
	 *
	 * @var object
	 */
	private $c;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! defined( 'NDVR_API' ) || (int) NDVR_API < 4 ) {
			$this->line( 'ABORT: Rosette Reviews with RR-00b (NDVR_API 4) must be active.' );
			return false;
		}
		$this->c = \NdvReviews\Plugin::instance()->container();
		add_filter( 'pre_wp_mail', '__return_true', 1 );

		try {
			$this->ok( (int) NDVR_API >= 4, 'AC16: NDVR_API is at least 4 (RR-00b)' );
			$this->ac1_ac2_media();
			$this->ac3_held_media();
			$this->ac4_ac5_pools();
			$this->ac6_native_post();
			$this->ac7_aggregate_saved();
			$this->ac8_restore();
			$this->ac9_ac10_landing();
			$this->ac11_size_guard();
			$this->ac12_badges();
			$this->ac13_questions();
			$this->ac14_csv();
			$this->review_fixes();
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
		}

		$this->cleanup();
		remove_filter( 'pre_wp_mail', '__return_true', 1 );
		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->count['fail'] ? 'FAIL' : 'PASS', $this->count['pass'], $this->count['fail'] ) );

		return 0 === $this->count['fail'];
	}

	/* ------------------------------------------------------------------ */

	/**
	 * AC1 + AC2: attach_media and type-aware reads.
	 *
	 * @return void
	 */
	private function ac1_ac2_media() {
		$product = $this->product();
		$review  = $this->review( $product, 'media@example.invalid' );
		$repo    = $this->c->get( 'reviews' );

		$img0 = $this->attachment( 'png', 'image/png' );
		$img1 = $this->attachment( 'png', 'image/png' );
		$img2 = $this->attachment( 'png', 'image/png' );
		$mp4  = $this->attachment( 'mp4', 'video/mp4' );
		$pdf  = $this->attachment( 'pdf', 'application/pdf' );

		$repo->attach_media( $review, array( $img0 ) );

		$seen = array();
		$spy  = function ( $status, $id, $context = array() ) use ( &$seen ) {
			$seen[] = $context;
			return $status;
		};
		add_filter( 'ndv-reviews/review_media_status', $spy, 10, 3 );
		$n = $repo->attach_media( $review, array( $img1, $img2 ), 'image', array( 'origin' => 'import' ) );
		remove_filter( 'ndv-reviews/review_media_status', $spy, 10 );

		$positions = $this->positions( $review );
		$this->ok( 2 === $n && array( 0, 1, 2 ) === $positions, 'AC1: two images added at positions 1 and 2', wp_json_encode( $positions ) );
		$this->ok( isset( $seen[0]['origin'] ) && 'import' === $seen[0]['origin'], 'AC1: the status filter receives the context' );
		$this->ok( 0 === $repo->attach_media( $review, array( $img1, $img2 ) ), 'AC1: re-attaching inserts nothing' );
		$this->ok( 0 === $repo->attach_media( $review, array( $pdf ) ), 'AC1: a PDF is refused' );

		// AC2.
		$review2 = $this->review( $product, 'media2@example.invalid' );
		$repo->attach_media( $review2, array( $img0 ) );
		$repo->attach_media( $review2, array( $mp4 ), 'video' );
		$q = $this->c->get( 'review_query' );
		$this->ok( 1 === count( $q->media( $review2 ) ) && 1 === count( $q->media( $review2, 'video' ) ) && 2 === count( $q->media( $review2, 'any' ) ), 'AC2: media() reads by type (image 1, video 1, any 2)' );

		wp_set_comment_status( $review2, 'approve' );
		$html = do_shortcode( '[ndvr-reviews product_id="' . $product . '"]' );
		$this->ok( ! preg_match( '/<img[^>]+src="[^"]+\.mp4"/', $html ), 'AC2: no <img> points at the video' );

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		set_current_screen( 'rosette_page_ndv-reviews-moderation' );
		$table = new \NdvReviews\Moderation\ListTable( \NdvReviews\Moderation\Page::PAGE_SLUG );
		$cell  = $table->column_media( get_comment( $review2 ) );
		$this->ok( '1 photo, 1 video' === $cell, 'AC2: the Media column reads "1 photo, 1 video"', $cell );

		// set_current_screen() makes is_admin() true for the rest of the run;
		// the storefront checks need it back off.
		$GLOBALS['current_screen'] = null;
	}

	/**
	 * AC3: held-media cleanup and the re-sweep.
	 *
	 * @return void
	 */
	private function ac3_held_media() {
		global $wpdb;
		$product = $this->product();
		$repo    = $this->c->get( 'reviews' );
		$hook    = \NdvReviews\Moderation\Actions::MEDIA_CLEANUP_HOOK;

		$spam = $this->review( $product, 'spam@example.invalid' );
		$a1   = $this->attachment( 'png', 'image/png' );
		$a2   = $this->attachment( 'png', 'image/png' );
		$repo->attach_media( $spam, array( $a1, $a2 ) );

		$keep = $this->review( $product, 'keep@example.invalid' );
		$k1   = $this->attachment( 'png', 'image/png' );
		$repo->attach_media( $keep, array( $k1 ) );

		wp_spam_comment( $spam );
		wp_spam_comment( $keep );
		$jobs = as_get_scheduled_actions(
			array(
				'hook'   => $hook,
				'args'   => array( 'comment_id' => $spam ),
				'status' => 'pending',
			),
			'ids'
		);
		$this->ok( 1 === count( $jobs ), 'AC3: marking spam schedules exactly one cleanup' );

		wp_set_comment_status( $keep, 'approve' );

		$zero = function () {
			return 0;
		};
		add_filter( 'ndv-reviews/held_media_days', $zero );
		$actions = $this->c->get( 'moderation_actions' );
		$actions->cleanup_held_media( $spam );
		$actions->cleanup_held_media( $keep );
		remove_filter( 'ndv-reviews/held_media_days', $zero );

		$media = $wpdb->prefix . 'ndvr_review_media';
		$left  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$media}` WHERE comment_id = %d", $spam ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->ok( 0 === $left && ! get_post( $a1 ) && ! get_post( $a2 ), 'AC3: the cleanup deletes the rows and the attachments' );
		$kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$media}` WHERE comment_id = %d", $keep ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->ok( 1 === $kept && get_post( $k1 ), 'AC3: a review approved before the run keeps its media' );

		// Re-sweep: a held review whose job is gone gets one back.
		$held = $this->review( $product, 'held@example.invalid' );
		$h1   = $this->attachment( 'png', 'image/png' );
		$repo->attach_media( $held, array( $h1 ) );
		wp_spam_comment( $held );
		as_unschedule_all_actions( $hook, array( 'comment_id' => $held ), 'ndv-reviews' );
		$this->c->get( 'scheduler' )->recover();
		$this->ok( (bool) as_has_scheduled_action( $hook, array( 'comment_id' => $held ), 'ndv-reviews' ), 'AC3: the hourly recovery re-creates a missing cleanup' );
	}

	/**
	 * AC4 + AC5: pool-aware reads and has_reviewed().
	 *
	 * @return void
	 */
	private function ac4_ac5_pools() {
		$a    = $this->product( 'Pool A' );
		$b    = $this->product( 'Pool B' );
		$pool = $this->pool_filter( $a, $b );

		$user = wp_insert_user(
			array(
				'user_login' => 'ndvr_qa7_' . wp_generate_password( 4, false, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'new-' . wp_generate_password( 4, false, false ) . '@example.invalid',
				'role'       => 'customer',
			)
		);
		$this->fx['users'][] = (int) $user;

		$id = $this->c->get( 'reviews' )->create(
			array(
				'product_id' => $b,
				'author'     => 'Old Email',
				'email'      => 'old@example.invalid',
				'user_id'    => 0,
				'content'    => 'Pooled review.',
				'rating'     => 5,
				'source'     => 'onsite',
				'approved'   => 1,
			)
		);
		$this->fx['comments'][] = (int) $id;
		// Attribute it to the user while keeping the old comment email.
		global $wpdb;
		$wpdb->update( $wpdb->comments, array( 'user_id' => (int) $user ), array( 'comment_ID' => $id ) );
		clean_comment_cache( $id );
		\NdvReviews\Reviews\ReviewTags::set( $id, array( 'fit' ) );

		$comment = get_comment( $id );
		$this->ok( (int) $comment->comment_post_ID === $a && $b === (int) get_comment_meta( $id, Pool::POOLED_FROM_META, true ), 'AC4: a review for B is stored on A with _ndvr_pooled_from = B' );
		$page = $this->c->get( 'review_query' )->paginate( array( 'product_id' => $b ) );
		$this->ok( 1 === (int) $page['total'], 'AC4: paginate( B ) returns it' );
		$tags = \NdvReviews\Reviews\ReviewTags::for_post( $b );
		$this->ok( isset( $tags['fit'] ), 'AC4: ReviewTags::for_post( B ) counts its tag' );

		$rv   = $this->c->get( 'reviewable' );
		$user = (int) $user;
		$this->ok( $rv->has_reviewed( 'new@example.invalid', $b, $user ), 'AC4: has_reviewed matches by user id' );
		$this->ok( $rv->has_reviewed( 'old@example.invalid', $b ), 'AC4: has_reviewed matches by email' );
		$this->ok( $rv->has_reviewed( '', $b, $user ), 'AC4: has_reviewed with no email still checks the user' );
		$this->ok( ! $rv->has_reviewed( 'new@example.invalid', $b, $user + 1000 ), 'AC4: has_reviewed is false for someone else' );

		// AC5.
		$order = wc_create_order();
		$order->add_product( wc_get_product( $a ), 1 );
		$order->add_product( wc_get_product( $b ), 1 );
		$order->set_billing_email( 'buyer-ab@example.invalid' );
		$order->save();
		$this->fx['orders'][] = $order->get_id();
		$ids = $rv->for_order( $order );
		$this->ok( 1 === count( $ids ), 'AC5: an order with A and B gives one product to review', wp_json_encode( $ids ) );

		$order7 = wc_create_order();
		$order7->add_product( wc_get_product( $a ), 1 );
		$order7->set_billing_email( 'new@example.invalid' );
		$order7->set_customer_id( $user );
		$order7->save();
		$this->fx['orders'][] = $order7->get_id();
		$this->ok( array() === $rv->for_order( $order7 ), 'AC5: the user who already reviewed gets nothing to review' );

		remove_filter( 'ndv-reviews/review_pool_id', $pool, 10 );
	}

	/**
	 * AC6: native (no-JS) post on a pooled product.
	 *
	 * @return void
	 */
	private function ac6_native_post() {
		$a    = $this->product( 'Native A' );
		$b    = $this->product( 'Native B' );
		$pool = $this->pool_filter( $a, $b );
		wp_set_current_user( 0 );
		// Every QA post comes from one IP within seconds: skip core's flood check.
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );

		$_POST = array(
			'comment_post_ID' => (string) $b,
			'rating'          => '4',
			'author'          => 'Native Shopper',
			'email'           => 'native@example.invalid',
			'comment'         => 'Posted without JavaScript.',
		);
		$comment = wp_handle_comment_submission( wp_unslash( $_POST ) );
		$_POST   = array();

		$ok = $comment instanceof \WP_Comment;
		$this->ok( $ok, 'AC6: the native post is accepted', is_wp_error( $comment ) ? $comment->get_error_message() : '' );
		if ( $ok ) {
			$this->fx['comments'][] = (int) $comment->comment_ID;
			$this->ok( (int) $comment->comment_post_ID === $a && $b === (int) get_comment_meta( $comment->comment_ID, Pool::POOLED_FROM_META, true ), 'AC6: it is stored on A with _ndvr_pooled_from = B', 'post=' . $comment->comment_post_ID . ' a=' . $a . ' b=' . $b . ' meta=' . get_comment_meta( $comment->comment_ID, Pool::POOLED_FROM_META, true ) );
			wp_set_comment_status( $comment->comment_ID, 'approve' );
			$this->ok( abs( (float) get_post_meta( $a, '_wc_average_rating', true ) - 4.0 ) < 0.01, "AC6: A's average includes the new rating" );
			$to = apply_filters( 'comment_post_redirect', home_url( '/' ), get_comment( $comment->comment_ID ) );
			$this->ok( 0 === strpos( $to, get_permalink( $b ) ), 'AC6: the shopper is sent back to B', $to );
		}

		wp_set_current_user( 1 );
		remove_filter( 'ndv-reviews/review_pool_id', $pool, 10 );
	}

	/**
	 * AC7: aggregate_saved fires after WooCommerce's recount.
	 *
	 * @return void
	 */
	private function ac7_aggregate_saved() {
		$a = $this->product( 'Agg A' );
		$this->review( $a, 'agg@example.invalid', true );

		$seen = array();
		$spy  = function ( $post_id, $data ) use ( &$seen ) {
			$seen[] = array( $post_id, $data, (float) get_post_meta( $post_id, '_wc_average_rating', true ) );
		};
		add_action( 'ndv-reviews/aggregate_saved', $spy, 10, 2 );
		$this->c->get( 'rating_cache' )->recalc_product( $a );
		remove_action( 'ndv-reviews/aggregate_saved', $spy, 10 );

		$this->ok( 1 === count( $seen ) && $a === $seen[0][0], 'AC7: aggregate_saved fires once per recalc' );
		$this->ok( $seen && abs( $seen[0][2] - (float) $seen[0][1]['average'] ) < 0.01, 'AC7: the listener sees the final stored average' );
	}

	/**
	 * AC8: orphans and restore (with replies), through the Tools POST.
	 *
	 * @return void
	 */
	private function ac8_restore() {
		$a    = $this->product( 'Restore A' );
		$b    = $this->product( 'Restore B' );
		$pool = $this->pool_filter( $a, $b );
		$id   = $this->c->get( 'reviews' )->create(
			array(
				'product_id' => $b,
				'author'     => 'Restore',
				'email'      => 'restore@example.invalid',
				'content'    => 'To move back.',
				'rating'     => 3,
				'source'     => 'onsite',
				'approved'   => 1,
			)
		);
		$this->fx['comments'][] = (int) $id;
		$reply                  = wp_insert_comment(
			array(
				'comment_post_ID'  => $a,
				'comment_parent'   => $id,
				'comment_content'  => 'Store reply.',
				'comment_author'   => 'Store',
				'comment_approved' => 1,
			)
		);
		$reply2 = wp_insert_comment(
			array(
				'comment_post_ID'  => $a,
				'comment_parent'   => $reply,
				'comment_content'  => 'Reply to the reply.',
				'comment_author'   => 'Shopper',
				'comment_approved' => 1,
			)
		);
		$this->fx['comments'][] = (int) $reply;
		$this->fx['comments'][] = (int) $reply2;
		remove_filter( 'ndv-reviews/review_pool_id', $pool, 10 );

		$this->ok( in_array( (int) $id, Pool::orphans( 1000 ), true ), 'AC8: orphans() finds the review once sharing stops' );

		$tools = $this->c->get( 'admin_tools_page' );
		$die   = function () {
			return function () {
				throw new NDVR_QA_00b_Die();
			};
		};
		add_filter( 'wp_die_handler', $die );
		$_POST    = array(
			'ndvr_tools_do' => 'restore_pooled',
			'_wpnonce'      => 'bad',
		);
		$_REQUEST = $_POST;
		try {
			$tools->handle_actions();
		} catch ( NDVR_QA_00b_Die $e ) {
			unset( $e );
		}
		$this->ok( $a === (int) get_comment( $id )->comment_post_ID, 'AC8: a POST without a valid nonce changes nothing' );

		$_POST['_wpnonce'] = wp_create_nonce( \NdvReviews\Admin\ToolsPage::NONCE );
		$_REQUEST          = $_POST;
		$tools->handle_actions();
		remove_filter( 'wp_die_handler', $die );
		$_POST    = array();
		$_REQUEST = array();
		clean_comment_cache( array( $id, $reply, $reply2 ) );

		$this->ok( $b === (int) get_comment( $id )->comment_post_ID && '' === get_comment_meta( $id, Pool::POOLED_FROM_META, true ), 'AC8: the Tools POST moves it back and clears the meta' );
		$this->ok( $b === (int) get_comment( $reply )->comment_post_ID && $b === (int) get_comment( $reply2 )->comment_post_ID, 'AC8: both reply levels move with it' );
		$this->ok( abs( (float) get_post_meta( $b, '_wc_average_rating', true ) - 3.0 ) < 0.01, "AC8: B's average is recalculated" );
	}

	/**
	 * AC9 + AC10: landing fields, handle filters, list default author.
	 *
	 * @return void
	 */
	private function ac9_ac10_landing() {
		$product = $this->product( 'Landing P' );
		$raw     = $this->c->get( 'token_repository' )->create_order_token( 0, 'landing@example.invalid', array( $product ) );

		if ( defined( 'NDVR_QA_BOOT' ) ) {
			// No double quotes in child code: escapeshellarg() on Windows replaces them.
			$code = 'add_action( \'ndv-reviews/landing_form_fields\', function ( $pid ) { echo \'<input type=hidden name=qa_field id=qa-field-\' . (int) $pid . \' value=1>\'; } );'
				. ' add_action( \'wp_loaded\', function () {}, 1 );'
				. ' wp_register_script( \'qa-handle\', \'https://example.invalid/qa.js\', array(), \'1\', true );'
				. ' add_filter( \'ndv-reviews/landing_script_handles\', function ( $h ) { $h[] = \'qa-handle\'; $h[] = \'qa-unregistered\'; return $h; } );'
				. ' $_GET = array( \'ndvr_k\' => ' . var_export( $raw, true ) . ' );'
				. ' \\NdvReviews\\Plugin::instance()->container()->get( \'landing\' )->maybe_render();';
			$html = $this->child( $code );
			$this->ok( 1 === substr_count( $html, 'name=qa_field' ), 'AC9: the landing_form_fields field renders once per product form', 'count=' . substr_count( $html, 'name=qa_field' ) );
			$this->ok( false !== strpos( $html, 'example.invalid/qa.js' ), 'AC9: a registered extra script handle prints' );
			$this->ok( false === strpos( $html, 'qa-unregistered' ), 'AC9: an unregistered handle prints nothing' );
		} else {
			$this->line( 'SKIP: AC9 (needs NDVR_QA_BOOT).' );
		}

		// AC10: a list-token review with no typed name.
		$scheduler = $this->c->get( 'scheduler' );
		$email     = 'ana-' . wp_generate_password( 4, false, false ) . '@example.invalid';
		$req       = $scheduler->queue_for_email( $email, 'Ana', array( $product ), array( 'meta' => array( 'campaign_id' => 31 ) ) );
		$mails     = array();
		$catch     = function ( $short, $atts ) use ( &$mails ) {
			$mails[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $catch, 0, 2 );
		$scheduler->process( $req );
		remove_filter( 'pre_wp_mail', $catch, 0 );
		$token = '';
		if ( $mails && preg_match( '/[?&](?:amp;)?ndvr_k=([A-Za-z0-9]+)/', (string) $mails[0]['message'], $m ) ) {
			$token = $m[1];
		}
		$res = $this->landing_submit( $token, $product );
		$new = get_comments(
			array(
				'post_id' => $product,
				'status'  => 'all',
				'number'  => 1,
				'orderby' => 'comment_ID',
				'order'   => 'DESC',
			)
		);
		if ( $new ) {
			$this->fx['comments'][] = (int) $new[0]->comment_ID;
		}
		$this->ok( $res && $new && 'Ana' === $new[0]->comment_author, 'AC10: a list review with no typed name uses the list first name' );

		global $wpdb;
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}ndvr_requests` WHERE dedupe_key LIKE 'c:31:%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * AC11: oversized requests get the size message (413).
	 *
	 * @return void
	 */
	private function ac11_size_guard() {
		$_POST                     = array();
		$_FILES                    = array();
		$_SERVER['CONTENT_LENGTH'] = (string) ( wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ) + 1024 );

		foreach ( array( 'review_form' => 'product form', 'landing' => 'landing page' ) as $service => $label ) {
			$out = $this->capture_json( array( $this->c->get( $service ), 'handle_submit' ) );
			$this->ok( false !== strpos( $out, 'too large' ), "AC11: the {$label} returns the size message", substr( $out, 0, 160 ) );
		}
		unset( $_SERVER['CONTENT_LENGTH'] );

		$this->ok( false !== strpos( (string) file_get_contents( NDVR_DIR . 'assets/js/collect.min.js' ), 'action=' ) && false !== strpos( (string) file_get_contents( NDVR_DIR . 'assets/js/reviews.min.js' ), 'action=' ), 'AC11: the built scripts put action= in the URL' );
	}

	/**
	 * AC12: verified text filter, incentive pill everywhere.
	 *
	 * @return void
	 */
	private function ac12_badges() {
		$product = $this->product( 'Badges P' );
		$offered = $this->review( $product, 'offered@example.invalid', true );
		$receive = $this->review( $product, 'received@example.invalid', true );
		$truthy  = $this->review( $product, 'truthy@example.invalid', true );
		update_comment_meta( $offered, '_ndvr_incentive_offered', 'offered' );
		update_comment_meta( $receive, '_ndvr_incentive_offered', 'received' );
		update_comment_meta( $truthy, '_ndvr_incentive_offered', '1' );
		update_comment_meta( $offered, '_ndvr_verified', 1 );

		$bought = function () {
			return 'Bought here';
		};
		add_filter( 'ndv-reviews/verified_badge_text', $bought );
		$list    = do_shortcode( '[ndvr-reviews product_id="' . $product . '"]' );
		$marquee = do_shortcode( '[ndvr-marquee product_id="' . $product . '"]' );
		remove_filter( 'ndv-reviews/verified_badge_text', $bought );
		$this->ok( false !== strpos( $list, 'Bought here' ) && false !== strpos( $marquee, 'Bought here' ), 'AC12: verified_badge_text changes the reviews list and the marquee' );

		$this->ok( false !== strpos( $list, 'Offered an incentive for reviewing' ) && false !== strpos( $list, 'Received an incentive for reviewing' ), 'AC12: offered and received pills on the reviews list' );
		$this->ok( 2 === substr_count( $list, 'Offered an incentive for reviewing' ), 'AC12: a value of 1 shows the offered text' );
		$this->ok( false !== strpos( $marquee, 'ndvr-incentive-badge' ), 'AC12: the pill shows in the marquee' );

		$view = $this->c->get( 'review_query' )->to_view( get_comment( $receive ) );
		$this->ok( true === $view['incentivized'] && 'received' === $view['incentive'], 'AC12: the view-model has incentive keys' );

		$empty = function () {
			return '';
		};
		add_filter( 'ndv-reviews/incentive_label', $empty );
		$list2 = do_shortcode( '[ndvr-reviews product_id="' . $product . '"]' );
		remove_filter( 'ndv-reviews/incentive_label', $empty );
		$this->ok( false !== strpos( $list2, 'Offered an incentive for reviewing' ), 'AC12: an empty label falls back to the free text' );

		global $wp_filter;
		$priority = false;
		foreach ( $wp_filter['ndv-reviews/review_author_badges']->callbacks as $prio => $cbs ) {
			foreach ( $cbs as $cb ) {
				if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof \NdvReviews\Display\ReviewBadges ) {
					$priority = $prio;
				}
			}
		}
		$this->ok( 5 === $priority, 'AC12: the pill listener runs at priority 5', (string) $priority );

		$later = function () {
			echo '<span class="qa-later">later</span>';
		};
		add_action( 'ndv-reviews/review_author_badges', $later, 10 );
		$list3 = do_shortcode( '[ndvr-reviews product_id="' . $product . '"]' );
		remove_action( 'ndv-reviews/review_author_badges', $later, 10 );
		$this->ok( strpos( $list3, 'ndvr-incentive-badge' ) < strpos( $list3, 'qa-later' ), 'AC12: the pill comes before a priority-10 listener' );

		// Theme overrides: 1.0.0 review-item.php keeps the pill; marquee without the action shows none.
		$this->override( 'review-item.php', file_get_contents( 'D:/.devcache/patches/pre-RR-00/templates/review-item.php' ) );
		$marquee_old = str_replace( "do_action( 'ndv-reviews/marquee_author_badges', \$ndvr_review );", '', (string) file_get_contents( NDVR_DIR . 'templates/marquee.php' ) );
		$this->override( 'marquee.php', $marquee_old );
		$list4    = do_shortcode( '[ndvr-reviews product_id="' . $product . '"]' );
		$marquee4 = do_shortcode( '[ndvr-marquee product_id="' . $product . '"]' );
		$this->remove_overrides();
		$this->ok( false !== strpos( $list4, 'ndvr-incentive-badge' ), 'AC12: a 1.0.0 review-item.php override still shows the pill' );
		$this->ok( false === strpos( $marquee4, 'ndvr-incentive-badge' ), 'AC12: a marquee override without the action shows none' );

		// Second page through admin-ajax (is_admin() true there).
		for ( $i = 0; $i < 12; $i++ ) {
			$this->review( $product, 'filler' . $i . '@example.invalid', true );
		}
		$_POST    = array(
			'nonce'      => wp_create_nonce( \NdvReviews\Display\Renderer::NONCE ),
			'product_id' => (string) $product,
			'page'       => '2',
			'per_page'   => '10',
		);
		$_REQUEST = $_POST;
		$out      = $this->capture_json( array( $this->c->get( 'renderer' ), 'ajax_list' ) );
		$_POST    = array();
		$_REQUEST = array();
		$this->ok( false !== strpos( $out, 'ndvr-incentive-badge' ), 'AC12: the pill shows on an AJAX-loaded page' );
	}

	/**
	 * AC13: questions columns, export and erasure.
	 *
	 * @return void
	 */
	private function ac13_questions() {
		global $wpdb;
		$q    = $wpdb->prefix . 'ndvr_questions';
		$a    = $wpdb->prefix . 'ndvr_answers';
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$q}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->ok( in_array( 'author_email', $cols, true ) && in_array( 'notified_at', $cols, true ) && \NdvReviews\Installer::is_current( \NdvReviews\Installer::V_QA_EMAIL ), 'AC13: questions has author_email and notified_at; V_QA_EMAIL is current' );

		$email = 'q-' . wp_generate_password( 4, false, false ) . '@example.invalid';
		$user  = wp_insert_user(
			array(
				'user_login' => 'ndvr_qaq_' . wp_generate_password( 4, false, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => $email,
				'role'       => 'customer',
			)
		);
		$this->fx['users'][] = (int) $user;
		$wpdb->insert(
			$q,
			array(
				'product_id'   => 1,
				'author_name'  => 'Asker',
				'question'     => 'Does it fit?',
				'status'       => 'approved',
				'created_at'   => current_time( 'mysql', true ),
				'author_email' => $email,
			)
		);
		$qid = (int) $wpdb->insert_id;
		$wpdb->insert(
			$a,
			array(
				'question_id' => $qid,
				'user_id'     => (int) $user,
				'author_name' => 'Answerer',
				'answer'      => 'Yes.',
				'status'      => 'approved',
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		$aid = (int) $wpdb->insert_id;

		$privacy = $this->c->get( 'privacy' );
		$export  = $privacy->export( $email, 1 );
		$groups  = wp_list_pluck( $export['data'], 'group_id' );
		$this->ok( in_array( 'ndvr_questions', $groups, true ) && in_array( 'ndvr_answers', $groups, true ), 'AC13: the export has the question and the answer' );

		$privacy->erase( $email, 1 );
		$qr = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$q}` WHERE id = %d", $qid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ar = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$a}` WHERE id = %d", $aid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->ok( null === $qr->author_email && 'Anonymous' === $qr->author_name && empty( $ar->user_id ) && 'Anonymous' === $ar->author_name, 'AC13: erasure removes the email, the account and the names' );

		$wpdb->delete( $q, array( 'id' => $qid ) );
		$wpdb->delete( $a, array( 'id' => $aid ) );
	}

	/**
	 * AC14: CSV helpers.
	 *
	 * @return void
	 */
	private function ac14_csv() {
		$guarded = \NdvReviews\Importers\Exporter::csv_cell( '=1+1' );
		$this->ok( "'=1+1" === $guarded && '=1+1' === \NdvReviews\Importers\Csv::unguard_cell( $guarded ), 'AC14: csv_cell() guards a formula and unguard_cell() reverses it' );
	}

	/**
	 * Code review fixes (code-RR-00b.md M1, M2, M3).
	 *
	 * @return void
	 */
	private function review_fixes() {
		// M3: a review still stored on the member product stays visible.
		$a    = $this->product( 'Fix A' );
		$b    = $this->product( 'Fix B' );
		$pool = $this->pool_filter( $a, $b );
		$own  = wp_insert_comment(
			array(
				'comment_post_ID'      => $b,
				'comment_author'       => 'Before the group',
				'comment_author_email' => 'before@example.invalid',
				'comment_content'      => 'Written before B joined the pool.',
				'comment_type'         => 'review',
				'comment_approved'     => 1,
			)
		);
		$this->fx['comments'][] = (int) $own;
		$this->review( $b, 'after@example.invalid', true ); // Stored on A.
		$page = $this->c->get( 'review_query' )->paginate( array( 'product_id' => $b ) );
		$this->ok( 2 === (int) $page['total'], 'M3: paginate( B ) shows the pool review and the one still on B', 'total=' . $page['total'] );
		remove_filter( 'ndv-reviews/review_pool_id', $pool, 10 );

		// M2: reviews of a still-active pool ahead of an orphan don't hide it.
		$c      = $this->product( 'Active pool C' );
		$d      = $this->product( 'Active member D' );
		$active = $this->pool_filter( $c, $d );
		$this->review( $d, 'active@example.invalid', true ); // On C, origin D, still pooled.
		$e      = $this->product( 'Old pool E' );
		$f      = $this->product( 'Old member F' );
		$old    = $this->pool_filter( $e, $f );
		$orphan = $this->review( $f, 'orphan@example.invalid', true ); // On E, origin F.
		remove_filter( 'ndv-reviews/review_pool_id', $old, 10 ); // F no longer pooled.
		$all = Pool::orphans( 1000 );
		$this->ok( in_array( $orphan, $all, true ) && array( $orphan ) === Pool::orphans( 1, $f ), 'M2: orphans() finds the orphan (limit applied after the pool check)' );
		$this->ok( ! in_array( $this->last_review_on( $c ), $all, true ), 'M2: a still-pooled review is not an orphan' );
		remove_filter( 'ndv-reviews/review_pool_id', $active, 10 );

		// M1: the Recent Reviews widget shows the incentive pill.
		$g = $this->product( 'Widget G' );
		$r = $this->review( $g, 'widget@example.invalid', true );
		update_comment_meta( $r, '_ndvr_incentive_offered', 'received' );
		$widget = new \NdvReviews\Integrations\Widgets\RecentReviewsWidget();
		ob_start();
		$widget->widget(
			array(
				'before_widget' => '',
				'after_widget'  => '',
				'before_title'  => '',
				'after_title'   => '',
			),
			array( 'limit' => 20 )
		);
		$html = (string) ob_get_clean();
		$this->ok( false !== strpos( $html, 'ndvr-incentive-badge' ), 'M1: the Recent Reviews widget shows the incentive pill' );
	}

	/**
	 * Newest review stored on a post.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	private function last_review_on( $post_id ) {
		$ids = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'all',
				'number'  => 1,
				'orderby' => 'comment_ID',
				'order'   => 'DESC',
				'fields'  => 'ids',
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Call a handler that ends in wp_send_json() and return its output.
	 *
	 * @param callable $handler Handler.
	 * @return string
	 */
	private function capture_json( $handler ) {
		$ajax = function () {
			return true;
		};
		$die  = function () {
			return function () {
				throw new NDVR_QA_00b_Die();
			};
		};
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			call_user_func( $handler );
		} catch ( NDVR_QA_00b_Die $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );

		return $out;
	}

	/**
	 * Submit a landing review with no typed name.
	 *
	 * @param string $raw        Token.
	 * @param int    $product_id Product.
	 * @return bool Success.
	 */
	private function landing_submit( $raw, $product_id ) {
		$scores = array();
		foreach ( (array) $this->c->get( 'criteria' )->get_active() as $criterion ) {
			$scores[ (int) $criterion->id ] = '5';
		}
		$_POST    = array(
			'nonce'         => wp_create_nonce( \NdvReviews\Collection\Landing::NONCE ),
			'token'         => $raw,
			'product_id'    => (string) $product_id,
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $scores,
			'comment'       => 'List review.',
		);
		$_REQUEST = $_POST;
		$out      = $this->capture_json( array( $this->c->get( 'landing' ), 'handle_submit' ) );
		$_POST    = array();
		$_REQUEST = array();
		$json     = json_decode( $out, true );

		return is_array( $json ) && ! empty( $json['success'] );
	}

	/**
	 * Map B onto A.
	 *
	 * @param int $a Pool product.
	 * @param int $b Member product.
	 * @return callable The filter (remove it after use).
	 */
	private function pool_filter( $a, $b ) {
		$f = function ( $pool, $id ) use ( $a, $b ) {
			return (int) $id === $b ? $a : $pool;
		};
		add_filter( 'ndv-reviews/review_pool_id', $f, 10, 2 );
		return $f;
	}

	/**
	 * Positions of a review's media rows.
	 *
	 * @param int $review Review id.
	 * @return int[]
	 */
	private function positions( $review ) {
		global $wpdb;
		$t = $wpdb->prefix . 'ndvr_review_media';
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT position FROM `{$t}` WHERE comment_id = %d ORDER BY position ASC", $review ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * An attachment fixture.
	 *
	 * @param string $ext  Extension.
	 * @param string $mime MIME type.
	 * @return int
	 */
	private function attachment( $ext, $mime ) {
		$dir  = wp_upload_dir();
		$file = trailingslashit( $dir['path'] ) . 'ndvr-qa-' . wp_generate_password( 6, false, false ) . '.' . $ext;
		if ( 'png' === $ext && function_exists( 'imagecreatetruecolor' ) ) {
			$im = imagecreatetruecolor( 4, 4 );
			imagepng( $im, $file );
			imagedestroy( $im );
		} else {
			file_put_contents( $file, 'qa' );
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'NDVR QA ' . $ext,
				'post_status'    => 'inherit',
			),
			$file
		);
		$this->fx['attachments'][] = (int) $id;
		return (int) $id;
	}

	/**
	 * A product.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	private function product( $name = 'RR00b QA product' ) {
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
	 * A review (pending unless approved).
	 *
	 * @param int    $product  Product.
	 * @param string $email    Email.
	 * @param bool   $approved Approve it.
	 * @return int
	 */
	private function review( $product, $email, $approved = false ) {
		$id = $this->c->get( 'reviews' )->create(
			array(
				'product_id' => $product,
				'author'     => 'QA ' . strtok( $email, '@' ),
				'email'      => $email,
				'content'    => 'QA review for ' . $email,
				'rating'     => 4,
				'source'     => 'onsite',
				'approved'   => $approved ? 1 : 0,
			)
		);
		$this->fx['comments'][] = (int) $id;
		return (int) $id;
	}

	/**
	 * Write a theme override.
	 *
	 * @param string $name    Template file name.
	 * @param string $content Content.
	 * @return void
	 */
	private function override( $name, $content ) {
		$dir = get_stylesheet_directory() . '/ndv-reviews';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/' . $name, $content );
		$this->overrides[] = $dir . '/' . $name;
	}

	/**
	 * Remove theme overrides.
	 *
	 * @return void
	 */
	private function remove_overrides() {
		foreach ( $this->overrides as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->overrides = array();
		$dir             = get_stylesheet_directory() . '/ndv-reviews';
		if ( is_dir( $dir ) && 2 === count( (array) scandir( $dir ) ) ) {
			rmdir( $dir );
		}
	}

	/**
	 * Run PHP in a child process of the QA site.
	 *
	 * @param string $code PHP code (single quotes only: escapeshellarg on Windows).
	 * @return string
	 */
	private function child( $code ) {
		$cmd = escapeshellarg( PHP_BINARY ) . ' -d memory_limit=512M ' . escapeshellarg( NDVR_QA_BOOT ) . ' eval ' . escapeshellarg( $code ) . ' 2>&1';
		return (string) shell_exec( $cmd );
	}

	/**
	 * Remove fixtures.
	 *
	 * @return void
	 */
	private function cleanup() {
		global $wpdb;
		$this->remove_overrides();
		foreach ( array_unique( $this->fx['comments'] ) as $id ) {
			if ( $id ) {
				wp_delete_comment( $id, true );
			}
		}
		foreach ( $this->fx['products'] as $id ) {
			foreach ( get_comments( array( 'post_id' => $id, 'status' => 'any', 'fields' => 'ids' ) ) as $cid ) {
				wp_delete_comment( (int) $cid, true );
			}
			$p = wc_get_product( $id );
			if ( $p ) {
				$p->delete( true );
			}
		}
		foreach ( $this->fx['orders'] as $id ) {
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
		foreach ( $this->fx['attachments'] as $id ) {
			if ( get_post( $id ) ) {
				wp_delete_attachment( $id, true );
			}
		}
		if ( $this->fx['users'] ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $this->fx['users'] as $id ) {
				wp_delete_user( $id );
			}
		}
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}ndvr_review_tokens` WHERE type = 'list' OR ( type = 'order' AND order_id IS NULL )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		as_unschedule_all_actions( \NdvReviews\Moderation\Actions::MEDIA_CLEANUP_HOOK );
		$this->line( 'cleanup: fixtures removed' );
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

$ndvr_qa_ok = ( new NDVR_QA_RR00b() )->run();
if ( defined( 'WP_CLI' ) && WP_CLI && ! $ndvr_qa_ok ) {
	\WP_CLI::halt( 1 );
}
