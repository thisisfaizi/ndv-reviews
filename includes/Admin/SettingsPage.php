<?php
/**
 * Admin screen: general settings.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Caps;
use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Core settings: which post types accept reviews, guest reviews, photos,
 * reCAPTCHA, schema, and data removal on uninstall.
 */
class SettingsPage implements Registerable {

	const PAGE_SLUG  = 'ndv-reviews-settings';
	const NONCE      = 'ndvr_settings';

	/**
	 * Upper bound for photos per review (matches the input's max).
	 */
	const MAX_PHOTOS = 20;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Notice.
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
		// Priority 11 — after CriteriaPage (10) creates the parent menu, so the
		// submenu's page hook is computed against the real parent (not "admin_*").
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	/**
	 * Add the Settings submenu (first under the menu).
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'ndv-reviews',
			__( 'Settings', 'rosette-reviews' ),
			__( 'Settings', 'rosette-reviews' ),
			Caps::manage(),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Persist settings.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! isset( $_POST['ndvr_settings_save'] ) ) {
			return;
		}
		// Nonce first, then capability, then work (PRD-00 §2.2).
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Caps::manage() ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'rosette-reviews' ), 403 );
		}

		// Only the public post types the screen offers may be stored.
		$cpts = isset( $_POST['reviewable_post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['reviewable_post_types'] ) ) : array();
		$cpts = array_values( array_intersect( $cpts, array_keys( $this->offered_post_types() ) ) );

		$schema_mode = isset( $_POST['schema_mode'] ) ? sanitize_key( wp_unslash( $_POST['schema_mode'] ) ) : 'auto';
		if ( ! in_array( $schema_mode, array( 'auto', 'plugin', 'off' ), true ) ) {
			$schema_mode = 'auto';
		}

		$values = array(
			'enable_reviews'           => ! empty( $_POST['enable_reviews'] ),
			'reviewable_post_types'    => $cpts,
			'allow_guest_reviews'      => ! empty( $_POST['allow_guest_reviews'] ),
			'photo_uploads'            => ! empty( $_POST['photo_uploads'] ),
			'max_photos'               => isset( $_POST['max_photos'] ) ? min( self::MAX_PHOTOS, absint( $_POST['max_photos'] ) ) : 5,
			'recaptcha_enabled'        => ! empty( $_POST['recaptcha_enabled'] ),
			'recaptcha_site_key'       => isset( $_POST['recaptcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_site_key'] ) ) : '',
			'schema_mode'              => $schema_mode,
			'remove_data_on_uninstall' => ! empty( $_POST['remove_data_on_uninstall'] ),
		);

		// The secret is never printed back, so an empty field keeps the saved
		// one; the "Remove" box clears it.
		$secret = isset( $_POST['recaptcha_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_secret'] ) ) : '';
		if ( ! empty( $_POST['recaptcha_secret_remove'] ) ) {
			$values['recaptcha_secret'] = '';
		} elseif ( '' !== $secret ) {
			$values['recaptcha_secret'] = $secret;
		}

		// Keys features registered for this page (RR-00 F6); other pages' keys
		// are left as stored.
		$values = array_merge( SettingsFields::sanitize_page( 'settings', $_POST ), $values );

		$this->settings->update( $values );

		$this->notice = __( 'Settings saved.', 'rosette-reviews' );
	}

	/**
	 * Public post types (other than products and attachments) the screen
	 * offers as reviewable.
	 *
	 * @return array<string,\WP_Post_Type>
	 */
	private function offered_post_types() {
		$cpts = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);
		unset( $cpts['product'], $cpts['attachment'] );

