<?php
/**
 * Consent for review emails at checkout (RR-07).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * An optional checkbox on classic checkout and the Checkout block, in
 * opt-in or opt-out mode, recorded on the order with the time and the
 * wording shown. `allows()` is the gate RR-09's eligibility check calls, so
 * every sender (automatic, manual, follow-up, Pro) respects it.
 *
 * Silence isn't consent: an unticked opt-out box is `not_objected`, never
 * `yes`, and opt-in sends only on an explicit `yes`. A recorded refusal (or
 * an erased answer) blocks in every mode, including off.
 *
 * The block checkout stores additional fields on the customer too (WC 10.8+
 * draft PATCH), which would show a returning customer the box ticked; that
 * copy is moved into the session for the open checkout and deleted.
 */
class Consent implements Registerable {

	const FIELD_ID       = 'ndv-reviews/review-email-consent';
	const WC_META        = '_wc_other/ndv-reviews/review-email-consent';
	const META           = '_ndvr_review_consent';
	const META_AT        = '_ndvr_review_consent_at';
	const META_TEXT      = '_ndvr_review_consent_text';
	const META_VIA       = '_ndvr_review_consent_via';
	const ENABLED_OPTION = 'ndv_reviews_consent_enabled_at';
	const SESSION_KEY    = 'ndvr_consent_draft';
	const MODES          = array( 'off', 'optin', 'optout' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Labels read this request (mode => text).
	 *
	 * @var array<string,string>
	 */
	private $labels = array();

	/**
	 * The label the block field was registered with this request.
	 *
	 * @var string
	 */
	private $block_label = '';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'ndv-reviews/settings_fields', array( $this, 'register_fields' ) );
		add_action( 'update_option_' . NDVR_OPTION_SETTINGS, array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'add_option_' . NDVR_OPTION_SETTINGS, array( $this, 'on_settings_added' ), 10, 2 );
		add_filter( 'ndv-reviews/transparency_facts', array( $this, 'transparency_facts' ) );

		// Classic checkout: outside the fragments checkout.js replaces.
		add_action( 'woocommerce_after_order_notes', array( $this, 'render_classic' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic' ), 10, 2 );

		// Checkout block (WooCommerce 8.9+).
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'save_block' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_draft', array( $this, 'on_update_draft' ) );
		add_filter( 'woocommerce_get_default_value_for_' . self::FIELD_ID, array( $this, 'default_value' ), 10, 3 );

		// Order screen: our line, not WooCommerce's editable copy of the field.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_admin_line' ) );
		add_filter( 'woocommerce_admin_shipping_fields', array( $this, 'hide_wc_admin_copy' ), 20 );

		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		// WooCommerce's own order eraser runs first and anonymises the billing
		// email: erase our answer on its way through each order.
		add_action( 'woocommerce_privacy_before_remove_order_personal_data', array( $this, 'erase_order' ) );

		// Never keep a customer-side copy of the answer: a returning customer
		// must never see the box ticked from an earlier order.
		add_filter( 'woocommerce_customer_allowed_session_meta_keys', array( $this, 'drop_session_key' ), 20 );
		add_filter( 'update_user_metadata', array( $this, 'block_user_meta' ), 10, 3 );
		add_filter( 'add_user_metadata', array( $this, 'block_user_meta' ), 10, 3 );

		// The text shown is exactly the text recorded: no "(optional)".
		add_filter( 'woocommerce_form_field_checkbox', array( $this, 'strip_optional' ), 10, 2 );
	}

	// ------------------------------------------------------------------
	// Rules
	// ------------------------------------------------------------------

	/**
	 * The configured mode.
	 *
	 * @return string off|optin|optout
	 */
	public function mode() {
		$mode = (string) $this->settings->get( 'consent_mode', 'off' );

		return in_array( $mode, self::MODES, true ) ? $mode : 'off';
	}

	/**
	 * The recorded answer, or why there is none.
	 *
	 * @param \WC_Order $order Order.
	 * @return string yes|no|not_objected|erased|none|legacy
	 */
	public function status( \WC_Order $order ) {
		$value = (string) $order->get_meta( self::META );
		if ( in_array( $value, array( 'yes', 'no', 'not_objected', 'erased' ), true ) ) {
			return $value;
		}
		$enabled = (int) get_option( self::ENABLED_OPTION, 0 );
		$created = $order->get_date_created();
		if ( $enabled && $created && $created->getTimestamp() < $enabled ) {
			return 'legacy';
		}

		return 'none';
	}

	/**
	 * Whether a review request may be sent for this order.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function allows( \WC_Order $order ) {
		$status = $this->status( $order );
		$mode   = $this->mode();

		if ( in_array( $status, array( 'no', 'erased' ), true ) ) {
			$allowed = false; // A refusal is never ignored, whatever the mode.
		} elseif ( 'off' === $mode || 'optout' === $mode ) {
			$allowed = true;
		} elseif ( 'yes' === $status ) {
			$allowed = true;
		} elseif ( 'legacy' === $status ) {
			$allowed = 'send' === (string) $this->settings->get( 'consent_legacy_orders', 'skip' );
		} else {
			$allowed = false; // Opt-in honours only an explicit yes (not_objected included).
		}

		/**
		 * Filter whether consent allows a review request for an order.
		 *
		 * @param bool      $allowed Decision.
		 * @param \WC_Order $order   Order.
		 */
		return (bool) apply_filters( 'ndv-reviews/review_consent_allows', $allowed, $order );
	}

