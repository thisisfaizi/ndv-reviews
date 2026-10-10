<?php
/**
 * "How reviews work" transparency notice (RR-03).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Display;

use NdvReviews\Reviews\Pool;
use NdvReviews\Reviews\PostTypes;
use NdvReviews\Support\Caps;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Support\View;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the notice from facts the code can prove (EU Omnibus / UK DMCC /
 * FTC 16 CFR 465): each sentence is keyed and shown only while it is true.
 * Features that change how reviews are handled add or replace their own keyed
 * sentence through `ndv-reviews/transparency_sentences` (RR-00 F8.3).
 *
 * Off by default on every site; the merchant switches it on after reading it.
 */
class Transparency implements Registerable {

	/**
	 * Cached "a third-party import exists" result.
	 */
	const IMPORT_TRANSIENT = 'ndvr_tp_import';

	/**
	 * User meta that hides the admin notice.
	 */
	const DISMISS_META = 'ndvr_transparency_notice_dismissed';

	/**
	 * Nonce action and GET argument for the dismissal.
	 */
	const NONCE = 'ndvr_transparency_notice';

	/**
	 * Allowed HTML in the merchant's extra text.
	 */
	const EXTRA_KSES = array(
		'a'      => array( 'href' => array() ),
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
	);

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

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
		add_action( 'ndv-reviews/summary_footer', array( $this, 'on_summary_footer' ), 10, 2 );
		add_filter( 'ndv-reviews/settings_fields', array( $this, 'register_fields' ) );
		add_shortcode( 'ndvr-transparency', array( $this, 'shortcode' ) );

