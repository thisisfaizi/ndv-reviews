<?php
/**
 * RR-00 acceptance harness (PRD .agents/prd/RR-00-foundations.md, ACs 1–8).
 *
 * Run inside WordPress with WooCommerce and Rosette Reviews active, on a TEST
 * site, as an administrator context:
 *
 *     wp eval-file .agents/qa/rr-00.php --user=1
 *
 * It forces upgrade states by writing the DB version, the lock row and the
 * settings option directly, and restores all of them at the end. AC7 (Pro)
 * runs only when the Pro add-on's Moderation\Plus class is loaded. AC8 is the
 * separate core-flows harness.
 *
 * Not shipped: `.agents` is in .distignore.
 *
 * @package NdvReviews
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification -- QA script, never shipped.

use NdvReviews\Installer;
use NdvReviews\Uninstall;

defined( 'ABSPATH' ) || exit;

// Enables Installer's test seam (ndv-reviews/qa_upgrade_steps). QA sites only.
if ( ! defined( 'NDVR_QA' ) ) {
	define( 'NDVR_QA', true );
}

/**
 * Thrown by the redirect stub so handlers that redirect-and-exit return here.
 */
final class NDVR_QA_Redirect extends \Exception {}

/**
 * The harness.
 */
final class NDVR_QA_RR00 {

	/**
	 * Check counts.
	 *
	 * @var int[]
	 */
	private $count = array(
		'pass' => 0,
		'fail' => 0,
	);

	/**
	 * Options/transients restored at the end.
	 *
	 * @var array<string,mixed>
	 */
	private $saved = array();

	/**
	 * Queries seen while recording.
	 *
	 * @var string[]|null
	 */
	private $queries = null;

