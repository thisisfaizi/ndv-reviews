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
			'schema_version'      => 1,
			'enable_reviews'      => true,
			'criteria_mode'       => 'mean',   // mean | primary.
			'allow_guest_reviews' => true,
			'require_verified'    => false,
			'photo_uploads'       => true,
			'max_photos'          => 5,
			'recaptcha_enabled'   => false,
			'recaptcha_site_key'  => '',
			'recaptcha_secret'    => '',
			'reminder_enabled'    => false,
			'reminder_status'     => 'completed',
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

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist a partial set of settings (merged over current values).
	 *
	 * @param array<string,mixed> $values Values to merge and save.
	 * @return void
	 */
	public function update( array $values ) {
		$merged = array_merge( $this->all(), $values );
		update_option( NDVR_OPTION_SETTINGS, $merged );
		$this->cache = $merged;
	}
}