		add_action( 'ndv-reviews/third_party_import_done', array( __CLASS__, 'forget_import' ), 10, 0 );
		add_action( 'transition_comment_status', array( __CLASS__, 'forget_import' ), 10, 0 );
		add_action( 'deleted_comment', array( __CLASS__, 'forget_import' ), 10, 0 );
		// Importers that write the hash themselves (an add-on) clear it too.
		add_action( 'added_comment_meta', array( __CLASS__, 'on_meta_added' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss' ) );
	}

	// Facts and sentences.

	/**
	 * What the code can prove about how reviews are handled.
	 *
	 * @param int $product_id Product id (0 = store-wide, a policy page).
	 * @return array<string,mixed>
	 */
	public function facts( $product_id ) {
		$product_id = absint( $product_id );
		$type       = $product_id ? get_post_type( $product_id ) : '';
		$is_product = 0 === $product_id || 'product' === $type;
		// WooCommerce's "verified owners only" applies to products only, so it
		// is a fact for a product, or store-wide when only products take reviews.
		$products_only = 0 === $product_id ? array( 'product' ) === PostTypes::all() : 'product' === $type;
		$status        = (string) $this->settings->get( 'reminder_status', 'completed' );

		$reviews_open = (bool) $this->settings->get( 'enable_reviews', true )
			&& ( $product_id ? comments_open( $product_id ) : 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' ) );

		$variable = false;
		if ( $product_id && 'product' === $type && function_exists( 'wc_get_product' ) ) {
			$product  = wc_get_product( $product_id );
			$variable = $product && $product->is_type( 'variable' );
		}

		$facts = array(
			/** This filter is documented in includes/Requests/Scheduler.php */
			'reminders'             => (bool) $this->settings->get( 'reminder_enabled' ) && (bool) apply_filters( 'ndv-reviews/should_send_reminder', true, 0 ),
			'reminder_status_label' => function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $status ) : $status,
			'is_product_context'    => $is_product,
			'followup'              => false,
			'consent'               => 'off',
			'consent_legacy'        => 'skip',
			'verification_required' => $products_only && 'yes' === get_option( 'woocommerce_review_rating_verification_required', 'no' ),
			'reviews_open'          => $reviews_open,
			'submission_gated'      => false !== has_filter( 'ndv-reviews/validate_review' ),
			/** This filter is documented in templates/review-item.php */
			'verified_label'        => (string) apply_filters( 'ndv-reviews/verified_badge_text', __( 'Verified buyer', 'rosette-reviews' ), array() ),
			'auto_approve'          => false !== has_filter( 'ndv-reviews/should_approve' ),
			'third_party_import'    => $this->has_third_party_import(),
			'external'              => false,
			'variable'              => $variable,
			'pooled'                => $this->is_pooled( $product_id ),
			'product_id'            => $product_id,
		);

		/**
		 * Filter the facts the transparency sentences are chosen from.
		 *
		 * @param array<string,mixed> $facts      Facts.
		 * @param int                 $product_id Product id (0 = store-wide).
		 */
		$filtered = apply_filters( 'ndv-reviews/transparency_facts', $facts, $product_id );

		return is_array( $filtered ) ? array_merge( $facts, $filtered ) : $facts;
	}

	/**
	 * Whether a product's rating covers other products' reviews: it shares
	 * another product's reviews (an add-on's pool), or holds reviews written
	 * for other products. Store-wide (0) is never pooled: each product's
	 * rating is still the average of the reviews shown with it.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	private function is_pooled( $product_id ) {
		if ( ! $product_id ) {
			return false;
		}
		if ( Pool::resolve_id( $product_id ) !== $product_id ) {
			return true;
		}

		return (int) get_comments(
			array(
				'post_id'  => $product_id,
				'meta_key' => Pool::POOLED_FROM_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'status'   => 'approve',
				'count'    => true,
				'number'   => 1,
			)
		) > 0;
	}

	/**
	 * The keyed sentences, in display order.
	 *
	 * @param int $product_id Product id (0 = store-wide).
	 * @return array<string,string> key => whole translated sentence (plain text).
	 */
	public function sentences( $product_id ) {
		$f = $this->facts( $product_id );
		$s = array();

		$s['source'] = ! empty( $f['external'] )
			? __( 'Reviews come from customers and from our profiles on other sites.', 'rosette-reviews' )
			: __( 'Reviews come from customers.', 'rosette-reviews' );

		if ( ! empty( $f['reminders'] ) && ! empty( $f['is_product_context'] ) ) {
			$opt_in = 'optin' === $f['consent'] && 'skip' === $f['consent_legacy'];
			$label  = (string) $f['reminder_status_label'];
			if ( $opt_in && ! empty( $f['followup'] ) ) {
				/* translators: %s: order status name, such as "Completed". */
				$s['requests'] = sprintf( __( 'After an order reaches the "%s" status, we email customers who agreed to this at checkout to ask for a review, and send one reminder if they haven\'t reviewed yet.', 'rosette-reviews' ), $label );
			} elseif ( $opt_in ) {
				/* translators: %s: order status name, such as "Completed". */
				$s['requests'] = sprintf( __( 'After an order reaches the "%s" status, we email customers who agreed to this at checkout to ask for a review.', 'rosette-reviews' ), $label );
			} elseif ( ! empty( $f['followup'] ) ) {
				/* translators: %s: order status name, such as "Completed". */
				$s['requests'] = sprintf( __( 'After an order reaches the "%s" status, we email the customer to ask for a review, and send one reminder if they haven\'t reviewed yet.', 'rosette-reviews' ), $label );
			} else {
				/* translators: %s: order status name, such as "Completed". */
				$s['requests'] = sprintf( __( 'After an order reaches the "%s" status, we email the customer to ask for a review.', 'rosette-reviews' ), $label );
			}
		}

		$verified = trim( (string) $f['verified_label'] );
		if ( '' !== $verified ) {
			$s['verified'] = ! empty( $f['third_party_import'] )
				/* translators: %s: the verified badge text, such as "Verified buyer". */
				? sprintf( __( 'A "%s" label on a review left in our store means it is linked to a purchase here: it came through a review link we emailed after that order, or the reviewer\'s account or email address has a matching order. On imported reviews, the label comes from the service they were imported from or from our order records.', 'rosette-reviews' ), $verified )
				/* translators: %s: the verified badge text, such as "Verified buyer". */
				: sprintf( __( 'A "%s" label means the review is linked to a purchase in our store: it came through a review link we emailed after that order, or the reviewer\'s account or email address has a matching order.', 'rosette-reviews' ), $verified );
		}

		if ( ! empty( $f['verification_required'] ) ) {
			$s['access'] = __( 'Only customers who bought the product can leave a review.', 'rosette-reviews' );
		} elseif ( ! empty( $f['reviews_open'] ) && empty( $f['submission_gated'] ) ) {
			$s['access'] = __( 'Customers who haven\'t bought the product can also leave a review. Their reviews don\'t have the label.', 'rosette-reviews' );
		}

		$s['moderation'] = ! empty( $f['auto_approve'] )
			? __( 'We don\'t remove reviews because they are negative.', 'rosette-reviews' )
			: __( 'We check reviews left on this store before they appear. We don\'t remove reviews because they are negative.', 'rosette-reviews' );

		if ( ! empty( $f['third_party_import'] ) ) {
			$s['imported'] = __( 'Some reviews were imported from another review service. They keep the status they had there.', 'rosette-reviews' );
		}

		if ( ! empty( $f['pooled'] ) ) {
			$s['average'] = __( 'A star rating is the average of the published reviews shown with it. Some products share their reviews, for example the versions of one item.', 'rosette-reviews' );
		} elseif ( empty( $f['product_id'] ) ) {
			$s['average'] = __( 'Each product\'s star rating is the average of its published reviews.', 'rosette-reviews' );
		} elseif ( empty( $f['is_product_context'] ) ) {
			$s['average'] = __( 'The star rating is the average of all published reviews shown here.', 'rosette-reviews' );
		} elseif ( ! empty( $f['variable'] ) ) {
			$s['average'] = __( 'The star rating is the average of all published reviews of this product, across all its options.', 'rosette-reviews' );
		} else {
			$s['average'] = __( 'The star rating is the average of all published reviews of this product.', 'rosette-reviews' );
		}

		/**
		 * Filter the transparency sentences. Keyed: replacing a key keeps its
		 * position, an empty string removes it, and a new key renders after the
		 * free ones. Values are whole sentences in plain text.
		 *
		 * @param array<string,string> $sentences  Sentences.
		 * @param array<string,mixed>  $facts      Facts.
		 * @param int                  $product_id Product id (0 = store-wide).
		 */
		$filtered = apply_filters( 'ndv-reviews/transparency_sentences', $s, $f, absint( $product_id ) );
		$filtered = is_array( $filtered ) ? $filtered : $s;

		$out = array();
		foreach ( $filtered as $key => $sentence ) {
			$key = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) );
			if ( '' !== $key && is_string( $sentence ) && '' !== trim( $sentence ) ) {
				$out[ $key ] = $sentence;
			}
		}

