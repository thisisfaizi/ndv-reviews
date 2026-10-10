<?php
/**
 * Admin screen: review questions (RR-11).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Admin;

use NdvReviews\Reviews\ReviewFieldRepository;
use NdvReviews\Support\Caps;
use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews → Review Questions: add, edit, reorder, activate and delete the
 * questions shown on every customer review form.
 */
class QuestionsPage implements Registerable {

	const PAGE_SLUG = 'ndv-reviews-questions';
	const NONCE     = 'ndvr_review_fields';

	/**
	 * Questions.
	 *
	 * @var ReviewFieldRepository
	 */
	private $fields;

	/**
	 * Notices for this request.
	 *
	 * @var array<int,array{type:string,message:string}>
	 */
	private $notices = array();

	/**
	 * Constructor.
	 *
	 * @param ReviewFieldRepository $fields Questions.
	 */
	public function __construct( ReviewFieldRepository $fields ) {
		$this->fields = $fields;
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
	 * Add the submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			CriteriaPage::MENU_SLUG,
			__( 'Review Questions', 'rosette-reviews' ),
			__( 'Review Questions', 'rosette-reviews' ),
			Caps::manage( 'questions' ),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle the screen's POSTs: nonce, then capability, then work.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['ndvr_fields_do'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing only; the nonce is checked next.
			return;
		}
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( Caps::manage( 'questions' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to manage review questions.', 'rosette-reviews' ), 403 );
		}

		$do = sanitize_key( wp_unslash( $_POST['ndvr_fields_do'] ) );
		$id = isset( $_POST['ndvr_id'] ) ? absint( wp_unslash( $_POST['ndvr_id'] ) ) : 0;

		if ( 'save' === $do ) {
			$data   = array(
				'label'    => isset( $_POST['ndvr_label'] ) ? sanitize_text_field( wp_unslash( $_POST['ndvr_label'] ) ) : '',
				'type'     => isset( $_POST['ndvr_type'] ) ? sanitize_key( wp_unslash( $_POST['ndvr_type'] ) ) : 'text',
				'options'  => isset( $_POST['ndvr_options'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ndvr_options'] ) ) : '',
				'required' => ! empty( $_POST['ndvr_required'] ),
			);
			$result = $id ? $this->fields->update( $id, $data ) : $this->fields->insert( $data );
			if ( ! is_wp_error( $result ) ) {
				$saved = $id ? $id : (int) $result;

				/**
				 * Fires after a review question was saved (Pro saves its own fields).
				 *
				 * @param int   $id   Question id.
				 * @param array $post The raw posted data (unslashed).
				 */
				do_action( 'ndv-reviews/review_fields_saved', $saved, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- listeners sanitise what they read.
			}
			$this->push( $result, $id ? __( 'Question saved.', 'rosette-reviews' ) : __( 'Question added.', 'rosette-reviews' ) );
		} elseif ( 'delete' === $do ) {
			$this->push( $this->fields->delete( $id ) ? true : new \WP_Error( 'ndvr_field_missing', __( 'That question no longer exists.', 'rosette-reviews' ) ), __( 'Question deleted.', 'rosette-reviews' ) );
		} elseif ( 'toggle' === $do ) {
			$status = isset( $_POST['ndvr_status'] ) && 'active' === $_POST['ndvr_status'] ? 'active' : 'inactive';
			$this->push( $this->fields->update( $id, array( 'status' => $status ) ), __( 'Question updated.', 'rosette-reviews' ) );
		} elseif ( 'up' === $do || 'down' === $do ) {
			$this->fields->move( $id, $do );
		}
	}

	/**
	 * Record a notice.
	 *
	 * @param mixed  $result  Result.
	 * @param string $success Success text.
	 * @return void
	 */
	private function push( $result, $success ) {
		$this->notices[] = is_wp_error( $result )
			? array(
				'type'    => 'error',
				'message' => $result->get_error_message(),
			)
			: array(
				'type'    => 'success',
				'message' => $success,
			);
	}

	/**
	 * Type labels.
	 *
	 * @return array<string,string>
	 */
	private static function types() {
		return array(
			'choice' => __( 'Choice', 'rosette-reviews' ),
			'text'   => __( 'Short text', 'rosette-reviews' ),
			'yesno'  => __( 'Yes or no', 'rosette-reviews' ),
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Caps::manage( 'questions' ) ) ) {
			return;
		}
		$all    = $this->fields->get_all();
		$active = $this->fields->count_active();
		$max    = $this->fields->max_active();
		$types  = self::types();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which question to prefill.
		$edit_id = isset( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0;
		$editing = $edit_id ? $this->fields->find( $edit_id ) : null;
		$ids     = array_keys( $all );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Review Questions', 'rosette-reviews' ); ?></h1>
			<?php foreach ( $this->notices as $notice ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endforeach; ?>
			<?php if ( ! $this->fields->ready() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Rosette Reviews is finishing a database update. Questions are available once it is done.', 'rosette-reviews' ); ?></p></div>
			<?php endif; ?>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php esc_html_e( 'Questions', 'rosette-reviews' ); ?></h2></div>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: active questions, 2: maximum. */
							__( 'Customers see active questions on every review form, after their review text. %1$s of %2$s questions active.', 'rosette-reviews' ),
							number_format_i18n( $active ),
							number_format_i18n( $max )
						)
					);
					?>
				</p>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Question', 'rosette-reviews' ); ?></th>
						<th><?php esc_html_e( 'Type', 'rosette-reviews' ); ?></th>
						<th><?php esc_html_e( 'Options', 'rosette-reviews' ); ?></th>
						<th><?php esc_html_e( 'Required', 'rosette-reviews' ); ?></th>
						<th><?php esc_html_e( 'Active', 'rosette-reviews' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'rosette-reviews' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( ! $all ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No questions yet.', 'rosette-reviews' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $all as $id => $field ) : ?>
						<?php $at = array_search( $id, $ids, true ); ?>
						<tr>
							<td><?php echo esc_html( $field['label'] ); ?></td>
							<td><?php echo esc_html( $types[ $field['type'] ] ?? $field['type'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', $field['options'] ) ); ?></td>
							<td><?php echo esc_html( $field['required'] ? __( 'Yes', 'rosette-reviews' ) : __( 'No', 'rosette-reviews' ) ); ?></td>
							<td><?php echo esc_html( 'active' === $field['status'] ? __( 'Yes', 'rosette-reviews' ) : __( 'No', 'rosette-reviews' ) ); ?></td>
							<td>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( self::NONCE ); ?>
									<input type="hidden" name="ndvr_id" value="<?php echo (int) $id; ?>" />
									<button type="submit" class="button button-small" name="ndvr_fields_do" value="up" <?php disabled( 0 === $at ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: question. */ __( 'Move up: %s', 'rosette-reviews' ), $field['label'] ) ); ?>">↑</button>
									<button type="submit" class="button button-small" name="ndvr_fields_do" value="down" <?php disabled( count( $ids ) - 1 === $at ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: question. */ __( 'Move down: %s', 'rosette-reviews' ), $field['label'] ) ); ?>">↓</button>
								</form>
								<a class="button button-small" href="
								<?php
								echo esc_url(
									add_query_arg(
										array(
											'page' => self::PAGE_SLUG,
											'edit' => $id,
										),
										admin_url( 'admin.php' )
									)
								);
								?>
																		"><?php esc_html_e( 'Edit', 'rosette-reviews' ); ?></a>
								<form method="post" style="display:inline;">
									<?php wp_nonce_field( self::NONCE ); ?>
									<input type="hidden" name="ndvr_id" value="<?php echo (int) $id; ?>" />
									<input type="hidden" name="ndvr_status" value="<?php echo 'active' === $field['status'] ? 'inactive' : 'active'; ?>" />
									<button type="submit" class="button button-small" name="ndvr_fields_do" value="toggle"><?php echo esc_html( 'active' === $field['status'] ? __( 'Deactivate', 'rosette-reviews' ) : __( 'Activate', 'rosette-reviews' ) ); ?></button>
								</form>
								<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this question? Its answers stop showing on reviews.', 'rosette-reviews' ) ); ?>');">
									<?php wp_nonce_field( self::NONCE ); ?>
									<input type="hidden" name="ndvr_id" value="<?php echo (int) $id; ?>" />
									<button type="submit" class="button button-small button-link-delete" name="ndvr_fields_do" value="delete"><?php esc_html_e( 'Delete', 'rosette-reviews' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="ndvr-card">
				<div class="ndvr-card-header"><h2><?php echo esc_html( $editing ? __( 'Edit question', 'rosette-reviews' ) : __( 'Add a question', 'rosette-reviews' ) ); ?></h2></div>
				<?php if ( ! $editing && $active >= $max ) : ?>
					<div class="notice notice-info inline"><p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: active questions, 2: maximum. */
								__( '%1$s of %2$s questions active. Deactivate one to add another.', 'rosette-reviews' ),
								number_format_i18n( $active ),
								number_format_i18n( $max )
							)
						);
						?>
					</p></div>
				<?php else : ?>
					<?php
					$field = $editing ? $editing : array(
						'id'       => 0,
						'label'    => '',
						'type'     => 'choice',
						'options'  => array(),
						'required' => false,
					);
					?>
					<form method="post">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="ndvr_id" value="<?php echo (int) $field['id']; ?>" />
						<p><label for="ndvr-label"><?php esc_html_e( 'Question', 'rosette-reviews' ); ?></label><br>
							<input type="text" id="ndvr-label" name="ndvr_label" class="regular-text" maxlength="191" required value="<?php echo esc_attr( $field['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. How does it fit?', 'rosette-reviews' ); ?>" /></p>
						<p><label for="ndvr-type"><?php esc_html_e( 'Type', 'rosette-reviews' ); ?></label><br>
							<select id="ndvr-type" name="ndvr_type">
								<?php foreach ( $types as $key => $text ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $field['type'], $key ); ?>><?php echo esc_html( $text ); ?></option>
								<?php endforeach; ?>
							</select></p>
						<p><label for="ndvr-options"><?php esc_html_e( 'Options (one per line, for Choice)', 'rosette-reviews' ); ?></label><br>
							<textarea id="ndvr-options" name="ndvr_options" rows="5" class="regular-text"><?php echo esc_textarea( implode( "\n", (array) $field['options'] ) ); ?></textarea></p>
						<p><label><input type="checkbox" name="ndvr_required" value="1" <?php checked( ! empty( $field['required'] ) ); ?> /> <?php esc_html_e( 'Required', 'rosette-reviews' ); ?></label></p>
						<?php
						/**
						 * Fires inside the question form (Pro adds "Show as filter").
						 *
						 * @param array<string,mixed> $field The question being edited (id 0 when new).
						 */
						do_action( 'ndv-reviews/review_field_form_after', $field );
						?>
						<p>
							<button type="submit" class="button button-primary" name="ndvr_fields_do" value="save"><?php echo esc_html( $editing ? __( 'Save question', 'rosette-reviews' ) : __( 'Add question', 'rosette-reviews' ) ); ?></button>
							<?php if ( $editing ) : ?>
								<a class="button" href="<?php echo esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'rosette-reviews' ); ?></a>
							<?php endif; ?>
						</p>
					</form>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Answers show on the review card, can be edited on the review\'s Edit screen, and are exported and imported as CSV columns named q_ followed by the question\'s slug.', 'rosette-reviews' ); ?></p>
			</div>
		</div>
		<?php
	}
}