	/**
	 * The checkbox text for a mode, read once per request through
	 * `ndv-reviews/translate_setting` (RR-08).
	 *
	 * @param string $mode optin|optout.
	 * @return string
	 */
	public function label( $mode ) {
		$mode = 'optout' === $mode ? 'optout' : 'optin';
		if ( isset( $this->labels[ $mode ] ) ) {
			return $this->labels[ $mode ];
		}
		$key   = 'consent_label_' . $mode;
		$value = trim( (string) $this->settings->get( $key, '' ) );

		/** This filter is documented in includes/Display/Transparency.php */
		$value = trim( (string) apply_filters( 'ndv-reviews/translate_setting', $value, $key, '' ) );
		if ( '' === $value ) {
			$value = 'optout' === $mode
				? __( 'Don\'t email me a link to review my purchase', 'rosette-reviews' )
				: __( 'Email me a link to review my purchase', 'rosette-reviews' );
		}
		$this->labels[ $mode ] = $value;

		return $value;
	}

	/**
	 * Whether this WooCommerce can register the Checkout block field (8.9+).
	 *
	 * @param string $wc_version WooCommerce version.
	 * @return bool
	 */
	public static function block_field_supported( $wc_version ) {
		$supported = version_compare( (string) $wc_version, '8.9', '>=' );

		/**
		 * Filter whether the Checkout block consent field can be registered.
		 *
		 * @param bool   $supported  Default: WooCommerce 8.9 or later.
		 * @param string $wc_version WooCommerce version.
		 */
		return (bool) apply_filters( 'ndv-reviews/consent_block_supported', $supported, (string) $wc_version );
	}

	/**
	 * Store an answer on the order (saved by the caller or WooCommerce).
	 *
	 * @param \WC_Order $order   Order.
	 * @param bool      $ticked  Whether the box was ticked.
	 * @param string    $label   The text shown.
	 * @param string    $via     classic|block.
	 * @return void
	 */
	private function record( \WC_Order $order, $ticked, $label, $via ) {
		$mode = $this->mode();
		if ( 'off' === $mode ) {
			return;
		}
		$value = 'optin' === $mode ? ( $ticked ? 'yes' : 'no' ) : ( $ticked ? 'no' : 'not_objected' );

		$order->update_meta_data( self::META, $value );
		$order->update_meta_data( self::META_AT, gmdate( 'Y-m-d H:i:s' ) );
		$order->update_meta_data( self::META_TEXT, function_exists( 'mb_substr' ) ? mb_substr( (string) $label, 0, 200 ) : substr( (string) $label, 0, 200 ) );
		$order->update_meta_data( self::META_VIA, 'block' === $via ? 'block' : 'classic' );
	}

	// ------------------------------------------------------------------
	// Classic checkout
	// ------------------------------------------------------------------

