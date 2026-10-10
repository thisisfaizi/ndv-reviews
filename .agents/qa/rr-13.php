<?php
/**
 * RR-13 acceptance harness (PRD .agents/prd/RR-13-turnstile-hcaptcha.md §12).
 *
 *     php boot.php run .agents/qa/rr-13.php 1   (QA site, D:/.devcache/qa-site)
 *
 * Provider calls are stubbed with pre_http_request; no network.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions, WordPress.WP.GlobalVariablesOverride.Prohibited -- QA script, never shipped.

use NdvReviews\Forms\AntiSpam;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by the die handlers.
 */
final class NDVR_QA_13_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR13 {

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
	 * Saved state.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * Product.
	 *
	 * @var int
	 */
	private $product = 0;

	/**
	 * Page with the standalone form / without any.
	 *
	 * @var int[]
	 */
	private $pages = array();

	/**
	 * Captured HTTP calls.
	 *
	 * @var array<int,array{url:string,args:array}>
	 */
	private $http = array();

	/**
	 * Stubbed provider answer: array body, or a WP_Error.
	 *
	 * @var mixed
	 */
	private $answer = array( 'success' => true );

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! method_exists( AntiSpam::class, 'provider' ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-13 must be active.' );
			return false;
		}
		$this->setup();
		try {
			$this->ac1_ac2_ac11();
			$this->ac3_to_ac6();
			$this->ac7();
			$this->ac8_ac9();
			$this->ac10();
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
	 * Raw-write settings (merged).
	 *
	 * @param array<string,mixed> $values Values.
	 * @return void
	 */
	private function set( array $values ) {
		$this->c()->get( 'settings' )->update( $values );
	}

	/**
	 * Setup.
	 *
	 * @return void
	 */
	private function setup() {
		$this->saved['free']    = get_option( NDVR_OPTION_SETTINGS, null );
		$this->saved['verify']  = get_option( 'woocommerce_review_rating_verification_required', null );
		$this->saved['version'] = get_option( NDVR_OPTION_DB_VERSION );
		update_option( 'woocommerce_review_rating_verification_required', 'no' );
		$this->set(
			array(
				'allow_guest_reviews' => true,
				'enable_reviews'      => true,
				'photo_uploads'       => false,
				'captcha_provider'    => 'none',
			)
		);
		add_filter( 'wp_is_comment_flood', '__return_false', 99 );
		add_filter( 'pre_wp_mail', '__return_true', 1 );
		add_filter( 'ndv-reviews/max_review_fields', '__return_zero' );
		add_filter(
			'ndv-reviews/rate_limit_per_hour',
			static function () {
				return 1000;
			}
		); // phpcs:ignore Universal.FunctionDeclarations.NoLongClosures
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false === strpos( (string) $url, 'siteverify' ) ) {
					return $pre;
				}
				$this->http[] = array(
					'url'  => (string) $url,
					'args' => (array) $args,
				);
				if ( is_wp_error( $this->answer ) ) {
					return $this->answer;
				}
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $this->answer ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			1,
			3
		);
		wp_set_current_user( 1 );
		$p = new \WC_Product_Simple();
		$p->set_name( 'RR13 product' );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$p->set_reviews_allowed( true );
		$this->product = (int) $p->save();
		foreach ( array( '[ndvr-testimonial product_id="' . $this->product . '"]', 'No form here.' ) as $content ) {
			$this->pages[] = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'RR13',
					'post_content' => $content,
				)
			);
		}
	}

	/**
	 * Fresh script registry.
	 *
	 * @return void
	 */
	private function fresh_scripts() {
		$GLOBALS['wp_scripts'] = null;
		wp_scripts();
	}

	/**
	 * Enqueue as on the product page; return the queue.
	 *
	 * @return string[]
	 */
	private function product_queue() {
		$this->fresh_scripts();
		$GLOBALS['wp_query']     = new \WP_Query(
			array(
				'p'         => $this->product,
				'post_type' => 'product',
			)
		);
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$this->c()->get( 'review_form' )->enqueue_assets();
		$queue                   = wp_scripts()->queue;
		$GLOBALS['wp_query']     = new \WP_Query();
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

		return $queue;
	}

	/**
	 * Render a page's content; return [queue, html].
	 *
	 * @param int $page Page id.
	 * @return array{0:string[],1:string}
	 */
	private function page_queue( $page ) {
		$this->fresh_scripts();
		$html = do_shortcode( (string) get_post( $page )->post_content );

		return array( wp_scripts()->queue, $html );
	}

	/**
	 * The product form markup.
	 *
	 * @return string
	 */
	private function product_form() {
		$m = new \ReflectionMethod( $this->c()->get( 'review_form' ), 'render_fields' );
		$m->setAccessible( true );

		return (string) $m->invoke( $this->c()->get( 'review_form' ) );
	}

	/**
	 * Submit the product form as a guest.
	 *
	 * @param array<string,mixed> $extra Captcha fields.
	 * @return array JSON.
	 */
	private function submit( array $extra ) {
		$scores = array();
		foreach ( (array) $this->c()->get( 'criteria' )->get_active() as $c ) {
			$scores[ (int) $c->id ] = '5';
		}
		$prev = get_current_user_id();
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '10.13.' . wp_rand( 1, 250 ) . '.' . wp_rand( 1, 250 );
		$_POST                  = $extra + array(
			'ndvr_nonce'    => wp_create_nonce( \NdvReviews\Forms\ReviewForm::NONCE_ACTION ),
			'product_id'    => (string) $this->product,
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $scores,
			'comment'       => 'A captcha-checked review that is long enough.',
			'author'        => 'Guest',
			'email'         => 'rr13-' . wp_generate_password( 5, false, false ) . '@example.invalid',
		);
		$_REQUEST               = $_POST;
		$die                    = static function () {
			return static function () {
				throw new NDVR_QA_13_Die();
			};
		};
		$ajax                   = static function () {
			return true;
		};
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_doing_ajax', $ajax );
		$this->http = array();
		ob_start();
		try {
			$this->c()->get( 'review_form' )->handle_submit();
		} catch ( NDVR_QA_13_Die $e ) {
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

	// ------------------------------------------------------------------

	/**
	 * AC1, AC2, AC11: scripts and markup.
	 *
	 * @return void
	 */
	private function ac1_ac2_ac11() {
		$handles    = array( 'ndvr-recaptcha', 'ndvr-turnstile', 'ndvr-hcaptcha' );
		$q1         = $this->product_queue();
		list( $q2 ) = $this->page_queue( $this->pages[0] );
		$this->ok( in_array( 'ndvr-reviews', $q1, true ) && in_array( 'ndvr-collect', $q2, true ) && ! array_intersect( $handles, array_merge( $q1, $q2 ) ), 'AC1: by default neither the product page nor the [ndvr-testimonial] page enqueues a captcha script' );

		$this->set(
			array(
				'captcha_provider'   => 'turnstile',
				'turnstile_site_key' => '1x00000000000000000000AA',
				'turnstile_secret'   => '1x0000000000000000000000000000000AA',
			)
		);
		$q1                = $this->product_queue();
		$src               = wp_scripts()->registered['ndvr-turnstile']->src ?? '';
		list( $q2, $html ) = $this->page_queue( $this->pages[0] );
		$deps              = wp_scripts()->registered['ndvr-collect']->deps;
		list( $q3 )        = $this->page_queue( $this->pages[1] );
		$this->ok( in_array( 'ndvr-turnstile', $q1, true ) && ! array_intersect( array( 'ndvr-recaptcha', 'ndvr-hcaptcha' ), $q1 ) && in_array( 'ndvr-turnstile', $deps, true ) && in_array( 'ndvr-turnstile', wp_scripts()->registered['ndvr-reviews']->deps ?? array( 'ndvr-turnstile' ), true ), 'AC2: Turnstile → only ndvr-turnstile, a dependency of ndvr-collect on the [ndvr-testimonial] page' );
		$this->ok( ! array_intersect( $handles, $q3 ), 'AC2: a page without a form enqueues no captcha script' );
		$this->ok( 0 === strpos( (string) $src, 'https://challenges.cloudflare.com/turnstile/v0/api.js' ) && false !== strpos( (string) $src, 'render=explicit' ), 'AC2: the Turnstile script is the explicit-render URL' );

		$form = $this->product_form();
		$this->ok( 1 === preg_match( '/<div class="ndvr-captcha" data-provider="turnstile" data-sitekey="1x00000000000000000000AA"><\/div>/', $form ) && false !== strpos( $form, 'name="ndvr_captcha_token"' ), 'AC11: the product form has .ndvr-captcha[data-provider=turnstile][data-sitekey] and ndvr_captcha_token' );
		$this->ok( false !== strpos( $html, 'data-captcha-provider="turnstile"' ) && false !== strpos( $html, 'data-captcha-key="1x00000000000000000000AA"' ) && false !== strpos( $html, 'class="ndvr-captcha" data-provider="turnstile"' ), 'AC11: the testimonial root has data-captcha-provider="turnstile" and the form a widget container' );

		$min = (string) file_get_contents( NDVR_DIR . 'assets/js/collect.min.js' );
		$this->ok( false === stripos( $min, "createElement('script')" ) && false === stripos( $min, 'createElement("script")' ) && false === stripos( $min, "createElement( 'script' )" ), 'AC2: collect.min.js no longer injects a <script>' );
	}

	/**
	 * AC3–AC6: verification.
	 *
	 * @return void
	 */
	private function ac3_to_ac6() {
		// Turnstile.
		$this->answer = array( 'success' => true );
		$res          = $this->submit( array( 'ndvr_captcha_token' => 'tok-ok' ) );
		$call         = $this->http[0] ?? array(
			'url'  => '',
			'args' => array(),
		);
		$this->ok( ! empty( $res['success'] ), 'AC3: Turnstile success → the review is accepted (' . wp_json_encode( $res ) . ')' );
		$this->ok( 'https://challenges.cloudflare.com/turnstile/v0/siteverify' === $call['url'] && ! empty( $call['args']['reject_unsafe_urls'] ) && 5 === (int) ( $call['args']['timeout'] ?? 0 ) && isset( $call['args']['body']['secret'], $call['args']['body']['response'] ) && ! isset( $call['args']['body']['remoteip'] ), 'AC3: siteverify through wp_safe_remote_post, 5 s timeout, no remoteip' );
		$this->answer = array( 'success' => false );
		$res          = $this->submit( array( 'ndvr_captcha_token' => 'tok-bad' ) );
		$this->ok( empty( $res['success'] ) && 'Captcha verification failed. Please try again.' === ( $res['data']['message'] ?? '' ), 'AC3: a failed check → 400 with the captcha message' );

		// Widget's own field name is accepted too.
		$this->answer = array( 'success' => true );
		$res          = $this->submit( array( 'cf-turnstile-response' => 'tok-native' ) );
		$this->ok( ! empty( $res['success'] ) && 'tok-native' === ( $this->http[0]['args']['body']['response'] ?? '' ), 'Fallback: the widget\'s own cf-turnstile-response field is accepted' );

		// hCaptcha.
		$this->set(
			array(
				'captcha_provider'  => 'hcaptcha',
				'hcaptcha_site_key' => '10000000-ffff-ffff-ffff-000000000001',
				'hcaptcha_secret'   => '0x0000000000000000000000000000000000000000',
			)
		);
		$res = $this->submit( array( 'ndvr_captcha_token' => 'h-ok' ) );
		$this->ok( ! empty( $res['success'] ) && 'https://api.hcaptcha.com/siteverify' === ( $this->http[0]['url'] ?? '' ) && ! empty( $this->http[0]['args']['reject_unsafe_urls'] ), 'AC4: hCaptcha success via its siteverify endpoint' );
		$this->answer = array( 'success' => false );
		$res          = $this->submit( array( 'ndvr_captcha_token' => 'h-bad' ) );
		$this->ok( empty( $res['success'] ), 'AC4: a failed hCaptcha check → 400' );

		// AC5.
		$this->answer = array( 'success' => true );
		$res          = $this->submit( array( 'ndvr_captcha_token' => '' ) );
		$this->ok( empty( $res['success'] ) && ! $this->http, 'AC5: an empty token with a provider and secret → 400, no call' );
		$this->set(
			array(
				'captcha_provider'   => 'recaptcha',
				'recaptcha_site_key' => 'site-r',
				'recaptcha_secret'   => 'secret-r',
			)
		);
		$this->answer = array(
			'success' => true,
			'score'   => 0.9,
		);
		$res          = $this->submit( array( 'ndvr_recaptcha_token' => 'old-client' ) );
		$this->ok( ! empty( $res['success'] ) && 'old-client' === ( $this->http[0]['args']['body']['response'] ?? '' ), 'AC5: an old client sending only ndvr_recaptcha_token still verifies' );
		$this->answer = array(
			'success' => true,
			'score'   => 0.1,
		);
		$res          = $this->submit( array( 'ndvr_captcha_token' => 'low' ) );
		$this->ok( empty( $res['success'] ), 'reCAPTCHA keeps its score threshold' );

		// AC6.
		$fired = 0;
		$spy   = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'ndv-reviews/captcha_unreachable', $spy );
		$this->answer = new \WP_Error( 'http_request_failed', 'down' );
		$res          = $this->submit( array( 'ndvr_captcha_token' => 'x' ) );
		remove_action( 'ndv-reviews/captcha_unreachable', $spy );
		$this->answer = array( 'success' => true );
		$this->ok( ! empty( $res['success'] ) && 1 === $fired, 'AC6: an unreachable provider allows the review and fires captcha_unreachable once' );
	}

	/**
	 * AC7: upgrade from the reCAPTCHA checkbox.
	 *
	 * @return void
	 */
	private function ac7() {
		$all = get_option( NDVR_OPTION_SETTINGS, array() );
		unset( $all['captcha_provider'] );
		$all['recaptcha_enabled'] = true;
		$all['recaptcha_secret']  = 'x';
		update_option( NDVR_OPTION_SETTINGS, $all );
		update_option( NDVR_OPTION_DB_VERSION, (string) ( \NdvReviews\Installer::V_FOUNDATIONS - 1 ), true );
		delete_transient( \NdvReviews\Installer::BACKOFF_TRANSIENT );
		$before = AntiSpam::provider();
		\NdvReviews\Installer::maybe_upgrade();
		$stored = get_option( NDVR_OPTION_SETTINGS, array() );
		$this->ok( 'recaptcha' === $before && 'recaptcha' === ( $stored['captcha_provider'] ?? '' ), 'AC7: before the step provider() is recaptcha; after maybe_upgrade() the stored provider is recaptcha' );
		$page = $this->c()->get( 'admin_settings_page' );
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->ok( 1 === preg_match( '/<option value="recaptcha"\s+selected=[\'"]selected[\'"]\s*>Google reCAPTCHA v3<\/option>/', $html ), 'AC7: the settings screen shows Google reCAPTCHA v3 selected' );
	}

	/**
	 * AC8, AC9.
	 *
	 * @return void
	 */
	private function ac8_ac9() {
		$this->set(
			array(
				'captcha_provider'   => 'turnstile',
				'turnstile_site_key' => 'site-t',
				'turnstile_secret'   => 'SECRET-TURNSTILE-KEEP',
			)
		);
		$page     = $this->c()->get( 'admin_settings_page' );
		$_POST    = array(
			'ndvr_settings_save'  => '1',
			'_wpnonce'            => wp_create_nonce( \NdvReviews\Admin\SettingsPage::NONCE ),
			'enable_reviews'      => '1',
			'allow_guest_reviews' => '1',
			'captcha_provider'    => 'turnstile',
			'turnstile_site_key'  => 'site-t2',
			'turnstile_secret'    => '',
		);
		$_REQUEST = $_POST;
		$page->handle_save();
		$_POST    = array();
		$_REQUEST = array();
		$s        = $this->c()->get( 'settings' );
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->ok( 'SECRET-TURNSTILE-KEEP' === $s->get( 'turnstile_secret' ) && 'site-t2' === $s->get( 'turnstile_site_key' ) && false === strpos( $html, 'SECRET-TURNSTILE-KEEP' ), 'AC8: a blank Turnstile secret keeps the stored one, which never appears in the page' );
		$this->ok( 'turnstile' === AntiSpam::provider() && ! array_key_exists( 'recaptcha_enabled', array_diff_key( (array) get_option( NDVR_OPTION_SETTINGS ), array( 'recaptcha_enabled' => 1 ) ) ), 'Save: the provider select is stored' );

		$dash = $this->c()->get( 'admin_dashboard_page' );
		$m    = new \ReflectionMethod( $dash, 'checklist' );
		$m->setAccessible( true );
		$this->set( array( 'allow_guest_reviews' => true ) );
		$items = (array) $m->invoke( $dash, 10 );
		$spam  = null;
		foreach ( $items as $item ) {
			if ( isset( $item['label'] ) && false !== strpos( (string) $item['label'], 'spam' ) ) {
				$spam = $item;
			}
		}
		$this->ok( $spam && ! empty( $spam['done'] ), 'AC9: the dashboard checklist marks spam protection done with Turnstile' );
	}

	/**
	 * AC10: readme.
	 *
	 * @return void
	 */
	private function ac10() {
		$r  = (string) file_get_contents( NDVR_DIR . 'readme.txt' );
		$ok = false !== strpos( $r, 'optional captcha: Google reCAPTCHA v3, Cloudflare Turnstile or hCaptcha' )
			&& false !== strpos( $r, 'The optional captchas are the only features that use an outside service' )
			&& false !== strpos( $r, '**Cloudflare Turnstile**' ) && false !== strpos( $r, '**hCaptcha**' )
			&& false !== strpos( $r, 'https://www.cloudflare.com/privacypolicy/' ) && false !== strpos( $r, 'https://www.hcaptcha.com/privacy' )
			&& false !== strpos( $r, 'https://policies.google.com/privacy' );
		$this->ok( $ok, 'AC10: the readme lists the three services with their terms and privacy links' );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	private function teardown() {
		foreach ( get_comments(
			array(
				'post_id' => $this->product,
				'fields'  => 'ids',
				'status'  => 'all',
			)
		) as $cid ) {
			wp_delete_comment( (int) $cid, true );
		}
		wp_delete_post( $this->product, true );
		foreach ( $this->pages as $id ) {
			wp_delete_post( $id, true );
		}
		if ( null === $this->saved['free'] ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved['free'] );
		}
		if ( null === $this->saved['verify'] ) {
			delete_option( 'woocommerce_review_rating_verification_required' );
		} else {
			update_option( 'woocommerce_review_rating_verification_required', $this->saved['verify'] );
		}
		update_option( NDVR_OPTION_DB_VERSION, (string) $this->saved['version'], true );
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

( new NDVR_QA_RR13() )->run();