	/**
	 * Run everything.
	 *
	 * @return bool
	 */
	public function run() {
		if ( ! class_exists( '\NdvReviews\Installer' ) || ! defined( 'NDVR_API' ) ) {
			$this->line( 'ABORT: Rosette Reviews with RR-00 must be active.' );
			return false;
		}

		$this->save_state();
		add_filter( 'query', array( $this, 'record_query' ), 1 );

		try {
			$this->ac1_upgrades();
			$this->ac1_lock();
			$this->ac1_backoff_and_downgrade();
			$this->ac1_activation();
			$this->ac2_uninstall();
			$this->ac3_moderation();
			$this->ac4_template();
			$this->ac5_secret();
			$this->ac6_rate_limit();
			$this->ac7_pro();
			$this->ok( (int) NDVR_API >= 2, 'F3b: NDVR_API is defined and at least 2 (RR-00)' );
		} catch ( \Throwable $e ) {
			$this->ok( false, 'uncaught ' . get_class( $e ), $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
		}

		remove_filter( 'query', array( $this, 'record_query' ), 1 );
		$this->restore_state();

		$this->line( sprintf( 'RESULT: %s (%d passed, %d failed)', $this->count['fail'] ? 'FAIL' : 'PASS', $this->count['pass'], $this->count['fail'] ) );

		return 0 === $this->count['fail'];
	}

	/* ------------------------------------------------------------------ */

	/**
	 * AC 1.1: v2 -> v3 applies the step once, under the lock.
	 *
	 * @return void
	 */
	private function ac1_upgrades() {
		$this->force_version( 2 );
		$this->raw_settings( array( 'recaptcha_enabled' => true ), array( 'captcha_provider' ) );

		Installer::maybe_upgrade();

		$this->ok( (int) NDVR_DB_VERSION === $this->stored_version(), 'AC1.1: v2 upgrades to the code version', (string) $this->stored_version() );
		$raw = get_option( NDVR_OPTION_SETTINGS );
		$this->ok( is_array( $raw ) && 'recaptcha' === ( $raw['captcha_provider'] ?? '' ), 'AC1.1: v3 step set captcha_provider=recaptcha' );
		$this->ok( null === $this->lock_value(), 'AC1.1: lock released after the run' );

		// Run again: nothing to do, the step doesn't rerun.
		$this->raw_settings( array(), array( 'captcha_provider' ) );
		Installer::maybe_upgrade();
		$raw = get_option( NDVR_OPTION_SETTINGS );
		$this->ok( ! isset( $raw['captcha_provider'] ), 'AC1.1: a current site runs no step again' );
	}

	/**
	 * AC 1.2: fresh lock skips, stale lock is taken over, cache can't fool it.
	 *
	 * @return void
	 */
	private function ac1_lock() {
		// Fresh lock: skip.
		$this->force_version( 2 );
		$this->put_lock( time() . '|fresh' );
		Installer::maybe_upgrade();
		$this->ok( 2 === $this->stored_version(), 'AC1.2: a fresh lock makes the run skip' );

		// Stale lock (11 minutes): taken over.
		$this->put_lock( ( time() - 660 ) . '|stale' );
		Installer::maybe_upgrade();
		$this->ok( (int) NDVR_DB_VERSION === $this->stored_version(), 'AC1.2: a stale lock is taken over' );
		$this->ok( null === $this->lock_value(), 'AC1.2: taken-over lock released' );

		// A cache that wrongly says the lock is absent.
		$this->put_lock( time() . '|held' );
		wp_cache_set( 'notoptions', array( Installer::LOCK_OPTION => true ), 'options' );
		$this->ok( false === Installer::acquire_lock(), 'AC1.2: a held lock is held even when the cache says it is absent' );

		// Two takeovers of the same stale value: exactly one wins.
		$this->put_lock( ( time() - 700 ) . '|stale2' );
		$first  = Installer::acquire_lock();
		$second = Installer::acquire_lock();
		$this->ok( false !== $first && false === $second, 'AC1.2: only one takeover of a stale lock wins' );

		// Releasing with a stale token leaves the current lock.
		Installer::release_lock( 'not-the-owner' );
		$this->ok( $first === $this->lock_value(), 'AC1.2: release with a stale token keeps the current lock' );
		if ( false !== $first ) {
			Installer::release_lock( $first );
		}
		$this->ok( null === $this->lock_value(), 'AC1.2: the owner releases its lock' );
	}

	/**
	 * AC 1.3: failure sets backoff; downgrade changes nothing.
	 *
	 * @return void
	 */
	private function ac1_backoff_and_downgrade() {
		global $wpdb;

		// Make the post-dbDelta table check fail for one table.
		$this->force_version( 2 );
		$target = $wpdb->prefix . NDVR_TABLE_PREFIX . 'criteria';
		$hide   = function ( $sql ) use ( $target ) {
			// Installer::missing_tables() probes each table with SELECT 1 ... LIMIT 0.
			if ( false !== strpos( $sql, 'SELECT 1 FROM `' . $target . '` LIMIT 0' ) ) {
				return 'SELECT 1 FROM `ndvr_qa_no_such_table` LIMIT 0';
			}
			return $sql;
		};
		add_filter( 'query', $hide, 2 );
		Installer::maybe_upgrade();
		remove_filter( 'query', $hide, 2 );

		$this->ok( 2 === $this->stored_version(), 'AC1.3: a failed run leaves the version at 2' );
		$this->ok( false !== get_transient( Installer::BACKOFF_TRANSIENT ), 'AC1.3: backoff transient set' );
		$this->ok( '' !== (string) get_option( Installer::ERROR_OPTION, '' ), 'AC1.3: error stored for the notice' );

		// A step returning WP_Error (test seam, NDVR_QA only): the run stops and
		// the version stays at the last good step.
		$this->force_version( 2 );
		$fail = function ( $steps ) {
			$steps[ Installer::V_FOUNDATIONS ] = function () {
				return new \WP_Error( 'qa_step', 'QA step failed' );
			};
			return $steps;
		};
		add_filter( 'ndv-reviews/qa_upgrade_steps', $fail );
		Installer::maybe_upgrade();
		remove_filter( 'ndv-reviews/qa_upgrade_steps', $fail );
		$this->ok( 2 === $this->stored_version(), 'AC1.3: a WP_Error step leaves the version at 2' );
		$this->ok( false !== strpos( (string) get_option( Installer::ERROR_OPTION, '' ), 'QA step failed' ), 'AC1.3: the step error is stored' );

		// A throwing step counts as a failure too.
		$this->force_version( 2 );
		$throw = function ( $steps ) {
			$steps[ Installer::V_FOUNDATIONS ] = function () {
				throw new \RuntimeException( 'QA step threw' );
			};
			return $steps;
		};
		add_filter( 'ndv-reviews/qa_upgrade_steps', $throw );
		Installer::maybe_upgrade();
		remove_filter( 'ndv-reviews/qa_upgrade_steps', $throw );
		$this->ok( 2 === $this->stored_version() && null === $this->lock_value(), 'AC1.3: a throwing step fails cleanly and releases the lock' );
		// Two pending steps: the first succeeds and is stamped, the second fails.
		if ( (int) NDVR_DB_VERSION >= 4 ) {
			$this->force_version( 2 );
			$later = function ( $steps ) {
				$steps[4] = function () {
					return new \WP_Error( 'qa_step', 'later step failed' );
				};
				return $steps;
			};
			add_filter( 'ndv-reviews/qa_upgrade_steps', $later );
			Installer::maybe_upgrade();
			remove_filter( 'ndv-reviews/qa_upgrade_steps', $later );
			$this->ok( 3 === $this->stored_version(), 'AC1.3: a finished step stays stamped when a later one fails', (string) $this->stored_version() );
		}

		$this->force_version( 2 );
		$fail_again = function ( $steps ) {
			$steps[ Installer::V_FOUNDATIONS ] = function () {
				return new \WP_Error( 'qa_step', 'again' );
			};
			return $steps;
		};
		add_filter( 'ndv-reviews/qa_upgrade_steps', $fail_again );
		Installer::maybe_upgrade();
		remove_filter( 'ndv-reviews/qa_upgrade_steps', $fail_again );

		$this->queries = array();
		Installer::maybe_upgrade();
		$creates       = $this->count_queries( '/^\s*(CREATE|ALTER)\s+TABLE/i' );
		$this->queries = null;
		$this->ok( 0 === $creates, 'AC1.3: during backoff a request runs no dbDelta', "creates={$creates}" );

		// Downgrade: stored 9, code 3.
		delete_transient( Installer::BACKOFF_TRANSIENT );
		$this->force_version( 9 );
		$this->queries = array();
		Installer::maybe_upgrade();
		\NdvReviews\Activator::activate();
		$creates       = $this->count_queries( '/^\s*(CREATE|ALTER)\s+TABLE/i' );
		$this->queries = null;
		$this->ok( 9 === $this->stored_version(), 'AC1.3: stored 9 stays 9 (never lowered)' );
		$this->ok( 0 === $creates, 'AC1.3/1.4: a downgrade (and its activation) runs no CREATE/ALTER', "creates={$creates}" );
		delete_transient( 'ndv_reviews_activated' );
	}

	/**
	 * AC 1.4: activation runs pending steps; fresh install stamps without steps.
	 *
	 * @return void
	 */
	private function ac1_activation() {
		// Reactivation of a v2 site.
		$this->force_version( 2 );
		$this->raw_settings( array( 'recaptcha_enabled' => true ), array( 'captcha_provider' ) );
		\NdvReviews\Deactivator::deactivate();
		\NdvReviews\Activator::activate();
		$raw = get_option( NDVR_OPTION_SETTINGS );
		$this->ok( (int) NDVR_DB_VERSION === $this->stored_version() && 'recaptcha' === ( $raw['captcha_provider'] ?? '' ), 'AC1.4: reactivating a v2 site runs the v3 step' );

		// Fresh install: no version option.
		delete_option( NDVR_OPTION_DB_VERSION );
		$this->raw_settings( array( 'recaptcha_enabled' => true ), array( 'captcha_provider' ) );
		\NdvReviews\Activator::activate();
		$raw = get_option( NDVR_OPTION_SETTINGS );
		$this->ok( (int) NDVR_DB_VERSION === $this->stored_version(), 'AC1.4: a fresh install stamps the code version' );
		$this->ok( ! isset( $raw['captcha_provider'] ), 'AC1.4: a fresh install runs no migration step' );
		$criteria = ( new \NdvReviews\Reviews\CriteriaRepository() )->get_all();
		$this->ok( ! empty( $criteria ), 'AC1.4: criteria exist after a fresh install' );
		delete_transient( 'ndv_reviews_activated' );
	}

	/**
	 * AC 2: the dry run lists every table and registry entry.
	 *
	 * @return void
	 */
	private function ac2_uninstall() {
		$log  = Uninstall::run( true );
		$text = implode( "\n", $log );

		$missing = array();
		foreach ( Installer::table_names() as $table ) {
			if ( false === strpos( $text, 'table: ' . $table ) ) {
				$missing[] = $table;
			}
		}
		foreach ( Uninstall::registry() as $kind => $entries ) {
			foreach ( $entries as $entry ) {
				if ( false === strpos( $text, $entry ) ) {
					$missing[] = $kind . ':' . $entry;
				}
			}
		}
		$this->ok( empty( $missing ), 'AC2: dry run lists every table and registry entry', implode( ', ', $missing ) );
		$this->ok( false !== get_option( NDVR_OPTION_SETTINGS, false ), 'AC2: dry run deleted nothing' );

		$order = array_search( 'reviews: ', array_map( function ( $l ) { return substr( $l, 0, 9 ); }, $log ), true );
		$first_table = null;
		foreach ( $log as $i => $l ) {
			if ( 0 === strpos( $l, 'table: ' ) ) {
				$first_table = $i;
				break;
			}
		}
		$this->ok( false !== $order && null !== $first_table && $order < $first_table, 'AC2: reviews are handled before tables are dropped' );
		// The same dry run through uninstall.php itself (opt-in off: a dry run ignores it).
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', NDVR_BASENAME );
		}
		if ( ! defined( 'NDVR_UNINSTALL_DRY_RUN' ) ) {
			define( 'NDVR_UNINSTALL_DRY_RUN', true );
		}
		$via_file = include NDVR_DIR . 'uninstall.php';
		$this->ok( is_array( $via_file ) && $via_file === $log, 'AC2: uninstall.php returns the same dry-run log' );
		$this->ok( false !== get_option( NDVR_OPTION_SETTINGS, false ), 'AC2: uninstall.php dry run deleted nothing' );
		$this->line( 'MANUAL: AC2 "plugin not loaded": deactivate the plugin, then in a fresh `wp eval` define WP_UNINSTALL_PLUGIN and NDVR_UNINSTALL_DRY_RUN and include uninstall.php; expect the log and no fatal.' );
	}