	/**
	 * The checkbox after the order notes.
	 *
	 * @param mixed $checkout Checkout (unused).
	 * @return void
	 */
	public function render_classic( $checkout = null ) {
		unset( $checkout );
		$mode = $this->mode();
		if ( 'off' === $mode || ! function_exists( 'woocommerce_form_field' ) ) {
			return;
		}
		woocommerce_form_field(
			'ndvr_review_consent',
			array(
				'type'     => 'checkbox',
				'class'    => array( 'form-row-wide', 'ndvr-review-consent' ),
				'label'    => esc_html( $this->label( $mode ) ),
				'required' => false,
			),
			0
		);
		echo '<input type="hidden" name="ndvr_review_consent_shown" value="1" />';
	}

	/**
	 * Record the classic answer (WC_Checkout::process_checkout() verified the
	 * checkout nonce before this runs).
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Posted data (unused).
	 * @return void
	 */
	public function save_classic( $order, $data = array() ) {
		unset( $data );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WC_Checkout::process_checkout().
		if ( ! $order instanceof \WC_Order || 'off' === $this->mode() || ! isset( $_POST['ndvr_review_consent_shown'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['ndvr_review_consent_shown'] ) ) ) {
			return; // The form didn't show the box (express flows): record nothing.
		}
		$ticked = isset( $_POST['ndvr_review_consent'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['ndvr_review_consent'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->record( $order, $ticked, $this->label( $this->mode() ), 'classic' );
	}

	// ------------------------------------------------------------------
	// Checkout block
	// ------------------------------------------------------------------

	/**
	 * Register the block field (not before woocommerce_init; WC 11 warns
	 * when fields are registered before after_setup_theme).
	 *
	 * @return void
	 */
	public function register_block_field() {
		$mode = $this->mode();
		if ( 'off' === $mode || ! defined( 'WC_VERSION' ) || ! self::block_field_supported( WC_VERSION ) || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		$this->block_label = $this->label( $mode );
		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => self::FIELD_ID,
					'label'                      => $this->block_label,
					'optionalLabel'              => $this->block_label,
					'location'                   => 'order',
					'type'                       => 'checkbox',
					'required'                   => false,
					'show_in_order_confirmation' => false,
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e ); // Registered twice or rejected by WooCommerce: the classic box still works.
		}
	}

	/**
	 * Record the block answer and drop the customer-side copy.
	 *
	 * @param \WC_Order        $order   Order.
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 */
	public function save_block( $order, $request ) {
		if ( ! $order instanceof \WC_Order || 'off' === $this->mode() ) {
			return;
		}
		// Only an answer the request itself carries is recorded. WooCommerce
		// also copies customer/session values onto the order (an express
		// payment that never showed the box would inherit a tick from an
		// abandoned checkout), so `_wc_other/…` on the order is never read.
		$fields = $request instanceof \WP_REST_Request ? $request->get_param( 'additional_fields' ) : null;
		$ticked = null;
		if ( is_array( $fields ) && array_key_exists( self::FIELD_ID, $fields ) ) {
			$ticked = self::truthy( $fields[ self::FIELD_ID ] );
		}
		if ( null !== $ticked ) {
			$label = '' !== $this->block_label ? $this->block_label : $this->label( $this->mode() );
			$this->record( $order, $ticked, $label, 'block' );
		}

		$this->forget_customer_copy();
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * A block PATCH with no order in flight (WC 10.8+): keep the customer's
	 * choice for this checkout in the session, delete the stored copy.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void
	 */
	public function on_update_draft( $request = null ) {
		unset( $request );
		if ( ! function_exists( 'WC' ) || ! WC()->customer || 'off' === $this->mode() ) {
			return;
		}
		$value = WC()->customer->get_meta( self::WC_META );
		if ( '' !== $value && null !== $value && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, self::truthy( $value ) );
		}
		$this->forget_customer_copy();
	}

	/**
	 * The box's value for the open checkout: the session's, never one stored
	 * on the customer from an earlier order.
	 *
	 * @param mixed  $value     Value so far.
	 * @param string $group     Group.
	 * @param mixed  $wc_object WC_Customer or WC_Order.
	 * @return mixed
	 */
	public function default_value( $value, $group = '', $wc_object = null ) {
		unset( $group );
		if ( $wc_object instanceof \WC_Customer && function_exists( 'WC' ) && WC()->session ) {
			$draft = WC()->session->get( self::SESSION_KEY, null );
			if ( null !== $draft ) {
				return $draft ? '1' : '0';
			}
		}

		return $value;
	}

	/**
	 * Delete WooCommerce's customer-side copy of our field.
	 *
	 * @return void
	 */
	private function forget_customer_copy() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return;
		}
		if ( '' !== (string) WC()->customer->get_meta( self::WC_META ) ) {
			WC()->customer->delete_meta_data( self::WC_META );
			WC()->customer->save();
		}
		// The user meta cache is primed when WC_Customer loads, so the check is free.
		if ( WC()->customer->get_id() && '' !== get_user_meta( WC()->customer->get_id(), self::WC_META, true ) ) {
			delete_user_meta( WC()->customer->get_id(), self::WC_META );
		}
	}