		return $cpts;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Caps::manage() ) ) {
			return;
		}
		$s = $this->settings;

		$cpts    = $this->offered_post_types();
		$enabled =(array) $s->get( 'reviewable_post_types', array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Settings', 'rosette-reviews' ); ?></h1>

			<?php if ( '' !== $this->notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $this->notice ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>

				<?php // ── Card: Collection ── ?>
				<div class="ndvr-card">
					<div class="ndvr-card-header"><h2><?php esc_html_e( 'Collection', 'rosette-reviews' ); ?></h2></div>
					<div class="ndvr-field">
						<label style="font-weight:700;"><?php esc_html_e( 'Reviews', 'rosette-reviews' ); ?></label>
						<label style="display:flex;align-items:center;gap:8px;font-weight:400;margin-bottom:8px;">
							<input type="checkbox" name="enable_reviews" value="1" <?php checked( (bool) $s->get( 'enable_reviews' ) ); ?> />
							<?php esc_html_e( 'Enable reviews', 'rosette-reviews' ); ?>
						</label>
						<label style="display:flex;align-items:center;gap:8px;font-weight:400;">
							<input type="checkbox" name="allow_guest_reviews" value="1" <?php checked( (bool) $s->get( 'allow_guest_reviews' ) ); ?> />
							<?php esc_html_e( 'Allow guest (logged-out) reviews', 'rosette-reviews' ); ?>
						</label>
					</div>

					<?php if ( ! empty( $cpts ) ) : ?>
					<div class="ndvr-field" style="margin-top:18px;">
						<label style="font-weight:700;"><?php esc_html_e( 'Also collect reviews on', 'rosette-reviews' ); ?></label>
						<span class="description" style="display:block;margin-bottom:10px;"><?php esc_html_e( 'WooCommerce products are always reviewable. Add other public post types here.', 'rosette-reviews' ); ?></span>
						<div style="display:flex;flex-wrap:wrap;gap:10px;">
							<?php foreach ( $cpts as $cpt ) : ?>
								<label style="display:inline-flex;align-items:center;gap:7px;background:var(--ndvr-haze);border-radius:8px;padding:7px 12px;font-weight:500;cursor:pointer;">
									<input type="checkbox" name="reviewable_post_types[]" value="<?php echo esc_attr( $cpt->name ); ?>" <?php checked( in_array( $cpt->name, $enabled, true ) ); ?> />
									<?php echo esc_html( $cpt->labels->singular_name ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<div class="ndvr-field" style="margin-top:18px;">
						<label style="font-weight:700;"><?php esc_html_e( 'Photo uploads', 'rosette-reviews' ); ?></label>
						<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
							<label style="display:flex;align-items:center;gap:7px;font-weight:400;">
								<input type="checkbox" name="photo_uploads" value="1" <?php checked( (bool) $s->get( 'photo_uploads' ) ); ?> />
								<?php esc_html_e( 'Allow photo uploads', 'rosette-reviews' ); ?>
							</label>
							<label style="display:flex;align-items:center;gap:7px;font-weight:400;color:var(--ndvr-slate);">
								<?php esc_html_e( 'Max per review:', 'rosette-reviews' ); ?>
								<input type="number" name="max_photos" min="0" max="<?php echo esc_attr( self::MAX_PHOTOS ); ?>" value="<?php echo esc_attr( $s->get( 'max_photos', 5 ) ); ?>" style="width:60px;" />
							</label>
						</div>
					</div>
				</div>

				<?php // ── Card: Spam protection ── ?>
				<div class="ndvr-card">
					<div class="ndvr-card-header"><h2><?php esc_html_e( 'Spam Protection', 'rosette-reviews' ); ?></h2></div>
					<div class="ndvr-field">
						<label style="display:flex;align-items:center;gap:8px;font-weight:600;margin-bottom:14px;">
							<input type="checkbox" name="recaptcha_enabled" value="1" <?php checked( (bool) $s->get( 'recaptcha_enabled' ) ); ?> />
							<?php esc_html_e( 'Enable reCAPTCHA v3 (your own keys)', 'rosette-reviews' ); ?>
						</label>
						<div class="ndvr-field-row">
							<div class="ndvr-field">
								<label><?php esc_html_e( 'Site key', 'rosette-reviews' ); ?></label>
								<input type="text" name="recaptcha_site_key" value="<?php echo esc_attr( $s->get( 'recaptcha_site_key' ) ); ?>" placeholder="6Lcxxx..." />
							</div>
							<div class="ndvr-field">
								<?php SettingsFields::render_secret_input( 'recaptcha_secret', __( 'Secret key', 'rosette-reviews' ), '' !== (string) $s->get( 'recaptcha_secret' ) ); ?>
							</div>
						</div>
					</div>
				</div>

				<?php
				// ── Card: Reviews and trust (RR-00 F6) ── shown once a feature registers a field.
				if ( SettingsFields::card_fields( 'settings', 'trust' ) ) :
					?>
					<div class="ndvr-card">
						<div class="ndvr-card-header"><h2><?php esc_html_e( 'Reviews and trust', 'rosette-reviews' ); ?></h2></div>
						<?php SettingsFields::render_card_fields( 'settings', 'trust', $s->all() ); ?>
					</div>
				<?php endif; ?>

				<?php // ── Card: SEO & Advanced ── ?>
				<div class="ndvr-card">
					<div class="ndvr-card-header"><h2><?php esc_html_e( 'SEO & Advanced', 'rosette-reviews' ); ?></h2></div>
					<div class="ndvr-field">
						<label><?php esc_html_e( 'Schema markup (JSON-LD)', 'rosette-reviews' ); ?></label>
						<select name="schema_mode" style="max-width:340px;">
							<option value="auto"   <?php selected( $s->get( 'schema_mode' ), 'auto' ); ?>><?php esc_html_e( 'Automatic: add ratings to WooCommerce\'s product schema; skip standalone schema if an SEO plugin is active (recommended)', 'rosette-reviews' ); ?></option>
							<option value="plugin" <?php selected( $s->get( 'schema_mode' ), 'plugin' ); ?>><?php esc_html_e( 'Always: also output standalone product schema when an SEO plugin is active', 'rosette-reviews' ); ?></option>
							<option value="off"    <?php selected( $s->get( 'schema_mode' ), 'off' ); ?>><?php esc_html_e( 'Off: add no review schema', 'rosette-reviews' ); ?></option>
						</select>
					</div>
					<div class="ndvr-field" style="margin-top:18px;padding-top:16px;border-top:1px solid var(--ndvr-line);">
						<label style="display:flex;align-items:center;gap:8px;font-weight:400;color:var(--ndvr-slate);">
							<input type="checkbox" name="remove_data_on_uninstall" value="1" <?php checked( (bool) $s->get( 'remove_data_on_uninstall' ) ); ?> />
							<?php esc_html_e( 'Delete all Rosette Reviews data when the plugin is uninstalled', 'rosette-reviews' ); ?>
						</label>
					</div>
				</div>

				<div style="margin-top:4px;">
					<button type="submit" name="ndvr_settings_save" value="1" class="button button-primary"><?php esc_html_e( 'Save settings', 'rosette-reviews' ); ?></button>
				</div>
			</form>
		</div>
		<?php
	}
}