	/**
	 * AC 3: a test view, column and row action render; a custom action fires
	 * after the nonce check.
	 *
	 * @return void
	 */
	private function ac3_moderation() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		set_current_screen( 'rosette_page_ndv-reviews-moderation' );

		$view = function ( $views ) {
			$views['qa_view'] = array(
				'label'      => 'QA view',
				'count'      => 7,
				'query_args' => function ( $args ) {
					$args['status'] = 'hold';
					return $args;
				},
			);
			return $views;
		};
		$col    = function ( $c ) {
			$c['qa_col'] = 'QA column';
			return $c;
		};
		$cell   = function () {
			return '<em>qa-cell</em><script>bad()</script>';
		};
		$action = function ( $a, $item ) {
			$a['qa_act'] = '<a href="' . esc_url( \NdvReviews\Moderation\Page::row_action_url( 'qa_act', (int) $item->comment_ID ) ) . '">QA act</a>';
			return $a;
		};
		add_filter( 'ndv-reviews/moderation_views', $view );
		add_filter( 'ndv-reviews/moderation_columns', $col );
		add_filter( 'ndv-reviews/moderation_column_qa_col', $cell );
		add_filter( 'ndv-reviews/moderation_row_actions', $action, 10, 2 );

		$table = new \NdvReviews\Moderation\ListTable( \NdvReviews\Moderation\Page::PAGE_SLUG );
		$views = $table->get_views();
		$this->ok( isset( $views['qa_view'] ) && false !== strpos( $views['qa_view'], 'QA view' ) && false !== strpos( $views['qa_view'], '(7)' ), 'AC3: a filtered view renders with its count' );
		$cols = $table->get_columns();
		$this->ok( isset( $cols['qa_col'] ), 'AC3: a filtered column is added' );
		$html = $table->column_default( (object) array( 'comment_ID' => 1 ), 'qa_col' );
		$this->ok( false !== strpos( $html, '<em>qa-cell</em>' ) && false === strpos( $html, '<script>' ), 'AC3: the column cell renders through wp_kses_post' );

