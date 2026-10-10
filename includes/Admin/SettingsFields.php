<?php
/**
 * Settings field registry (RR-00 F6).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Settings keys added by features, each owned by one admin page.
 *
 * Features append to `ndv-reviews/settings_fields`. The page that owns a key
 * (its `page`) sanitizes and saves it; every other page leaves it alone, and
 * all saves merge into the stored option, so unknown keys are never dropped.
 * The keys built into each screen are still saved by that screen's own code.
 */
final class SettingsFields {

	/**
	 * Pages a field can belong to.
	 */
	const PAGES = array( 'settings', 'reminders', 'design' );

	/**
	 * Hidden form field listing the registered keys a form rendered.
	 */
	const MARKER = 'ndvr_fields';

	/**
	 * Re-entrancy guard: a filter callback that reads settings must not
	 * rebuild the registry from inside itself.
	 *
	 * @var bool
	 */
	private static $building = false;

	/**
	 * The normalized registry, cached once `init` has fired.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static $cache = null;

	/**
	 * The registry, normalized.
	 *
	 * Each entry is key => array(
	 *     'sanitize' => callable( mixed $raw ): mixed. $raw is the unslashed POST
	 *                   value, or null when the key wasn't posted (an unticked
	 *                   box or an empty multi-select): return the empty value.
	 *     'default'  => mixed.
	 *     'page'     => settings|reminders|design.
	 *     'card'     => card slug on that page ('trust' = "Reviews and trust").
	 *     'render'   => optional callable( mixed $value, string $key ): void that
	 *                   prints the field (escaped) inside its card.
	 *     'secret'   => optional bool. An empty submission keeps the stored value,
	 *                   and the value is never printed back.
	 * ).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields() {
		if ( self::$building ) {
			return array();
		}
		// Features register on plugins_loaded, so after `init` the list is final
		// for this request; settings reads on every page render reuse it.
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		self::$building = true;
		/**
		 * Filter the registered settings fields (RR-00 F6).
		 *
		 * @param array<string,array<string,mixed>> $fields key => {sanitize, default, page, card, render?, secret?}.
		 */
		$raw            = apply_filters( 'ndv-reviews/settings_fields', array() );
		self::$building = false;

