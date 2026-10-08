<?php
/**
 * Reviews moderation admin page (list + edit + action handling).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Moderation;

use NdvReviews\Support\Registerable;
use NdvReviews\Reviews\CriteriaRepository;
use NdvReviews\Reviews\ReviewQuery;
use NdvReviews\Reviews\RatingCache;
use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the All Reviews screen and processes approve/unapprove/spam/trash,
 * bulk actions, and full review editing (body, title, criteria, media).
 */
class Page implements Registerable {

	const CAPABILITY  = 'moderate_comments';
	const PARENT_SLUG = 'ndv-reviews';
	const PAGE_SLUG   = 'ndv-reviews-moderation';

	/**
	 * Criteria repository.
	 *
	 * @var CriteriaRepository
	 */
	private $criteria;

	/**
	 * Review query.
	 *
	 * @var ReviewQuery
	 */
	private $query;

	/**
	 * Rating cache.
	 *
	 * @var RatingCache
	 */
	private $ratings;

	/**
	 * Constructor.
	 *
	 * @param CriteriaRepository $criteria Criteria repository.
	 * @param ReviewQuery        $query    Review query.
	 * @param RatingCache        $ratings  Rating cache.
	 */
	public function __construct( CriteriaRepository $criteria, ReviewQuery $query, RatingCache $ratings ) {
		$this->criteria = $criteria;
		$this->query    = $query;
		$this->ratings  = $ratings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_filter( 'comment_edit_redirect', array( $this, 'edit_redirect' ), 10, 2 );
	}

	/**
	 * After a review is saved in the core comment editor, return users who
	 * can't open the Comments screen (no `edit_posts`) to All Reviews.
	 *
	 * @param string $location   Redirect URL.
	 * @param int    $comment_id Comment id.
	 * @return string
	 */
	public function edit_redirect( $location, $comment_id ) {
		if ( 'review' !== get_comment_type( $comment_id ) || current_user_can( 'edit_posts' ) ) {
			return $location;
		}

		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Add the All Reviews submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'All Reviews', 'ndv-reviews' ),
			__( 'All Reviews', 'ndv-reviews' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle row, bulk, and edit-save actions.
	 *
	 * @return void
	 */
	public function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Edit save (POST).
		if ( isset( $_POST['ndvr_edit_save'] ) ) {
			check_admin_referer( 'ndvr_edit_review' );
			$this->save_edit();
			return;
		}

		// Bulk actions (GET form from the list table). The bulk dropdown submits
		// `action`/`action2` with one of our keys; ignore the -1 "no action" value.
		$bulk = $this->requested_bulk_action();
		if ( $bulk ) {
			check_admin_referer( 'bulk-ndvr_reviews' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ids = isset( $_REQUEST['review'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['review'] ) ) : array();
			foreach ( $ids as $id ) {
				$this->apply_status( $id, $bulk );
			}
			$this->redirect_clean( $this->preserved_filters() );
		}

		// Single row action (GET).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ndvr_action'], $_GET['review'] ) ) {
			$action = sanitize_key( wp_unslash( $_GET['ndvr_action'] ) );
			if ( 'edit' === $action ) {
				return; // Rendered by render().
			}
			check_admin_referer( 'ndvr_review_action' );
			$this->apply_status( absint( wp_unslash( $_GET['review'] ) ), $action );
			$this->redirect_clean( $this->preserved_filters() );
		}
	}

	/**
	 * Resolve a requested bulk action (one of our keys, or '').
	 *
	 * @return string
	 */
	private function requested_bulk_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '-1';
		if ( '-1' === $action || '' === $action ) {
			$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '-1';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return in_array( $action, array( 'approve', 'unapprove', 'spam', 'trash', 'unspam', 'untrash', 'delete' ), true ) ? $action : '';
	}

