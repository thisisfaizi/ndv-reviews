<?php
/**
 * Plugin orchestrator: service container + hook bootstrap.
 *
 * @package NdvReviews
 */

namespace NdvReviews;

use NdvReviews\Support\Container;
use NdvReviews\Support\Settings;
use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Central bootstrap. Owns the container and exposes the stable internal API
 * that the Pro add-on hooks into (it never edits free files — build-plan §5.3).
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Construct with a fresh container and core service bindings.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->register_core_services();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The service container (Pro resolves and registers services through this).
	 *
	 * @return Container
	 */
	public function container() {
		return $this->container;
	}

	/**
	 * Convenience: settings accessor.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->container->get( 'settings' );
	}

	/**
	 * Bind the always-available core services.
	 *
	 * @return void
	 */
	private function register_core_services() {
		$c = $this->container;

		$c->set(
			'settings',
			static function () {
				return new Settings();
			}
		);

		$c->set(
			'criteria',
			static function () {
				return new \NdvReviews\Reviews\CriteriaRepository();
			}
		);

		$c->set(
			'rating_cache',
			static function () {
				return new \NdvReviews\Reviews\RatingCache();
			}
		);

		$c->set(
			'verified_buyer',
			static function () {
				return new \NdvReviews\Reviews\VerifiedBuyer();
			}
		);

		$c->set(
			'reviews',
			static function ( $c ) {
				return new \NdvReviews\Reviews\ReviewRepository(
					$c->get( 'rating_cache' ),
					$c->get( 'verified_buyer' ),
					$c->get( 'criteria' )
				);
			}
		);

		$c->set(
			'antispam',
			static function ( $c ) {
				return new \NdvReviews\Forms\AntiSpam( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'upload',
			static function ( $c ) {
				return new \NdvReviews\Forms\Upload( $c->get( 'settings' ) );
			}
		);

		$c->set(
			'review_form',
			static function ( $c ) {
				return new \NdvReviews\Forms\ReviewForm(
					$c->get( 'settings' ),
					$c->get( 'criteria' ),
					$c->get( 'reviews' ),
					$c->get( 'antispam' ),
					$c->get( 'upload' )
				);
			}
		);

		$c->set(
			'admin_criteria_page',
			static function ( $c ) {
				return new \NdvReviews\Admin\CriteriaPage( $c->get( 'criteria' ) );
			}
		);

		// --- Phase 2: display, voting, schema, moderation ---

		$c->set(
			'review_query',
			static function () {
				return new \NdvReviews\Reviews\ReviewQuery();
			}
		);

		$c->set(
			'summary',
			static function () {
				return new \NdvReviews\Display\Summary();
			}
		);

		$c->set(
			'renderer',
			static function ( $c ) {
				return new \NdvReviews\Display\Renderer(
					$c->get( 'settings' ),
					$c->get( 'summary' ),
					$c->get( 'review_query' )
				);
			}
		);

		$c->set(
			'votes',
			static function () {
				return new \NdvReviews\Reviews\Votes();
			}
		);

		$c->set(
			'json_ld',
			static function ( $c ) {
				return new \NdvReviews\Schema\JsonLd( $c->get( 'settings' ), $c->get( 'review_query' ) );
			}
		);

		$c->set(
			'moderation_actions',
			static function ( $c ) {
				return new \NdvReviews\Moderation\Actions( $c->get( 'rating_cache' ) );
			}
		);

		$c->set(
			'moderation_page',
			static function ( $c ) {
				return new \NdvReviews\Moderation\Page(
					$c->get( 'criteria' ),
					$c->get( 'review_query' ),
					$c->get( 'rating_cache' )
				);
			}
		);
	}

	/**
	 * Boot the plugin: load i18n, register services, fire the Pro entry hook.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Translations are auto-loaded by WordPress.org for the plugin slug since WP 4.6.
		add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );

		/**
		 * Phase-by-phase service modules that wire their own hooks.
		 *
		 * @var array<int,Registerable> $services
		 */
		$services = array(
			$this->container->get( 'review_form' ),
			$this->container->get( 'admin_criteria_page' ),
			$this->container->get( 'renderer' ),
			$this->container->get( 'votes' ),
			$this->container->get( 'json_ld' ),
			$this->container->get( 'moderation_actions' ),
			$this->container->get( 'moderation_page' ),
		);

		/**
		 * Filter the list of core services before they register their hooks.
		 *
		 * @param Registerable[] $services Core service instances.
		 * @param Plugin         $plugin   The plugin instance.
		 */
		$services = apply_filters( 'ndv-reviews/services', $services, $this );

		foreach ( $services as $service ) {
			if ( $service instanceof Registerable ) {
				$service->register();
			}
		}

		/**
		 * Fires once the free plugin is fully loaded. The Pro add-on boots here,
		 * checks its license, and registers its modules against this instance.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'ndv-reviews/loaded', $this );
	}

	/**
	 * Admin notice shown when WooCommerce is not active.
	 *
	 * @return void
	 */
	public static function render_woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'NDV Reviews requires WooCommerce to be installed and active.', 'ndv-reviews' )
		);
	}
}