		// Custom row action: nonce + capability, then the action fires.
		$fired  = array();
		$listen = function ( $act, $ids ) use ( &$fired ) {
			$fired[] = array( $act, $ids );
		};
		add_action( 'ndv-reviews/moderation_handle_action', $listen, 10, 2 );
		$stop = function () {
			throw new NDVR_QA_Redirect();
		};
		add_filter( 'wp_redirect', $stop );

		$_GET = array(
			'page'        => \NdvReviews\Moderation\Page::PAGE_SLUG,
			'ndvr_action' => 'qa_act',
			'review'      => '42',
			'_wpnonce'    => 'invalid',
		);
		$_REQUEST = $_GET;
		$page     = \NdvReviews\Plugin::instance()->container()->get( 'moderation_page' );
		$die      = function () {
			return function () {
				throw new NDVR_QA_Redirect( 'die' );
			};
		};
		add_filter( 'wp_die_handler', $die );
		try {
			$page->handle_actions();
		} catch ( NDVR_QA_Redirect $r ) {
			unset( $r );
		}
		$this->ok( empty( $fired ), 'AC3: a bad nonce never reaches the custom action' );

		$_GET['_wpnonce'] = wp_create_nonce( 'ndvr_review_action' );
		$_REQUEST         = $_GET;
		try {
			$page->handle_actions();
		} catch ( NDVR_QA_Redirect $r ) {
			unset( $r );
		}
		$this->ok( 1 === count( $fired ) && 'qa_act' === $fired[0][0] && array( 42 ) === $fired[0][1], 'AC3: a custom row action fires after the nonce check' );

