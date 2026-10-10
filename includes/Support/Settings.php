<?php
/**
 * Settings accessor — reads/writes the single autoloaded options array.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin, cached wrapper over the `ndv_reviews_settings` option (build-plan §6.3).
 *
 * Avoids option sprawl: one versioned array holds all free settings.
 */
class Settings {

	/**
	 * Cached settings array.
	 *
	 * @var array<string,mixed>|null
	 */
	private $cache = null;

	/**
	 * Default settings schema. New keys are added here as phases land.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enable_reviews'      => true,
			'reviewable_post_types' => array(), // extra post types beyond product.
			'allow_guest_reviews' => true,
			'photo_uploads'       => true,
			'max_photos'          => 5,
			// Design (storefront appearance — see the Design screen).
			'design_accent'       => '#181a1f', // brand/accent color for buttons, active pills, links.
			'design_template'     => 'list',    // list | grid.
			'design_summary'      => 'panel',   // panel | compact.
			'design_card'         => 'soft',    // soft | bordered | flat.
			'design_rating'       => 'stars',   // stars | hearts | thumbs | emoji.
			'design_rating_color' => '',        // star/heart colour; '' = built-in (amber stars, red hearts).
			'design_bar_color'    => '',        // rating bar colour; '' = built-in (follows stars; criteria green).
			'design_font'         => 'system', // system | serif | rounded | mono.
			'design_scale'        => 'normal', // compact | normal | large.
			'schema_mode'         => 'auto',   // auto | plugin | off.
			'recaptcha_enabled'   => false,
			'recaptcha_site_key'  => '',
			'recaptcha_secret'    => '',
			// No `captcha_provider` default on purpose: update() writes defaults into
			// the option, and an absent key must keep meaning "reCAPTCHA when
			// recaptcha_enabled" until the RR-00 step has run (AntiSpam::provider()).
			'turnstile_site_key'  => '',
			'turnstile_secret'    => '',
			'hcaptcha_site_key'   => '',
			'hcaptcha_secret'     => '',
			'reminder_enabled'    => false,
			'reminder_status'     => 'completed',
			'reminder_delay_days' => 7,
			'reminder_subject'    => '',
			'reminder_body'       => '',
			'from_name'           => '',
			'from_email'          => '',
			'token_expiry_days'   => 60,
			'admin_notify'        => 'pending', // off | all | pending.
			'admin_notify_email'  => '',        // empty = site admin email.
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * Get the full settings array (merged with defaults).
	 *
	 * @return array<string,mixed>
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( NDVR_OPTION_SETTINGS, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}

		return $this->cache;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if the key is missing.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		if ( null !== $default ) {
			return $default;
		}

		// A key a feature registered (RR-00 F6) but nobody has saved yet.
		$registered = \NdvReviews\Admin\SettingsFields::defaults();

		return array_key_exists( $key, $registered ) ? $registered[ $key ] : null;
	}

	/**
	 * Persist a partial set of settings (merged over current values).
	 *
	 * @param array<string,mixed> $values Values to merge and save.
	 * @return void
	 */
	public function update( array $values ) {
		// Merge over the stored option, not $this->cache: an upgrade step (or
		// another writer) may have changed the option after the cache was
		// filled, and a stale merge would silently undo it.
		$stored = get_option( NDVR_OPTION_SETTINGS, array() );
		$merged = array_merge( wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() ), $values );
		update_option( NDVR_OPTION_SETTINGS, $merged );
		$this->cache = $merged;
	}
}
