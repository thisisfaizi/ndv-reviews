<?php
/**
 * RR-12 acceptance harness (PRD .agents/prd/RR-12-minimum-length.md §12).
 *
 *     php boot.php run .agents/qa/rr-12.php 1   (QA site, D:/.devcache/qa-site)
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions -- QA script, never shipped.

use NdvReviews\Reviews\ReviewLength;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the die handlers.
 */
final class NDVR_QA_12_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR12 {

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
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! class_exists( ReviewLength::class ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-12 must be active.' );
			return false;
		}
		$this->setup();
		try {
			$this->ac1_ac8();
			$this->ac2_ac3_ac4();
			$this->ac5_ac6();
			$this->ac7();
			$this->ac9();
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
	 * Set the minimum.
	 *
	 * @param int $min Minimum.
	 * @return void
	 */
	private function min( $min ) {
		$this->c()->get( 'settings' )->update( array( ReviewLength::SETTING => ReviewLength::sanitize( $min ) ) );
	}

	/**
	 * Setup.
	 *
	 * @return void
	 */
	private function setup() {
		$this->saved['verify'] = get_option( 'woocommerce_review_rating_verification_required', null );
		$this->saved['free']   = get_option( NDVR_OPTION_SETTINGS, null );
		update_option( 'woocommerce_review_rating_verification_required', 'no' );
		$this->c()->get( 'settings' )->update(
			array(
				'allow_guest_reviews' => true,
				'enable_reviews'      => true,
				'photo_uploads'       => false,
				'recaptcha_enabled'   => false,
			)
		);
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );
		add_filter( 'pre_wp_mail', '__return_true', 1 );
		// No review questions get in the way.
		add_filter( 'ndv-reviews/max_review_fields', '__return_zero' );
		wp_set_current_user( 1 );
		for ( $i = 0; $i < 2; $i++ ) {
			$p = new \WC_Product_Simple();
			$p->set_name( 'RR12 product ' . $i );
			$p->set_status( 'publish' );
			$p->set_regular_price( '10' );
			$p->set_reviews_allowed( true );
			$this->p[]              = (int) $p->save();
			$this->fx['products'][] = end( $this->p );
		}
	}

	/**
	 * Criteria scores.
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
	 * create() with this text and source.
	 *
	 * @param string $content Text.
	 * @param string $source  Source.
	 * @return int|\WP_Error
	 */
	private function create( $content, $source = 'onsite' ) {
		return $this->c()->get( 'reviews' )->create(
			array(
				'product_id' => $this->p[0],
				'author'     => 'RR12',
				'email'      => 'rr12-' . wp_generate_password( 5, false, false ) . '@example.invalid',
				'content'    => $content,
				'criteria'   => $this->scores(),
				'source'     => $source,
			)
		);
	}

