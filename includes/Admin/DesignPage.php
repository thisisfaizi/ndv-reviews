<?php
/**
 * Admin screen: storefront design.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Caps;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Display\Design;
use NdvReviews\Display\Html;
use NdvReviews\Support\View;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the merchant choose how reviews look — accent color, layout, summary
 * style, card style, rating icon and typography — with a live preview of the
 * unsaved choices. Free, by design (competitors gate this behind Pro).
 */
class DesignPage implements Registerable {

	const PAGE_SLUG  = 'ndv-reviews-design';
	const NONCE      = 'ndvr_design';

	/**
	 * Design keys and their allowed values (first = default).
	 *
	 * @var array<string,string[]>
	 */
	private static $choices = array(
		'design_template' => array( 'list', 'grid' ),
		'design_summary'  => array( 'panel', 'compact' ),
		'design_card'     => array( 'soft', 'bordered', 'flat' ),
		'design_rating'   => array( 'stars', 'hearts', 'thumbs', 'emoji' ),
		'design_font'     => array( 'system', 'serif', 'rounded', 'mono' ),
		'design_scale'    => array( 'normal', 'compact', 'large' ),
	);

	const DEFAULT_ACCENT = '#181a1f';

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
		add_action( 'admin_menu', array( $this, 'register_menu' ), 10 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Add the Design submenu (just under the top-level item).
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'ndv-reviews',
			__( 'Design', 'rosette-reviews' ),
			__( 'Design', 'rosette-reviews' ),
			Caps::manage( 'design' ),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Persist the design choices (or reset them), then redirect so a refresh
	 * never re-submits the form (post/redirect/get).
	 *
	 * @return void
	 */
	public function handle_save() {
		$save  = isset( $_POST['ndvr_design_save'] );
		$reset = isset( $_POST['ndvr_design_reset'] );
		if ( ! ( $save || $reset ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Caps::manage( 'design' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to change the design.', 'rosette-reviews' ), 403 );
		}

		$values = array(
			'design_accent'       => self::DEFAULT_ACCENT,
			'design_rating_color' => '',
			'design_bar_color'    => '',
		);
		foreach ( self::$choices as $key => $allowed ) {
			$values[ $key ] = $allowed[0];
		}

		if ( $save ) {
			foreach ( self::$choices as $key => $allowed ) {
				$value          = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
				$values[ $key ] = in_array( $value, $allowed, true ) ? $value : $allowed[0];
			}
			$accent = isset( $_POST['design_accent'] ) ? Design::sanitize_color( sanitize_text_field( wp_unslash( $_POST['design_accent'] ) ) ) : '';
			if ( '' !== $accent ) {
				$values['design_accent'] = $accent;
			}
			// A colour is stored only when its "Use a custom colour" box is ticked; '' keeps the built-in one.
			foreach ( array_keys( self::colors() ) as $key ) {
				if ( ! empty( $_POST[ $key . '_custom' ] ) && isset( $_POST[ $key ] ) ) {
					$values[ $key ] = Design::sanitize_color( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
				}
			}
		}

		$this->settings->update( $values );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => $reset ? 'reset' : 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Current value of a design key, falling back to its default.
	 *
	 * @param string $key Design key.
	 * @return string
	 */
	private function value( $key ) {
		$value = (string) $this->settings->get( $key );

		return in_array( $value, self::$choices[ $key ], true ) ? $value : self::$choices[ $key ][0];
	}

	/**
	 * Optional storefront colours: setting key => [label, help, built-in colour shown in the picker].
	 *
	 * @return array<string,string[]>
	 */
	private static function colors() {
		return array(
			'design_rating_color' => array(
				__( 'Rating icon color', 'rosette-reviews' ),
				__( 'Stars and hearts in reviews, the summary, the review form and widgets. Thumbs and emoji keep their own colors.', 'rosette-reviews' ),
				'#d97706',
			),
			'design_bar_color'    => array(
				__( 'Rating bar color', 'rosette-reviews' ),
				__( 'The star distribution bars and the rating breakdown bars (Quality, Value…). By default the distribution bars match the stars and the breakdown bars are green.', 'rosette-reviews' ),
				'#0f7d5b',
			),
		);
	}

	/**
	 * Render one optional colour: a "custom" checkbox, the picker and a
	 * contrast warning for colours too light to see on white.
	 *
	 * @param string   $key   Setting key.
	 * @param string[] $field Label, help text, built-in colour.
	 * @return void
	 */
	private function color_field( $key, array $field ) {
		$saved  = Design::sanitize_color( (string) $this->settings->get( $key ) );
		$custom = '' !== $saved;
		$value  = $custom ? $saved : $field[2];
		$ratio  = Design::contrast_on_white( $value );
		$id     = 'ndvr-' . str_replace( '_', '-', $key );
		?>
		<div class="ndvr-color-field" data-ndvr-color="<?php echo esc_attr( $key ); ?>">
			<p class="ndvr-color-label"><strong><?php echo esc_html( $field[0] ); ?></strong></p>
			<p class="ndvr-accent-help" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $field[1] ); ?></p>
			<div class="ndvr-color-row">
				<label class="ndvr-color-toggle">
					<input type="checkbox" name="<?php echo esc_attr( $key ); ?>_custom" value="1" <?php checked( $custom ); ?> />
					<?php esc_html_e( 'Use a custom color', 'rosette-reviews' ); ?>
				</label>
				<input type="color" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" data-default="<?php echo esc_attr( $field[2] ); ?>" aria-label="<?php echo esc_attr( $field[0] ); ?>" aria-describedby="<?php echo esc_attr( $id ); ?>-help <?php echo esc_attr( $id ); ?>-warn" />
			</div>
			<p class="ndvr-contrast-warning" id="<?php echo esc_attr( $id ); ?>-warn" role="status" <?php echo ( $custom && $ratio < 3 ) ? '' : 'hidden'; ?>>
				<?php
				printf(
					/* translators: %s: contrast ratio such as 2.1 */
					esc_html__( 'This color is hard to see on a white background (%s:1). Ratings need at least 3:1, so pick a darker shade.', 'rosette-reviews' ),
					'<span class="ndvr-contrast-ratio">' . esc_html( number_format_i18n( $ratio, 1 ) ) . '</span>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render an option card (label-wrapped radio).
	 *
	 * @param string $group   Field name.
	 * @param string $value   Option value.
	 * @param string $label   Caption.
	 * @param string $preview Pre-escaped preview markup.
	 * @return void
	 */
	private function option( $group, $value, $label, $preview ) {
		printf(
			'<label class="ndvr-opt"><input type="radio" name="%1$s" value="%2$s" %3$s /><span class="ndvr-opt-card"><span class="ndvr-opt-preview" aria-hidden="true">%4$s</span><span class="ndvr-opt-label">%5$s</span></span></label>',
			esc_attr( $group ),
			esc_attr( $value ),
			checked( $this->value( $group ), $value, false ),
			$preview, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- controlled inline SVG/markup.
			esc_html( $label )
		);
	}

	/**
	 * Static sample markup for the preview (mirrors summary.php and
	 * review-item.php classes; no hooks fire, so add-ons never see fake data).
	 *
	 * @return string
	 */
	private function preview_markup() {
		$summary = View::render(
			'summary.php',
			array(
				'summary' => array(
					'average'      => 4.6,
					'count'        => 128,
					'distribution' => array(
						5 => 92,
						4 => 24,
						3 => 7,
						2 => 3,
						1 => 2,
					),
					'recommend'    => 94,
					'verified'     => 117,
					'criteria'     => array(
						array(
							'name'    => __( 'Quality', 'rosette-reviews' ),
							'average' => 4.8,
						),
						array(
							'name'    => __( 'Value', 'rosette-reviews' ),
							'average' => 4.3,
						),
					),
				),
				'form_id' => 'ndvr-preview-form',
			)
		);

		$cards = array(
			array(
				'name'  => __( 'Maya R.', 'rosette-reviews' ),
				'stars' => 5,
				'title' => __( 'Exactly as described', 'rosette-reviews' ),
				'body'  => __( 'The fabric is heavier than I expected and the stitching is neat. It washed well and kept its shape.', 'rosette-reviews' ),
				'rec'   => true,
			),
			array(
				'name'  => __( 'Daniel K.', 'rosette-reviews' ),
				'stars' => 4,
				'title' => __( 'Good, runs slightly small', 'rosette-reviews' ),
				'body'  => __( 'Quality is good for the price. I would order one size up next time.', 'rosette-reviews' ),
				'rec'   => false,
			),
		);

		ob_start();
		echo $summary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output is escaped at source.
		echo '<ol class="ndvr-review-list">';
		foreach ( $cards as $card ) {
			?>
			<li class="ndvr-review">
				<div class="ndvr-review-head">
					<?php echo Html::avatar( $card['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="ndvr-review-byline">
						<div class="ndvr-review-author">
							<span class="ndvr-review-name"><?php echo esc_html( $card['name'] ); ?></span>
							<span class="ndvr-verified-badge"><?php esc_html_e( 'Verified buyer', 'rosette-reviews' ); ?></span>
						</div>
						<div class="ndvr-review-meta">
							<?php echo Html::stars( $card['stars'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="ndvr-review-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ) ) ); ?></span>
						</div>
					</div>
				</div>
				<h4 class="ndvr-review-title"><?php echo esc_html( $card['title'] ); ?></h4>
				<div class="ndvr-review-body"><p><?php echo esc_html( $card['body'] ); ?></p></div>
				<div class="ndvr-review-foot">
					<?php if ( $card['rec'] ) : ?>
						<span class="ndvr-recommend ndvr-recommend-yes"><?php esc_html_e( 'Recommends this product', 'rosette-reviews' ); ?></span>
					<?php endif; ?>
					<span class="ndvr-helpful"><?php esc_html_e( 'Helpful', 'rosette-reviews' ); ?> <span class="ndvr-helpful-count">(3)</span></span>
				</div>
			</li>
			<?php
		}
		echo '</ol>';

		return (string) ob_get_clean();
	}

	/**
	 * Full HTML document for the preview iframe. An iframe keeps wp-admin's
	 * own styles (and the admin skin's token values) out of the preview, so
	 * it shows the storefront styles exactly.
	 *
	 * @return string
	 */
	private function preview_document() {
		// The preview iframe is a standalone document, so the storefront styles are
		// inlined from the plugin's own files rather than enqueued.
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$css    = '';
		foreach ( array( 'tokens', 'display' ) as $name ) {
			$file = NDVR_DIR . 'assets/css/' . $name . $suffix . '.css';
			if ( ! is_readable( $file ) ) {
				$file = NDVR_DIR . 'assets/css/' . $name . '.css';
			}
			$css .= is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		}
		$links = '<style>' . wp_strip_all_tags( $css ) . '</style>';

		$classes = 'ndvr-reviews-wrap ndvr-preview-root ' . Design::classes( $this->settings );

		return '<!doctype html><html><head><meta charset="utf-8">' . $links .
			'<style id="ndvr-preview-vars">' . Design::inline_css( $this->settings ) . '</style>' .
			'<style>body{margin:0;padding:20px;background:#fff}.ndvr-write-review{pointer-events:none}</style>' .
			'</head><body class="' . esc_attr( Design::rating_class( $this->settings ) ) . '"><div class="' . esc_attr( trim( $classes ) ) . '">' .
			$this->preview_markup() .
			'</div></body></html>';
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Caps::manage( 'design' ) ) ) {
			return;
		}

		$accent = Design::sanitize_color( (string) $this->settings->get( 'design_accent' ) );
		$accent = '' !== $accent ? $accent : self::DEFAULT_ACCENT;

		$presets = array(
			self::DEFAULT_ACCENT => __( 'Ink', 'rosette-reviews' ),
			'#d6334f'            => __( 'Rosette', 'rosette-reviews' ),
			'#0f7d5b'            => __( 'Green', 'rosette-reviews' ),
			'#2563eb'            => __( 'Blue', 'rosette-reviews' ),
			'#7c3aed'            => __( 'Violet', 'rosette-reviews' ),
			'#be185d'            => __( 'Pink', 'rosette-reviews' ),
			'#c2410c'            => __( 'Orange', 'rosette-reviews' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after redirect.
		$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
		?>
		<div class="wrap ndvr-design">
			<h1><?php esc_html_e( 'Design', 'rosette-reviews' ); ?></h1>
			<?php if ( 'saved' === $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Design saved.', 'rosette-reviews' ); ?></p></div>
			<?php elseif ( 'reset' === $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Design reset to the defaults.', 'rosette-reviews' ); ?></p></div>
			<?php endif; ?>
			<p class="ndvr-design-intro"><?php esc_html_e( 'Choose how reviews look on your storefront: the product reviews tab, shortcodes, blocks, widgets and Elementor widgets. The preview updates as you change options; nothing is applied until you save.', 'rosette-reviews' ); ?></p>

			<div class="ndvr-design-layout">
				<form method="post" class="ndvr-design-form" id="ndvr-design-form">
					<?php wp_nonce_field( self::NONCE ); ?>

					<fieldset class="ndvr-design-section">
						<legend><?php esc_html_e( 'Accent color', 'rosette-reviews' ); ?></legend>
						<p class="ndvr-accent-help" id="ndvr-accent-help"><?php esc_html_e( 'Used for buttons, active filters and the current page number. Text on the accent switches between dark and white automatically for contrast.', 'rosette-reviews' ); ?></p>
						<div class="ndvr-accent-row">
							<label class="ndvr-accent-field" for="ndvr-accent">
								<span><?php esc_html_e( 'Custom color', 'rosette-reviews' ); ?></span>
								<input type="color" id="ndvr-accent" name="design_accent" value="<?php echo esc_attr( $accent ); ?>" aria-describedby="ndvr-accent-help" />
							</label>
							<div class="ndvr-swatches" role="group" aria-label="<?php esc_attr_e( 'Preset colors', 'rosette-reviews' ); ?>">
								<?php foreach ( $presets as $preset => $name ) : ?>
									<button type="button" class="ndvr-swatch" data-color="<?php echo esc_attr( $preset ); ?>" aria-pressed="<?php echo strtolower( $preset ) === strtolower( $accent ) ? 'true' : 'false'; ?>">
										<span class="ndvr-swatch-dot" style="background:<?php echo esc_attr( $preset ); ?>" aria-hidden="true"></span>
										<span class="ndvr-swatch-name"><?php echo esc_html( $name ); ?></span>
									</button>
								<?php endforeach; ?>
							</div>
						</div>
					</fieldset>

					<fieldset class="ndvr-design-section">
						<legend><?php esc_html_e( 'Review layout', 'rosette-reviews' ); ?></legend>
						<div class="ndvr-opts">
							<?php
							$this->option( 'design_template', 'list', __( 'List', 'rosette-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><rect x="8" y="10" width="74" height="14" rx="3" fill="#dfe3e9"/><rect x="8" y="30" width="74" height="14" rx="3" fill="#dfe3e9"/></svg>' );
							$this->option( 'design_template', 'grid', __( 'Grid', 'rosette-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><rect x="8" y="10" width="34" height="34" rx="4" fill="#dfe3e9"/><rect x="48" y="10" width="34" height="34" rx="4" fill="#dfe3e9"/></svg>' );
							?>
						</div>
					</fieldset>

					<fieldset class="ndvr-design-section">
						<legend><?php esc_html_e( 'Summary style', 'rosette-reviews' ); ?></legend>
						<div class="ndvr-opts">
							<?php
							$this->option( 'design_summary', 'panel', __( 'Full panel', 'rosette-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><text x="8" y="34" font-size="22" font-weight="800" fill="#181a1f">4.5</text><rect x="44" y="14" width="38" height="5" rx="2" fill="#d97706"/><rect x="44" y="24" width="30" height="5" rx="2" fill="#d97706"/><rect x="44" y="34" width="22" height="5" rx="2" fill="#dfe3e9"/></svg>' );
							$this->option( 'design_summary', 'compact', __( 'Compact', 'rosette-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><text x="10" y="32" font-size="16" font-weight="800" fill="#181a1f">4.5</text><text x="34" y="31" font-size="13" fill="#d97706">&#9733;&#9733;&#9733;&#9733;</text></svg>' );
							?>
						</div>
					</fieldset>

					<fieldset class="ndvr-design-section">
						<legend><?php esc_html_e( 'Card style', 'rosette-reviews' ); ?></legend>
						<div class="ndvr-opts">
							<?php
							$this->option( 'design_card', 'soft', __( 'Soft shadow', 'rosette-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><rect x="12" y="12" width="66" height="34" rx="8" fill="#e3e6eb"/><rect x="12" y="10" width="66" height="34" rx="8" fill="#fff" stroke="#e8eaef"/></svg>' );
							$this->option( 'design_card', 'bordered', __( 'Bordered', 'rosette-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><rect x="12" y="10" width="66" height="36" rx="8" fill="#fff" stroke="#9aa3b2" stroke-width="1.5"/></svg>' );
							$this->option( 'design_card', 'flat', __( 'Flat', 'rosette-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><rect x="12" y="14" width="66" height="6" rx="3" fill="#dfe3e9"/><rect x="12" y="26" width="50" height="6" rx="3" fill="#dfe3e9"/><rect x="12" y="38" width="58" height="6" rx="3" fill="#dfe3e9"/></svg>' );
							?>
						</div>
					</fieldset>

					<fieldset class="ndvr-design-section">
						<legend><?php esc_html_e( 'Rating icon', 'rosette-reviews' ); ?></legend>
						<div class="ndvr-opts ndvr-opts-rating">
							<?php
							$this->option( 'design_rating', 'stars', __( 'Stars', 'rosette-reviews' ), '<span class="ndvr-glyph ndvr-glyph-star">&#9733;&#9733;&#9733;&#9733;&#9733;</span>' );
							$this->option( 'design_rating', 'hearts', __( 'Hearts', 'rosette-reviews' ), '<span class="ndvr-glyph ndvr-glyph-heart">&#9829;&#9829;&#9829;&#9829;&#9829;</span>' );
							$this->option( 'design_rating', 'thumbs', __( 'Thumbs', 'rosette-reviews' ), '<span class="ndvr-glyph">&#128077;&#128077;&#128077;</span>' );
							$this->option( 'design_rating', 'emoji', __( 'Emoji', 'rosette-reviews' ), '<span class="ndvr-glyph">&#128525;&#128525;&#128525;</span>' );
							?>
						</div>
					</fieldset>

					<fieldset class="ndvr-design-section">
						<legend><?php esc_html_e( 'Rating colors', 'rosette-reviews' ); ?></legend>
						<?php
						foreach ( self::colors() as $key => $field ) {
							$this->color_field( $key, $field );
						}
						?>
					</fieldset>

					<fieldset class="ndvr-design-section">
						<legend><?php esc_html_e( 'Typography', 'rosette-reviews' ); ?></legend>
						<div class="ndvr-typo-row">
							<label for="ndvr-design-font"><?php esc_html_e( 'Font', 'rosette-reviews' ); ?>
								<select id="ndvr-design-font" name="design_font">
									<?php foreach ( array( 'system' => __( 'System (matches the visitor\'s device)', 'rosette-reviews' ), 'serif' => __( 'Serif', 'rosette-reviews' ), 'rounded' => __( 'Rounded', 'rosette-reviews' ), 'mono' => __( 'Monospace', 'rosette-reviews' ) ) as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $this->value( 'design_font' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label for="ndvr-design-scale"><?php esc_html_e( 'Text size', 'rosette-reviews' ); ?>
								<select id="ndvr-design-scale" name="design_scale">
									<?php foreach ( array( 'compact' => __( 'Small (14px)', 'rosette-reviews' ), 'normal' => __( 'Normal (15px)', 'rosette-reviews' ), 'large' => __( 'Large (17px)', 'rosette-reviews' ) ) as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $this->value( 'design_scale' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</div>
					</fieldset>

					<p class="ndvr-design-actions">
						<button type="submit" name="ndvr_design_save" value="1" class="button button-primary"><?php esc_html_e( 'Save design', 'rosette-reviews' ); ?></button>
						<button type="submit" name="ndvr_design_reset" value="1" class="button ndvr-design-reset" data-confirm="<?php esc_attr_e( 'Reset every design option to its default?', 'rosette-reviews' ); ?>"><?php esc_html_e( 'Reset to defaults', 'rosette-reviews' ); ?></button>
					</p>
				</form>

				<aside class="ndvr-design-preview" aria-label="<?php esc_attr_e( 'Preview', 'rosette-reviews' ); ?>">
					<h2 class="ndvr-design-preview-title"><?php esc_html_e( 'Preview', 'rosette-reviews' ); ?></h2>
					<iframe
						class="ndvr-design-preview-frame"
						title="<?php esc_attr_e( 'Preview of the review summary and two sample reviews', 'rosette-reviews' ); ?>"
						srcdoc="<?php echo esc_attr( $this->preview_document() ); ?>"
						data-fonts="<?php echo esc_attr( wp_json_encode( Design::fonts() ) ); ?>"
						data-scales="<?php echo esc_attr( wp_json_encode( Design::scales() ) ); ?>"
					></iframe>
				</aside>
			</div>
		</div>
		<?php
	}
}
