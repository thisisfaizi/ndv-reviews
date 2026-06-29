<?php
/**
 * Admin screen: general settings.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Registerable;
use NdvReviews\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Core settings: which post types accept reviews, guest reviews, photos,
 * reCAPTCHA, schema, and data removal on uninstall.
 */
class SettingsPage implements Registerable {

	const CAPABILITY = 'manage_woocommerce';
	const PAGE_SLUG  = 'ndv-reviews-settings';
	const NONCE      = 'ndvr_settings';

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
			__( 'Settings', 'ndv-reviews' ),
			__( 'Settings', 'ndv-reviews' ),
			self::CAPABILITY,
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
		if ( ! isset( $_POST['ndvr_settings_save'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$cpts = isset( $_POST['reviewable_post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['reviewable_post_types'] ) ) : array();

		$this->settings->update(
			array(
				'enable_reviews'        => ! empty( $_POST['enable_reviews'] ),
				'reviewable_post_types' => array_values( array_diff( $cpts, array( 'product' ) ) ),
				'allow_guest_reviews'   => ! empty( $_POST['allow_guest_reviews'] ),
				'photo_uploads'         => ! empty( $_POST['photo_uploads'] ),
				'max_photos'            => isset( $_POST['max_photos'] ) ? absint( $_POST['max_photos'] ) : 5,
				'recaptcha_enabled'     => ! empty( $_POST['recaptcha_enabled'] ),
				'recaptcha_site_key'    => isset( $_POST['recaptcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_site_key'] ) ) : '',
				'recaptcha_secret'      => isset( $_POST['recaptcha_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['recaptcha_secret'] ) ) : '',
				'schema_mode'           => isset( $_POST['schema_mode'] ) ? sanitize_key( wp_unslash( $_POST['schema_mode'] ) ) : 'auto',
				'remove_data_on_uninstall' => ! empty( $_POST['remove_data_on_uninstall'] ),
			)
		);

		$this->notice = __( 'Settings saved.', 'ndv-reviews' );
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
		$s = $this->settings;

		$cpts = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'objects'
		);
		unset( $cpts['product'], $cpts['attachment'] );
		$enabled = (array) $s->get( 'reviewable_post_types', array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Settings', 'ndv-reviews' ); ?></h1>
			<?php if ( '' !== $this->notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $this->notice ); ?></p></div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Reviews', 'ndv-reviews' ); ?></th>
						<td><label><input type="checkbox" name="enable_reviews" value="1" <?php checked( (bool) $s->get( 'enable_reviews' ) ); ?> /> <?php esc_html_e( 'Enable reviews', 'ndv-reviews' ); ?></label>
						<br><label><input type="checkbox" name="allow_guest_reviews" value="1" <?php checked( (bool) $s->get( 'allow_guest_reviews' ) ); ?> /> <?php esc_html_e( 'Allow guest (logged-out) reviews', 'ndv-reviews' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Reviewable content', 'ndv-reviews' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'WooCommerce products are always reviewable. Enable other post types to collect reviews on them (use the [ndvr-reviews] / [ndvr-form] shortcodes on those templates).', 'ndv-reviews' ); ?></p>
							<?php if ( empty( $cpts ) ) : ?>
								<p><em><?php esc_html_e( 'No other public post types found.', 'ndv-reviews' ); ?></em></p>
							<?php else : ?>
								<?php foreach ( $cpts as $cpt ) : ?>
									<label style="display:inline-block;margin:0 14px 6px 0;"><input type="checkbox" name="reviewable_post_types[]" value="<?php echo esc_attr( $cpt->name ); ?>" <?php checked( in_array( $cpt->name, $enabled, true ) ); ?> /> <?php echo esc_html( $cpt->labels->singular_name ); ?></label>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Photos', 'ndv-reviews' ); ?></th>
						<td><label><input type="checkbox" name="photo_uploads" value="1" <?php checked( (bool) $s->get( 'photo_uploads' ) ); ?> /> <?php esc_html_e( 'Allow photo uploads', 'ndv-reviews' ); ?></label>
						&nbsp; <?php esc_html_e( 'Max:', 'ndv-reviews' ); ?> <input type="number" name="max_photos" min="0" value="<?php echo esc_attr( $s->get( 'max_photos', 5 ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'reCAPTCHA v3', 'ndv-reviews' ); ?></th>
						<td>
							<label><input type="checkbox" name="recaptcha_enabled" value="1" <?php checked( (bool) $s->get( 'recaptcha_enabled' ) ); ?> /> <?php esc_html_e( 'Enable (your own keys)', 'ndv-reviews' ); ?></label><br>
							<input type="text" name="recaptcha_site_key" value="<?php echo esc_attr( $s->get( 'recaptcha_site_key' ) ); ?>" placeholder="<?php esc_attr_e( 'Site key', 'ndv-reviews' ); ?>" class="regular-text" />
							<input type="text" name="recaptcha_secret" value="<?php echo esc_attr( $s->get( 'recaptcha_secret' ) ); ?>" placeholder="<?php esc_attr_e( 'Secret key', 'ndv-reviews' ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SEO schema', 'ndv-reviews' ); ?></th>
						<td>
							<select name="schema_mode">
								<option value="auto" <?php selected( $s->get( 'schema_mode' ), 'auto' ); ?>><?php esc_html_e( 'Auto (defer to WooCommerce / SEO plugin)', 'ndv-reviews' ); ?></option>
								<option value="plugin" <?php selected( $s->get( 'schema_mode' ), 'plugin' ); ?>><?php esc_html_e( 'Always output our schema', 'ndv-reviews' ); ?></option>
								<option value="off" <?php selected( $s->get( 'schema_mode' ), 'off' ); ?>><?php esc_html_e( 'Off', 'ndv-reviews' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'ndv-reviews' ); ?></th>
						<td><label><input type="checkbox" name="remove_data_on_uninstall" value="1" <?php checked( (bool) $s->get( 'remove_data_on_uninstall' ) ); ?> /> <?php esc_html_e( 'Delete all NDV Reviews data when the plugin is uninstalled', 'ndv-reviews' ); ?></label></td>
					</tr>
				</table>
				<p><button type="submit" name="ndvr_settings_save" value="1" class="button button-primary"><?php esc_html_e( 'Save settings', 'ndv-reviews' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