	/**
	 * Run an AJAX handler as a guest; JSON out.
	 *
	 * @param callable $handler Handler.
	 * @param array    $post    POST (nonce added by $nonce).
	 * @param string   $nonce   Nonce action.
	 * @param string   $field   Nonce field.
	 * @return array
	 */
	private function ajax( callable $handler, array $post, $nonce, $field ) {
		$prev = get_current_user_id();
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '10.12.' . wp_rand( 1, 250 ) . '.' . wp_rand( 1, 250 );
		$_POST                  = $post + array( $field => wp_create_nonce( $nonce ) );
		$_REQUEST               = $_POST;
		$die                    = static function () {
			return static function () {
				throw new NDVR_QA_12_Die();
			};
		};
		$ajax                   = static function () {
			return true;
		};
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_doing_ajax', $ajax );
		ob_start();
		try {
			call_user_func( $handler );
		} catch ( NDVR_QA_12_Die $e ) {
			unset( $e );
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_die_ajax_handler', $die );
		remove_filter( 'wp_doing_ajax', $ajax );
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( $prev );
		$json = json_decode( $out, true );

		return is_array( $json ) ? $json : array();
	}

	/**
	 * Number of reviews on the fixtures.
	 *
	 * @return int
	 */
	private function reviews() {
		return (int) get_comments(
			array(
				'post__in' => $this->p,
				'count'    => true,
				'status'   => 'all',
			)
		);
	}

	/**
	 * A landing token for both products.
	 *
	 * @return string
	 */
	private function token() {
		$email = 'rr12-land-' . wp_generate_password( 5, false, false ) . '@example.invalid';
		$order = wc_create_order();
		foreach ( $this->p as $pid ) {
			$order->add_product( wc_get_product( $pid ), 1 );
		}
		$order->set_billing_email( $email );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();

		return $this->c()->get( 'token_repository' )->create_order_token_row( $order->get_id(), $email, $this->p, 0 )['raw'];
	}

	/**
	 * AC1, AC8.
	 *
	 * @return void
	 */
	private function ac1_ac8() {
		$this->min( 30 );
		$r = $this->create( 'Too short.' );
		$this->ok( is_wp_error( $r ) && 'ndvr_too_short' === $r->get_error_code() && 'Please write at least 30 characters.' === $r->get_error_message(), 'AC1: create() (onsite, 10 characters) → ndvr_too_short "Please write at least 30 characters."' );
		$r = $this->create( 'Too short.', 'list_link' );
		$this->ok( is_wp_error( $r ) && 'ndvr_too_short' === $r->get_error_code(), 'AC8: list_link with 10 characters → ndvr_too_short' );

		$n   = $this->reviews();
		$res = $this->ajax(
			array( $this->c()->get( 'review_form' ), 'handle_submit' ),
			array(
				'product_id'    => (string) $this->p[0],
				'ndvr_consent'  => '1',
				'ndvr_criteria' => $this->scores(),
				'comment'       => 'Too short.',
				'author'        => 'Guest',
				'email'         => 'rr12-g@example.invalid',
			),
			\NdvReviews\Forms\ReviewForm::NONCE_ACTION,
			'ndvr_nonce'
		);
		$this->ok( empty( $res['success'] ) && 'Please write at least 30 characters.' === ( $res['data']['message'] ?? '' ) && $this->reviews() === $n, 'AC1: ndvr_submit_review returns the message and stores nothing' );

		$res = $this->ajax(
			array( $this->c()->get( 'testimonial_form' ), 'handle_submit' ),
			array(
				'product_id'    => (string) $this->p[0],
				'ndvr_consent'  => '1',
				'ndvr_criteria' => $this->scores(),
				'comment'       => 'Too short.',
				'author'        => 'Guest',
				'email'         => 'rr12-t@example.invalid',
			),
			\NdvReviews\Forms\TestimonialForm::NONCE,
			'nonce'
		);
		$this->ok( empty( $res['success'] ) && 'Please write at least 30 characters.' === ( $res['data']['message'] ?? '' ) && $this->reviews() === $n, 'AC1: ndvr_testimonial_submit returns the message and stores nothing' );

		$res = $this->ajax(
			array( $this->c()->get( 'landing' ), 'handle_submit' ),
			array(
				'token'         => $this->token(),
				'product_id'    => (string) $this->p[0],
				'ndvr_consent'  => '1',
				'ndvr_criteria' => $this->scores(),
				'comment'       => 'Too short.',
			),
			\NdvReviews\Collection\Landing::NONCE,
			'nonce'
		);
		$this->ok( empty( $res['success'] ) && 'Please write at least 30 characters.' === ( $res['data']['message'] ?? '' ) && $this->reviews() === $n, 'AC1: ndvr_collect_submit returns the message and stores nothing (' . wp_json_encode( $res ) . ')' );
	}

	/**
	 * AC2–AC4: counting.
	 *
	 * @return void
	 */
	private function ac2_ac3_ac4() {
		$this->min( 30 );
		$emoji = "\u{1F600}";
		$a     = $this->create( str_repeat( $emoji, 30 ) );
		$b     = $this->create( str_repeat( $emoji, 29 ) );
		$c     = $this->create( str_repeat( "\u{597D}", 30 ) );
		$this->ok( is_int( $a ) && is_wp_error( $b ) && is_int( $c ), 'AC2: 30 emoji accepted, 29 refused; 30 CJK characters accepted' );
		$this->ok( 3 === ReviewLength::count( 'a' . str_repeat( ' ', 40 ) . 'b' ) && is_wp_error( $this->create( 'a' . str_repeat( ' ', 40 ) . 'b' ) ), 'AC3: "a" + 40 spaces + "b" counts as 3 and is refused' );
		$this->ok( 11 === ReviewLength::count( '<strong>Too short</strong> &amp;' ), 'AC4: tags and &amp; don\'t inflate the count (11)' );
		$this->ok( 500 === ReviewLength::sanitize( 900 ) && 0 === ReviewLength::sanitize( 'x' ), 'Setting: values are clamped to 0–500' );
	}

	/**
	 * AC5, AC6.
	 *
	 * @return void
	 */
	private function ac5_ac6() {
		$this->min( 30 );
		$path = wp_tempnam( 'rr12.csv' );
		file_put_contents( $path, "product_id,author,email,rating,content\n" . $this->p[0] . ",Imp,rr12-imp@example.invalid,5,Short\n" );
		$this->fx['files'][] = $path;
		$res                 = $this->c()->get( 'csv_importer' )->import( $path );
		$this->ok( 1 === (int) $res['imported'], 'AC5: a 5-character review imports through Importers\\Csv' );

		$this->min( 0 );
		$product = (string) $this->form( 'product' );
		$testi   = (string) $this->form( 'testimonial' );
		$landing = (string) $this->form( 'landing' );
		$none    = true;
		foreach ( array( $product, $testi, $landing ) as $html ) {
			$none = $none && false === strpos( $html, 'data-ndvr-min-length' ) && false === strpos( $html, 'ndvr-length-hint' );
		}
		$this->ok( $none && '' !== $product && '' !== $testi && '' !== $landing, 'AC6: with minimum 0 no form carries data-ndvr-min-length or a hint' );
		$this->ok( is_int( $this->create( 'K' ) ), 'AC6: with minimum 0 a 1-character review saves' );
	}

	/**
	 * Render a form.
	 *
	 * @param string $which product|testimonial|landing.
	 * @return string
	 */
	private function form( $which ) {
		if ( 'product' === $which ) {
			$m = new \ReflectionMethod( $this->c()->get( 'review_form' ), 'render_fields' );
			$m->setAccessible( true );
			return (string) $m->invoke( $this->c()->get( 'review_form' ) );
		}
		if ( 'testimonial' === $which ) {
			return (string) do_shortcode( '[ndvr-form product_id="' . $this->p[0] . '"]' );
		}

		return \NdvReviews\Support\View::render(
			'magic-landing.php',
			array(
				'valid'          => true,
				'token'          => 'x',
				'products'       => $this->p,
				'criteria'       => $this->c()->get( 'criteria' )->get_active(),
				'settings'       => $this->c()->get( 'settings' ),
				'nonce'          => 'n',
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'ajax_action'    => \NdvReviews\Collection\Landing::AJAX_ACTION,
				'default_author' => '',
				'is_test'        => false,
				'review_fields'  => array(),
			)
		);
	}

	/**
	 * AC7: markup with minimum 30.
	 *
	 * @return void
	 */
	private function ac7() {
		$this->min( 30 );
		foreach ( array( 'product', 'testimonial', 'landing' ) as $which ) {
			$html  = $this->form( $which );
			$areas = preg_match_all( '/<textarea[^>]*name="comment"[^>]*>/', $html, $m );
			$ok    = $areas > 0;
			foreach ( $m[0] as $tag ) {
				$ok = $ok && false !== strpos( $tag, 'data-ndvr-min-length="30"' ) && false !== strpos( $tag, 'data-ndvr-status-reached="Minimum length reached."' ) && false !== strpos( $tag, 'data-ndvr-status-format="%1$d of %2$d characters."' )
					&& preg_match( '/aria-describedby="([^"]+)"/', $tag, $d ) && 1 === substr_count( $html, 'id="' . $d[1] . '"' ) && preg_match( '/<span class="ndvr-length-hint" id="' . preg_quote( $d[1], '/' ) . '">At least 30 characters\.<\/span>/', $html );
			}
			$counts  = preg_match_all( '/<span class="ndvr-length-count"([^>]*)>/', $html, $cm );
			$no_live = $counts === $areas && false === strpos( implode( '', $cm[1] ), 'aria-live' );
			$status  = preg_match_all( '/<span class="ndvr-length-status screen-reader-text" role="status" aria-live="polite"><\/span>/', $html );
			$this->ok( $ok && $no_live && $status === $areas, "AC7 ({$which}): data attributes, hint id = aria-describedby, counter without aria-live, one empty polite status per textarea ({$areas})" );
		}
	}

	/**
	 * AC9: the filter.
	 *
	 * @return void
	 */
	private function ac9() {
		$this->min( 30 );
		$fifty = static function () {
			return 50;
		};
		add_filter( 'ndv-reviews/min_review_length', $fifty );
		$r = $this->create( str_repeat( 'x', 40 ) );
		remove_filter( 'ndv-reviews/min_review_length', $fifty );
		$this->ok( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), '50' ) && is_int( $this->create( str_repeat( 'x', 40 ) ) ), 'AC9: the filter returning 50 raises the floor for that request only' );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	private function teardown() {
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
		foreach ( $this->fx['files'] as $f ) {
			wp_delete_file( $f );
		}
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

( new NDVR_QA_RR12() )->run();