		return $out;
	}

	/**
	 * The notice HTML.
	 *
	 * @param int    $product_id  Product id (0 = store-wide).
	 * @param string $surface     tab|summary|criteria|reviews|widget|page.
	 * @param bool   $collapsible Wrap in <details> (false on a policy page).
	 * @return string
	 */
	public function render( $product_id, $surface = 'summary', $collapsible = true ) {
		// A page that takes no reviews (a summary shortcode falling back to
		// the current page) gets the store-wide wording, never "this product".
		$product_id = absint( $product_id );
		if ( $product_id && ! PostTypes::is_reviewable( $product_id ) ) {
			$product_id = 0;
		}
		$sentences = $this->sentences( $product_id );
		if ( empty( $sentences ) ) {
			return '';
		}

		/**
		 * Filter a translatable setting's value (multilingual add-ons).
		 *
		 * @param string $value   Stored value.
		 * @param string $key     Setting key.
		 * @param string $context Context.
		 */
		$extra = (string) apply_filters( 'ndv-reviews/translate_setting', (string) $this->settings->get( 'transparency_extra', '' ), 'transparency_extra', '' );

		return View::render(
			'transparency.php',
			array(
				'sentences'   => array_values( $sentences ),
				'extra_html'  => wp_kses( $extra, self::EXTRA_KSES ),
				'collapsible' => (bool) $collapsible,
				'surface'     => (string) $surface,
			)
		);
	}

	/**
	 * Print the notice after a summary, when switched on for that surface.
	 *
	 * @param int    $product_id Product id.
	 * @param string $surface    Surface.
	 * @return void
	 */
	public function on_summary_footer( $product_id, $surface = 'summary' ) {
		if ( ! $this->settings->get( 'transparency_enabled' ) ) {
			return;
		}

		/**
		 * Filter the summary surfaces that show the notice.
		 *
		 * @param string[] $surfaces Default tab, summary, criteria, reviews (not the sidebar widget).
		 */
		$surfaces = (array) apply_filters( 'ndv-reviews/transparency_surfaces', array( 'tab', 'summary', 'criteria', 'reviews' ) );
		if ( ! in_array( (string) $surface, $surfaces, true ) ) {
			return;
		}

		echo $this->render( (int) $product_id, (string) $surface ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes each value.
	}

	/**
	 * `[ndvr-transparency product_id="0"]`: the sentences as plain paragraphs
	 * for a policy page. Shown whether or not the under-ratings note is on.
	 *
	 * @param array<string,mixed>|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'product_id' => 0 ), $atts, 'ndvr-transparency' );

		$widgets = \NdvReviews\Plugin::instance()->container()->get( 'widgets' );
		$widgets->enqueue( 'stars' );

		return $this->render( absint( $atts['product_id'] ), 'page', false );
	}

	/**
	 * Whether any third-party import exists (cached 12 h; cleared by imports
	 * and comment changes).
	 *
	 * @return bool
	 */
	public function has_third_party_import() {
		$cached = get_transient( self::IMPORT_TRANSIENT );
		if ( false !== $cached ) {
			return '1' === (string) $cached;
		}

		$count = (int) get_comments(
			array(
				'meta_key' => '_ndvr_import_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'status'   => 'approve',
				'type__in' => array( 'review', 'comment' ),
				'count'    => true,
				'number'   => 1,
			)
		);
		set_transient( self::IMPORT_TRANSIENT, $count > 0 ? '1' : '0', 12 * HOUR_IN_SECONDS );

		return $count > 0;
	}

	/**
	 * Forget the cached import result when an import hash is written.
	 *
	 * @param int    $meta_id    Meta id.
	 * @param int    $comment_id Comment id.
	 * @param string $meta_key   Meta key.
	 * @return void
	 */
	public static function on_meta_added( $meta_id, $comment_id, $meta_key ) {
		unset( $meta_id, $comment_id );
		if ( '_ndvr_import_hash' === $meta_key ) {
			self::forget_import();
		}
	}

	/**
	 * Forget the cached import result.
	 *
	 * @return void
	 */
	public static function forget_import() {
		delete_transient( self::IMPORT_TRANSIENT );
	}

	// Settings and admin notice.

	/**
	 * Register the two settings on the "Reviews and trust" card.
	 *
	 * @param array<string,array<string,mixed>> $fields Fields.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_fields( $fields ) {
		$fields = (array) $fields;

		$fields['transparency_enabled'] = array(
			'sanitize' => array( $this, 'sanitize_enabled' ),
			'default'  => false,
			'page'     => 'settings',
			'card'     => 'trust',
			'render'   => array( $this, 'render_settings' ),
		);
		$fields['transparency_extra']   = array(
			'sanitize' => array( __CLASS__, 'sanitize_extra' ),
			'default'  => '',
			'page'     => 'settings',
			'card'     => 'trust',
		);

		return $fields;
	}

	/**
	 * Checkbox: absent is off. Saving the card also hides the notice for the
	 * user who saved it (they've now seen the setting).
	 *
	 * @param mixed $raw Posted value or null.
	 * @return bool
	 */
	public function sanitize_enabled( $raw ) {
		if ( get_current_user_id() ) {
			update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		}

		return null !== $raw && '' !== $raw && '0' !== (string) $raw;
	}

	/**
	 * The merchant's extra text: links, bold, italic and line breaks only.
	 *
	 * @param mixed $raw Posted value or null.
	 * @return string
	 */
	public static function sanitize_extra( $raw ) {
		$text = is_string( $raw ) ? $raw : '';

		return wp_kses( mb_substr( $text, 0, 1000 ), self::EXTRA_KSES );
	}

	/**
	 * Render the "How reviews work" fieldset (both fields, with a preview).
	 *
	 * @param mixed $value Current transparency_enabled value.
	 * @return void
	 */
	public function render_settings( $value ) {
		$extra = (string) $this->settings->get( 'transparency_extra', '' );
		// transparency_extra has no render callback of its own (it is printed
		// here), so its "rendered on this form" marker is printed here too.
		\NdvReviews\Admin\SettingsFields::marker( 'transparency_extra' );
		?>
		<fieldset class="ndvr-field">
			<legend style="font-weight:700;"><?php esc_html_e( 'How reviews work', 'rosette-reviews' ); ?></legend>
			<label style="display:flex;align-items:center;gap:8px;font-weight:400;">
				<input type="checkbox" name="transparency_enabled" value="1" <?php checked( (bool) $value ); ?> />
				<?php esc_html_e( 'Show a "How reviews work" note under your ratings', 'rosette-reviews' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'One sentence says you don\'t remove reviews because they are negative. Only switch this on if that\'s how you moderate.', 'rosette-reviews' ); ?></p>
			<p class="description"><?php esc_html_e( 'Built from your settings, so it changes when they do. You are responsible for it being true: read it, and add anything specific to your store.', 'rosette-reviews' ); ?></p>
			<?php if ( ! get_option( 'comment_moderation' ) ) : ?>
				<p class="description"><strong><?php esc_html_e( 'Comment moderation is off (Settings → Discussion). New reviews now always wait for you, but reviews published before this update appeared without a check. Look through them before you switch the note on.', 'rosette-reviews' ); ?></strong></p>
			<?php endif; ?>
			<label for="ndvr-transparency-extra" style="display:block;margin-top:12px;"><?php esc_html_e( 'Anything else shoppers should know (optional)', 'rosette-reviews' ); ?></label>
			<textarea id="ndvr-transparency-extra" name="transparency_extra" rows="3" class="large-text" maxlength="1000"><?php echo esc_textarea( $extra ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Links, bold and italic are kept; everything else is removed.', 'rosette-reviews' ); ?></p>
			<p style="margin:12px 0 4px;font-weight:600;"><?php esc_html_e( 'Shoppers see:', 'rosette-reviews' ); ?></p>
			<div class="ndvr-transparency-preview" style="padding:10px 14px;border:1px solid var(--ndvr-line,#e8eaef);border-radius:8px;background:#fff;">
				<?php
				foreach ( $this->sentences( $this->preview_product() ) as $sentence ) {
					echo '<p style="margin:0 0 6px;">' . esc_html( $sentence ) . '</p>';
				}
				if ( '' !== $extra ) {
					echo '<div>' . wp_kses( $extra, self::EXTRA_KSES ) . '</div>';
				}
				?>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * A published simple product for the settings preview (0 if none).
	 *
	 * @return int
	 */
	private function preview_product() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}
		$ids = wc_get_products(
			array(
				'limit'  => 1,
				'status' => 'publish',
				'type'   => 'simple',
				'return' => 'ids',
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * One-time notice on our screens while the note is off.
	 *
	 * @return void
	 */
	public function maybe_notice() {
		if ( $this->settings->get( 'transparency_enabled' ) || ! current_user_can( Caps::manage() ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( 'plugins' !== $screen->id && false === strpos( (string) $screen->id, 'ndv-reviews' ) ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}

		$settings = admin_url( 'admin.php?page=ndv-reviews-settings' );
		$dismiss  = wp_nonce_url( add_query_arg( self::NONCE, 1 ), self::NONCE );
		printf(
			'<div class="notice notice-info"><p>%1$s</p><p><a href="%2$s">%3$s</a> · <a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'Rosette Reviews can show shoppers a short "How reviews work" note under your ratings. EU consumer law expects stores that show reviews to explain how they check them. Read the text it would show, then switch it on.', 'rosette-reviews' ),
			esc_url( $settings ),
			esc_html__( 'Open Settings', 'rosette-reviews' ),
			esc_url( $dismiss ),
			esc_html__( 'Dismiss', 'rosette-reviews' )
		);
	}

	/**
	 * Handle the notice's dismiss link (nonce, capability, then work).
	 *
	 * @return void
	 */
	public function maybe_dismiss() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( empty( $_GET[ self::NONCE ] ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Caps::manage() ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		wp_safe_redirect( remove_query_arg( array( self::NONCE, '_wpnonce' ) ) );
		exit;
	}
}
