<?php
/**
 * Request exclusions: settings fields and their screen (RR-05).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Admin\RequestsPage;
use NdvReviews\Collection\Reviewable;
use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the three exclusion keys on the Reminders screen (RR-00 F6
 * registry, card `exclusions`), renders them inside the "Reminder settings"
 * table, loads WooCommerce's select scripts there, and resets Reviewable's
 * per-request cache when the settings change. The rules themselves live in
 * Collection\Reviewable.
 */
class Exclusions implements Registerable {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'ndv-reviews/settings_fields', array( $this, 'register_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'update_option_' . NDVR_OPTION_SETTINGS, array( Reviewable::class, 'reset_cache' ), 10, 0 );
		add_action( 'add_option_' . NDVR_OPTION_SETTINGS, array( Reviewable::class, 'reset_cache' ), 10, 0 );
	}

	/**
	 * The three keys.
	 *
	 * @param array<string,array> $fields Fields.
	 * @return array<string,array>
	 */
	public function register_fields( $fields ) {
		$fields = (array) $fields;

		$fields['reminder_exclude_cats']     = array(
			'sanitize' => array( __CLASS__, 'sanitize_cats' ),
			'default'  => array(),
			'page'     => 'reminders',
			'card'     => 'exclusions',
			'render'   => array( $this, 'render_cats' ),
		);
		$fields['reminder_exclude_products'] = array(
			'sanitize' => array( __CLASS__, 'sanitize_products' ),
			'default'  => array(),
			'page'     => 'reminders',
			'card'     => 'exclusions',
			'render'   => array( $this, 'render_products' ),
		);
		$fields['reminder_exclude_roles']    = array(
			'sanitize' => array( __CLASS__, 'sanitize_roles' ),
			'default'  => array(),
			'page'     => 'reminders',
			'card'     => 'exclusions',
			'render'   => array( $this, 'render_roles' ),
		);

		return $fields;
	}

	/**
	 * Positive integer ids from a posted list: only digit strings (or ints)
	 * count, so "-3" never becomes 3 and nested arrays are ignored.
	 *
	 * @param mixed $raw Posted value.
	 * @return int[]
	 */
	private static function ids( $raw ) {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $value ) {
			if ( ( is_int( $value ) && $value > 0 ) || ( is_string( $value ) && ctype_digit( $value ) && (int) $value > 0 ) ) {
				$out[] = (int) $value;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Existing product category ids only.
	 *
	 * @param mixed $raw Posted value or null.
	 * @return int[]
	 */
	public static function sanitize_cats( $raw ) {
		$ids = self::ids( $raw );
		if ( ! $ids ) {
			return array();
		}
		$existing = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'include'    => $ids,
				'fields'     => 'ids',
			)
		);

		return is_array( $existing ) ? array_values( array_intersect( $ids, array_map( 'absint', $existing ) ) ) : array();
	}

