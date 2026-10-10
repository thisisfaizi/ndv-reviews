<?php
/**
 * Minimum review length (RR-12).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * A floor on the number of characters in a customer-written review.
 *
 * - Setting `min_review_length` (0 = off, up to 500) on the "Reviews and
 *   trust" card; filter `ndv-reviews/min_review_length` ( $min, $data ).
 * - Counted on the cleaned text: tags stripped, entities decoded, runs of
 *   whitespace collapsed, Unicode code points (the form scripts count alike).
 * - Enforced in `ReviewRepository::create()` for interactive sources only;
 *   imports are exempt. The forms show a hint, a counter and one quiet
 *   screen-reader announcement; the server decides.
 */
class ReviewLength implements Registerable {

	const SETTING = 'min_review_length';
	const MAX     = 500;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'ndv-reviews/settings_fields', array( $this, 'register_fields' ) );
	}

	/**
	 * The setting on the "Reviews and trust" card.
	 *
	 * @param array<string,array<string,mixed>> $fields Fields.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_fields( $fields ) {
		$fields                  = (array) $fields;
		$fields[ self::SETTING ] = array(
			'sanitize' => array( __CLASS__, 'sanitize' ),
			'default'  => 0,
			'page'     => 'settings',
			'card'     => 'trust',
			'render'   => array( $this, 'render_setting' ),
		);

		return $fields;
	}

	/**
	 * 0–500.
	 *
	 * @param mixed $raw Posted value or null.
	 * @return int
	 */
	public static function sanitize( $raw ) {
		return min( self::MAX, absint( is_scalar( $raw ) ? $raw : 0 ) );
	}

	/**
	 * The number field.
	 *
	 * @param mixed $value Current value.
	 * @return void
	 */
	public function render_setting( $value ) {
		?>
		<p class="ndvr-field">
			<label for="ndvr-min-review-length"><?php esc_html_e( 'Minimum review length (characters)', 'rosette-reviews' ); ?></label><br>
			<input type="number" id="ndvr-min-review-length" name="<?php echo esc_attr( self::SETTING ); ?>" min="0" max="<?php echo (int) self::MAX; ?>" class="small-text" value="<?php echo esc_attr( (string) self::sanitize( $value ) ); ?>" />
			<span class="description"><?php esc_html_e( '0 means no minimum. Applies to reviews customers write, not to imports.', 'rosette-reviews' ); ?></span>
		</p>
		<?php
	}

	/**
	 * The minimum for a submission (0 = none).
	 *
	 * @param array<string,mixed> $data Submission data (product_id, source …).
	 * @return int
	 */
	public static function min( array $data = array() ) {
		$min = (int) \NdvReviews\Plugin::instance()->container()->get( 'settings' )->get( self::SETTING, 0 );

		/**
		 * Filter the minimum review length for a submission (per product, …).
		 *
		 * @param int                 $min  Characters (0 = none).
		 * @param array<string,mixed> $data Submission data.
		 */
		return min( self::MAX, max( 0, (int) apply_filters( 'ndv-reviews/min_review_length', $min, $data ) ) );
	}

	/**
	 * Characters in review text, as the server counts them.
	 *
	 * @param string $html Review text (already through wp_kses_post).
	 * @return int
	 */
	public static function count( $html ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return (int) mb_strlen( $text, 'UTF-8' );
	}

	/**
	 * Long enough? Interactive sources only.
	 *
	 * @param string              $content Review text (kses'd).
	 * @param array<string,mixed> $data    Submission data (`source` …).
	 * @return true|\WP_Error
	 */
	public static function check( $content, array $data ) {
		$source = isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'onsite';
		if ( ! Sources::is_interactive( $source ) ) {
			return true;
		}
		$min = self::min( $data );
		if ( $min > 0 && self::count( $content ) < $min ) {
			return new \WP_Error(
				'ndvr_too_short',
				sprintf(
					/* translators: %s: minimum number of characters. */
					_n( 'Please write at least %s character.', 'Please write at least %s characters.', $min, 'rosette-reviews' ),
					number_format_i18n( $min )
				)
			);
		}

		return true;
	}

	/**
	 * Attributes for a review textarea ('' when there is no minimum).
	 *
	 * @param string $hint_id Id of the hint element.
	 * @param int    $min     Minimum.
	 * @return string Escaped attribute string, with a leading space.
	 */
	public static function textarea_attrs( $hint_id, $min ) {
		if ( $min <= 0 ) {
			return '';
		}

		return sprintf(
			' aria-describedby="%1$s" data-ndvr-min-length="%2$d" data-ndvr-count-format="%3$s" data-ndvr-status-reached="%4$s" data-ndvr-status-format="%5$s"',
			esc_attr( $hint_id ),
			(int) $min,
			/* translators: 1: characters typed, 2: minimum. Shown under the review box. */
			esc_attr( __( '%1$d / %2$d', 'rosette-reviews' ) ),
			esc_attr( __( 'Minimum length reached.', 'rosette-reviews' ) ),
			/* translators: 1: characters typed, 2: minimum. Read by screen readers. */
			esc_attr( __( '%1$d of %2$d characters.', 'rosette-reviews' ) )
		);
	}

	/**
	 * Hint, counter and status after a review textarea ('' when no minimum).
	 *
	 * @param string $hint_id Id of the hint element.
	 * @param int    $min     Minimum.
	 * @return string HTML.
	 */
	public static function after_textarea( $hint_id, $min ) {
		if ( $min <= 0 ) {
			return '';
		}

		return sprintf(
			'<span class="ndvr-length-hint" id="%1$s">%2$s</span><span class="ndvr-length-count" aria-hidden="true"></span><span class="ndvr-length-status screen-reader-text" role="status" aria-live="polite"></span>',
			esc_attr( $hint_id ),
			esc_html(
				sprintf(
					/* translators: %s: minimum number of characters. */
					_n( 'At least %s character.', 'At least %s characters.', $min, 'rosette-reviews' ),
					number_format_i18n( $min )
				)
			)
		);
	}
}
