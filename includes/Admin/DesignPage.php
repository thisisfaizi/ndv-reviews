<?php
/**
 * Admin screen: storefront design.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;
use NdvReviews\Display\Design;

defined( 'ABSPATH' ) || exit;

/**
 * Lets the merchant choose how reviews look — accent color, layout, summary
 * style, card style, and rating icon — with live card selectors. Free, by
 * design (competitors gate this behind Pro).
 */
class DesignPage implements Registerable {

	const CAPABILITY = 'manage_woocommerce';
	const PAGE_SLUG  = 'ndv-reviews-design';
	const NONCE      = 'ndvr_design';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Notice text after saving.
	 *
	 * @var string
	 */
	private $notice = '';

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
			__( 'Design', 'ndv-reviews' ),
			__( 'Design', 'ndv-reviews' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Persist the design choices.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! isset( $_POST['ndvr_design_save'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$template = isset( $_POST['design_template'] ) ? sanitize_key( wp_unslash( $_POST['design_template'] ) ) : 'list';
		$summary  = isset( $_POST['design_summary'] ) ? sanitize_key( wp_unslash( $_POST['design_summary'] ) ) : 'panel';
		$card     = isset( $_POST['design_card'] ) ? sanitize_key( wp_unslash( $_POST['design_card'] ) ) : 'soft';
		$rating   = isset( $_POST['design_rating'] ) ? sanitize_key( wp_unslash( $_POST['design_rating'] ) ) : 'stars';
		$accent   = isset( $_POST['design_accent'] ) ? Design::sanitize_color( wp_unslash( $_POST['design_accent'] ) ) : '';
		$font     = isset( $_POST['design_font'] ) ? sanitize_key( wp_unslash( $_POST['design_font'] ) ) : 'system';
		$scale    = isset( $_POST['design_scale'] ) ? sanitize_key( wp_unslash( $_POST['design_scale'] ) ) : 'normal';

		$this->settings->update(
			array(
				'design_template' => in_array( $template, array( 'list', 'grid' ), true ) ? $template : 'list',
				'design_summary'  => in_array( $summary, array( 'panel', 'compact' ), true ) ? $summary : 'panel',
				'design_card'     => in_array( $card, array( 'soft', 'bordered', 'flat' ), true ) ? $card : 'soft',
				'design_rating'   => in_array( $rating, array( 'stars', 'hearts', 'thumbs', 'emoji' ), true ) ? $rating : 'stars',
				'design_accent'   => '' !== $accent ? $accent : '#181a1f',
				'design_font'     => in_array( $font, array( 'system', 'serif', 'rounded', 'mono' ), true ) ? $font : 'system',
				'design_scale'    => in_array( $scale, array( 'compact', 'normal', 'large' ), true ) ? $scale : 'normal',
			)
		);

		$this->notice = __( 'Design saved. View a product page to see it live.', 'ndv-reviews' );
	}

	/**
	 * Render an option card (label-wrapped radio).
	 *
	 * @param string $group   Field name.
	 * @param string $value   Option value.
	 * @param string $current Current value.
	 * @param string $label   Caption.
	 * @param string $preview Pre-escaped preview markup.
	 * @return void
	 */
	private function option( $group, $value, $current, $label, $preview ) {
		printf(
			'<label class="ndvr-opt"><input type="radio" name="%1$s" value="%2$s" %3$s /><span class="ndvr-opt-card"><span class="ndvr-opt-preview">%4$s</span><span class="ndvr-opt-label">%5$s</span></span></label>',
			esc_attr( $group ),
			esc_attr( $value ),
			checked( $current, $value, false ),
			$preview, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- controlled inline SVG/markup.
			esc_html( $label )
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$s        = $this->settings;
		$accent   = Design::sanitize_color( (string) $s->get( 'design_accent' ) );
		$accent   = '' !== $accent ? $accent : '#181a1f';
		$presets  = array( '#181a1f', '#0f7d5b', '#2563eb', '#7c3aed', '#db2777', '#ea580c' );

		// Preview helpers (small inline SVG wireframes).
		$bars = '<svg viewBox="0 0 90 56" width="90" height="56"><rect x="8" y="12" width="40" height="6" rx="3" fill="#cfd4dc"/><rect x="8" y="12" width="28" height="6" rx="3" fill="#9aa3b2"/><rect x="8" y="26" width="40" height="6" rx="3" fill="#cfd4dc"/><rect x="8" y="26" width="20" height="6" rx="3" fill="#9aa3b2"/><rect x="8" y="40" width="40" height="6" rx="3" fill="#cfd4dc"/><rect x="8" y="40" width="34" height="6" rx="3" fill="#9aa3b2"/></svg>';
		?>
		<div class="wrap ndvr-design">
			<h1><?php esc_html_e( 'Design', 'ndv-reviews' ); ?></h1>
			<?php if ( '' !== $this->notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $this->notice ); ?></p></div>
			<?php endif; ?>
			<p class="ndvr-design-intro"><?php esc_html_e( 'Choose how reviews look on your storefront. Changes apply everywhere reviews appear.', 'ndv-reviews' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>

				<section class="ndvr-design-section">
					<h2><?php esc_html_e( 'Accent color', 'ndv-reviews' ); ?></h2>
					<div class="ndvr-accent-row">
						<input type="color" id="ndvr-accent" name="design_accent" value="<?php echo esc_attr( $accent ); ?>" />
						<div class="ndvr-swatches">
							<?php foreach ( $presets as $preset ) : ?>
								<button type="button" class="ndvr-swatch" data-color="<?php echo esc_attr( $preset ); ?>" style="background:<?php echo esc_attr( $preset ); ?>" aria-label="<?php echo esc_attr( $preset ); ?>"></button>
							<?php endforeach; ?>
						</div>
						<span class="ndvr-accent-help"><?php esc_html_e( 'Buttons, active filters, links, and pagination.', 'ndv-reviews' ); ?></span>
					</div>
				</section>

				<section class="ndvr-design-section">
					<h2><?php esc_html_e( 'Layout', 'ndv-reviews' ); ?></h2>
					<div class="ndvr-opts">
						<?php
						$this->option( 'design_template', 'list', $s->get( 'design_template' ), __( 'List', 'ndv-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><rect x="8" y="10" width="74" height="14" rx="3" fill="#eef1f5"/><rect x="8" y="30" width="74" height="14" rx="3" fill="#eef1f5"/></svg>' );
						$this->option( 'design_template', 'grid', $s->get( 'design_template' ), __( 'Grid', 'ndv-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><rect x="8" y="10" width="34" height="34" rx="4" fill="#eef1f5"/><rect x="48" y="10" width="34" height="34" rx="4" fill="#eef1f5"/></svg>' );
						?>
					</div>
				</section>

				<section class="ndvr-design-section">
					<h2><?php esc_html_e( 'Summary style', 'ndv-reviews' ); ?></h2>
					<div class="ndvr-opts">
						<?php
						$this->option( 'design_summary', 'panel', $s->get( 'design_summary' ), __( 'Trust Panel', 'ndv-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><text x="8" y="34" font-size="22" font-weight="800" fill="#181a1f">4.5</text>' . '<rect x="44" y="14" width="38" height="5" rx="2" fill="#f6a93b"/><rect x="44" y="24" width="30" height="5" rx="2" fill="#f6a93b"/><rect x="44" y="34" width="22" height="5" rx="2" fill="#e2e5ea"/></svg>' );
						$this->option( 'design_summary', 'compact', $s->get( 'design_summary' ), __( 'Compact', 'ndv-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><text x="10" y="32" font-size="16" font-weight="800" fill="#181a1f">4.5</text><text x="34" y="31" font-size="13" fill="#f6a93b">★★★★</text></svg>' );
						?>
					</div>
				</section>

				<section class="ndvr-design-section">
					<h2><?php esc_html_e( 'Card style', 'ndv-reviews' ); ?></h2>
					<div class="ndvr-opts">
						<?php
						$this->option( 'design_card', 'soft', $s->get( 'design_card' ), __( 'Soft shadow', 'ndv-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><rect x="12" y="10" width="66" height="36" rx="8" fill="#fff" stroke="#e8eaef"/><rect x="12" y="42" width="66" height="6" rx="3" fill="#eef1f5"/></svg>' );
						$this->option( 'design_card', 'bordered', $s->get( 'design_card' ), __( 'Bordered', 'ndv-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><rect x="12" y="10" width="66" height="36" rx="8" fill="#fff" stroke="#9aa3b2" stroke-width="1.5"/></svg>' );
						$this->option( 'design_card', 'flat', $s->get( 'design_card' ), __( 'Flat', 'ndv-reviews' ), '<svg viewBox="0 0 90 56" width="90" height="56"><rect x="12" y="14" width="66" height="6" rx="3" fill="#eef1f5"/><rect x="12" y="26" width="50" height="6" rx="3" fill="#eef1f5"/><rect x="12" y="38" width="58" height="6" rx="3" fill="#eef1f5"/></svg>' );
						?>
					</div>
				</section>

				<section class="ndvr-design-section">
					<h2><?php esc_html_e( 'Rating icon', 'ndv-reviews' ); ?></h2>
					<div class="ndvr-opts ndvr-opts-rating">
						<?php
						$this->option( 'design_rating', 'stars', $s->get( 'design_rating' ), __( 'Stars', 'ndv-reviews' ), '<span class="ndvr-glyph" style="color:#f6a93b">&#9733;&#9733;&#9733;&#9733;&#9733;</span>' );
						$this->option( 'design_rating', 'hearts', $s->get( 'design_rating' ), __( 'Hearts', 'ndv-reviews' ), '<span class="ndvr-glyph" style="color:#e0405e">&#9829;&#9829;&#9829;&#9829;&#9829;</span>' );
						$this->option( 'design_rating', 'thumbs', $s->get( 'design_rating' ), __( 'Thumbs', 'ndv-reviews' ), '<span class="ndvr-glyph">&#128077;&#128077;&#128077;</span>' );
						$this->option( 'design_rating', 'emoji', $s->get( 'design_rating' ), __( 'Emoji', 'ndv-reviews' ), '<span class="ndvr-glyph">&#128525;&#128525;&#128525;</span>' );
						?>
					</div>
				</section>

				<section class="ndvr-design-section">
					<h2><?php esc_html_e( 'Typography', 'ndv-reviews' ); ?></h2>
					<p class="ndvr-typo-row" style="display:flex;gap:24px;flex-wrap:wrap;">
						<label><?php esc_html_e( 'Font', 'ndv-reviews' ); ?><br>
							<select name="design_font">
								<?php foreach ( array( 'system' => __( 'System', 'ndv-reviews' ), 'serif' => __( 'Serif', 'ndv-reviews' ), 'rounded' => __( 'Rounded', 'ndv-reviews' ), 'mono' => __( 'Mono', 'ndv-reviews' ) ) as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s->get( 'design_font', 'system' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label><?php esc_html_e( 'Text size', 'ndv-reviews' ); ?><br>
							<select name="design_scale">
								<?php foreach ( array( 'compact' => __( 'Compact', 'ndv-reviews' ), 'normal' => __( 'Normal', 'ndv-reviews' ), 'large' => __( 'Large', 'ndv-reviews' ) ) as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s->get( 'design_scale', 'normal' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
				</section>

				<p class="ndvr-design-actions">
					<button type="submit" name="ndvr_design_save" value="1" class="button button-primary"><?php esc_html_e( 'Save design', 'ndv-reviews' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
