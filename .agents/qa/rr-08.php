<?php
/**
 * RR-08 acceptance harness (PRD .agents/prd/RR-08-multilingual-emails.md §12).
 *
 *     php boot.php run .agents/qa/rr-08.php 1   (QA site, D:/.devcache/qa-site)
 *
 * WP_Locale_Switcher reads the installed languages once at bootstrap, so the
 * parent writes the .mo fixtures, then runs each scenario in a child process:
 * `plain` (fr installed, no multilingual plugin), `wpml` (fr + de installed,
 * a WPML stub with String Translation), `wpmlnost` (WPML without String
 * Translation), `polylang` (a Polylang stub), `missing` (fr removed). Real
 * WPML and Polylang (AC9) aren't installed here.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification, WordPress.WP.AlternativeFunctions -- QA script, never shipped.

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NDVR_QA_BOOT' ) && file_exists( dirname( ABSPATH ) . '/boot.php' ) ) {
	define( 'NDVR_QA_BOOT', dirname( ABSPATH ) . '/boot.php' );
}

/**
 * Thrown to stop the landing page before it exits.
 */
final class NDVR_QA_08_Stop extends \Exception {}

/**
 * Thrown by the AJAX die handler.
 */
final class NDVR_QA_08_Die extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR08 {

	const FR_SUBJECT = 'Comment s\'est passée votre commande chez %s ?';
	const DE_SUBJECT = 'Wie war Ihre Bestellung bei %s?';
	const EN_SUBJECT = 'How was your order from %s?';

	/**
	 * Extra French plugin strings (English => French).
	 *
	 * @var array<string,string>
	 */
	const FR_EXTRA = array(
		'Write a review'            => 'Écrire un avis',
		'Please write your review.' => 'Veuillez écrire votre avis.',
		'Thank you. Your review is published.' => 'Merci. Votre avis est publié.',
		'Thank you. Your review was submitted and is awaiting moderation.' => 'Merci. Votre avis attend la modération.',
	);

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
	 * Child mode (or '').
	 *
	 * @var string
	 */
	private $mode = '';

	/**
	 * Fixtures.
	 *
	 * @var array<string,array>
	 */
	private $fx = array(
		'products' => array(),
		'orders'   => array(),
	);

	/**
	 * Captured mail.
	 *
	 * @var array<int,array{subject:string,message:string}>
	 */
	private $mail = array();

	/**
	 * Locale when the last AJAX response was sent.
	 *
	 * @var string
	 */
	private $die_locale = '';

	/**
	 * Saved settings.
	 *
	 * @var mixed
	 */
	private $saved;

	/**
	 * Run.
	 *
	 * @return bool
	 */
	public function run() {
		$this->mode = defined( 'NDVR_QA_RR08_MODE' ) ? (string) NDVR_QA_RR08_MODE : '';
		if ( '' !== $this->mode ) {
			return $this->child();
		}
		if ( ! class_exists( '\NdvReviews\Integrations\Multilingual' ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-08 must be active.' );
			return false;
		}

		$this->fixtures( true );
		foreach ( array( 'plain', 'wpml', 'wpmlnost', 'polylang' ) as $mode ) {
			$this->spawn( $mode );
		}
		$this->fixtures( false );
		$this->spawn( 'missing' );
		$this->line( 'SKIP: AC9 real Polylang and real WPML (not installed on the QA site; Build spikes 1–5). Stubs above.' );
		$this->line( 'SKIP: test plan step 6, WordPress 6.0 (Playground not available); the 6.0 text-domain reload is exercised directly in [plain].' );

		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->count['fail'] ? 'FAIL' : 'PASS', $this->count['pass'], $this->count['fail'] ) );

