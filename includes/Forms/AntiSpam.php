<?php
/**
 * Anti-spam stack: honeypot, per-IP rate limiting, optional captcha
 * (Google reCAPTCHA v3, Cloudflare Turnstile or hCaptcha).
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
	 * Captcha providers (RR-13).
	 */
	const PROVIDERS = array( 'none', 'recaptcha', 'turnstile', 'hcaptcha' );

	/**
	 * Verify endpoints.
	 */
	const VERIFY_URLS = array(
		'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
		'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		'hcaptcha'  => 'https://api.hcaptcha.com/siteverify',
	);

	/**
	 * Provider script URLs (enqueued only where a form renders).
	 */
	const SCRIPT_URLS = array(
		'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
		// recaptchacompat=off: no window.grecaptcha hook clashing with real reCAPTCHA plugins.
		'hcaptcha'  => 'https://js.hcaptcha.com/1/api.js?render=explicit&recaptchacompat=off',
	);

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
	 * The per-IP limit and reCAPTCHA exist to stop anonymous floods. A request
	 * that the caller has already authenticated server-side (a resolved
	 * review-link token) skips both: the token caps what can be submitted, and
	 * a customer reviewing six items, or several customers behind one office
	 * IP, would otherwise hit the hourly ceiling. Never derive $authenticated
	 * from a client-supplied field.
	 *
	 * @param array<string,mixed> $input         Raw (already-unslashed) request data.
	 * @param bool                $authenticated Whether the caller verified a credential server-side.
	 * @return true|\WP_Error True if clean, WP_Error otherwise.
	 */
	public function check( array $input, $authenticated = false ) {
		// 1. Honeypot — bots fill hidden fields.
		if ( ! $this->honeypot_ok( $input ) ) {
			return new \WP_Error( 'ndvr_spam_honeypot', __( 'Your submission could not be processed.', 'rosette-reviews' ) );
		}

		if ( true === $authenticated ) {
			return true;
		}

		// 2. Per-IP rate limit.
		$rate = $this->check_rate_limit();
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// 3. Captcha (only the chosen provider, with the site owner's keys).
		$provider = self::provider();
		if ( 'none' !== $provider ) {
			$token = '';
			// Ours first, then the old reCAPTCHA field, then the widgets' own inputs.
			foreach ( array( 'ndvr_captcha_token', 'ndvr_recaptcha_token', 'cf-turnstile-response', 'h-captcha-response' ) as $field ) {
				if ( '' === $token && isset( $input[ $field ] ) && is_string( $input[ $field ] ) ) {
					$token = sanitize_text_field( $input[ $field ] );
				}
			}
			$captcha = $this->verify_captcha( $provider, $token );
			if ( is_wp_error( $captcha ) ) {
				return $captcha;
			}
		}

		return true;
	}

	/**
	 * Kept for backward compatibility. Rate-limit counting now happens on every
	 * attempt inside check() (see check_rate_limit()), so a failed/abusive
	 * submission is throttled too — not only successful ones. This is a no-op.
	 *
	 * @return void
	 */
	public function record() {
		// Intentionally empty — counting moved to check_rate_limit().
	}

	/**
	 * Enforce a per-IP submission ceiling, counting EVERY attempt (pass or fail)
	 * so a stream of invalid submissions can't bypass the limit.
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

		return $this->rate_limit( 'submit', $max );
	}

	/**
	 * Whether the honeypot field was left empty (a person, not a bot).
	 *
	 * @param array<string,mixed> $input Raw (already-unslashed) request data.
	 * @return bool
	 */
	public function honeypot_ok( array $input ) {
		return empty( $input[ self::HONEYPOT ] );
	}

	/**
	 * Count one attempt in a per-IP hourly bucket and refuse once it is full
	 * (RR-00 F7).
	 *
	 * Every attempt counts, pass or fail, so a stream of invalid requests can't
	 * bypass the limit. Buckets are independent: `report` never touches
	 * `submit`. Keys are `ndvr_rl_{bucket}_{hash}`; the `submit` bucket keeps
	 * its original `ndvr_rl_{iphash}` key so existing counters carry over.
	 *
	 * @param string $bucket       Bucket slug (submit, report, edit, list, ...).
	 * @param int    $max_per_hour Attempts allowed per hour; 0 or less disables.
	 * @param string $subject      Optional key that replaces the visitor IP (for
	 *                             example "user:42" for a per-user limit).
	 * @return true|\WP_Error
	 */
	public function rate_limit( $bucket, $max_per_hour, $subject = '' ) {
		$bucket       = sanitize_key( (string) $bucket );
		$max_per_hour = (int) $max_per_hour;
		if ( '' === $bucket || $max_per_hour <= 0 ) {
			return true;
		}

		$key   = $this->rate_key( $bucket, (string) $subject );
		$count = (int) get_transient( $key );
		if ( $count >= $max_per_hour ) {
			$message = 'submit' === $bucket
				? __( 'You are submitting reviews too quickly. Please try again later.', 'rosette-reviews' )
				: __( 'You are doing that too often. Please try again later.', 'rosette-reviews' );
			return new \WP_Error( 'ndvr_spam_rate', $message );
		}

		// Count this attempt now, before the caller's own validation can fail,
		// so failures are throttled the same as successes.
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Transient key for a bucket and the current visitor.
	 *
	 * @param string $bucket  Sanitized bucket slug.
	 * @param string $subject Optional extra key part.
	 * @return string
	 */
	private function rate_key( $bucket, $subject = '' ) {
		if ( '' === $subject ) {
			$hash = $this->ip_hash();
			if ( 'submit' === $bucket ) {
				return 'ndvr_rl_' . $hash;
			}
		} else {
			// A subject (for example "user:42") replaces the IP, so a per-user
			// limit holds when the user changes network.
			$hash = wp_hash( 'subject|' . $subject );
		}

		// Transient names are capped at 172 characters; keep the slug short.
		return 'ndvr_rl_' . substr( $bucket, 0, 20 ) . '_' . $hash;
	}

	/**
	 * A salted hash of the visitor IP (we never store raw IPs: an IP is
	 * personal data). Public so features can key their own records by it.
	 *
	 * @return string
	 */
	public function ip_hash() {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return wp_hash( $ip );
	}

	/**
	 * The chosen captcha provider. Reads the raw option, so a site upgraded from
	 * the reCAPTCHA checkbox keeps its captcha even before the settings step
	 * has run (an absent key with `recaptcha_enabled` on means reCAPTCHA).
	 *
	 * @return string none|recaptcha|turnstile|hcaptcha
	 */
	public static function provider() {
		$raw = get_option( NDVR_OPTION_SETTINGS, array() );
		$raw = is_array( $raw ) ? $raw : array();
		if ( isset( $raw['captcha_provider'] ) && in_array( $raw['captcha_provider'], self::PROVIDERS, true ) ) {
			return (string) $raw['captcha_provider'];
		}

		return ! empty( $raw['recaptcha_enabled'] ) ? 'recaptcha' : 'none';
	}

	/**
	 * A provider's site key ('' when none).
	 *
	 * @param string $provider Provider.
	 * @return string
	 */
	public static function site_key( $provider ) {
		if ( ! in_array( $provider, array( 'recaptcha', 'turnstile', 'hcaptcha' ), true ) ) {
			return '';
		}

		return (string) \NdvReviews\Plugin::instance()->container()->get( 'settings' )->get( $provider . '_site_key', '' );
	}

	/**
	 * The provider the forms render: the chosen one when it has a site key.
	 *
	 * @return string none|recaptcha|turnstile|hcaptcha
	 */
	public static function active() {
		$provider = self::provider();

		return 'none' !== $provider && '' !== self::site_key( $provider ) ? $provider : 'none';
	}

	/**
	 * Register the active provider's script handle (none for reCAPTCHA-less
	 * stores). Returns the handle, or '' when there is none.
	 *
	 * @return string
	 */
	public static function register_script() {
		$provider = self::active();
		if ( 'none' === $provider ) {
			return '';
		}
		$handle = 'ndvr-' . $provider;
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			$src = 'recaptcha' === $provider
				? 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( self::site_key( 'recaptcha' ) )
				: self::SCRIPT_URLS[ $provider ];
			// The provider serves its own versioned script; no version query.
			wp_register_script( $handle, $src, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}

		return $handle;
	}

	/**
	 * Verify a captcha token with its provider, using the site owner's secret.
	 *
	 * @param string $provider recaptcha|turnstile|hcaptcha.
	 * @param string $token    Client token.
	 * @return true|\WP_Error
	 */
	private function verify_captcha( $provider, $token ) {
		$secret = (string) $this->settings->get( 'recaptcha' === $provider ? 'recaptcha_secret' : $provider . '_secret', '' );

		// Misconfiguration (no secret, or no site key so no widget renders):
		// don't block legitimate customers over a missing key.
		if ( '' === $secret || '' === self::site_key( $provider ) || ! isset( self::VERIFY_URLS[ $provider ] ) ) {
			return true;
		}

		if ( '' === $token ) {
			return new \WP_Error( 'ndvr_spam_captcha', __( 'Captcha verification failed. Please try again.', 'rosette-reviews' ) );
		}

		/**
		 * Filter a captcha provider's verify URL.
		 *
		 * @param string $url      Endpoint.
		 * @param string $provider recaptcha|turnstile|hcaptcha.
		 */
		$url = (string) apply_filters( 'ndv-reviews/captcha_verify_url', self::VERIFY_URLS[ $provider ], $provider );

		// No visitor IP is sent (less personal data).
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => 5,
				// Form-encoded (hCaptcha accepts nothing else). hCaptcha also checks the
				// site key, so a token issued for another site is refused.
				'body'    => array_merge(
					array(
						'secret'   => $secret,
						'response' => $token,
					),
					'hcaptcha' === $provider ? array( 'sitekey' => self::site_key( 'hcaptcha' ) ) : array()
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			/**
			 * Fires when a captcha provider can't be reached. The submission is
			 * allowed, so a network hiccup never loses a real review.
			 *
			 * @param string    $provider Provider.
			 * @param \WP_Error $error    The request error.
			 */
			do_action( 'ndv-reviews/captcha_unreachable', $provider, $response );
			return true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();
		if ( true !== ( $body['success'] ?? null ) ) {
			return new \WP_Error( 'ndvr_spam_captcha', __( 'Captcha verification failed. Please try again.', 'rosette-reviews' ) );
		}
		if ( 'recaptcha' !== $provider ) {
			return true;
		}

		/**
		 * Filter the minimum acceptable reCAPTCHA v3 score.
		 *
		 * @param float $threshold Default 0.5.
		 */
		$threshold = (float) apply_filters( 'ndv-reviews/recaptcha_threshold', 0.5 );

		if ( empty( $body['success'] ) || ( isset( $body['score'] ) && (float) $body['score'] < $threshold ) ) {
			return new \WP_Error( 'ndvr_spam_captcha', __( 'Captcha verification failed. Please try again.', 'rosette-reviews' ) );
		}

		return true;
	}
}