	/**
	 * Current status/product/star filters to preserve across redirects.
	 *
	 * @return array<string,mixed>
	 */
	private function preserved_filters() {
		$out = array();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['status'] ) ) {
			$out['status'] = sanitize_key( wp_unslash( $_GET['status'] ) );
		}
		if ( ! empty( $_GET['product_id'] ) ) {
			$out['product_id'] = absint( $_GET['product_id'] );
		}
		if ( ! empty( $_GET['star'] ) ) {
			$out['star'] = absint( $_GET['star'] );
		}
		if ( isset( $_GET['s'] ) && '' !== $_GET['s'] ) {
			$out['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $out;
	}

	/**
	 * Apply a status change to a review.
	 *
	 * @param int    $id     Comment id.
	 * @param string $action approve|unapprove|spam|trash|unspam|untrash|delete.
	 * @return void
	 */
	private function apply_status( $id, $action ) {
		$comment = get_comment( $id );
		if ( ! $comment ) {
			return;
		}

		switch ( $action ) {
			case 'untrash':
				wp_untrash_comment( $comment );
				return;
			case 'unspam':
				wp_unspam_comment( $comment );
				return;
			case 'delete':
				// Permanent deletion only from the Trash or Spam views, as in core.
				if ( in_array( (string) $comment->comment_approved, array( 'trash', 'spam' ), true ) ) {
					wp_delete_comment( $comment, true );
				}
				return;
		}

		$map = array(
			'approve'   => 'approve',
			'unapprove' => 'hold',
			'spam'      => 'spam',
			'trash'     => 'trash',
		);

		if ( ! isset( $map[ $action ] ) ) {
			return;
		}

		wp_set_comment_status( $comment, $map[ $action ] );
	}

	/**
	 * Persist an edited review.
	 *
	 * @return void
	 */
	private function save_edit() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- caller ran check_admin_referer( 'ndvr_edit_review' ) + capability check.
		$id = isset( $_POST['review'] ) ? absint( wp_unslash( $_POST['review'] ) ) : 0;
		if ( ! $id ) {
			return;
		}

		$content = isset( $_POST['ndvr_content'] ) ? wp_kses_post( wp_unslash( $_POST['ndvr_content'] ) ) : '';
		$title   = isset( $_POST['ndvr_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ndvr_title'] ) ) : '';

		// Criteria scores. Validated before anything is written: a review that has
		// (or is given) criteria scores must keep at least one, otherwise it would
		// silently keep a stale rating. A native review with no scores and none
		// submitted keeps its WooCommerce rating untouched.
		$submitted = isset( $_POST['ndvr_criteria'] ) && is_array( $_POST['ndvr_criteria'] ) ? array_map( 'floatval', wp_unslash( $_POST['ndvr_criteria'] ) ) : array();
		$scores    = $this->valid_scores( $submitted );
		$touched   = ! empty( array_filter( $submitted ) ) || $this->has_scores( $id );
		if ( $touched && empty( $scores ) ) {
			$this->redirect_clean(
				array(
					'ndvr_action' => 'edit',
					'review'      => $id,
					'ndvr_error'  => 'rating',
				)
			);
		}

		wp_update_comment(
			array(
				'comment_ID'      => $id,
				'comment_content' => $content,
			)
		);
		update_comment_meta( $id, '_ndvr_title', $title );

		// Topic tags (comma-separated) — shared store used by the storefront pills.
		if ( isset( $_POST['ndvr_tags'] ) ) {
			$raw  = sanitize_text_field( wp_unslash( $_POST['ndvr_tags'] ) );
			$tags = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
			\NdvReviews\Reviews\ReviewTags::set( $id, $tags );
		}

		if ( $touched ) {
			$this->save_criteria_scores( $id, $scores );
		}

		// Remove selected media.
		if ( ! empty( $_POST['ndvr_remove_media'] ) && is_array( $_POST['ndvr_remove_media'] ) ) {
			$remove = array_map( 'absint', wp_unslash( $_POST['ndvr_remove_media'] ) );
			$this->remove_media( $id, $remove );
		}

		$comment = get_comment( $id );
		$this->ratings->recalc_review( $id );
		if ( $comment ) {
			$this->ratings->recalc_product( (int) $comment->comment_post_ID );
		}

		$this->redirect_clean( array( 'updated' => 1 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Keep scores for existing criteria (any status) within 0.5-5.
	 *
	 * @param array<int|string,float> $scores criteria_id => rating.
	 * @return array<int,float>
	 */
	private function valid_scores( array $scores ) {
		$known = array();
		foreach ( $this->criteria->get_all() as $criterion ) {
			$known[ (int) $criterion->id ] = true;
		}

		$out = array();
		foreach ( $scores as $criteria_id => $rating ) {
			$criteria_id = absint( $criteria_id );
			$rating      = (float) $rating;
			if ( isset( $known[ $criteria_id ] ) && $rating >= 0.5 && $rating <= 5 ) {
				$out[ $criteria_id ] = round( $rating, 2 );
			}
		}

		return $out;
	}

	/**
	 * Whether a review has any stored criteria scores.
	 *
	 * @param int $comment_id Comment id.
	 * @return bool
	 */
	private function has_scores( $comment_id ) {
		global $wpdb;
		$table = Db::table( 'review_criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE comment_id = %d", $comment_id ) ) > 0;
	}

	/**
	 * Replace a review's criteria scores.
	 *
	 * @param int              $comment_id Comment id.
	 * @param array<int,float> $scores     criteria_id => rating, from valid_scores().
	 * @return void
	 */
	private function save_criteria_scores( $comment_id, array $scores ) {
		global $wpdb;
		$table = Db::table( 'review_criteria' );

		$wpdb->delete( $table, array( 'comment_id' => $comment_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( $scores as $criteria_id => $rating ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'comment_id'  => $comment_id,
					'criteria_id' => (int) $criteria_id,
					'rating'      => $rating,
				),
				array( '%d', '%d', '%f' )
			);
		}
	}

	/**
	 * Remove photos from a review and delete each file once no review uses it.
	 *
	 * @param int   $comment_id     Comment id.
	 * @param int[] $attachment_ids Attachment ids (as posted by the edit form).
	 * @return void
	 */
	private function remove_media( $comment_id, array $attachment_ids ) {
		global $wpdb;
		$table = Db::table( 'review_media' );

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$removed       = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'attachment_id' => $attachment_id,
					'comment_id'    => $comment_id,
				),
				array( '%d', '%d' )
			);

			// Only an attachment that really belonged to this review is deleted.
			if ( $removed ) {
				Actions::delete_photo_if_unused( $attachment_id, $comment_id );
			}
		}
	}

	/**
	 * Redirect back to the clean list URL after an action.
	 *
	 * @param array<string,mixed> $extra Extra query args.
	 * @return void
	 */
	private function redirect_clean( array $extra = array() ) {
		$url = add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $extra ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the screen (list or edit).
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['ndvr_action'] ) ? sanitize_key( wp_unslash( $_GET['ndvr_action'] ) ) : '';
		if ( 'edit' === $action ) {
			$this->render_edit();
			return;
		}

		$table = new ListTable( self::PAGE_SLUG );
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reviews', 'ndv-reviews' ); ?></h1>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['updated'] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Review updated.', 'ndv-reviews' ) . '</p></div>';
			}
			$table->views();
			?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php
				// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view filter carried into the search form.
				if ( isset( $_GET['status'] ) ) {
					echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) . '" />';
				}
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				$table->search_box( __( 'Search reviews', 'ndv-reviews' ), 'ndvr-review' );
				wp_nonce_field( 'bulk-ndvr_reviews' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the edit form for one review.
	 *
	 * @return void
	 */
	private function render_edit() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id      = isset( $_GET['review'] ) ? absint( wp_unslash( $_GET['review'] ) ) : 0;
		$comment = get_comment( $id );
		if ( ! $comment ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Review not found.', 'ndv-reviews' ) . '</p></div>';
			return;
		}

		$view     = $this->query->to_view( $comment );
		$scores   = array();
		foreach ( $this->query->criteria_scores( $id ) as $cs ) {
			$scores[ $cs['name'] ] = $cs['rating'];
		}
		$criteria = $this->criteria->get_all();
		$media    = $this->query->media( $id );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Edit Review', 'ndv-reviews' ); ?></h1>
			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php if ( isset( $_GET['ndvr_error'] ) && 'rating' === sanitize_key( wp_unslash( $_GET['ndvr_error'] ) ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Not saved: a review needs at least one criteria rating.', 'ndv-reviews' ); ?></p></div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'ndvr_edit_review' ); ?>
				<input type="hidden" name="review" value="<?php echo esc_attr( $id ); ?>" />

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ndvr_title"><?php esc_html_e( 'Title', 'ndv-reviews' ); ?></label></th>
						<td><input name="ndvr_title" id="ndvr_title" type="text" class="regular-text" value="<?php echo esc_attr( $view['title'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="ndvr_tags"><?php esc_html_e( 'Topics', 'ndv-reviews' ); ?></label></th>
						<td>
							<input name="ndvr_tags" id="ndvr_tags" type="text" class="regular-text" value="<?php echo esc_attr( implode( ', ', \NdvReviews\Reviews\ReviewTags::get( $id ) ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. fit, battery, support', 'ndv-reviews' ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma-separated. Shown as filter pills on the storefront.', 'ndv-reviews' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="ndvr_content"><?php esc_html_e( 'Review', 'ndv-reviews' ); ?></label></th>
						<td><textarea name="ndvr_content" id="ndvr_content" rows="6" class="large-text"><?php echo esc_textarea( $comment->comment_content ); ?></textarea></td>
					</tr>
					<?php if ( ! empty( $criteria ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Criteria ratings', 'ndv-reviews' ); ?></th>
							<td>
								<?php foreach ( $criteria as $criterion ) : ?>
									<?php
									$val = isset( $scores[ $criterion->name ] ) ? round( (float) $scores[ $criterion->name ], 2 ) : 0.0;
									// Half-star steps, plus the stored value when it falls between them
									// (e.g. an imported 4.25), so saving never rounds a score.
									$steps = range( 0.5, 5, 0.5 );
									if ( $val > 0 && ! in_array( $val, $steps, false ) ) { // phpcs:ignore WordPress.PHP.StrictInArray.FoundNonStrictFalse -- float comparison.
										$steps[] = $val;
										sort( $steps );
									}
									?>
									<p>
										<label>
											<span style="display:inline-block;min-width:140px;"><?php echo esc_html( $criterion->name ); ?></span>
											<select name="ndvr_criteria[<?php echo esc_attr( $criterion->id ); ?>]">
												<option value="0"><?php esc_html_e( '—', 'ndv-reviews' ); ?></option>
												<?php foreach ( $steps as $step ) : ?>
													<option value="<?php echo esc_attr( $step ); ?>" <?php selected( abs( $val - $step ) < 0.001 ); ?>><?php echo esc_html( (string) $step ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
									</p>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( ! empty( $media ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Photos', 'ndv-reviews' ); ?></th>
							<td>
								<?php foreach ( $media as $m ) : ?>
									<label style="display:inline-block;margin:0 12px 12px 0;text-align:center;">
										<img src="<?php echo esc_url( $m['thumb'] ); ?>" style="width:80px;height:80px;object-fit:cover;display:block;border-radius:6px;" alt="" /><br>
										<input type="checkbox" name="ndvr_remove_media[]" value="<?php echo esc_attr( $m['id'] ); ?>" /> <?php esc_html_e( 'Remove', 'ndv-reviews' ); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<p>
					<button type="submit" name="ndvr_edit_save" value="1" class="button button-primary"><?php esc_html_e( 'Save review', 'ndv-reviews' ); ?></button>
					<a href="<?php echo esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Back', 'ndv-reviews' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}
}