		return 0 === $this->count['fail'];
	}

	/**
	 * Write or delete the .mo fixtures.
	 *
	 * @param bool $write Write (true) or delete.
	 * @return void
	 */
	private function fixtures( $write ) {
		require_once ABSPATH . WPINC . '/pomo/mo.php';
		$dir   = WP_LANG_DIR;
		$files = array(
			'fr_FR' => array(
				'core'   => $dir . '/fr_FR.mo',
				'plugin' => $dir . '/plugins/rosette-reviews-fr_FR.mo',
				'text'   => self::FR_SUBJECT,
			),
			'de_DE' => array(
				'core'   => $dir . '/de_DE.mo',
				'plugin' => $dir . '/plugins/rosette-reviews-de_DE.mo',
				'text'   => self::DE_SUBJECT,
			),
		);
		wp_mkdir_p( $dir . '/plugins' );
		foreach ( $files as $f ) {
			if ( ! $write ) {
				foreach ( array( 'core', 'plugin' ) as $k ) {
					if ( file_exists( $f[ $k ] ) ) {
						wp_delete_file( $f[ $k ] );
					}
				}
				continue;
			}
			$core = new \MO();
			$core->export_to_file( $f['core'] );
			$plugin = new \MO();
			$plugin->add_entry(
				new \Translation_Entry(
					array(
						'singular'     => self::EN_SUBJECT,
						'translations' => array( $f['text'] ),
					)
				)
			);
			if ( self::FR_SUBJECT === $f['text'] ) {
				foreach ( self::FR_EXTRA as $en => $fr ) {
					$plugin->add_entry(
						new \Translation_Entry(
							array(
								'singular'     => $en,
								'translations' => array( $fr ),
							)
						)
					);
				}
			}
			$plugin->export_to_file( $f['plugin'] );
		}
	}

	/**
	 * Run a scenario child.
	 *
	 * @param string $mode Mode.
	 * @return void
	 */
	private function spawn( $mode ) {
		if ( ! defined( 'NDVR_QA_BOOT' ) || ! function_exists( 'shell_exec' ) ) {
			$this->line( "SKIP: {$mode} (needs NDVR_QA_BOOT)." );
			return;
		}
		$prepend = dirname( NDVR_QA_BOOT ) . '/rr08-prepend.php';
		$code    = "<?php\ndefine( 'NDVR_QA_RR08_MODE', '{$mode}' );\n";
		if ( 'wpml' === $mode || 'wpmlnost' === $mode ) {
			$code .= "define( 'ICL_SITEPRESS_VERSION', '4.7-qa-stub' );\n";
		}
		if ( 'wpml' === $mode ) {
			$code .= "define( 'WPML_ST_VERSION', '3.2-qa-stub' );\n";
		}
		if ( 'polylang' === $mode ) {
			$code .= file_get_contents( __DIR__ . '/fixtures/rr-08/polylang-stub.php' );
		}
		file_put_contents( $prepend, $code );
		$cmd = escapeshellarg( PHP_BINARY ) . ' -d memory_limit=512M -d ' . escapeshellarg( 'auto_prepend_file=' . $prepend ) . ' ' . escapeshellarg( NDVR_QA_BOOT ) . ' run ' . escapeshellarg( __FILE__ ) . ' 1 2>&1';
		$out = (string) shell_exec( $cmd );
		wp_delete_file( $prepend );
		foreach ( preg_split( '/\R/', $out ) as $row ) {
			if ( preg_match( '/^CHILD (PASS|FAIL): (.*)$/', $row, $m ) ) {
				$this->ok( 'PASS' === $m[1], "[{$mode}] " . $m[2] );
			} elseif ( 0 === strpos( $row, 'CHILD INFO: ' ) ) {
				$this->line( "INFO [{$mode}] " . substr( $row, 12 ) );
			}
		}
		if ( false === strpos( $out, 'CHILD DONE' ) ) {
			$this->ok( false, "[{$mode}] the child did not finish: " . substr( $out, 0, 600 ) );
		}
	}

	// ------------------------------------------------------------------
	// Child
	// ------------------------------------------------------------------

	/**
	 * Child entry.
	 *
	 * @return bool
	 */
	private function child() {
		ob_start(); // Keeps headers unsent for the landing page's header() call.
		$this->saved = get_option( NDVR_OPTION_SETTINGS, null );
		$this->c()->get( 'settings' )->update(
			array(
				'reminder_enabled'    => true,
				'reminder_delay_days' => 0,
				'reminder_subject'    => '',
				'reminder_body'       => '',
				'consent_mode'        => 'off',
			)
		);
		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) {
				$this->mail[] = array(
					'subject' => (string) $atts['subject'],
					'message' => (string) $atts['message'],
				);
				return true;
			},
			1,
			2
		);
		$p = new \WC_Product_Simple();
		$p->set_name( 'RR08 product' );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$p->set_reviews_allowed( true );
		$this->fx['products'][] = (int) $p->save();

		try {
			if ( 'plain' === $this->mode ) {
				$this->plain();
			} elseif ( 'wpml' === $this->mode ) {
				$this->wpml();
			} elseif ( 'wpmlnost' === $this->mode ) {
				$this->wpml_no_st();
			} elseif ( 'polylang' === $this->mode ) {
				$this->polylang();
			} else {
				$this->missing();
			}
		} catch ( \Throwable $e ) {
			$this->say( false, 'uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
		$this->teardown();
		echo "CHILD DONE\n";
		ob_end_flush();

		return true;
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
	 * Store name as the Mailer prints it.
	 *
	 * @return string
	 */
	private function store() {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * An order.
	 *
	 * @return \WC_Order
	 */
	private function order( $email = '' ) {
		$order = wc_create_order();
		$order->add_product( wc_get_product( $this->fx['products'][0] ), 1 );
		$order->set_billing_first_name( 'Claire' );
		$order->set_billing_email( '' !== $email ? $email : 'rr08-' . wp_generate_password( 6, false, false ) . '@example.invalid' );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->save();
		$this->fx['orders'][] = (int) $order->get_id();

		return $order;
	}

	/**
	 * Queue + send a request; return [request id, the mail].
	 *
	 * @param \WC_Order $order Order.
	 * @return array{0:int,1:array|null}
	 */
	private function send( $order ) {
		$id         = $this->c()->get( 'scheduler' )->queue_for_order( $order->get_id(), array( 'source' => 'manual' ) );
		$this->mail = array();
		if ( ! is_wp_error( $id ) ) {
			$this->c()->get( 'scheduler' )->process( (int) $id );
		}

		return array( is_wp_error( $id ) ? 0 : (int) $id, $this->mail ? end( $this->mail ) : null );
	}

	/**
	 * Scenario: fr installed, no multilingual plugin.
	 *
	 * @return void
	 */
	private function plain() {
		$ml = $this->c()->get( 'multilingual' );
		$this->say( '' === $ml->active(), 'no multilingual plugin is detected' );

		// AC1: identical email with and without the service's hooks.
		// Two orders for one address (the 20 h cooldown is per order).
		$email = 'rr08-same-' . wp_generate_password( 6, false, false ) . '@example.invalid';
		list( , $a ) = $this->send( $this->order( $email ) );
		remove_filter( 'ndv-reviews/translate_setting', array( $ml, 'translate_filter' ), 10 );
		list( , $b ) = $this->send( $this->order( $email ) );
		add_filter( 'ndv-reviews/translate_setting', array( $ml, 'translate_filter' ), 10, 3 );
		$norm = static function ( $html ) {
			return preg_replace( '/ndvr_k=[^"&]+/', 'ndvr_k=X', (string) $html );
		};
		$this->say( $a && $b && $norm( $a['message'] ) === $norm( $b['message'] ) && $a['subject'] === $b['subject'], 'AC1: the email is identical with and without the multilingual hooks' );
		$this->say( $a && sprintf( self::EN_SUBJECT, $this->store() ) === $a['subject'], 'AC1: the default subject is in the site language' );

		// AC7: the order_language filter alone switches the email.
		$force = static function () {
			return array(
				'code'   => 'fr',
				'locale' => 'fr_FR',
			);
		};
		add_filter( 'ndv-reviews/order_language', $force );
		$before = get_locale();
		list( , $fr ) = $this->send( $this->order() );
		$this->say( $fr && sprintf( self::FR_SUBJECT, $this->store() ) === $fr['subject'], 'AC7: order_language {fr, fr_FR} gives the French subject with no multilingual plugin (' . ( $fr ? $fr['subject'] : 'no mail' ) . ')' );
		$this->say( get_locale() === $before, 'AC4: the locale is restored after the send' );

		// AC10: the landing page renders in the order's locale.
		$link = $fr && preg_match( '/ndvr_k=([^"&]+)/', $fr['message'], $m ) ? rawurldecode( $m[1] ) : '';
		$seen = '';
		$spy  = static function ( $path, $name ) use ( &$seen ) {
			if ( 'magic-landing.php' === $name ) {
				$seen = get_locale();
				throw new NDVR_QA_08_Stop( 'stop' );
			}
			return $path;
		};
		add_filter( 'ndv-reviews/template_path', $spy, 10, 2 );
		$_GET = array( 'ndvr_k' => $link );
		try {
			$this->c()->get( 'landing' )->maybe_render();
		} catch ( NDVR_QA_08_Stop $e ) {
			unset( $e );
		}
		$_GET = array();
		remove_filter( 'ndv-reviews/template_path', $spy, 10 );
		remove_filter( 'ndv-reviews/order_language', $force );
		$this->say( '' !== $link && 'fr_FR' === $seen, 'AC10: the review page for a French order renders in fr_FR (' . $seen . ')' );
		$this->say( get_locale() === $before, 'AC10: and the locale is restored after an exception' );

		add_filter( 'ndv-reviews/order_language', $force );
		$this->review_submit( $link, $before );
		remove_filter( 'ndv-reviews/order_language', $force );

		// M2: WordPress 6.0 unloads the domain on a switch and won't reload it.
		$this->say( false === has_action( 'change_locale', array( $ml, 'reload_textdomain' ) ), 'M2: on WordPress 6.2+ no reload hook is added' );
		switch_to_locale( 'fr_FR' );
		unload_textdomain( 'rosette-reviews' ); // As 6.0 does: not reloadable.
		$dead = __( self::EN_SUBJECT, 'rosette-reviews' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		$ml->reload_textdomain( 'fr_FR' );
		$alive = __( self::EN_SUBJECT, 'rosette-reviews' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		restore_previous_locale();
		$this->say( self::EN_SUBJECT === $dead && self::FR_SUBJECT === $alive, 'M2: reload_textdomain() brings back the French strings after a 6.0-style unload' );

		// Nested switches restore in order.
		$inner = '';
		$mid   = '';
		$ml->with_locale(
			'fr_FR',
			function () use ( $ml, &$inner, &$mid ) {
				$inner = $ml->with_locale(
					'de_DE',
					static function () {
						return get_locale();
					}
				);
				$mid   = get_locale();
			}
		);
		$this->say( 'de_DE' === $inner && 'fr_FR' === $mid && get_locale() === $before, 'Nested with_locale() calls restore in order' );
	}

	/**
	 * M1 and m3: the review submit and the used link.
	 *
	 * @param string $link   Raw token of a French order.
	 * @param string $before Site locale.
	 * @return void
	 */
	private function review_submit( $link, $before ) {
		$row    = $this->c()->get( 'token_repository' )->resolve( $link );
		$pid    = $this->fx['products'][0];
		$scores = array();
		foreach ( (array) $this->c()->get( 'criteria' )->get_active() as $criterion ) {
			$scores[ (int) $criterion->id ] = '5';
		}
		$post = array(
			'nonce'         => wp_create_nonce( \NdvReviews\Collection\Landing::NONCE ),
			'token'         => $link,
			'product_id'    => (string) $pid,
			'ndvr_consent'  => '1',
			'ndvr_criteria' => $scores,
			'comment'       => '',
		);
		$bad  = json_decode( $this->ajax( $post ), true );
		$this->say( is_array( $bad ) && empty( $bad['success'] ) && 'Veuillez écrire votre avis.' === $bad['data']['message'], 'M1: a validation message is in the order\'s language (' . ( is_array( $bad ) ? (string) $bad['data']['message'] : 'no JSON' ) . ')' );

		$at  = '';
		$spy = static function () use ( &$at ) {
			$at = get_locale();
		};
		add_action( 'ndv-reviews/review_created', $spy, 10 );
		$post['comment'] = 'Très bien.';
		$good            = json_decode( $this->ajax( $post ), true );
		remove_action( 'ndv-reviews/review_created', $spy, 10 );
		$this->say( $row && is_array( $good ) && ! empty( $good['success'] ) && 0 === strpos( (string) $good['data']['message'], 'Merci.' ), 'M1: the thank-you message is in the order\'s language (' . ( is_array( $good ) ? wp_json_encode( $good['data'] ) : 'no JSON' ) . ')' );
		$this->say( $before === $at, 'M1: review_created listeners (the store\'s new-review email) run in the site language (' . $at . ')' );
		$this->say( $this->die_locale === $before, 'M1: the locale is back to the site\'s when the success response is sent (' . $this->die_locale . ')' );

		// m3: the used link's thank-you page, head included, in French.
		$seen = '';
		$stop = static function ( $handles ) use ( &$seen ) {
			$seen = get_locale();
			throw new NDVR_QA_08_Stop( 'stop' );
		};
		add_filter( 'ndv-reviews/landing_style_handles', $stop );
		$_GET = array( 'ndvr_k' => $link );
		ob_start();
		try {
			$this->c()->get( 'landing' )->maybe_render();
		} catch ( NDVR_QA_08_Stop $e ) {
			unset( $e );
		}
		$head = (string) ob_get_clean();
		$_GET = array();
		remove_filter( 'ndv-reviews/landing_style_handles', $stop );
		$this->say( 'fr_FR' === $seen && false !== strpos( $head, 'Écrire un avis' ) && false !== strpos( $head, 'lang="fr-FR"' ), 'm3: a used link\'s page, <html lang> and <title> included, are in French (' . $seen . ')' );
		$this->say( get_locale() === $before, 'm3: and the locale is restored' );
		foreach ( get_comments(
			array(
				'post_id' => $pid,
				'fields'  => 'ids',
			)
		) as $cid ) {
			wp_delete_comment( (int) $cid, true );
		}
	}

	/**
	 * Run an AJAX handler and return its JSON output.
	 *
	 * @param array $post POST data.
	 * @return string
	 */
	private function ajax( array $post ) {
		$ajax = static function () {
			return true;
		};
		$die  = static function () {
			return static function () {
				throw new NDVR_QA_08_Die();
			};
		};
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'wp_die_ajax_handler', $die );
		$_POST    = $post;
		$_REQUEST = $post;
		ob_start();
		try {
			$this->c()->get( 'landing' )->handle_submit();
		} catch ( NDVR_QA_08_Die $e ) {
			unset( $e );
		}
		// The response ends a real request; unwind what it left for the next one.
		$this->die_locale = get_locale();
		while ( is_locale_switched() ) {
			restore_previous_locale();
		}
		$out      = (string) ob_get_clean();
		$_POST    = array();
		$_REQUEST = array();
		remove_filter( 'wp_doing_ajax', $ajax );
		remove_filter( 'wp_die_ajax_handler', $die );

		return $out;
	}

	/**
	 * Scenario: WPML stub (fr + de installed).
	 *
	 * @return void
	 */
	private function wpml() {
		$ml = $this->c()->get( 'multilingual' );
		$this->say( 'wpml' === $ml->active(), 'WPML is detected (stub)' );
		// WPML's current language: the admin picked German in the admin bar.
		$GLOBALS['ndvr_qa_wpml'] = 'de';
		add_filter(
			'wpml_current_language',
			static function () {
				return $GLOBALS['ndvr_qa_wpml'];
			}
		);
		add_action(
			'wpml_switch_language',
			static function ( $code ) {
				$GLOBALS['ndvr_qa_wpml'] = null === $code ? 'en' : $code;
			}
		);
		add_filter(
			'wpml_default_language',
			static function () {
				return 'en';
			}
		);
		add_filter(
			'wpml_active_languages',
			static function () {
				return array(
					'en' => array(
						'code'           => 'en',
						'default_locale' => 'en_US',
					),
					'de' => array(
						'code'           => 'de',
						'default_locale' => 'de_DE',
					),
					'fr' => array(
						'code'           => 'fr',
						'default_locale' => 'fr_FR',
					),
				);
			}
		);
		add_filter(
			'wpml_permalink',
			static function ( $url, $code ) {
				return home_url( '/' . $code . '/' );
			},
			10,
			2
		);

		// AC2.
		$o = $this->order();
		$o->update_meta_data( 'wpml_language', 'fr' );
		$o->save();
		$this->say( array( 'code' => 'fr', 'locale' => 'fr_FR' ) === $ml->order_language( $o ), 'Detection: wpml_language meta + active languages → {fr, fr_FR}' );
		$before = get_locale();
		list( , $m ) = $this->send( $o );
		$this->say( $m && sprintf( self::FR_SUBJECT, $this->store() ) === $m['subject'], 'AC2: the default subject is French (' . ( $m ? $m['subject'] : 'no mail' ) . ')' );
		$this->say( $m && false !== strpos( $m['message'], home_url( '/' ) . '?ndvr_k=' ) && false === strpos( $m['message'], home_url( '/fr/' ) ), 'M4: by default the review link uses the plain home URL' );
		$this->say( 'de' === $GLOBALS['ndvr_qa_wpml'], 'WPML\'s language is switched back after the send' );
		add_filter( 'ndv-reviews/language_review_links', '__return_true' );
		$fr_link = $this->c()->get( 'mailer' )->build_link( 'abc', 'fr' );
		remove_filter( 'ndv-reviews/language_review_links', '__return_true' );
		$this->say( home_url( '/fr/' ) . '?ndvr_k=abc' === $fr_link, 'M4: with ndv-reviews/language_review_links on, the link uses wpml_permalink (' . $fr_link . ')' );

		// AC3: custom texts through WPML string translation, sanitised after.
		$args = array();
		$tr   = static function ( $value, $context, $name, $lang ) use ( &$args ) {
			$args[] = array( $context, $name, $lang );
			if ( 'reminder_subject' === $name ) {
				return 'Sujet FR {store_name}';
			}
			if ( 'reminder_body' === $name ) {
				return 'Bonjour {customer_name} <script>alert(1)</script> merci';
			}
			return $value;
		};
		add_filter( 'wpml_translate_single_string', $tr, 10, 4 );
		$this->c()->get( 'settings' )->update(
			array(
				'reminder_subject' => 'Custom EN subject',
				'reminder_body'    => 'Custom EN body',
			)
		);
		$o3 = $this->order();
		$o3->update_meta_data( 'wpml_language', 'fr' );
		$o3->save();
		list( , $m3 ) = $this->send( $o3 );
		remove_filter( 'wpml_translate_single_string', $tr, 10 );
		$this->say( in_array( array( 'ndv-reviews', 'reminder_subject', 'fr' ), $args, true ), 'AC3: wpml_translate_single_string receives (ndv-reviews, reminder_subject, fr)' );
		$this->say( $m3 && 'Sujet FR ' . $this->store() === $m3['subject'], 'AC3: its return value is the sent subject (' . ( $m3 ? $m3['subject'] : 'no mail' ) . ')' );
		$this->say( $m3 && false !== strpos( $m3['message'], 'Bonjour Claire' ) && false === stripos( $m3['message'], '<script' ), 'AC3: a translated body with <script> is sent without it' );
		$this->c()->get( 'settings' )->update(
			array(
				'reminder_subject' => '',
				'reminder_body'    => '',
			)
		);

		// AC4: restored, even when building throws.
		$this->say( get_locale() === $before, 'AC4: the locale is restored after the send' );
		$o4 = $this->order();
		$o4->update_meta_data( 'wpml_language', 'fr' );
		$o4->save();
		$inside = '';
		$boom   = static function () use ( &$inside ) {
			$inside = get_locale() . '/' . $GLOBALS['ndvr_qa_wpml'];
			throw new \RuntimeException( 'build failed' );
		};
		add_filter( 'ndv-reviews/request_email_texts', $boom );
		$caught = false;
		try {
			$this->c()->get( 'mailer' )->send_for_order( $o4->get_id(), array( 'request_id' => 0 ) );
		} catch ( \RuntimeException $e ) {
			$caught = 'build failed' === $e->getMessage();
		}
		remove_filter( 'ndv-reviews/request_email_texts', $boom );
		$this->say( $caught && 'fr_FR/fr' === $inside, 'AC4: the exception was thrown inside the French build (' . $inside . ')' );
		$this->say( get_locale() === $before && 'de' === $GLOBALS['ndvr_qa_wpml'], 'AC4: after it, the WordPress locale and WPML\'s language are both restored' );

		// AC11.
		$cur = static function () {
			return 'fr';
		};
		$t11 = static function ( $value, $context, $name, $lang ) {
			return 'transparency_extra' === $name && 'fr' === $lang ? 'Texte FR' : $value;
		};
		add_filter( 'wpml_current_language', $cur );
		add_filter( 'wpml_translate_single_string', $t11, 10, 4 );
		$got = apply_filters( 'ndv-reviews/translate_setting', 'X', 'transparency_extra', '' );
		remove_filter( 'wpml_translate_single_string', $t11, 10 );
		remove_filter( 'wpml_current_language', $cur );
		$this->say( 'Texte FR' === $got, 'AC11: translate_setting with lang \'\' uses the current WPML language' );

		// AC8: registration once per change.
		$reg   = array();
		$count = static function ( $context, $name ) use ( &$reg ) {
			$reg[] = $context . ':' . $name;
		};
		add_action( 'wpml_register_single_string', $count, 10, 2 );
		delete_option( \NdvReviews\Integrations\Multilingual::HASH_OPTION );
		$this->c()->get( 'settings' )->update(
			array(
				'reminder_subject' => 'Reg subject',
				'followup_body'    => 'Reg follow',
			)
		);
		$reg = array();
		delete_option( \NdvReviews\Integrations\Multilingual::HASH_OPTION );
		$ml->register_strings();
		$first = $reg;
		$reg   = array();
		$ml->register_strings();
		$second = $reg;
		$reg    = array();
		$this->c()->get( 'settings' )->update( array( 'reminder_subject' => 'Reg subject 2' ) );
		$third = $reg;
		remove_action( 'wpml_register_single_string', $count, 10 );
		sort( $first );
		$this->say( array( 'ndv-reviews:followup_body', 'ndv-reviews:reminder_subject' ) === $first, 'AC8: the first admin_init registers each non-empty text once (' . implode( ',', $first ) . ')' );
		$this->say( array() === $second, 'AC8: a second admin_init registers nothing' );
		$this->say( array( 'ndv-reviews:reminder_subject' ) === $third, 'AC8: saving a changed reminder_subject registers that one name' );
		$this->c()->get( 'settings' )->update(
			array(
				'reminder_subject' => '',
				'followup_body'    => '',
			)
		);

		ob_start();
		$ml->render_note();
		$this->say( false !== strpos( (string) ob_get_clean(), 'WPML detected.' ), 'Screen: the WPML note' );

		// AC5: an admin retry with no order language uses the site language.
		update_user_meta( 1, 'locale', 'de_DE' );
		set_current_screen( 'dashboard' );
		unload_textdomain( 'rosette-reviews' );
		$admin_says = __( self::EN_SUBJECT, 'rosette-reviews' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		$plain      = $this->order(); // No wpml_language: unknown.
		$id         = $this->c()->get( 'scheduler' )->queue_for_order( $plain->get_id(), array( 'source' => 'manual' ) );
		$this->c()->get( 'request_repository' )->set_status( (int) $id, 'failed', 'QA' );
		$this->mail = array();
		$this->c()->get( 'scheduler' )->retry( (int) $id );
		$sent = $this->mail ? end( $this->mail ) : null;
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		delete_user_meta( 1, 'locale' );
		$this->say( self::DE_SUBJECT === $admin_says, 'AC5 precondition: the admin user\'s locale is German here' );
		$this->say( $sent && sprintf( self::EN_SUBJECT, $this->store() ) === $sent['subject'], 'AC5: the retried email uses the site language, not German (' . ( $sent ? $sent['subject'] : 'no mail' ) . ')' );

		// M3: an order with no language uses WPML's default, not the current (de).
		$langs = array();
		$tr3   = static function ( $value, $context, $name, $lang ) use ( &$langs ) {
			$langs[] = $name . ':' . $lang;
			return 'de' === $lang ? 'Betreff DE' : $value;
		};
		add_filter( 'wpml_translate_single_string', $tr3, 10, 4 );
		$this->c()->get( 'settings' )->update( array( 'reminder_subject' => 'Custom EN subject' ) );
		list( , $m5 ) = $this->send( $this->order() );
		$this->say( $m5 && 'Custom EN subject' === $m5['subject'] && in_array( 'reminder_subject:en', $langs, true ) && ! in_array( 'reminder_subject:de', $langs, true ), 'M3: no order language → texts looked up in the default language (en), not the current one (de) (' . implode( ',', $langs ) . ')' );

		// m6: a list recipient gets the default language too.
		global $wpdb;
		$t      = \NdvReviews\Support\Db::table( 'review_tokens' );
		$max    = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$langs  = array();
		$this->mail = array();
		$r      = $this->c()->get( 'mailer' )->send_to_list_recipient( 'rr08-list-' . wp_generate_password( 6, false, false ) . '@example.invalid', 'Ann', array( $this->fx['products'][0] ), 0 );
		$list   = $this->mail ? end( $this->mail ) : null;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE type = 'list' AND id > %d", $max ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		remove_filter( 'wpml_translate_single_string', $tr3, 10 );
		$this->c()->get( 'settings' )->update( array( 'reminder_subject' => '' ) );
		$this->say( true === $r && $list && 'Custom EN subject' === $list['subject'] && in_array( 'reminder_subject:en', $langs, true ) && 'de' === $GLOBALS['ndvr_qa_wpml'], 'm6: a list recipient\'s email uses the default language and switches WPML back' );

		// m5: a translated subject is cleaned like the stored one.
		$tr5 = static function ( $value, $context, $name ) {
			return 'reminder_subject' === $name ? "Sujet <b>FR</b>\n%0a" : $value;
		};
		add_filter( 'wpml_translate_single_string', $tr5, 10, 3 );
		$this->c()->get( 'settings' )->update( array( 'reminder_subject' => 'Custom EN subject' ) );
		$o6 = $this->order();
		$o6->update_meta_data( 'wpml_language', 'fr' );
		$o6->save();
		list( , $m6 ) = $this->send( $o6 );
		remove_filter( 'wpml_translate_single_string', $tr5, 10 );
		$this->c()->get( 'settings' )->update( array( 'reminder_subject' => '' ) );
		$this->say( $m6 && false === strpos( $m6['subject'], '<b>' ) && false === strpos( $m6['subject'], "\n" ) && 0 === strpos( $m6['subject'], 'Sujet FR' ), 'm5: a translated subject loses tags and line breaks (' . ( $m6 ? $m6['subject'] : 'no mail' ) . ')' );

		// m1: looked up trimmed, as registered.
		$seen = array();
		$tr1  = static function ( $value, $context, $name, $lang ) use ( &$seen ) {
			$seen[] = $value;
			return 'Hello' === $value ? 'Bonjour' : $value;
		};
		add_filter( 'wpml_translate_single_string', $tr1, 10, 4 );
		$a = $ml->translate( "  Hello \n", 'reminder_body', 'fr' );
		$b = $ml->translate( "  Other \n", 'reminder_body', 'fr' );
		remove_filter( 'wpml_translate_single_string', $tr1, 10 );
		$this->say( 'Bonjour' === $a && "  Other \n" === $b && array( 'Hello', 'Other' ) === $seen, 'm1: a text with surrounding whitespace is looked up trimmed; an untranslated one comes back as stored' );
	}

	/**
	 * Scenario: WPML without String Translation.
	 *
	 * @return void
	 */
	private function wpml_no_st() {
		$ml  = $this->c()->get( 'multilingual' );
		$reg = 0;
		$cnt = static function () use ( &$reg ) {
			++$reg;
		};
		add_action( 'wpml_register_single_string', $cnt );
		$this->c()->get( 'settings' )->update( array( 'reminder_subject' => 'No ST subject' ) );
		delete_option( \NdvReviews\Integrations\Multilingual::HASH_OPTION );
		$ml->register_strings();
		remove_action( 'wpml_register_single_string', $cnt );
		$this->say( 'wpml' === $ml->active() && 0 === $reg && false === get_option( \NdvReviews\Integrations\Multilingual::HASH_OPTION ), 'm2: without WPML String Translation nothing is registered and no hash is saved' );
	}

	/**
	 * Scenario: Polylang stub (fixtures/rr-08/polylang-stub.php).
	 *
	 * @return void
	 */
	private function polylang() {
		$ml = $this->c()->get( 'multilingual' );
		$this->say( 'polylang' === $ml->active(), 'Polylang is detected (stub)' );
		$pll = &$GLOBALS['ndvr_qa_pll'];

		$o = $this->order();
		$pll['post'][ $o->get_id() ] = 'fr';
		$this->say( array( 'code' => 'fr', 'locale' => 'fr_FR' ) === $ml->order_language( $o ), 'AC9 (stub): pll_get_post_language → {fr, fr_FR}' );
		$before = get_locale();
		list( , $m ) = $this->send( $o );
		$this->say( $m && sprintf( self::FR_SUBJECT, $this->store() ) === $m['subject'], 'AC9 (stub): the default subject is French' );
		$this->say( $m && false !== strpos( $m['message'], home_url( '/' ) . '?ndvr_k=' ), 'M4: the link uses the plain home URL by default' );
		$this->say( get_locale() === $before, 'The locale is restored' );

		$this->c()->get( 'settings' )->update( array( 'reminder_subject' => 'Custom EN subject' ) );
		$pll['tr'] = array(
			'fr' => array( 'Custom EN subject' => 'Sujet FR' ),
			'de' => array( 'Custom EN subject' => 'Betreff DE' ),
		);
		$o2 = $this->order();
		$pll['post'][ $o2->get_id() ] = 'fr';
		list( , $m2 ) = $this->send( $o2 );
		$this->say( $m2 && 'Sujet FR' === $m2['subject'], 'AC9 (stub): the custom subject goes through pll_translate_string for fr' );

		// M3: unknown order language → Polylang's default (en), not current (de).
		$pll['calls'] = array();
		list( , $m3 ) = $this->send( $this->order() );
		$this->say( $m3 && 'Custom EN subject' === $m3['subject'] && in_array( array( 'Custom EN subject', 'en' ), $pll['calls'], true ), 'M3: no order language → pll_translate_string( …, en ), not the current de' );

		// Registration (Polylang lists only strings registered this request).
		$pll['registered'] = array();
		$ml->register_strings();
		$this->say( in_array( array( 'reminder_subject', 'Custom EN subject', 'Rosette Reviews', false ), $pll['registered'], true ), 'Polylang registration: name, text, group, single line' );
		$this->c()->get( 'settings' )->update( array( 'reminder_subject' => '' ) );

		add_filter( 'ndv-reviews/language_review_links', '__return_true' );
		$link = $this->c()->get( 'mailer' )->build_link( 'abc', 'fr' );
		remove_filter( 'ndv-reviews/language_review_links', '__return_true' );
		$this->say( home_url( '/fr/' ) . '?ndvr_k=abc' === $link, 'M4: with the filter on, the link uses pll_home_url' );
	}

	/**
	 * Scenario: fr removed.
	 *
	 * @return void
	 */
	private function missing() {
		$force = static function () {
			return array(
				'code'   => 'fr',
				'locale' => 'fr_FR',
			);
		};
		add_filter( 'ndv-reviews/order_language', $force );
		list( $id, $m ) = $this->send( $this->order() );
		remove_filter( 'ndv-reviews/order_language', $force );
		$row = $id ? $this->c()->get( 'request_repository' )->find( $id ) : null;
		$this->say( $m && sprintf( self::EN_SUBJECT, $this->store() ) === $m['subject'] && $row && 'sent' === $row->status, 'AC6: with fr_FR not installed, a French order still sends, in the site language' );
	}

	/**
	 * Clean up (child).
	 *
	 * @return void
	 */
	private function teardown() {
		global $wpdb;
		$t = \NdvReviews\Support\Db::table( 'requests' );
		foreach ( $this->fx['orders'] as $id ) {
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
		delete_option( \NdvReviews\Integrations\Multilingual::HASH_OPTION );
		if ( null === $this->saved ) {
			delete_option( NDVR_OPTION_SETTINGS );
		} else {
			update_option( NDVR_OPTION_SETTINGS, $this->saved );
		}
	}

	/**
	 * Child assertion.
	 *
	 * @param bool   $ok  Condition.
	 * @param string $msg Message.
	 * @return void
	 */
	private function say( $ok, $msg ) {
		echo 'CHILD ' . ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $msg . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

( new NDVR_QA_RR08() )->run();