	/**
	 * Product ids only (not variations: reviews attach to the parent).
	 *
	 * @param mixed $raw Posted value or null.
	 * @return int[]
	 */
	public static function sanitize_products( $raw ) {
		$ids = self::ids( $raw );

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					return 'product' === get_post_type( $id );
				}
			)
		);
	}

	/**
	 * Existing role slugs only.
	 *
	 * @param mixed $raw Posted value or null.
	 * @return string[]
	 */
	public static function sanitize_roles( $raw ) {
		$roles = array();
		foreach ( is_array( $raw ) ? $raw : array() as $value ) {
			if ( is_string( $value ) && '' !== sanitize_key( $value ) ) {
				$roles[] = sanitize_key( $value );
			}
		}
		$roles = array_values( array_unique( $roles ) );

		return array_values( array_intersect( $roles, array_keys( wp_roles()->get_names() ) ) );
	}

	/**
	 * WooCommerce's select scripts on the Reminders screen only.
	 *
	 * @param string $hook_suffix Admin page hook.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, RequestsPage::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
	}

	/**
	 * Heading row printed before the fields.
	 *
	 * @return void
	 */
	public static function render_heading() {
		?>
		<tr>
			<td colspan="2" style="padding-left:0;padding-bottom:0;"><h3 style="margin:8px 0 0;"><?php esc_html_e( 'Who and what to ask about', 'rosette-reviews' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Excluded items are left out of the email and the review page, including links already sent. If an order has nothing left to review, no email is sent. Customers can still review any product on its product page.', 'rosette-reviews' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Categories, children indented.
	 *
	 * @param mixed $value Current value.
	 * @return void
	 */
	public function render_cats( $value ) {
		$selected = array_map( 'absint', (array) $value );
		$terms    = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$terms    = is_array( $terms ) ? $terms : array();
		?>
		<tr>
			<th scope="row"><label for="ndvr-exclude-cats"><?php esc_html_e( 'Don\'t ask about products in these categories', 'rosette-reviews' ); ?></label></th>
			<td>
				<select multiple class="wc-enhanced-select" id="ndvr-exclude-cats" name="reminder_exclude_cats[]" style="min-width:320px;" data-placeholder="<?php esc_attr_e( 'Choose categories', 'rosette-reviews' ); ?>">
					<?php foreach ( $this->ordered_terms( $terms ) as $row ) : ?>
						<option value="<?php echo esc_attr( (string) $row['term']->term_id ); ?>" <?php selected( in_array( (int) $row['term']->term_id, $selected, true ) ); ?>><?php echo esc_html( str_repeat( '— ', $row['depth'] ) . $row['term']->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Child categories are excluded too.', 'rosette-reviews' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Terms in tree order with their depth.
	 *
	 * @param \WP_Term[] $terms Terms.
	 * @return array<int,array{term:\WP_Term,depth:int}>
	 */
	private function ordered_terms( array $terms ) {
		$children = array();
		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}
		$out  = array();
		$walk = static function ( $parent_id, $depth ) use ( &$walk, &$out, $children ) {
			foreach ( isset( $children[ $parent_id ] ) ? $children[ $parent_id ] : array() as $term ) {
				$out[] = array(
					'term'  => $term,
					'depth' => $depth,
				);
				if ( $depth < 10 ) {
					$walk( (int) $term->term_id, $depth + 1 );
				}
			}
		};
		$walk( 0, 0 );

		return $out;
	}

	/**
	 * Product search.
	 *
	 * @param mixed $value Current value.
	 * @return void
	 */
	public function render_products( $value ) {
		$selected = array_values( array_filter( array_map( 'absint', (array) $value ) ) );
		?>
		<tr>
			<th scope="row"><label for="ndvr-exclude-products"><?php esc_html_e( 'Don\'t ask about these products', 'rosette-reviews' ); ?></label></th>
			<td>
				<select multiple class="wc-product-search" id="ndvr-exclude-products" name="reminder_exclude_products[]" style="min-width:320px;" data-action="woocommerce_json_search_products" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'rosette-reviews' ); ?>">
					<?php foreach ( $selected as $id ) : ?>
						<?php if ( 'product' === get_post_type( $id ) ) : ?>
							<option value="<?php echo esc_attr( (string) $id ); ?>" selected="selected"><?php echo esc_html( get_the_title( $id ) ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * Role checkboxes.
	 *
	 * @param mixed $value Current value.
	 * @return void
	 */
	public function render_roles( $value ) {
		$selected = array_map( 'strval', (array) $value );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Don\'t send requests to customers with these roles', 'rosette-reviews' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Don\'t send requests to customers with these roles', 'rosette-reviews' ); ?></legend>
					<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
						<label style="display:inline-block;margin:0 16px 6px 0;"><input type="checkbox" name="reminder_exclude_roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( (string) $slug, $selected, true ) ); ?> /> <?php echo esc_html( translate_user_role( $name ) ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description"><?php esc_html_e( 'Guest orders have no role, so they are never excluded here.', 'rosette-reviews' ); ?></p>
			</td>
		</tr>
		<?php
	}
}
