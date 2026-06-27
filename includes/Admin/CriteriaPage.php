<?php
/**
 * Admin screen: manage rating criteria.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Support\Registerable;
use NdvReviews\Reviews\CriteriaRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level NDV Reviews menu and the Rating Criteria screen.
 */
class CriteriaPage implements Registerable {

	const CAPABILITY = 'manage_woocommerce';
	const MENU_SLUG  = 'ndv-reviews';
	const PAGE_SLUG  = 'ndv-reviews-criteria';
	const NONCE      = 'ndvr_criteria_action';

	/**
	 * Criteria repository.
	 *
	 * @var CriteriaRepository
	 */
	private $criteria;

	/**
	 * Notices to render after handling an action.
	 *
	 * @var array<int,array{type:string,message:string}>
	 */
	private $notices = array();

	/**
	 * Constructor.
	 *
	 * @param CriteriaRepository $criteria Criteria repository.
	 */
	public function __construct( CriteriaRepository $criteria ) {
		$this->criteria = $criteria;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Add the menu and the Criteria subpage.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'NDV Reviews', 'ndv-reviews' ),
			__( 'NDV Reviews', 'ndv-reviews' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-star-filled',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Rating Criteria', 'ndv-reviews' ),
			__( 'Rating Criteria', 'ndv-reviews' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle add/update/delete POST actions (nonce + capability guarded).
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['ndvr_criteria_do'] ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage review criteria.', 'ndv-reviews' ) );
		}

		check_admin_referer( self::NONCE );

		$do = sanitize_key( wp_unslash( $_POST['ndvr_criteria_do'] ) );

		if ( 'add' === $do ) {
			$name   = isset( $_POST['ndvr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ndvr_name'] ) ) : '';
			$result = $this->criteria->insert( array( 'name' => $name ) );
			$this->push_result( $result, __( 'Criterion added.', 'ndv-reviews' ) );
		} elseif ( 'delete' === $do ) {
			$id = isset( $_POST['ndvr_id'] ) ? absint( $_POST['ndvr_id'] ) : 0;
			$this->criteria->delete( $id );
			$this->notices[] = array(
				'type'    => 'success',
				'message' => __( 'Criterion deleted.', 'ndv-reviews' ),
			);
		} elseif ( 'toggle' === $do ) {
			$id      = isset( $_POST['ndvr_id'] ) ? absint( $_POST['ndvr_id'] ) : 0;
			$status  = isset( $_POST['ndvr_status'] ) && 'active' === $_POST['ndvr_status'] ? 'active' : 'inactive';
			$result  = $this->criteria->update( $id, array( 'status' => $status ) );
			$this->push_result( $result, __( 'Criterion updated.', 'ndv-reviews' ) );
		}
	}

	/**
	 * Record a notice based on a repository result.
	 *
	 * @param mixed  $result  Result from a repository call.
	 * @param string $success Success message.
	 * @return void
	 */
	private function push_result( $result, $success ) {
		if ( is_wp_error( $result ) ) {
			$this->notices[] = array(
				'type'    => 'error',
				'message' => $result->get_error_message(),
			);
		} else {
			$this->notices[] = array(
				'type'    => 'success',
				'message' => $success,
			);
		}
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

		$all      = $this->criteria->get_all();
		$active   = $this->criteria->count_active();
		$max      = $this->criteria->max_active();
		$at_cap   = $active >= $max;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Rating Criteria', 'ndv-reviews' ); ?></h1>

			<?php foreach ( $this->notices as $notice ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<p>
				<?php
				printf(
					/* translators: 1: active count, 2: max allowed. */
					esc_html__( 'Customers rate each active criterion when leaving a review. Active: %1$d of %2$d.', 'ndv-reviews' ),
					(int) $active,
					(int) $max
				);
				?>
			</p>

			<table class="widefat striped" style="max-width:720px;margin-bottom:20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'ndv-reviews' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ndv-reviews' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ndv-reviews' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $all ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No criteria yet.', 'ndv-reviews' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $all as $criterion ) : ?>
						<tr>
							<td><?php echo esc_html( $criterion->name ); ?></td>
							<td><?php echo esc_html( 'active' === $criterion->status ? __( 'Active', 'ndv-reviews' ) : __( 'Inactive', 'ndv-reviews' ) ); ?></td>
							<td>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( self::NONCE ); ?>
									<input type="hidden" name="ndvr_id" value="<?php echo esc_attr( $criterion->id ); ?>" />
									<input type="hidden" name="ndvr_status" value="<?php echo 'active' === $criterion->status ? 'inactive' : 'active'; ?>" />
									<button type="submit" name="ndvr_criteria_do" value="toggle" class="button button-small">
										<?php echo esc_html( 'active' === $criterion->status ? __( 'Deactivate', 'ndv-reviews' ) : __( 'Activate', 'ndv-reviews' ) ); ?>
									</button>
								</form>
								<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this criterion and its scores?', 'ndv-reviews' ) ); ?>');">
									<?php wp_nonce_field( self::NONCE ); ?>
									<input type="hidden" name="ndvr_id" value="<?php echo esc_attr( $criterion->id ); ?>" />
									<button type="submit" name="ndvr_criteria_do" value="delete" class="button button-small button-link-delete">
										<?php esc_html_e( 'Delete', 'ndv-reviews' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Add a criterion', 'ndv-reviews' ); ?></h2>

			<?php if ( $at_cap ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: %d: max criteria. */
							esc_html__( 'The free version supports up to %d active criteria. Upgrade to NDV Reviews Pro for unlimited criteria and per-category templates.', 'ndv-reviews' ),
							(int) $max
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="text" name="ndvr_name" required maxlength="191" placeholder="<?php esc_attr_e( 'e.g. Comfort', 'ndv-reviews' ); ?>" />
					<button type="submit" name="ndvr_criteria_do" value="add" class="button button-primary">
						<?php esc_html_e( 'Add criterion', 'ndv-reviews' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