		$fields = array();
		foreach ( (array) $raw as $key => $field ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $field ) || empty( $field['sanitize'] ) || ! is_callable( $field['sanitize'] ) ) {
				continue;
			}
			$page = isset( $field['page'] ) ? (string) $field['page'] : '';
			if ( ! in_array( $page, self::PAGES, true ) ) {
				continue;
			}

			$fields[ $key ] = array(
				'sanitize' => $field['sanitize'],
				'default'  => array_key_exists( 'default', $field ) ? $field['default'] : null,
				'page'     => $page,
				'card'     => isset( $field['card'] ) ? sanitize_key( (string) $field['card'] ) : '',
				'render'   => isset( $field['render'] ) && is_callable( $field['render'] ) ? $field['render'] : null,
				'secret'   => ! empty( $field['secret'] ),
			);
		}

		if ( did_action( 'init' ) ) {
			self::$cache = $fields;
		}

		return $fields;
	}

	/**
	 * Forget the cached registry (tests, or a filter added after `init`).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Defaults of every registered field (merged into Settings::all()).
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		$out = array();
		foreach ( self::fields() as $key => $field ) {
			$out[ $key ] = $field['default'];
		}

		return $out;
	}

	/**
	 * Sanitized values for one page's fields from a submitted form.
	 *
	 * @param string              $page  settings|reminders|design.
	 * @param array<string,mixed> $input The raw (slashed) request array, usually $_POST.
	 * @return array<string,mixed> Only keys owned by $page. Secret fields left
	 *                             blank are omitted, so the stored value stays;
	 *                             a ticked `{key}_remove` box clears one.
	 */
	public static function sanitize_page( $page, array $input ) {
		// Only keys the submitted form actually rendered (each rendered field
		// prints a marker): a page that doesn't show a field must not reset it.
		$listed = isset( $input[ self::MARKER ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $input[ self::MARKER ] ) ) : array();
		$listed = array_flip( $listed );

		$out = array();
		foreach ( self::fields() as $key => $field ) {
			if ( $page !== $field['page'] || ! isset( $listed[ $key ] ) ) {
				continue;
			}

			$raw = array_key_exists( $key, $input ) ? wp_unslash( $input[ $key ] ) : null;
			if ( $field['secret'] && ! empty( $input[ $key . '_remove' ] ) ) {
				$out[ $key ] = '';
				continue;
			}

			$value = call_user_func( $field['sanitize'], $raw );

			// A secret left blank (or blank after sanitizing) keeps the stored one.
			if ( $field['secret'] && ( null === $value || '' === $value ) ) {
				continue;
			}

			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * Print the hidden marker that tells sanitize_page() a field was on the
	 * form. render_card_fields() prints it for every field it renders; a
	 * feature that prints its own field elsewhere calls this next to it.
	 *
	 * @param string $key Settings key.
	 * @return void
	 */
	public static function marker( $key ) {
		printf( '<input type="hidden" name="%1$s[]" value="%2$s" />', esc_attr( self::MARKER ), esc_attr( sanitize_key( $key ) ) );
	}

	/**
	 * Default values for one page's fields (used by a "reset" button).
	 *
	 * @param string $page settings|reminders|design.
	 * @return array<string,mixed>
	 */
	public static function page_defaults( $page ) {
		$out = array();
		foreach ( self::fields() as $key => $field ) {
			if ( $page === $field['page'] && ! $field['secret'] ) {
				$out[ $key ] = $field['default'];
			}
		}

		return $out;
	}

	/**
	 * Fields on a page's card that have a render callback.
	 *
	 * @param string $page Page.
	 * @param string $card Card slug.
	 * @return array<string,array<string,mixed>>
	 */
	public static function card_fields( $page, $card ) {
		$out = array();
		foreach ( self::fields() as $key => $field ) {
			if ( $page === $field['page'] && $card === $field['card'] && $field['render'] ) {
				$out[ $key ] = $field;
			}
		}

		return $out;
	}

	/**
	 * Print a card's registered fields.
	 *
	 * @param string              $page     Page.
	 * @param string              $card     Card slug.
	 * @param array<string,mixed> $settings Current settings (Settings::all()).
	 * @param bool                $markers  Print each field's marker before it. Pass
	 *                                      false when the fields are table rows and
	 *                                      markers() was called outside the table.
	 * @return void
	 */
	public static function render_card_fields( $page, $card, array $settings, $markers = true ) {
		foreach ( self::card_fields( $page, $card ) as $key => $field ) {
			$value = array_key_exists( $key, $settings ) ? $settings[ $key ] : $field['default'];
			if ( $field['secret'] ) {
				// The callback learns only whether a value is saved, never the value.
				$value = ( is_string( $value ) && '' !== $value );
			}
			if ( $markers ) {
				self::marker( $key );
			}
			call_user_func( $field['render'], $value, $key );
		}
	}

	/**
	 * Print the markers of every renderable field on a card.
	 *
	 * @param string $page Page.
	 * @param string $card Card slug.
	 * @return void
	 */
	public static function markers( $page, $card ) {
		foreach ( array_keys( self::card_fields( $page, $card ) ) as $key ) {
			self::marker( $key );
		}
	}

	/**
	 * Print a secret-key input: never echoes the stored value. When one is
	 * saved, the field shows "•••• saved" and stays empty; leaving it empty
	 * keeps the key, typing replaces it, and the box removes it.
	 *
	 * @param string $name     Field name (the settings key).
	 * @param string $label    Visible label.
	 * @param bool   $is_saved Whether a value is stored.
	 * @return void
	 */
	public static function render_secret_input( $name, $label, $is_saved ) {
		$id          = 'ndvr-' . str_replace( '_', '-', sanitize_key( $name ) );
		$described   = $is_saved ? $id . '-help' : '';
		$placeholder = $is_saved ? __( '•••• saved', 'rosette-reviews' ) : '';
		?>
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
		<input type="password" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="" autocomplete="new-password" spellcheck="false" placeholder="<?php echo esc_attr( $placeholder ); ?>" aria-describedby="<?php echo esc_attr( $described ); ?>" />
		<?php if ( $is_saved ) : ?>
			<span class="description" id="<?php echo esc_attr( $id . '-help' ); ?>" style="display:block;"><?php esc_html_e( 'A key is saved. Leave this empty to keep it, or type a new one to replace it.', 'rosette-reviews' ); ?></span>
			<label style="display:flex;align-items:center;gap:6px;font-weight:400;margin-top:6px;">
				<input type="checkbox" name="<?php echo esc_attr( $name . '_remove' ); ?>" value="1" />
				<?php esc_html_e( 'Remove the saved key', 'rosette-reviews' ); ?>
			</label>
		<?php endif; ?>
		<?php
	}
}
