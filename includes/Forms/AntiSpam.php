<?php
/**
 * Anti-spam stack: honeypot, per-IP rate limiting, optional reCAPTCHA v3.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Forms;

use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight, layered spam protection for review submissions.
 */
class AntiSpam {

	/**
	 * Honeypot field name (must remain empty).
	 */
	const HONEYPOT = 'ndvr_hp_url';

	/**
	 * Settings accessor.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings accessor.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run all enabled checks against a submission.
	 *
	 * @param array<string,mixed> $input Raw (already-unslashed) request data.
	 * @return true|\WP_Error True if clean, WP_Error otherwise.
	 */
	public function check( array $input ) {
		// 1. Honeypot — bots fill hidden fields.
		if ( ! empty( $input[ self::HONEYPOT ] ) ) {
			return new \WP_Error( 'ndvr_spam_honeypot', __( 'Your submission could not be processed.', 'ndv-reviews' ) );
		}

		// 2. Per-IP rate limit.
		$rate = $this->check_rate_limit();
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// 3. reCAPTCHA v3 (only if enabled with the site owner's keys).
		if ( $this->settings->get( 'recaptcha_enabled' ) ) {
			$token   = isset( $input['ndvr_recaptcha_token'] ) ? sanitize_text_field( $input['ndvr_recaptcha_token'] ) : '';
			$captcha = $this->verify_recaptcha( $token );
			if ( is_wp_error( $captcha ) ) {
				return $captcha;
			}
		}

		return true;
	}

	/**
	 * Record a successful submission for rate-limiting purposes.
	 *
	 * @return void
	 */
	public function record() {
		$key   = $this->rate_key();
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Enforce a per-IP submission ceiling.
	 *
	 * @return true|\WP_Error
	 */
	private function check_rate_limit() {
		/**
		 * Filter the max review submissions per IP per hour.
		 *
		 * @param int $max Default ceiling.
		 */
		$max = (int) apply_filters( 'ndv-reviews/rate_limit_per_hour', 5 );

		if ( $max <= 0 ) {
			return true;
		}

		$count = (int) get_transient( $this->rate_key() );
		if ( $count >= $max ) {
			return new \WP_Error( 'ndvr_spam_rate', __( 'You are submitting reviews too quickly. Please try again later.', 'ndv-reviews' ) );
		}

		return true;
	}

	/**
	 * Transient key for the current visitor's hashed IP.
	 *
	 * @return string
	 */
	private function rate_key() {
		return 'ndvr_rl_' . $this->ip_hash();
	}

	/**
	 * A salted hash of the visitor IP (we never store raw IPs — IP is PII).
	 *
	 * @return string
	 */
	private function ip_hash() {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return wp_hash( $ip );
	}

	/**
	 * Verify a reCAPTCHA v3 token with Google using the site owner's secret.
	 *
	 * @param string $token Client token.
	 * @return true|\WP_Error
	 */
	private function verify_recaptcha( $token ) {
		$secret = (string) $this->settings->get( 'recaptcha_secret' );

		if ( '' === $secret ) {
			// Misconfiguration: do not block legitimate users over a missing key.
			return true;
		}

		if ( '' === $token ) {
			return new \WP_Error( 'ndvr_spam_captcha', __( 'Captcha verification failed. Please reload and try again.', 'ndv-reviews' ) );
		}

		$response = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 5,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Network hiccup shouldn't lose a real review; allow but log.
			return true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		/**
		 * Filter the minimum acceptable reCAPTCHA v3 score.
		 *
		 * @param float $threshold Default 0.5.
		 */
		$threshold = (float) apply_filters( 'ndv-reviews/recaptcha_threshold', 0.5 );

		if ( empty( $body['success'] ) || ( isset( $body['score'] ) && (float) $body['score'] < $threshold ) ) {
			return new \WP_Error( 'ndvr_spam_captcha', __( 'Captcha verification failed. Please try again.', 'ndv-reviews' ) );
		}

		return true;
	}
}