		remove_filter( 'wp_die_handler', $die );
		remove_filter( 'wp_redirect', $stop );
		remove_action( 'ndv-reviews/moderation_handle_action', $listen, 10 );
		remove_filter( 'ndv-reviews/moderation_views', $view );
		remove_filter( 'ndv-reviews/moderation_columns', $col );
		remove_filter( 'ndv-reviews/moderation_column_qa_col', $cell );
		remove_filter( 'ndv-reviews/moderation_row_actions', $action, 10 );
		$_GET     = array();
		$_REQUEST = array();
	}

	/**
	 * AC 4: the four new template actions fire in order.
	 *
	 * @return void
	 */
	private function ac4_template() {
		$order  = array();
		$hooks  = array( 'review_author_badges', 'review_meta_after', 'review_body_after', 'review_foot_end', 'review_item_after' );
		$listen = array();
		foreach ( $hooks as $hook ) {
			$listen[ $hook ] = function () use ( &$order, $hook ) {
				$order[] = $hook;
			};
			add_action( 'ndv-reviews/' . $hook, $listen[ $hook ] );
		}
		$mark = function ( $html ) {
			return $html . '<mark>qa</mark>';
		};
		add_filter( 'ndv-reviews/review_body_html', $mark );

		$html = \NdvReviews\Support\View::render(
			'review-item.php',
			array(
				'review'     => array(
					'id'         => 1,
					'author'     => 'QA',
					'date'       => current_time( 'mysql' ),
					'content'    => 'Body',
					'title'      => 'Title',
					'overall'    => 4.0,
					'rating'     => 4,
					'recommend'  => 'yes',
					'verified'   => true,
					'helpful_up' => 0,
					'criteria'   => array(),
					'media'      => array(),
				),
				'vote_nonce' => 'x',
			)
		);

		foreach ( $listen as $hook => $cb ) {
			remove_action( 'ndv-reviews/' . $hook, $cb );
		}
		remove_filter( 'ndv-reviews/review_body_html', $mark );

		$this->ok( $hooks === $order, 'AC4: template hooks fire in order', implode( ' > ', $order ) );
		$this->ok( false !== strpos( $html, '<mark>qa</mark>' ), 'AC4/F5: review_body_html filter applies' );
		$this->ok( false !== strpos( $html, '<h4 class="ndvr-review-title">Title</h4>' ), 'F5: title markup unchanged when unfiltered' );
		$this->line( 'NOTE: AC4 "old override without the hooks" is checked by copying the 1.0.0 review-item.php into the theme.' );
	}

	/**
	 * AC 5: a blank reCAPTCHA secret keeps the stored one; it's not in the HTML.
	 *
	 * @return void
	 */
	private function ac5_secret() {
		$settings = \NdvReviews\Plugin::instance()->container()->get( 'settings' );
		$settings->update( array( 'recaptcha_secret' => 'qa-secret-123' ) );

		// A registered reminders-page key must survive a Settings save (F6).
		$field = function ( $f ) {
			$f['qa_reminders_key'] = array(
				'sanitize' => 'sanitize_text_field',
				'default'  => '',
				'page'     => 'reminders',
				'card'     => 'qa',
			);
			return $f;
		};
		add_filter( 'ndv-reviews/settings_fields', $field );
		\NdvReviews\Admin\SettingsFields::flush();
		$settings->update( array( 'qa_reminders_key' => 'keep-me' ) );

		$page  = \NdvReviews\Plugin::instance()->container()->get( 'admin_settings_page' );
		$_POST = array(
			'ndvr_settings_save' => '1',
			'_wpnonce'           => wp_create_nonce( \NdvReviews\Admin\SettingsPage::NONCE ),
			'recaptcha_secret'   => '',
			'qa_reminders_key'   => 'overwritten',
			'enable_reviews'     => '1',
		);
		$_REQUEST                = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$page->handle_save();

		$raw = get_option( NDVR_OPTION_SETTINGS );
		$this->ok( 'qa-secret-123' === ( $raw['recaptcha_secret'] ?? '' ), 'AC5: a blank secret keeps the stored one' );
		$this->ok( 'keep-me' === ( $raw['qa_reminders_key'] ?? '' ), 'F6: a Settings save leaves a reminders-page key unchanged' );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->ok( false === strpos( $html, 'qa-secret-123' ), 'AC5: the secret is not in the page HTML' );

		remove_filter( 'ndv-reviews/settings_fields', $field );
		\NdvReviews\Admin\SettingsFields::flush();
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * AC 6: rate_limit('report', 2) blocks the third try without touching submit.
	 *
	 * @return void
	 */
	private function ac6_rate_limit() {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
		$spam                   = \NdvReviews\Plugin::instance()->container()->get( 'antispam' );
		$submit_key             = 'ndvr_rl_' . $spam->ip_hash();
		delete_transient( $submit_key );

		$results = array( $spam->rate_limit( 'report', 2 ), $spam->rate_limit( 'report', 2 ), $spam->rate_limit( 'report', 2 ) );
		$this->ok( true === $results[0] && true === $results[1] && is_wp_error( $results[2] ), 'AC6: the third report within the hour is refused' );
		$this->ok( false === get_transient( $submit_key ), 'AC6: the submit bucket is untouched' );

		delete_transient( 'ndvr_rl_report_' . $spam->ip_hash() );
	}

	/**
	 * AC 7 (Pro): no star floor; a 1-star verified review is auto-approved.
	 *
	 * @return void
	 */
	private function ac7_pro() {
		if ( ! class_exists( '\NdvReviews\Pro\Moderation\Plus' ) ) {
			$this->line( 'SKIP: AC7 needs the Pro add-on.' );
			return;
		}
		$pro = get_option( 'ndv_reviews_pro_settings', array() );
		$this->ok( ! is_array( $pro ) || ! array_key_exists( 'auto_approve_min_stars', $pro ), 'AC7: the min-stars setting no longer exists' );
		$this->line( 'MANUAL: AC7 second half: with "Auto-approve reviews from verified buyers" on, a 1-star review by a buyer is approved at once.' );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Record queries while $this->queries is an array.
	 *
	 * @param string $sql Query.
	 * @return string
	 */
	public function record_query( $sql ) {
		if ( is_array( $this->queries ) ) {
			$this->queries[] = $sql;
		}
		return $sql;
	}

	/**
	 * Count recorded queries matching a pattern.
	 *
	 * @param string $pattern Regex.
	 * @return int
	 */
	private function count_queries( $pattern ) {
		return count( preg_grep( $pattern, (array) $this->queries ) );
	}

	/**
	 * Save everything the harness changes.
	 *
	 * @return void
	 */
	private function save_state() {
		foreach ( array( NDVR_OPTION_DB_VERSION, NDVR_OPTION_SETTINGS, Installer::ERROR_OPTION ) as $option ) {
			$this->saved[ $option ] = get_option( $option, null );
		}
		$this->saved['__backoff'] = get_transient( Installer::BACKOFF_TRANSIENT );
		$this->saved['__ip']      = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- restored verbatim.
	}

	/**
	 * Restore the saved state and remove any lock row.
	 *
	 * @return void
	 */
	private function restore_state() {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => Installer::LOCK_OPTION ) );
		wp_cache_delete( 'notoptions', 'options' );
		foreach ( array( NDVR_OPTION_DB_VERSION, NDVR_OPTION_SETTINGS, Installer::ERROR_OPTION ) as $option ) {
			if ( null === $this->saved[ $option ] ) {
				delete_option( $option );
			} else {
				update_option( $option, $this->saved[ $option ] );
			}
		}
		if ( false === $this->saved['__backoff'] ) {
			delete_transient( Installer::BACKOFF_TRANSIENT );
		}
		if ( null === $this->saved['__ip'] ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->saved['__ip'];
		}
		$this->line( 'cleanup: state restored' );
	}

	/**
	 * Write the stored version and clear backoff/error.
	 *
	 * @param int $version Version.
	 * @return void
	 */
	private function force_version( $version ) {
		update_option( NDVR_OPTION_DB_VERSION, (string) $version, true );
		delete_transient( Installer::BACKOFF_TRANSIENT );
		delete_option( Installer::ERROR_OPTION );
		$this->clear_lock();
	}

	/**
	 * The stored version, read without caches.
	 *
	 * @return int|null
	 */
	private function stored_version() {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", NDVR_OPTION_DB_VERSION ) );
		return null === $v ? null : (int) $v;
	}

	/**
	 * Merge into / remove keys from the raw settings option.
	 *
	 * @param array<string,mixed> $set    Keys to set.
	 * @param string[]            $remove Keys to remove.
	 * @return void
	 */
	private function raw_settings( array $set, array $remove ) {
		$raw = get_option( NDVR_OPTION_SETTINGS, array() );
		$raw = is_array( $raw ) ? $raw : array();
		foreach ( $remove as $key ) {
			unset( $raw[ $key ] );
		}
		update_option( NDVR_OPTION_SETTINGS, array_merge( $raw, $set ) );
	}

	/**
	 * Insert a lock row directly.
	 *
	 * @param string $value Lock value.
	 * @return void
	 */
	private function put_lock( $value ) {
		global $wpdb;
		$this->clear_lock();
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => Installer::LOCK_OPTION,
				'option_value' => $value,
				'autoload'     => 'no',
			)
		);
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * The lock row value, or null.
	 *
	 * @return string|null
	 */
	private function lock_value() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Installer::LOCK_OPTION ) );
	}

	/**
	 * Remove the lock row.
	 *
	 * @return void
	 */
	private function clear_lock() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => Installer::LOCK_OPTION ) );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Record one check.
	 *
	 * @param bool   $condition Passed.
	 * @param string $label     Label.
	 * @param string $detail    Failure detail.
	 * @return void
	 */
	private function ok( $condition, $label, $detail = '' ) {
		++$this->count[ $condition ? 'pass' : 'fail' ];
		$this->line( ( $condition ? 'PASS: ' : 'FAIL: ' ) . $label . ( ! $condition && '' !== $detail ? ' -- ' . $detail : '' ) );
	}

	/**
	 * Print a line.
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

$ndvr_qa_ok = ( new NDVR_QA_RR00() )->run();
if ( defined( 'WP_CLI' ) && WP_CLI && ! $ndvr_qa_ok ) {
	\WP_CLI::halt( 1 );
}
