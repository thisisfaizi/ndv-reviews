<?php
/**
 * Reviews moderation list table.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Moderation;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists reviews with status views, product/star filters, and row/bulk actions.
 */
class ListTable extends \WP_List_Table {

	/**
	 * Page slug the table lives on (for building action URLs).
	 *
	 * @var string
	 */
	private $page_slug;

	/**
	 * Constructor.
	 *
	 * @param string $page_slug Admin page slug.
	 */
	public function __construct( $page_slug ) {
		$this->page_slug = $page_slug;

		parent::__construct(
			array(
				'singular' => 'ndvr_review',
				'plural'   => 'ndvr_reviews',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Current status filter (approved|moderated|spam|trash|all).
	 *
	 * @return string
	 */
	private function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';

		return in_array( $status, array( 'all', 'approved', 'moderated', 'spam', 'trash' ), true ) ? $status : 'all';
	}

	/**
	 * Build the WP_Comment_Query status arg from our view.
	 *
	 * @param string $view View key.
	 * @return string
	 */
	private function status_arg( $view ) {
		switch ( $view ) {
			case 'approved':
				return 'approve';
			case 'moderated':
				return 'hold';
			case 'spam':
				return 'spam';
			case 'trash':
				return 'trash';
			default:
				return 'all';
		}
	}

	/**
	 * Define columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'      => '<input type="checkbox" />',
			'author'  => __( 'Author', 'ndv-reviews' ),
			'rating'  => __( 'Rating', 'ndv-reviews' ),
			'review'  => __( 'Review', 'ndv-reviews' ),
			'product' => __( 'Product', 'ndv-reviews' ),
			'media'   => __( 'Photos', 'ndv-reviews' ),
			'date'    => __( 'Date', 'ndv-reviews' ),
		);
	}

	/**
	 * Status views.
	 *
	 * @return array<string,string>
	 */
	public function get_views() {
		$base    = menu_page_url( $this->page_slug, false );
		$current = $this->current_status();
		$views   = array(
			'all'       => __( 'All', 'ndv-reviews' ),
			'moderated' => __( 'Pending', 'ndv-reviews' ),
			'approved'  => __( 'Approved', 'ndv-reviews' ),
			'spam'      => __( 'Spam', 'ndv-reviews' ),
			'trash'     => __( 'Trash', 'ndv-reviews' ),
		);

		$out = array();
		foreach ( $views as $key => $label ) {
			$url        = 'all' === $key ? $base : add_query_arg( 'status', $key, $base );
			$class      = $current === $key ? ' class="current"' : '';
			$out[ $key ] = sprintf( '<a href="%s"%s>%s</a>', esc_url( $url ), $class, esc_html( $label ) );
		}

		return $out;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	public function get_bulk_actions() {
		return array(
			'approve'   => __( 'Approve', 'ndv-reviews' ),
			'unapprove' => __( 'Unapprove', 'ndv-reviews' ),
			'spam'      => __( 'Mark as spam', 'ndv-reviews' ),
			'trash'     => __( 'Move to trash', 'ndv-reviews' ),
		);
	}

	/**
	 * Product + star filters above the table.
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$product = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$star    = isset( $_GET['star'] ) ? absint( $_GET['star'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="alignleft actions">
			<input type="number" name="product_id" value="<?php echo $product ? esc_attr( $product ) : ''; ?>" placeholder="<?php esc_attr_e( 'Product ID', 'ndv-reviews' ); ?>" style="width:110px;" />
			<select name="star">
				<option value="0"><?php esc_html_e( 'All ratings', 'ndv-reviews' ); ?></option>
				<?php for ( $s = 5; $s >= 1; $s-- ) : ?>
					<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $star, $s ); ?>><?php echo esc_html( $s ); ?> ★</option>
				<?php endfor; ?>
			</select>
			<?php submit_button( __( 'Filter', 'ndv-reviews' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Prepare items.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;
		$paged    = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$product = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$star    = isset( $_GET['star'] ) ? absint( $_GET['star'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array(
			'type__in' => array( 'review', 'comment' ),
			'post_type' => 'product',
			'status'   => $this->status_arg( $this->current_status() ),
			'number'   => $per_page,
			'offset'   => ( $paged - 1 ) * $per_page,
			'orderby'  => 'comment_date_gmt',
			'order'    => 'DESC',
		);

		if ( $product ) {
			$args['post_id'] = $product;
		}
		if ( $star >= 1 && $star <= 5 ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => 'rating',
					'value'   => $star,
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			);
		}

		$this->items = get_comments( $args );

		$count_args          = $args;
		$count_args['count'] = true;
		$count_args['number'] = 0;
		$count_args['offset'] = 0;
		$total               = (int) get_comments( $count_args );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Checkbox column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="review[]" value="%d" />', (int) $item->comment_ID );
	}

	/**
	 * Author column with row actions.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_author( $item ) {
		$id      = (int) $item->comment_ID;
		$name    = $item->comment_author ? $item->comment_author : __( 'Anonymous', 'ndv-reviews' );
		$actions = array();

		if ( '1' !== (string) $item->comment_approved ) {
			$actions['approve'] = $this->action_link( 'approve', $id, __( 'Approve', 'ndv-reviews' ) );
		} else {
			$actions['unapprove'] = $this->action_link( 'unapprove', $id, __( 'Unapprove', 'ndv-reviews' ) );
		}
		$actions['edit']  = sprintf( '<a href="%s">%s</a>', esc_url( $this->edit_url( $id ) ), esc_html__( 'Edit', 'ndv-reviews' ) );
		$actions['spam']  = $this->action_link( 'spam', $id, __( 'Spam', 'ndv-reviews' ) );
		$actions['trash'] = $this->action_link( 'trash', $id, __( 'Trash', 'ndv-reviews' ) );

		return '<strong>' . esc_html( $name ) . '</strong><br><span class="ndvr-email">' . esc_html( $item->comment_author_email ) . '</span>' . $this->row_actions( $actions );
	}

	/**
	 * Rating column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_rating( $item ) {
		$rating = (float) get_comment_meta( $item->comment_ID, '_ndvr_overall_rating', true );
		if ( $rating <= 0 ) {
			$rating = (float) get_comment_meta( $item->comment_ID, 'rating', true );
		}

		return $rating > 0 ? esc_html( number_format_i18n( $rating, 1 ) . ' / 5' ) : '&mdash;';
	}

	/**
	 * Review excerpt column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_review( $item ) {
		$title = (string) get_comment_meta( $item->comment_ID, '_ndvr_title', true );
		$out   = '';
		if ( '' !== $title ) {
			$out .= '<strong>' . esc_html( $title ) . '</strong><br>';
		}
		$out .= esc_html( wp_trim_words( $item->comment_content, 28 ) );

		return $out;
	}

	/**
	 * Product column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_product( $item ) {
		$pid   = (int) $item->comment_post_ID;
		$title = get_the_title( $pid );

		return $title ? sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $pid ) ), esc_html( $title ) ) : esc_html( '#' . $pid );
	}

	/**
	 * Media count column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_media( $item ) {
		global $wpdb;
		$table = $wpdb->prefix . NDVR_TABLE_PREFIX . 'review_media';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE comment_id = %d", (int) $item->comment_ID ) );

		return $n > 0 ? esc_html( number_format_i18n( $n ) ) : '&mdash;';
	}

	/**
	 * Date column.
	 *
	 * @param \WP_Comment $item Comment.
	 * @return string
	 */
	public function column_date( $item ) {
		return esc_html( date_i18n( get_option( 'date_format' ), strtotime( $item->comment_date ) ) );
	}

	/**
	 * Default column fallback.
	 *
	 * @param \WP_Comment $item   Comment.
	 * @param string      $column Column id.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		return '';
	}

	/**
	 * Build a nonce-protected row action link.
	 *
	 * @param string $action Action key.
	 * @param int    $id     Comment id.
	 * @param string $label  Link text.
	 * @return string
	 */
	private function action_link( $action, $id, $label ) {
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'page'      => $this->page_slug,
					'ndvr_action' => $action,
					'review'    => $id,
				),
				admin_url( 'admin.php' )
			),
			'ndvr_review_action'
		);

		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * Edit screen URL for a review.
	 *
	 * @param int $id Comment id.
	 * @return string
	 */
	private function edit_url( $id ) {
		return add_query_arg(
			array(
				'page'        => $this->page_slug,
				'ndvr_action' => 'edit',
				'review'      => $id,
			),
			admin_url( 'admin.php' )
		);
	}
}
