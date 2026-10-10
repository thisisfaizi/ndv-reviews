<?php
/**
 * Review questions on the review card and the admin edit screen (RR-11).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Support\Registerable;

defined( 'ABSPATH' ) || exit;

/**
 * - The card: `<dl class="ndvr-answers">` after the review text
 *   (`ndv-reviews/review_body_after`), before the criteria pills.
 * - Moderation → Edit: an "Answers" row (`ndv-reviews/moderation_edit_fields`)
 *   saved on `ndv-reviews/moderation_edit_save`; the edit screen already
 *   checked the nonce and `moderate_comments`.
 */
class ReviewFields implements Registerable {

	/**
	 * Definitions and answers.
	 *
	 * @var ReviewFieldRepository
	 */
	private $fields;

	/**
	 * Constructor.
	 *
	 * @param ReviewFieldRepository $fields Repository.
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
		add_action( 'ndv-reviews/review_body_after', array( $this, 'render_card' ), 10, 1 );
		add_action( 'ndv-reviews/moderation_edit_fields', array( $this, 'render_edit_row' ), 10, 1 );
		add_action( 'ndv-reviews/moderation_edit_save', array( $this, 'save_edit' ), 10, 1 );
		add_action( ReviewFieldRepository::REINDEX_HOOK, array( $this->fields, 'reindex_filter_keys' ), 10, 2 );
	}

	/**
	 * The answers on a review card.
	 *
	 * @param array<string,mixed> $review Review view-model.
	 * @return void
	 */
	public function render_card( $review ) {
		if ( ! is_array( $review ) ) {
			return;
		}
		$answers = isset( $review['answers'] ) && is_array( $review['answers'] ) ? $review['answers'] : array();

		/**
		 * Filter a review's displayed answers.
		 *
		 * @param array<int,array{field_id:int,label:string,value:string}> $answers Answers.
		 * @param array<string,mixed>                                       $review  Review view-model.
		 */
		$answers = (array) apply_filters( 'ndv-reviews/review_field_answers', $answers, $review );

		/**
		 * Filter whether a review card shows its answers.
		 *
		 * @param bool                $show   Default: true when there are answers.
		 * @param array<string,mixed> $review Review view-model.
		 */
		if ( ! $answers || ! apply_filters( 'ndv-reviews/show_answers', true, $review ) ) {
			return;
		}
		echo '<dl class="ndvr-answers">';
		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) || ! isset( $answer['label'], $answer['value'] ) ) {
				continue;
			}
			echo '<div><dt>' . esc_html( (string) $answer['label'] ) . '</dt><dd>' . esc_html( (string) $answer['value'] ) . '</dd></div>';
		}
		echo '</dl>';
	}

	/**
	 * The "Answers" row on Moderation → Edit: every active question and any
	 * other question this review answered.
	 *
	 * @param \WP_Comment $comment Review.
	 * @return void
	 */
	public function render_edit_row( $comment ) {
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		$stored = $this->fields->stored( (int) $comment->comment_ID );
		$shown  = array();
		foreach ( $this->fields->get_all() as $id => $field ) {
			if ( 'active' === $field['status'] || isset( $stored[ $id ] ) ) {
				$shown[ $id ] = $field;
			}
		}
		if ( ! $shown ) {
			return;
		}
		?>
		<tr>
			<th><?php esc_html_e( 'Answers', 'rosette-reviews' ); ?></th>
			<td>
				<input type="hidden" name="ndvr_answers_present" value="1" />
				<?php foreach ( $shown as $id => $field ) : ?>
					<?php $value = isset( $stored[ $id ] ) ? $stored[ $id ] : ''; ?>
					<p>
						<label>
							<span style="display:inline-block;min-width:140px;"><?php echo esc_html( $field['label'] ); ?></span>
							<?php if ( 'text' === $field['type'] ) : ?>
								<input type="text" name="ndvr_answers[<?php echo (int) $id; ?>]" maxlength="120" class="regular-text" value="<?php echo esc_attr( $value ); ?>" />
							<?php else : ?>
								<?php
								$options = 'yesno' === $field['type']
									? array(
										'yes' => __( 'Yes', 'rosette-reviews' ),
										'no'  => __( 'No', 'rosette-reviews' ),
									)
									: array_combine( $field['options'], $field['options'] );
								// An old answer no longer among the options stays selectable.
								if ( '' !== $value && ! isset( $options[ $value ] ) ) {
									$options[ $value ] = $value;
								}
								?>
								<select name="ndvr_answers[<?php echo (int) $id; ?>]">
									<option value=""><?php esc_html_e( '—', 'rosette-reviews' ); ?></option>
									<?php foreach ( (array) $options as $opt => $text ) : ?>
										<option value="<?php echo esc_attr( (string) $opt ); ?>" <?php selected( (string) $opt, $value ); ?>><?php echo esc_html( (string) $text ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</label>
					</p>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the edited answers (the edit screen checked nonce and capability).
	 *
	 * @param int $comment_id Review.
	 * @return void
	 */
	public function save_edit( $comment_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the edit screen ran check_admin_referer( 'ndvr_edit_review' ) and the capability check.
		if ( empty( $_POST['ndvr_answers_present'] ) ) {
			return;
		}
		$raw = isset( $_POST['ndvr_answers'] ) && is_array( $_POST['ndvr_answers'] ) ? wp_unslash( $_POST['ndvr_answers'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize() cleans each value.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// Only the rendered questions change; answers to deleted questions stay.
		$answers = $this->fields->stored( (int) $comment_id );
		$clean   = $this->fields->sanitize( (array) $raw );
		foreach ( (array) $raw as $id => $value ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}
			if ( isset( $clean[ $id ] ) ) {
				$answers[ $id ] = $clean[ $id ];
			} elseif ( isset( $answers[ $id ] ) && is_scalar( $value ) && ReviewFieldRepository::clean_text( $value ) === $answers[ $id ] ) {
				continue; // An old choice the merchant left untouched survives an options edit.
			} else {
				unset( $answers[ $id ] );
			}
		}
		$this->fields->save_answers( (int) $comment_id, $answers );
	}
}