	/**
	 * WooCommerce persists additional fields in the customer session: ours
	 * is left out.
	 *
	 * @param string[] $keys Allowed session meta keys.
	 * @return string[]
	 */
	public function drop_session_key( $keys ) {
		return array_values( array_diff( (array) $keys, array( self::WC_META ) ) );
	}

	/**
	 * Refuse to store our field on a user (WooCommerce's customer copy).
	 *
	 * @param null|bool $check    Short-circuit.
	 * @param int       $user_id  User.
	 * @param string    $meta_key Key.
	 * @return null|bool
	 */
	public function block_user_meta( $check, $user_id, $meta_key ) {
		unset( $user_id );

		return self::WC_META === $meta_key ? true : $check;
	}

	/**
	 * Remove WooCommerce's "(optional)" from our classic checkbox, so the
	 * recorded text is exactly what the customer saw.
	 *
	 * @param string $field Field HTML.
	 * @param string $key   Field key.
	 * @return string
	 */
	public function strip_optional( $field, $key ) {
		if ( 'ndvr_review_consent' !== $key ) {
			return $field;
		}

		return (string) preg_replace( '#(&nbsp;)?<span class="optional">.*?</span>#s', '', (string) $field );
	}

	/**
	 * Boolean from a checkbox value.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function truthy( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value || 'yes' === $value;
	}

	// ------------------------------------------------------------------
	// Order screen
	// ------------------------------------------------------------------

	/**
	 * The "Review emails:" line under the billing address.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function render_admin_line( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$status = $this->status( $order );
		if ( 'none' === $status && 'off' === $this->mode() && ! get_option( self::ENABLED_OPTION ) ) {
			return; // The store never asked: nothing to say.
		}
		$at   = (string) $order->get_meta( self::META_AT );
		$date = '';
		if ( '' !== $at ) {
			$dt = new \WC_DateTime( $at, new \DateTimeZone( 'UTC' ) );
			$dt->setTimezone( new \DateTimeZone( wc_timezone_string() ) );
			$date = wc_format_datetime( $dt );
		}
		switch ( $status ) {
			case 'yes':
				/* translators: %s: date. */
				$text = '' !== $date ? sprintf( __( 'Agreed at checkout on %s', 'rosette-reviews' ), $date ) : __( 'Agreed at checkout', 'rosette-reviews' );
				break;
			case 'no':
				/* translators: %s: date. */
				$text = '' !== $date ? sprintf( __( 'Declined at checkout on %s', 'rosette-reviews' ), $date ) : __( 'Declined at checkout', 'rosette-reviews' );
				break;
			case 'not_objected':
				/* translators: %s: date. */
				$text = '' !== $date ? sprintf( __( 'Didn\'t opt out at checkout on %s', 'rosette-reviews' ), $date ) : __( 'Didn\'t opt out at checkout', 'rosette-reviews' );
				break;
			case 'erased':
				$text = __( 'Answer erased on request', 'rosette-reviews' );
				break;
			case 'legacy':
				$text = __( 'Order placed before consent was turned on', 'rosette-reviews' );
				break;
			default:
				$text = __( 'No answer recorded', 'rosette-reviews' );
		}
		printf(
			'<p class="ndvr-review-consent-line"><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Review emails:', 'rosette-reviews' ),
			esc_html( $text )
		);
	}

	/**
	 * Keep WooCommerce's editable copy of our field off the order screen
	 * (`show_in_order_confirmation` covers only the thank-you page and emails).
	 *
	 * @param array<string,mixed> $fields Fields.
	 * @return array<string,mixed>
	 */
	public function hide_wc_admin_copy( $fields ) {
		if ( is_array( $fields ) ) {
			unset( $fields[ self::FIELD_ID ] );
		}

		return $fields;
	}

	// ------------------------------------------------------------------
	// Settings, transparency
	// ------------------------------------------------------------------

	/**
	 * When consent goes from off to on, remember when (orders before that are
	 * "placed before consent was turned on").
	 *
	 * @param mixed $old_value Old settings.
	 * @param mixed $new_value New settings.
	 * @return void
	 */
	public function on_settings_updated( $old_value, $new_value ) {
		$was = is_array( $old_value ) && isset( $old_value['consent_mode'] ) ? (string) $old_value['consent_mode'] : 'off';
		$now = is_array( $new_value ) && isset( $new_value['consent_mode'] ) ? (string) $new_value['consent_mode'] : 'off';
		if ( ( 'off' === $was || ! in_array( $was, self::MODES, true ) ) && in_array( $now, array( 'optin', 'optout' ), true ) ) {
			update_option( self::ENABLED_OPTION, time(), false );
		}
	}

	/**
	 * The same for a first save.
	 *
	 * @param string $option Option.
	 * @param mixed  $value  Value.
	 * @return void
	 */
	public function on_settings_added( $option, $value ) {
		unset( $option );
		$this->on_settings_updated( array(), $value );
	}

	/**
	 * Transparency facts `consent` and `consent_legacy` (RR-03).
	 *
	 * @param array<string,mixed> $facts Facts.
	 * @return array<string,mixed>
	 */
	public function transparency_facts( $facts ) {
		$facts                   = is_array( $facts ) ? $facts : array();
		$facts['consent']        = $this->mode();
		$facts['consent_legacy'] = 'send' === (string) $this->settings->get( 'consent_legacy_orders', 'skip' ) ? 'send' : 'skip';

		return $facts;
	}

	/**
	 * The four keys (Reminders page, card `consent`).
	 *
	 * @param array<string,array> $fields Fields.
	 * @return array<string,array>
	 */
	public function register_fields( $fields ) {
		$fields = (array) $fields;
		$label  = static function ( $raw ) {
			$text = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';

			return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
		};

		$fields['consent_mode']          = array(
			'sanitize' => static function ( $raw ) {
				return is_string( $raw ) && in_array( $raw, self::MODES, true ) ? $raw : 'off';
			},
			'default'  => 'off',
			'page'     => 'reminders',
			'card'     => 'consent',
			'render'   => array( $this, 'render_mode' ),
		);
		$fields['consent_label_optin']   = array(
			'sanitize' => $label,
			'default'  => '',
			'page'     => 'reminders',
			'card'     => 'consent',
			'render'   => array( $this, 'render_label_optin' ),
		);
		$fields['consent_label_optout']  = array(
			'sanitize' => $label,
			'default'  => '',
			'page'     => 'reminders',
			'card'     => 'consent',
			'render'   => array( $this, 'render_label_optout' ),
		);
		$fields['consent_legacy_orders'] = array(
			'sanitize' => static function ( $raw ) {
				return 'send' === $raw ? 'send' : 'skip';
			},
			'default'  => 'skip',
			'page'     => 'reminders',
			'card'     => 'consent',
			'render'   => array( $this, 'render_legacy' ),
		);

		return $fields;
	}

	/**
	 * Whether the checkout page uses the Checkout block.
	 *
	 * @return bool
	 */
	private function checkout_uses_block() {
		$page = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;

		return $page > 0 && has_block( 'woocommerce/checkout', $page );
	}

	/**
	 * Heading + mode row (and the old-WooCommerce warning).
	 *
	 * @param mixed $value Value.
	 * @return void
	 */
	public function render_mode( $value ) {
		$value = in_array( (string) $value, self::MODES, true ) ? (string) $value : 'off';
		?>
		<tr>
			<td colspan="2" style="padding-left:0;padding-bottom:0;"><h3 style="margin:8px 0 0;"><?php esc_html_e( 'Consent', 'rosette-reviews' ); ?></h3>
				<p class="description"><?php esc_html_e( 'The answer is saved on each order with the time and the wording shown. Orders created without the checkout form, such as orders you add in the admin, have no answer: in opt-in mode they get no request.', 'rosette-reviews' ); ?></p>
				<?php if ( 'off' !== $value && defined( 'WC_VERSION' ) && ! self::block_field_supported( WC_VERSION ) && $this->checkout_uses_block() ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Your checkout uses the Checkout block, which needs WooCommerce 8.9 or later to show the consent checkbox. Until you update, block checkout orders have no answer recorded.', 'rosette-reviews' ); ?></p></div>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="consent_mode"><?php esc_html_e( 'Ask for consent at checkout', 'rosette-reviews' ); ?></label></th>
			<td>
				<select name="consent_mode" id="consent_mode">
					<option value="off" <?php selected( $value, 'off' ); ?>><?php esc_html_e( 'Off: don\'t show a checkbox', 'rosette-reviews' ); ?></option>
					<option value="optin" <?php selected( $value, 'optin' ); ?>><?php esc_html_e( 'Opt-in: send only if the customer ticks the box', 'rosette-reviews' ); ?></option>
					<option value="optout" <?php selected( $value, 'optout' ); ?>><?php esc_html_e( 'Opt-out: send unless the customer ticks the box', 'rosette-reviews' ); ?></option>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * Opt-in label row.
	 *
	 * @param mixed $value Value.
	 * @return void
	 */
	public function render_label_optin( $value ) {
		?>
		<tr>
			<th scope="row"><label for="consent_label_optin"><?php esc_html_e( 'Opt-in checkbox text', 'rosette-reviews' ); ?></label></th>
			<td><input type="text" maxlength="200" class="large-text" name="consent_label_optin" id="consent_label_optin" value="<?php echo esc_attr( (string) $value ); ?>" placeholder="<?php esc_attr_e( 'Email me a link to review my purchase', 'rosette-reviews' ); ?>" />
				<p class="description"><?php esc_html_e( 'Used in opt-in mode.', 'rosette-reviews' ); ?></p></td>
		</tr>
		<?php
	}

	/**
	 * Opt-out label row.
	 *
	 * @param mixed $value Value.
	 * @return void
	 */
	public function render_label_optout( $value ) {
		?>
		<tr>
			<th scope="row"><label for="consent_label_optout"><?php esc_html_e( 'Opt-out checkbox text', 'rosette-reviews' ); ?></label></th>
			<td><input type="text" maxlength="200" class="large-text" name="consent_label_optout" id="consent_label_optout" value="<?php echo esc_attr( (string) $value ); ?>" placeholder="<?php esc_attr_e( 'Don\'t email me a link to review my purchase', 'rosette-reviews' ); ?>" />
				<p class="description"><?php esc_html_e( 'Used in opt-out mode.', 'rosette-reviews' ); ?></p></td>
		</tr>
		<?php
	}

	/**
	 * Legacy orders row.
	 *
	 * @param mixed $value Value.
	 * @return void
	 */
	public function render_legacy( $value ) {
		$value = 'send' === $value ? 'send' : 'skip';
		?>
		<tr>
			<th scope="row"><label for="consent_legacy_orders"><?php esc_html_e( 'Orders placed before you turned this on', 'rosette-reviews' ); ?></label></th>
			<td>
				<select name="consent_legacy_orders" id="consent_legacy_orders">
					<option value="skip" <?php selected( $value, 'skip' ); ?>><?php esc_html_e( 'Don\'t send', 'rosette-reviews' ); ?></option>
					<option value="send" <?php selected( $value, 'send' ); ?>><?php esc_html_e( 'Send', 'rosette-reviews' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Used in opt-in mode.', 'rosette-reviews' ); ?></p>
			</td>
		</tr>
		<?php
	}

	// ------------------------------------------------------------------
	// Privacy
	// ------------------------------------------------------------------

	/**
	 * Register the exporter.
	 *
	 * @param array<string,array> $exporters Exporters.
	 * @return array<string,array>
	 */
	public function register_exporter( $exporters ) {
		$exporters['ndv-reviews-consent'] = array(
			'exporter_friendly_name' => __( 'Rosette Reviews: review email consent', 'rosette-reviews' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string,array> $erasers Erasers.
	 * @return array<string,array>
	 */
	public function register_eraser( $erasers ) {
		$erasers['ndv-reviews-consent'] = array(
			'eraser_friendly_name' => __( 'Rosette Reviews: review email consent', 'rosette-reviews' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Orders for an email, one page.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return \WC_Order[]
	 */
	private function orders_for( $email, $page ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! is_email( $email ) ) {
			return array();
		}

		$args   = array(
			'limit' => 50,
			'page'  => max( 1, (int) $page ),
			'type'  => 'shop_order',
		);
		$orders = array();
		foreach ( (array) wc_get_orders( $args + array( 'billing_email' => $email ) ) as $o ) {
			$orders[ $o->get_id() ] = $o;
		}
		// Orders on the account with that email, whatever billing email they used.
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			foreach ( (array) wc_get_orders( $args + array( 'customer_id' => (int) $user->ID ) ) as $o ) {
				$orders[ $o->get_id() ] = $o;
			}
		}

		return array_filter(
			$orders,
			static function ( $o ) {
				return $o instanceof \WC_Order;
			}
		);
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{data:array,done:bool}
	 */
	public function export( $email, $page = 1 ) {
		$orders = $this->orders_for( $email, $page );
		$labels = array(
			'yes'          => __( 'Agreed', 'rosette-reviews' ),
			'no'           => __( 'Declined', 'rosette-reviews' ),
			'not_objected' => __( 'Did not object', 'rosette-reviews' ),
			'erased'       => __( 'Erased', 'rosette-reviews' ),
		);
		$data   = array();
		foreach ( $orders as $order ) {
			$value = (string) $order->get_meta( self::META );
			if ( ! isset( $labels[ $value ] ) ) {
				continue;
			}
			$data[] = array(
				'group_id'    => 'ndvr_review_consent',
				'group_label' => __( 'Review email consent', 'rosette-reviews' ),
				'item_id'     => 'ndvr-consent-' . $order->get_id(),
				'data'        => array(
					array(
						'name'  => __( 'Order number', 'rosette-reviews' ),
						'value' => (string) $order->get_order_number(),
					),
					array(
						'name'  => __( 'Answer', 'rosette-reviews' ),
						'value' => $labels[ $value ],
					),
					array(
						'name'  => __( 'Date', 'rosette-reviews' ),
						'value' => '' === (string) $order->get_meta( self::META_AT ) ? '' : $order->get_meta( self::META_AT ) . ' UTC',
					),
					array(
						'name'  => __( 'Checkbox text', 'rosette-reviews' ),
						'value' => (string) $order->get_meta( self::META_TEXT ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $orders ) < 50,
		);
	}

	/**
	 * Erase the answer on one order (also from WooCommerce's own order eraser,
	 * which anonymises the billing email before our eraser could find it).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether something was erased.
	 */
	public function erase_order( $order ) {
		if ( ! $order instanceof \WC_Order || ( '' === (string) $order->get_meta( self::META ) && '' === (string) $order->get_meta( self::WC_META ) ) ) {
			return false;
		}
		if ( 'erased' === $order->get_meta( self::META ) && '' === (string) $order->get_meta( self::META_AT ) && '' === (string) $order->get_meta( self::WC_META ) ) {
			return false;
		}
		$order->update_meta_data( self::META, 'erased' );
		foreach ( array( self::META_AT, self::META_TEXT, self::META_VIA, self::WC_META ) as $key ) {
			$order->delete_meta_data( $key );
		}
		$order->save();

		return true;
	}

	/**
	 * Eraser callback: `erased` blocks sending in every mode.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public function erase( $email, $page = 1 ) {
		$orders  = $this->orders_for( $email, $page );
		$removed = false;
		foreach ( $orders as $order ) {
			$removed = $this->erase_order( $order ) || $removed;
		}
		if ( 1 === (int) $page ) {
			$user = get_user_by( 'email', $email );
			if ( $user && '' !== (string) get_user_meta( $user->ID, self::WC_META, true ) ) {
				delete_user_meta( $user->ID, self::WC_META );
				$removed = true;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $orders ) < 50,
		);
	}
}
