<?php
/**
 * RR-11 acceptance harness (PRD .agents/prd/RR-11-custom-questions.md §12).
 *
 *     php boot.php run .agents/qa/rr-11.php 1   (QA site, D:/.devcache/qa-site)
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- QA script, never shipped.

use NdvReviews\Installer;
use NdvReviews\Reviews\ReviewFieldRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the die handlers.
 */
final class NDVR_QA_11_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR11 {

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
		'comments' => array(),
		'orders'   => array(),
		'users'    => array(),
		'files'    => array(),
	);

	/**
	 * Saved options.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * Products.
	 *
	 * @var int[]
	 */
	private $p = array();

	/**
	 * Question ids.
	 *
	 * @var array<string,int>
	 */
	private $q = array();

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! class_exists( ReviewFieldRepository::class ) || ! defined( 'NDVR_API' ) || (int) NDVR_API < 8 ) {
			$this->line( 'ABORT: Rosette Reviews with RR-11 (NDVR_API 8) must be active.' );
			return false;
		}
		$this->setup();
		try {
			$this->ac1();
			$this->ac2_ac3();
			$this->ac4();
			$this->ac5_ac6_ac7();
			$this->ac8_ac9();
			$this->ac11();
			$this->ac12();
			$this->ac13_ac14();
			$this->ac10();
			$this->extras();
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
	 * The fields service.
	 *
	 * @return ReviewFieldRepository
	 */
	private function f() {
		return $this->c()->get( 'review_fields' );
	}

	/**
	 * Setup.
	 *
	 * @return void
	 */
	private function setup() {
		global $wpdb;
		$this->saved['verify'] = get_option( 'woocommerce_review_rating_verification_required', null );
		$this->saved['free']   = get_option( NDVR_OPTION_SETTINGS, null );
		$this->saved['fields'] = $wpdb->get_results( 'SELECT * FROM ' . \NdvReviews\Support\Db::table( 'review_fields' ), ARRAY_A );
		update_option( 'woocommerce_review_rating_verification_required', 'no' );
		$this->c()->get( 'settings' )->update(
			array(
				'allow_guest_reviews' => true,
				'enable_reviews'      => true,
				'photo_uploads'       => false,
				'recaptcha_enabled'   => false,
			)
		);
		$wpdb->query( 'DELETE FROM ' . \NdvReviews\Support\Db::table( 'review_fields' ) );
		$this->f()->flush();
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );
		add_filter( 'pre_wp_mail', '__return_true', 1 );
		wp_set_current_user( 1 );
		for ( $i = 0; $i < 2; $i++ ) {
			$p = new \WC_Product_Simple();
			$p->set_name( 'RR11 product ' . $i );
			$p->set_status( 'publish' );
			$p->set_regular_price( '10' );
			$p->set_reviews_allowed( true );
			$this->p[]              = (int) $p->save();
			$this->fx['products'][] = end( $this->p );
		}
	}

	/**
	 * Throwing die handlers (AJAX and admin).
	 *
	 * @param callable $fn Code.
	 * @return array{out:string,died:bool}
	 */
	private function capture( callable $fn ) {
		$die  = static function () {
			return static function () {
				throw new NDVR_QA_11_Die();
			};
		};
		$ajax = static function () {
			return true;
		};
		add_filter( 'wp_die_handler', $die );
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_doing_ajax', $ajax );
		$died = false;
		ob_start();
		try {
			call_user_func( $fn );
		} catch ( NDVR_QA_11_Die $e ) {
			$died = true;
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_die_handler', $die );
		remove_filter( 'wp_die_ajax_handler', $die );
		remove_filter( 'wp_doing_ajax', $ajax );

		return array(
			'out'  => $out,
			'died' => $died,
		);
	}

	/**
	 * POST the Questions screen.
	 *
	 * @param array<string,mixed> $post  Fields.
	 * @param bool                $nonce Add a valid nonce.
	 * @return array{out:string,died:bool,page:object}
	 */
	private function questions_post( array $post, $nonce = true ) {
		$page     = new \NdvReviews\Admin\QuestionsPage( $this->f() );
		$_POST    = $post + ( $nonce ? array( '_wpnonce' => wp_create_nonce( \NdvReviews\Admin\QuestionsPage::NONCE ) ) : array() );
		$_REQUEST = $_POST;
		$r        = $this->capture( array( $page, 'handle_actions' ) );
		$_POST    = array();
		$_REQUEST = array();
		ob_start();
		$page->render();
		$r['html'] = (string) ob_get_clean();

		return $r;
	}

	/**
	 * Criteria scores for a submission.
	 *
	 * @return array<int,string>
	 */
	private function scores() {
		$s = array();
		foreach ( (array) $this->c()->get( 'criteria' )->get_active() as $c ) {
			$s[ (int) $c->id ] = '5';
		}

		return $s;
	}

	/**
	 * Submit the product form.
	 *
	 * @param array<string,mixed> $extra Extra fields.
	 * @return array JSON.
	 */
	private function product_submit( array $extra ) {
		$prev = get_current_user_id();
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '10.11.' . wp_rand( 1, 250 ) . '.' . wp_rand( 1, 250 );
		$_POST                  = $extra + array(
			'ndvr_nonce'    => wp_create_nonce( \NdvReviews\Forms\ReviewForm::NONCE_ACTION ),
			'product_id'    => (string) $this->p[0],
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $this->scores(),
			'comment'       => 'Fits well and looks good.',
			'author'        => 'RR11 Tester',
			'email'         => 'rr11-' . wp_generate_password( 5, false, false ) . '@example.invalid',
		);
		$_REQUEST               = $_POST;
		$r                      = $this->capture( array( $this->c()->get( 'review_form' ), 'handle_submit' ) );
		$_POST                  = array();
		$_REQUEST               = array();
		wp_set_current_user( $prev );
		$json = json_decode( $r['out'], true );
		if ( is_array( $json ) && ! empty( $json['success'] ) ) {
			$this->track_latest();
		}

		return is_array( $json ) ? $json : array();
	}

	/**
	 * Remember the newest review for cleanup.
	 *
	 * @return int
	 */
	private function track_latest() {
		$ids = get_comments(
			array(
				'post__in' => $this->p,
				'number'   => 1,
				'orderby'  => 'comment_ID',
				'order'    => 'DESC',
				'fields'   => 'ids',
				'status'   => 'all',
			)
		);
		$id  = $ids ? (int) $ids[0] : 0;
		if ( $id && ! in_array( $id, $this->fx['comments'], true ) ) {
			$this->fx['comments'][] = $id;
		}

		return $id;
	}

	/**
	 * Call a private method.
	 *
	 * @param object $obj  Object.
	 * @param string $name Method.
	 * @param array  $args Args.
	 * @return mixed
	 */
	private static function call( $obj, $name, array $args = array() ) {
		$m = new \ReflectionMethod( $obj, $name );
		$m->setAccessible( true );

		return $m->invokeArgs( $obj, $args );
	}

	// ------------------------------------------------------------------

	/**
	 * AC1: upgrade path and the read guard.
	 *
	 * @return void
	 */
	private function ac1() {
		global $wpdb;
		$table = \NdvReviews\Support\Db::table( 'review_fields' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		update_option( NDVR_OPTION_DB_VERSION, (string) ( Installer::V_FIELDS - 1 ), true );
		delete_transient( Installer::BACKOFF_TRANSIENT );
		$this->f()->flush();
		$before = $this->f()->get_active();
		$id     = $this->c()->get( 'reviews' )->create(
			array(
				'product_id' => $this->p[0],
				'author'     => 'Before',
				'email'      => 'rr11-before@example.invalid',
				'content'    => 'Saved before the upgrade.',
				'criteria'   => $this->scores(),
				'source'     => 'onsite',
				'answers'    => array( 1 => 'x' ),
			)
		);
		if ( is_int( $id ) ) {
			$this->fx['comments'][] = $id;
		}
		Installer::maybe_upgrade();
		$exists = false !== $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" );
		$this->ok( array() === $before && is_int( $id ) && $id > 0, 'AC1: before the upgrade there are no questions and a submission still saves' );
		$this->ok( $exists && (int) get_option( NDVR_OPTION_DB_VERSION ) === Installer::V_FIELDS, 'AC1: the upgrade creates ndvr_review_fields and stores V_FIELDS' );
		$this->f()->flush();
	}

	/**
	 * AC2, AC3: the admin screen.
	 *
	 * @return void
	 */
	private function ac2_ac3() {
		$saved = array();
		$spy   = static function ( $id ) use ( &$saved ) {
			$saved[] = (int) $id;
		};
		add_action( 'ndv-reviews/review_fields_saved', $spy );
		$r = $this->questions_post(
			array(
				'ndvr_fields_do' => 'save',
				'ndvr_label'     => 'Fit',
				'ndvr_type'      => 'choice',
				'ndvr_options'   => "Runs small\nTrue to size\nRuns large\nTrue to size",
				'ndvr_required'  => '1',
			)
		);
		$this->questions_post(
			array(
				'ndvr_fields_do' => 'save',
				'ndvr_label'     => 'Used for',
				'ndvr_type'      => 'text',
			)
		);
		remove_action( 'ndv-reviews/review_fields_saved', $spy );
		$all             = array_values( $this->f()->get_all() );
		$this->q['fit']  = isset( $all[0] ) ? $all[0]['id'] : 0;
		$this->q['used'] = isset( $all[1] ) ? $all[1]['id'] : 0;
		$this->ok( 2 === count( $all ) && 'fit' === $all[0]['slug'] && array( 'Runs small', 'True to size', 'Runs large' ) === $all[0]['options'] && $all[0]['required'] && 'text' === $all[1]['type'] && ! $all[1]['required'], 'AC2: the screen creates Fit (choice, 3 options, duplicate line dropped, required) and Used for (text, optional)' );
		$this->ok( 2 === count( $saved ) && false !== strpos( $r['html'], 'Question added.' ), 'AC2: review_fields_saved fires for each save; the notice shows' );

		$n = count( $this->f()->get_all() );
		$r = $this->questions_post(
			array(
				'ndvr_fields_do' => 'save',
				'ndvr_label'     => 'No nonce',
				'ndvr_type'      => 'text',
			),
			false
		);
		$this->ok( $r['died'] && count( $this->f()->get_all() ) === $n, 'AC2: a POST without the nonce changes nothing' );
		$uid                 = (int) wp_insert_user(
			array(
				'user_login' => 'rr11sub' . wp_rand( 1000, 9999 ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'rr11sub' . wp_rand( 1000, 9999 ) . '@example.invalid',
				'role'       => 'subscriber',
			)
		);
		$this->fx['users'][] = $uid;
		wp_set_current_user( $uid );
		$r = $this->questions_post(
			array(
				'ndvr_fields_do' => 'save',
				'ndvr_label'     => 'Subscriber',
				'ndvr_type'      => 'text',
			)
		);
		wp_set_current_user( 1 );
		$this->ok( $r['died'] && count( $this->f()->get_all() ) === $n, 'AC2: a user without the capability changes nothing' );

		// AC3.
		$r = $this->questions_post(
			array(
				'ndvr_fields_do' => 'save',
				'ndvr_label'     => 'Third',
				'ndvr_type'      => 'yesno',
			)
		);
		$this->ok( count( $this->f()->get_all() ) === $n && false !== strpos( $r['html'], 'You can have 2 active questions.' ) && false !== strpos( $r['html'], '2 of 2 questions active. Deactivate one to add another.' ), 'AC3: a third active question is refused with the notice' );
		$ten = static function () {
			return 10;
		};
		add_filter( 'ndv-reviews/max_review_fields', $ten );
		$third = $this->f()->insert(
			array(
				'label' => 'Third',
				'type'  => 'yesno',
			)
		);
		remove_filter( 'ndv-reviews/max_review_fields', $ten );
		$this->ok( is_int( $third ) && $third > 0, 'AC3: with max_review_fields 10 the third is allowed' );
		$this->f()->delete( (int) $third );
		$bad = $this->f()->insert(
			array(
				'label'   => 'Bad',
				'type'    => 'choice',
				'options' => 'Only one',
				'status'  => 'inactive',
			)
		);
		$this->ok( is_wp_error( $bad ) && 'ndvr_field_options' === $bad->get_error_code(), 'Validation: a choice question needs 2 to 6 options' );
	}

	/**
	 * AC4: the product form markup.
	 *
	 * @return void
	 */
	private function ac4() {
		$html  = (string) self::call( $this->c()->get( 'review_form' ), 'render_fields' );
		$area  = strpos( $html, 'name="comment"' );
		$fit   = strpos( $html, '>Fit' );
		$used  = strpos( $html, '>Used for' );
		$photo = strpos( $html, 'ndvr-photos' );
		$this->c()->get( 'settings' )->update( array( 'photo_uploads' => true ) );
		$with = (string) self::call( $this->c()->get( 'review_form' ), 'render_fields' );
		$this->c()->get( 'settings' )->update( array( 'photo_uploads' => false ) );
		$this->ok( false !== $area && false !== $fit && false !== $used && $area < $fit && $fit < $used && false === $photo && strpos( $with, '>Used for' ) < strpos( $with, 'ndvr-photos' ), 'AC4: both questions render in order after the textarea and before the photos field' );
		$this->ok( false !== strpos( $html, 'id="ndvr-f' . $this->q['fit'] . '-o2"' ) && false !== strpos( $html, 'name="ndvr_answers_present"' ) && false !== strpos( $html, 'required' ), 'AC4: option ids, the present marker and required attributes' );
	}

	/**
	 * AC5–AC7: product form submissions.
	 *
	 * @return void
	 */
	private function ac5_ac6_ac7() {
		global $wpdb;
		$atts   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
		$before = (int) get_comments(
			array(
				'post__in' => $this->p,
				'count'    => true,
				'status'   => 'all',
			)
		);
		$res    = $this->product_submit( array( 'ndvr_answers_present' => '1' ) );
		$after  = (int) get_comments(
			array(
				'post__in' => $this->p,
				'count'    => true,
				'status'   => 'all',
			)
		);
		$this->ok( empty( $res['success'] ) && 'Fit needs an answer.' === ( $res['data']['message'] ?? '' ) && $after === $before && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ) === $atts, 'AC5: no Fit answer → "Fit needs an answer.", nothing stored (' . wp_json_encode( $res ) . ')' );

		$res = $this->product_submit(
			array(
				'ndvr_answers_present' => '1',
				'ndvr_answers'         => array(
					$this->q['fit']  => 'True to size',
					$this->q['used'] => 'Daily walks',
				),
			)
		);
		$cid = $this->track_latest();
		$this->ok(
			! empty( $res['success'] ) && array(
				$this->q['fit']  => 'True to size',
				$this->q['used'] => 'Daily walks',
			) == get_comment_meta( $cid, ReviewFieldRepository::ANSWERS_META, true ),
			'AC6: a valid submission stores both answers'
		); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
		wp_set_comment_status( $cid, 'approve' );
		$list = do_shortcode( '[ndvr-reviews product_id="' . $this->p[0] . '"]' );
		$body = strpos( $list, 'ndvr-review-body' );
		$ans  = strpos( $list, '<dl class="ndvr-answers"><div><dt>Fit</dt><dd>True to size</dd></div>' );
		$crit = strpos( $list, 'ndvr-review-criteria' );
		$this->ok( false !== $ans && $body < $ans && ( false === $crit || $ans < $crit ), 'AC6: the card shows <dt>Fit</dt><dd>True to size</dd> after the body and before the criteria' );
		$this->saved['cid'] = $cid;

		$res = $this->product_submit(
			array(
				'ndvr_answers_present' => '1',
				'ndvr_answers'         => array(
					$this->q['fit']  => 'Enormous',
					$this->q['used'] => str_repeat( 'a', 200 ),
				),
			)
		);
		$this->ok( empty( $res['success'] ) && 'Fit needs an answer.' === ( $res['data']['message'] ?? '' ), 'AC7: a choice value not in the options is dropped (so the required Fit is missing)' );
		$res    = $this->product_submit(
			array(
				'ndvr_answers_present' => '1',
				'ndvr_answers'         => array(
					$this->q['fit']  => 'Runs small',
					$this->q['used'] => str_repeat( 'a', 200 ),
					'999999'         => 'unknown',
				),
			)
		);
		$stored = get_comment_meta( $this->track_latest(), ReviewFieldRepository::ANSWERS_META, true );
		$this->ok( ! empty( $res['success'] ) && 120 === mb_strlen( (string) ( $stored[ $this->q['used'] ] ?? '' ) ) && ! isset( $stored[999999] ), 'AC7: a 200-character text answer is stored as 120; an unknown field id is dropped' );
	}

	/**
	 * AC8, AC9: the landing page.
	 *
	 * @return void
	 */
	private function ac8_ac9() {
		$email = 'rr11-land-' . wp_generate_password( 5, false, false ) . '@example.invalid';
		$order = wc_create_order();
		foreach ( $this->p as $pid ) {
			$order->add_product( wc_get_product( $pid ), 1 );
		}
		$order->set_billing_email( $email );
		$order->set_billing_first_name( 'Lana' );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();
		$token                = $this->c()->get( 'token_repository' )->create_order_token_row( $order->get_id(), $email, $this->p, 0 );

		$html = \NdvReviews\Support\View::render(
			'magic-landing.php',
			array(
				'valid'          => true,
				'token'          => $token['raw'],
				'products'       => $this->p,
				'criteria'       => $this->c()->get( 'criteria' )->get_active(),
				'settings'       => $this->c()->get( 'settings' ),
				'nonce'          => wp_create_nonce( \NdvReviews\Collection\Landing::NONCE ),
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'ajax_action'    => \NdvReviews\Collection\Landing::AJAX_ACTION,
				'default_author' => 'Lana',
				'is_test'        => false,
				'review_fields'  => $this->f()->get_active(),
			)
		);
		$a    = 'id="p' . $this->p[0] . '-f' . $this->q['fit'] . '-o1"';
		$b    = 'id="p' . $this->p[1] . '-f' . $this->q['fit'] . '-o1"';
		$this->ok( 1 === substr_count( $html, $a ) && 1 === substr_count( $html, $b ) && 2 === substr_count( $html, 'name="ndvr_answers_present"' ), 'AC8: the landing page has the questions in each product form with unique ids' );

		$land = function ( $product, array $extra ) use ( $token ) {
			$prev = get_current_user_id();
			wp_set_current_user( 0 );
			$_POST    = $extra + array(
				'nonce'         => wp_create_nonce( \NdvReviews\Collection\Landing::NONCE ),
				'token'         => $token['raw'],
				'product_id'    => (string) $product,
				'ndvr_consent'  => '1',
				'ndvr_criteria' => $this->scores(),
				'comment'       => 'From the email link.',
			);
			$_REQUEST = $_POST;
			$r        = $this->capture( array( $this->c()->get( 'landing' ), 'handle_submit' ) );
			wp_set_current_user( $prev );
			$_POST    = array();
			$_REQUEST = array();
			$json     = json_decode( $r['out'], true );

			return is_array( $json ) ? $json : array();
		};
		$res  = $land(
			$this->p[0],
			array(
				'ndvr_answers_present' => '1',
				'ndvr_answers'         => array( $this->q['fit'] => 'Runs large' ),
			)
		);
		$cid  = $this->track_latest();
		$this->ok( ! empty( $res['success'] ) && array( $this->q['fit'] => 'Runs large' ) == get_comment_meta( $cid, ReviewFieldRepository::ANSWERS_META, true ), 'AC8: ndvr_collect_submit stores the answer on that product\'s review (' . wp_json_encode( $res ) . ')' ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
		$res = $land( $this->p[1], array() );
		$this->track_latest();
		$this->ok( ! empty( $res['success'] ), 'AC9: an old override (no marker) without Fit still saves' );
	}

	/**
	 * AC11: the admin edit screen.
	 *
	 * @return void
	 */
	private function ac11() {
		$cid  = (int) $this->saved['cid'];
		$page = $this->c()->get( 'moderation_page' );
		$_GET = array( 'review' => (string) $cid );
		ob_start();
		self::call( $page, 'render_edit' );
		$html = (string) ob_get_clean();
		$_GET = array();
		$this->ok( false !== strpos( $html, '<th>Answers</th>' ) && false !== strpos( $html, 'value="Daily walks"' ) && 1 === preg_match( '/value="True to size"\s+selected=/', $html ), 'AC11: the edit screen shows the answers' );
		$_POST    = array(
			'review'               => (string) $cid,
			'ndvr_content'         => 'Fits well and looks good.',
			'ndvr_title'           => '',
			'ndvr_criteria'        => $this->scores(),
			'ndvr_answers_present' => '1',
			'ndvr_answers'         => array(
				$this->q['fit']  => 'Runs small',
				$this->q['used'] => 'Hiking',
			),
		);
		$_REQUEST = $_POST;
		$throw    = static function () {
			throw new NDVR_QA_11_Die();
		};
		add_filter( 'wp_redirect', $throw, 1 );
		try {
			self::call( $page, 'save_edit' );
		} catch ( NDVR_QA_11_Die $e ) {
			unset( $e );
		}
		remove_filter( 'wp_redirect', $throw, 1 );
		$_POST    = array();
		$_REQUEST = array();
		$this->ok(
			array(
				$this->q['fit']  => 'Runs small',
				$this->q['used'] => 'Hiking',
			) == get_comment_meta( $cid, ReviewFieldRepository::ANSWERS_META, true ),
			'AC11: saving changes the stored answers'
		); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}

	/**
	 * AC12: privacy.
	 *
	 * @return void
	 */
	private function ac12() {
		$this->f()->update( $this->q['fit'], array( 'filterable' => true ) );
		$cid = (int) $this->saved['cid'];
		$this->f()->save_answers( $cid, $this->f()->stored( $cid ) );
		$has_key = '' !== (string) get_comment_meta( $cid, ReviewFieldRepository::ANSWER_PREFIX . $this->q['fit'], true );

		// paginate's answers arg (Pro filter chips) on the filterable field.
		$hit  = $this->c()->get( 'review_query' )->paginate(
			array(
				'product_id' => $this->p[0],
				'answers'    => array( $this->q['fit'] => 'Runs small' ),
			)
		);
		$miss = $this->c()->get( 'review_query' )->paginate(
			array(
				'product_id' => $this->p[0],
				'answers'    => array( $this->q['fit'] => 'Runs large' ),
			)
		);
		$this->ok( $has_key && in_array( $cid, wp_list_pluck( $hit['items'], 'id' ), true ) && ! in_array( $cid, wp_list_pluck( $miss['items'], 'id' ), true ), 'paginate( answers ) filters on a filterable question\'s _ndvr_ans_ key' );

		$email     = get_comment( $cid )->comment_author_email;
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$found     = false;
		foreach ( $exporters as $exporter ) {
			$data = call_user_func( $exporter['callback'], $email, 1 );
			foreach ( (array) ( $data['data'] ?? array() ) as $item ) {
				foreach ( (array) $item['data'] as $row ) {
					$found = $found || ( 'Question: Used for' === $row['name'] && 'Hiking' === $row['value'] );
				}
			}
		}
		$this->ok( $found, 'AC12: the privacy export lists the answers' );
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		foreach ( $erasers as $eraser ) {
			call_user_func( $eraser['callback'], $email, 1 );
		}
		global $wpdb;
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE comment_id = %d AND ( meta_key = %s OR meta_key LIKE %s )", $cid, ReviewFieldRepository::ANSWERS_META, $wpdb->esc_like( ReviewFieldRepository::ANSWER_PREFIX ) . '%' ) );
		$this->ok( 0 === $left, 'AC12: erasure removes _ndvr_answers and every _ndvr_ans_* key' );
		$this->f()->update( $this->q['fit'], array( 'filterable' => false ) );
	}

	/**
	 * AC13, AC14: definition changes.
	 *
	 * @return void
	 */
	private function ac13_ac14() {
		$cid = (int) $this->product_submit(
			array(
				'ndvr_answers_present' => '1',
				'ndvr_answers'         => array(
					$this->q['fit']  => 'True to size',
					$this->q['used'] => 'Weekends',
				),
			)
		)['success'] ? $this->track_latest() : 0;
		wp_set_comment_status( $cid, 'approve' );

		// AC14 first: editing an option label leaves stored answers alone.
		$this->f()->update( $this->q['fit'], array( 'options' => "Runs small\nExact fit\nRuns large" ) );
		$stored = $this->f()->stored( $cid );
		$this->ok( 'True to size' === ( $stored[ $this->q['fit'] ] ?? '' ), 'AC14: editing an option label doesn\'t change stored answers' );

		$this->f()->update( $this->q['used'], array( 'status' => 'inactive' ) );
		$labels = wp_list_pluck( $this->f()->answers_for_view( $cid ), 'label' );
		$this->ok( in_array( 'Used for', $labels, true ), 'AC13: a deactivated question\'s answers stay visible' );
		$fit_def = $this->f()->find( $this->q['fit'] );
		$this->f()->delete( $this->q['fit'] );
		$list = do_shortcode( '[ndvr-reviews product_id="' . $this->p[0] . '"]' );
		$this->ok( false === strpos( $list, '<dt>Fit</dt>' ) && false !== strpos( $list, '<dt>Used for</dt><dd>Weekends</dd>' ), 'AC13: deleting Fit hides its answers on cards' );
		// Restore Fit for AC10 (as a choice, same slug).
		$this->q['fit'] = (int) $this->f()->insert(
			array(
				'label'    => 'Fit',
				'type'     => 'choice',
				'options'  => $fit_def['options'],
				'required' => true,
			)
		);
		$this->f()->update( $this->q['used'], array( 'status' => 'active' ) );
	}

	/**
	 * AC10: CSV export and import.
	 *
	 * @return void
	 */
	private function ac10() {
		global $wpdb;
		$exporter = $this->c()->get( 'exporter' );
		$columns  = (array) self::call( $exporter, 'columns' );
		$this->ok( in_array( 'q_fit', $columns, true ) && in_array( 'q_used-for', $columns, true ), 'AC10: the export has q_fit and q_used-for columns' );

		// A file shaped like the export (header + rows), built from rows().
		$path = wp_tempnam( 'rr11.csv' );
		$fh   = fopen( $path, 'w' );
		fputcsv( $fh, array( 'product_id', 'author', 'email', 'rating', 'content', 'q_fit', 'q_used-for' ), ',', '"', '\\' );
		fputcsv( $fh, array( $this->p[0], 'Imp A', 'rr11-impa@example.invalid', '5', 'Imported A ' . wp_generate_password( 4, false, false ), 'True to size', 'Gym' ), ',', '"', '\\' );
		fputcsv( $fh, array( $this->p[0], 'Imp B', 'rr11-impb@example.invalid', '4', 'Imported B ' . wp_generate_password( 4, false, false ), 'Enormous', '' ), ',', '"', '\\' );
		fputcsv( $fh, array( $this->p[0], 'Imp C', 'rr11-impc@example.invalid', '4', 'Imported C ' . wp_generate_password( 4, false, false ), '', 'Work' ), ',', '"', '\\' );
		fclose( $fh );
		$this->fx['files'][] = $path;
		$rows                = iterator_to_array( self::call( $exporter, 'rows' ), false );
		$this->ok(
			(bool) array_filter(
				$rows,
				static function ( $r ) {
					return 'Weekends' === ( $r['q_used-for'] ?? '' );
				}
			),
			'AC10: exported rows carry the stored answer under q_used-for'
		);

		// Clean site: no questions.
		$wpdb->query( 'DELETE FROM ' . \NdvReviews\Support\Db::table( 'review_fields' ) );
		$this->f()->flush();
		$res = $this->c()->get( 'csv_importer' )->import( $path );
		$imp = $this->imported();
		$fit = null;
		foreach ( $this->f()->get_all() as $field ) {
			if ( 'fit' === $field['slug'] ) {
				$fit = $field;
			}
		}
		$a = $imp ? $this->f()->stored( $imp[0] ) : array();
		$this->ok( 3 === (int) $res['imported'] && $fit && 'text' === $fit['type'] && 'inactive' === $fit['status'] && 0 === $this->f()->count_active() && 'True to size' === ( $a[ $fit['id'] ] ?? '' ), 'AC10: importing into a clean site creates an inactive Short text "fit" and stores the answers (imported ' . (int) $res['imported'] . ')' );
		$form = (string) self::call( $this->c()->get( 'review_form' ), 'render_fields' );
		$this->ok( false === strpos( $form, 'ndvr-questions' ), 'AC10: the product form renders no question' );
		$this->ok( $imp && $this->f()->answers_for_view( $imp[0] ), 'AC10: the imported answers are there for the cards' );

		// Again into a site where Fit is a Choice question.
		foreach ( $imp as $cid ) {
			wp_delete_comment( $cid, true );
		}
		$wpdb->query( 'DELETE FROM ' . \NdvReviews\Support\Db::table( 'review_fields' ) );
		$this->f()->flush();
		$choice = (int) $this->f()->insert(
			array(
				'label'    => 'Fit',
				'type'     => 'choice',
				'options'  => "Runs small\nTrue to size\nRuns large",
				'required' => true,
			)
		);
		$res    = $this->c()->get( 'csv_importer' )->import( $path );
		$imp    = $this->imported();
		$got    = array();
		foreach ( $imp as $cid ) {
			$got[] = $this->f()->stored( $cid )[ $choice ] ?? '';
		}
		sort( $got );
		$this->ok( 3 === (int) $res['imported'] && array( '', '', 'True to size' ) === $got, 'AC10: against a Choice "Fit", valid answers are restored, invalid ones dropped, and a row missing the required answer imports' );
		foreach ( $imp as $cid ) {
			wp_delete_comment( $cid, true );
		}
	}

	/**
	 * Imported review ids from this run.
	 *
	 * @return int[]
	 */
	private function imported() {
		return array_map(
			'intval',
			get_comments(
				array(
					'post_id'    => $this->p[0],
					'meta_key'   => '_ndvr_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => 'import', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'     => 'ids',
					'status'     => 'all',
					'orderby'    => 'comment_ID',
					'order'      => 'ASC',
				)
			)
		);
	}

	/**
	 * Pro-facing hooks and the question-form hook.
	 *
	 * @return void
	 */
	private function extras() {
		$seen = 0;
		$cb   = static function () use ( &$seen ) {
			++$seen;
		};
		add_action( 'ndv-reviews/review_field_form_after', $cb );
		ob_start();
		( new \NdvReviews\Admin\QuestionsPage( $this->f() ) )->render();
		ob_end_clean();
		remove_action( 'ndv-reviews/review_field_form_after', $cb );
		$order = apply_filters( 'ndv-reviews/admin_submenu_order', array() );
		$this->ok( $seen >= 0 && has_action( 'ndv-reviews/moderation_edit_fields' ) && has_action( 'ndv-reviews/moderation_edit_save' ), 'The moderation_edit_fields / moderation_edit_save actions are wired' );
		unset( $order );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	private function teardown() {
		global $wpdb;
		foreach ( $this->fx['comments'] as $id ) {
			wp_delete_comment( (int) $id, true );
		}
		foreach ( $this->fx['products'] as $id ) {
			foreach ( get_comments(
				array(
					'post_id' => $id,
					'fields'  => 'ids',
					'status'  => 'all',
				)
			) as $cid ) {
				wp_delete_comment( (int) $cid, true );
			}
			wp_delete_post( $id, true );
		}
		foreach ( $this->fx['orders'] as $id ) {
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->fx['users'] as $id ) {
			wp_delete_user( $id );
		}
		foreach ( $this->fx['files'] as $f ) {
			wp_delete_file( $f );
		}
		$table = \NdvReviews\Support\Db::table( 'review_fields' );
		$wpdb->query( "DELETE FROM `{$table}`" );
		foreach ( (array) $this->saved['fields'] as $row ) {
			$wpdb->insert( $table, $row );
		}
		$this->f()->flush();
		if ( null === $this->saved['verify'] ) {
			delete_option( 'woocommerce_review_rating_verification_required' );
		} else {
			update_option( 'woocommerce_review_rating_verification_required', $this->saved['verify'] );
		}
		if ( null === $this->saved['free'] ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved['free'] );
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

( new NDVR_QA_RR11() )->run();
