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
		$this->container->set(
			'settings',
			static function () {
				return new Settings();
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

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );

		/**
		 * Register the phase-by-phase service modules here as they are built.
		 * Each must implement Registerable. Phase 0 ships none yet.
		 *
		 * @var array<int,Registerable> $services
		 */
		$services = array();

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
	 * Load the plugin text domain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( NDVR_TEXTDOMAIN, false, dirname( NDVR_BASENAME ) . '/languages' );
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
