<?php
/**
 * Merchant-defined review questions (RR-11).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Installer;
use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Definitions (table `ndvr_review_fields`), answer sanitising, the required
 * check and answer storage.
 *
 * - Types: choice (2–6 options), text (≤ 120 characters), yesno.
 * - Answers: comment meta `_ndvr_answers` (field id => text; a choice stores
 *   the option text, so editing options never rewrites old answers; yes/no
 *   stores `yes`|`no`), plus `_ndvr_ans_<id>` for filterable fields (Pro),
 *   kept in sync when a question becomes filterable or stops being so.
 * - Deleting a question is a soft delete (status `deleted`): its id is never
 *   reused, so stored answers can't be shown under a new question.
 * - Free allows 2 active questions; `ndv-reviews/max_review_fields` raises it.
 * - Every read returns nothing until the V_FIELDS upgrade has run.
 * - Text is stored decoded (sanitize_text_field, then entities decoded) and
 *   escaped on output.
 */
class ReviewFieldRepository {

	const FREE_MAX      = 2;
	const TYPES         = array( 'choice', 'text', 'yesno' );
	const ANSWERS_META  = '_ndvr_answers';
	const ANSWER_PREFIX = '_ndvr_ans_';
	const TEXT_MAX      = 120;
	const OPTION_MAX    = 60;
	const LABEL_MAX     = 191;
	const SLUG_MAX      = 180;
	const CACHE_GROUP   = 'ndvr';
	const CACHE_KEY     = 'review_fields';
	const REINDEX_HOOK  = 'ndvr_review_field_reindex';
	const REINDEX_BATCH = 500;

	/**
	 * Every row for this request, deleted ones included.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private $rows = null;

	/**
	 * Whether the table exists (upgrade done).
	 *
	 * @return bool
	 */
	public function ready() {
		return Installer::is_current( Installer::V_FIELDS );
	}

	/**
	 * Maximum active questions.
	 *
	 * @return int
	 */
	public function max_active() {
		/**
		 * Filter the maximum number of active review questions (Pro raises it).
		 *
		 * @param int $max Default 2.
		 */
		return max( 0, (int) apply_filters( 'ndv-reviews/max_review_fields', self::FREE_MAX ) );
	}

	/**
	 * Every row, deleted ones included, in position order.
	 *
	 * @return array<int,array<string,mixed>> id => field.
	 */
	public function defined() {
		if ( ! $this->ready() ) {
			return array();
		}
		if ( null !== $this->rows ) {
			return $this->rows;
		}
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			$this->rows = $cached;
			return $this->rows;
		}
		global $wpdb;
		$table = Db::table( 'review_fields' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY position ASC, id ASC" );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$field               = self::from_row( $row );
			$out[ $field['id'] ] = $field;
		}
		// A failed read (missing table after a failed repair) is never cached.
		if ( null !== $rows && '' === (string) $wpdb->last_error ) {
			wp_cache_set( self::CACHE_KEY, $out, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}
		$this->rows = $out;

		return $out;
	}

	/**
	 * Every question that exists (not deleted), in position order.
	 *
	 * @return array<int,array<string,mixed>> id => field.
	 */
	public function get_all() {
		return array_filter(
			$this->defined(),
			static function ( $field ) {
				return 'deleted' !== $field['status'];
			}
		);
	}

	/**
	 * Active questions (capped), in position order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_active() {
		$active = array_filter(
			$this->get_all(),
			static function ( $field ) {
				return 'active' === $field['status'];
			}
		);

		return array_slice( $active, 0, $this->max_active(), true );
	}

	/**
	 * One question (not deleted).
	 *
	 * @param int $id Id.
	 * @return array<string,mixed>|null
	 */
	public function find( $id ) {
		$all = $this->get_all();

		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/**
	 * Active question count (uncapped).
	 *
	 * @return int
	 */
	public function count_active() {
		$n = 0;
		foreach ( $this->get_all() as $field ) {
			$n += 'active' === $field['status'] ? 1 : 0;
		}

		return $n;
	}

	/**
	 * Clean free text: tags stripped, then decoded (output is escaped).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function clean_text( $value ) {
		return trim( wp_specialchars_decode( sanitize_text_field( (string) $value ), ENT_QUOTES ) );
	}

	/**
	 * Normalise a row.
	 *
	 * @param object $row Row.
	 * @return array<string,mixed>
	 */
	private static function from_row( $row ) {
		$options = json_decode( (string) $row->options, true );
		$status  = in_array( $row->status, array( 'active', 'inactive', 'deleted' ), true ) ? (string) $row->status : 'active';

		return array(
			'id'         => (int) $row->id,
			'label'      => (string) $row->label,
			'slug'       => (string) $row->slug,
			'type'       => in_array( $row->type, self::TYPES, true ) ? (string) $row->type : 'text',
			'options'    => is_array( $options ) ? array_values( array_map( 'strval', $options ) ) : array(),
			'required'   => (bool) $row->required,
			'filterable' => (bool) $row->filterable,
			'position'   => (int) $row->position,
			'status'     => $status,
		);
	}

	/**
	 * Clean and check a definition.
	 *
	 * @param array<string,mixed>      $data     Raw data.
	 * @param array<string,mixed>|null $existing Current definition (update).
	 * @return array<string,mixed>|\WP_Error
	 */
	private function clean_definition( array $data, $existing = null ) {
		$label = array_key_exists( 'label', $data ) ? self::clean_text( $data['label'] ) : ( $existing ? $existing['label'] : '' );
		if ( '' === $label || mb_strlen( $label ) > self::LABEL_MAX ) {
			return new \WP_Error( 'ndvr_field_label', __( 'Give the question a text of up to 191 characters.', 'rosette-reviews' ) );
		}
		$type = array_key_exists( 'type', $data ) ? sanitize_key( (string) $data['type'] ) : ( $existing ? $existing['type'] : 'text' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'ndvr_field_type', __( 'Choose a question type.', 'rosette-reviews' ) );
		}
		$options = array();
		if ( 'choice' === $type ) {
			$raw = array_key_exists( 'options', $data ) ? $data['options'] : ( $existing ? $existing['options'] : array() );
			$raw = is_array( $raw ) ? $raw : preg_split( '/\R/', (string) $raw );
			foreach ( (array) $raw as $line ) {
				$line = self::clean_text( $line );
				if ( '' !== $line && ! in_array( $line, $options, true ) ) {
					if ( mb_strlen( $line ) > self::OPTION_MAX ) {
						return new \WP_Error( 'ndvr_field_option', __( 'Each option can be up to 60 characters.', 'rosette-reviews' ) );
					}
					$options[] = $line;
				}
			}
			if ( count( $options ) < 2 || count( $options ) > 6 ) {
				return new \WP_Error( 'ndvr_field_options', __( 'A choice question needs 2 to 6 options, one per line.', 'rosette-reviews' ) );
			}
		}

		return array(
			'label'      => $label,
			'type'       => $type,
			'options'    => $options,
			'required'   => array_key_exists( 'required', $data ) ? ! empty( $data['required'] ) : ( $existing ? $existing['required'] : false ),
			'filterable' => array_key_exists( 'filterable', $data ) ? ! empty( $data['filterable'] ) : ( $existing ? $existing['filterable'] : false ),
		);
	}

	/**
	 * The cap notice.
	 *
	 * @return \WP_Error
	 */
	private function cap_error() {
		return new \WP_Error(
			'ndvr_field_cap',
			sprintf(
				/* translators: %s: maximum number of active questions. */
				_n( 'You can have %s active question.', 'You can have %s active questions.', $this->max_active(), 'rosette-reviews' ),
				number_format_i18n( $this->max_active() )
			)
		);
	}

	/**
	 * Add a question.
	 *
	 * @param array<string,mixed> $data label, type, options, required, filterable, status, slug.
	 * @return int|\WP_Error New id.
	 */
	public function insert( array $data ) {
		if ( ! $this->ready() ) {
			return new \WP_Error( 'ndvr_not_ready', __( 'The database update has not finished yet.', 'rosette-reviews' ) );
		}
		$clean = $this->clean_definition( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$status = isset( $data['status'] ) && 'inactive' === $data['status'] ? 'inactive' : 'active';
		if ( 'active' === $status && $this->count_active() >= $this->max_active() ) {
			return $this->cap_error();
		}
		$slug = isset( $data['slug'] ) && '' !== sanitize_title( (string) $data['slug'] ) ? sanitize_title( (string) $data['slug'] ) : sanitize_title( $clean['label'] );

		global $wpdb;
		$max = 0;
		foreach ( $this->defined() as $field ) {
			$max = max( $max, $field['position'] + 1 );
		}
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table( 'review_fields' ),
			array(
				'label'      => $clean['label'],
				'slug'       => $this->unique_slug( '' !== $slug ? $slug : 'question' ),
				'type'       => $clean['type'],
				'options'    => wp_json_encode( $clean['options'] ),
				'required'   => $clean['required'] ? 1 : 0,
				'filterable' => $clean['filterable'] ? 1 : 0,
				'position'   => $max,
				'status'     => $status,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
		$this->flush();

		return $inserted ? (int) $wpdb->insert_id : new \WP_Error( 'ndvr_field_db', __( 'Could not save the question.', 'rosette-reviews' ) );
	}

	/**
	 * Change a question. Turning `filterable` on or off re-indexes the
	 * per-question filter keys of stored reviews.
	 *
	 * @param int                 $id   Id.
	 * @param array<string,mixed> $data Fields to change (label, type, options, required, filterable, status, position).
	 * @return true|\WP_Error
	 */
	public function update( $id, array $data ) {
		$existing = $this->find( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ndvr_field_missing', __( 'That question no longer exists.', 'rosette-reviews' ) );
		}
		$clean = $this->clean_definition( $data, $existing );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$fields = array(
			'label'      => $clean['label'],
			'type'       => $clean['type'],
			'options'    => wp_json_encode( $clean['options'] ),
			'required'   => $clean['required'] ? 1 : 0,
			'filterable' => $clean['filterable'] ? 1 : 0,
		);
		$format = array( '%s', '%s', '%s', '%d', '%d' );
		if ( isset( $data['status'] ) ) {
			$status = 'inactive' === $data['status'] ? 'inactive' : 'active';
			if ( 'active' === $status && 'active' !== $existing['status'] && $this->count_active() >= $this->max_active() ) {
				return $this->cap_error();
			}
			$fields['status'] = $status;
			$format[]         = '%s';
		}
		if ( isset( $data['position'] ) ) {
			$fields['position'] = (int) $data['position'];
			$format[]           = '%d';
		}

		global $wpdb;
		$updated = $wpdb->update( Db::table( 'review_fields' ), $fields, array( 'id' => (int) $id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->flush();
		if ( false === $updated ) {
			return new \WP_Error( 'ndvr_field_db', __( 'Could not save the question.', 'rosette-reviews' ) );
		}
		if ( $clean['filterable'] !== $existing['filterable'] ) {
			$this->reindex_filter_keys( (int) $id );
		}

		return true;
	}

	/**
	 * How many reviews have an answer to a question (for the type-change
	 * warning).
	 *
	 * @param int $id Question id.
	 * @return int
	 */
	public function count_answers( $id ) {
		global $wpdb;
		// Serialized `_ndvr_answers` has the id as an integer key: "i:{id};s:".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_value LIKE %s", self::ANSWERS_META, '%' . $wpdb->esc_like( 'i:' . absint( $id ) . ';s:' ) . '%' ) );
	}

	/**
	 * Bring `_ndvr_ans_<id>` in line with the question: write it on every
	 * review that answered it when the question is filterable, delete it
	 * otherwise. Large stores continue in Action Scheduler batches.
	 *
	 * @param int $id       Question id.
	 * @param int $after_id Continue after this comment id (batches).
	 * @return void
	 */
	public function reindex_filter_keys( $id, $after_id = 0 ) {
		global $wpdb;
		$id    = absint( $id );
		$field = $this->find( $id );
		$key   = self::ANSWER_PREFIX . $id;
		if ( ! $field || ! $field['filterable'] ) {
			$wpdb->delete( $wpdb->commentmeta, array( 'meta_key' => $key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			self::flush_comment_meta();
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT comment_id, meta_value FROM {$wpdb->commentmeta} WHERE meta_key = %s AND comment_id > %d ORDER BY comment_id ASC LIMIT %d", self::ANSWERS_META, absint( $after_id ), self::REINDEX_BATCH ) );
		$last = 0;
		foreach ( $rows as $row ) {
			$last   = (int) $row->comment_id;
			$stored = maybe_unserialize( $row->meta_value );
			if ( is_array( $stored ) && isset( $stored[ $id ] ) && '' !== (string) $stored[ $id ] ) {
				update_comment_meta( $last, $key, (string) $stored[ $id ] );
			} else {
				delete_comment_meta( $last, $key );
			}
		}
		if ( count( $rows ) === self::REINDEX_BATCH ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::REINDEX_HOOK, array( $id, $last ), 'ndv-reviews' );
			} else {
				$this->reindex_filter_keys( $id, $last );
			}
		}
	}

	/**
	 * Move a question one place up or down.
	 *
	 * @param int    $id        Id.
	 * @param string $direction up|down.
	 * @return bool
	 */
	public function move( $id, $direction ) {
		$ids = array_keys( $this->get_all() );
		$at  = array_search( (int) $id, $ids, true );
		if ( false === $at ) {
			return false;
		}
		$to = 'up' === $direction ? $at - 1 : $at + 1;
		if ( $to < 0 || $to >= count( $ids ) ) {
			return false;
		}
		$swap       = $ids[ $to ];
		$ids[ $to ] = (int) $id;
		$ids[ $at ] = $swap;
		global $wpdb;
		foreach ( $ids as $position => $field_id ) {
			$wpdb->update( Db::table( 'review_fields' ), array( 'position' => $position ), array( 'id' => $field_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$this->flush();

		return true;
	}

	/**
	 * Delete a question: a soft delete, so its id is never reused. Its answers
	 * stay stored (they stop showing); its filter keys are removed.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $this->find( $id ) ) {
			return false;
		}
		$done = $wpdb->update( Db::table( 'review_fields' ), array( 'status' => 'deleted' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->commentmeta, array( 'meta_key' => self::ANSWER_PREFIX . $id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		self::flush_comment_meta();
		$this->flush();

		return false !== $done;
	}

	/**
	 * An inactive Short text question for a CSV column `q_<slug>` (created
	 * when missing), so imported answers are never lost.
	 *
	 * @param string $slug Column slug.
	 * @return int Field id, or 0 when the slug is empty.
	 */
	public function ensure_imported( $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug || ! $this->ready() ) {
			return 0;
		}
		foreach ( $this->get_all() as $field ) {
			if ( $field['slug'] === $slug ) {
				return $field['id'];
			}
		}
		$id = $this->insert(
			array(
				'label'  => ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) ),
				'slug'   => $slug,
				'type'   => 'text',
				'status' => 'inactive',
			)
		);

		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Clean posted or imported answers against the definitions (any status
	 * but deleted). Unknown ids, choices not among the options and empty
	 * values are dropped.
	 *
	 * @param array<int|string,mixed> $raw field id => value.
	 * @return array<int,string>
	 */
	public function sanitize( array $raw ) {
		$all = $this->get_all();
		$out = array();
		foreach ( $raw as $id => $value ) {
			$id = absint( $id );
			if ( ! isset( $all[ $id ] ) || ! is_scalar( $value ) ) {
				continue;
			}
			$field = $all[ $id ];
			$value = self::clean_text( $value );
			if ( '' === $value ) {
				continue;
			}
			if ( 'choice' === $field['type'] ) {
				if ( in_array( $value, $field['options'], true ) ) {
					$out[ $id ] = $value;
				}
			} elseif ( 'yesno' === $field['type'] ) {
				$value = strtolower( $value );
				if ( in_array( $value, array( 'yes', 'no' ), true ) ) {
					$out[ $id ] = $value;
				}
			} else {
				$out[ $id ] = mb_substr( $value, 0, self::TEXT_MAX );
			}
		}

		return $out;
	}

	/**
	 * Read the forms' `ndvr_answers_present` marker: the ids of the questions
	 * the form rendered ("12,15"), or true for an old marker ("1": all).
	 *
	 * @param mixed $raw Posted marker.
	 * @return int[]|bool False when absent.
	 */
	public static function parse_present( $raw ) {
		if ( ! is_scalar( $raw ) || '' === (string) $raw ) {
			return false;
		}
		$raw = (string) $raw;
		if ( '1' === $raw ) {
			return true;
		}
		$ids = array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );

		return $ids ? $ids : false;
	}

	/**
	 * Required questions answered? Only for customer-written sources, and only
	 * for questions the form rendered (a cached page that predates a newly
	 * required question isn't blocked by it).
	 *
	 * @param array<int,string> $clean   From sanitize().
	 * @param string            $source  Review source.
	 * @param int[]|bool        $present Rendered ids, true for "all", false for none.
	 * @return true|\WP_Error
	 */
	public function validate( array $clean, $source, $present ) {
		if ( ! $present || ! Sources::is_interactive( (string) $source ) ) {
			return true;
		}
		$rendered = is_array( $present ) ? array_map( 'absint', $present ) : null;
		foreach ( $this->get_active() as $id => $field ) {
			if ( null !== $rendered && ! in_array( (int) $id, $rendered, true ) ) {
				continue;
			}
			if ( $field['required'] && ! isset( $clean[ $id ] ) ) {
				return new \WP_Error(
					'ndvr_missing_answer',
					/* translators: %s: question text. */
					sprintf( __( '%s needs an answer.', 'rosette-reviews' ), $field['label'] )
				);
			}
		}

		return true;
	}

	/**
	 * Store a review's answers (replacing earlier ones).
	 *
	 * @param int               $comment_id Review.
	 * @param array<int,string> $clean      From sanitize() (may include answers to deleted questions).
	 * @return void
	 */
	public function save_answers( $comment_id, array $clean ) {
		global $wpdb;
		$comment_id = absint( $comment_id );
		if ( ! $comment_id ) {
			return;
		}
		if ( $clean ) {
			update_comment_meta( $comment_id, self::ANSWERS_META, $clean );
		} else {
			delete_comment_meta( $comment_id, self::ANSWERS_META );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- per-field keys for Pro's filters.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id = %d AND meta_key LIKE %s", $comment_id, $wpdb->esc_like( self::ANSWER_PREFIX ) . '%' ) );
		wp_cache_delete( $comment_id, 'comment_meta' );
		$all = $this->get_all();
		foreach ( $clean as $id => $value ) {
			if ( isset( $all[ $id ] ) && $all[ $id ]['filterable'] ) {
				update_comment_meta( $comment_id, self::ANSWER_PREFIX . $id, $value );
			}
		}
	}

	/**
	 * A review's stored answers (field id => value), raw.
	 *
	 * @param int $comment_id Review.
	 * @return array<int,string>
	 */
	public function stored( $comment_id ) {
		$stored = get_comment_meta( absint( $comment_id ), self::ANSWERS_META, true );

		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}

	/**
	 * Display text of an answer: a yes/no question's `yes`/`no` in the reader's
	 * language; anything else exactly as stored (a question retyped to Yes/No
	 * keeps showing its old text answers).
	 *
	 * @param array<string,mixed> $field Field.
	 * @param string              $value Stored value.
	 * @return string
	 */
	public static function display_value( array $field, $value ) {
		$value = (string) $value;
		if ( 'yes' === $value ) {
			return __( 'Yes', 'rosette-reviews' );
		}
		if ( 'no' === $value ) {
			return __( 'No', 'rosette-reviews' );
		}

		return $value;
	}

	/**
	 * Answers for display: questions that still exist (active or not), in
	 * position order.
	 *
	 * @param int $comment_id Review.
	 * @return array<int,array{field_id:int,label:string,value:string}>
	 */
	public function answers_for_view( $comment_id ) {
		$stored = $this->stored( $comment_id );
		if ( ! $stored ) {
			return array();
		}
		$out = array();
		foreach ( $this->get_all() as $id => $field ) {
			if ( isset( $stored[ $id ] ) && '' !== $stored[ $id ] ) {
				$out[] = array(
					'field_id' => $id,
					'label'    => $field['label'],
					'value'    => self::display_value( $field, $stored[ $id ] ),
				);
			}
		}

		return $out;
	}

	/**
	 * Every stored answer for a privacy export, deleted questions included.
	 *
	 * @param int $comment_id Review.
	 * @return array<int,array{label:string,value:string}>
	 */
	public function answers_for_export( $comment_id ) {
		$defined = $this->defined();
		$out     = array();
		foreach ( $this->stored( $comment_id ) as $id => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$field = isset( $defined[ $id ] ) ? $defined[ $id ] : null;
			$out[] = array(
				'label' => $field && 'deleted' !== $field['status']
					? $field['label']
					/* translators: 1: question text (or its id), shown for a deleted question. */
					: sprintf( __( '%s (deleted question)', 'rosette-reviews' ), $field ? $field['label'] : '#' . (int) $id ),
				'value' => $field ? self::display_value( $field, $value ) : $value,
			);
		}

		return $out;
	}

	/**
	 * A slug not used by any question (deleted ones included), short enough
	 * for the column.
	 *
	 * @param string $base Base slug.
	 * @return string
	 */
	private function unique_slug( $base ) {
		// sanitize_title() percent-encodes non-Latin text: cut at a %xx boundary.
		if ( strlen( $base ) > self::SLUG_MAX ) {
			$base = rtrim( (string) preg_replace( '/%[0-9a-f]?$/i', '', substr( $base, 0, self::SLUG_MAX ) ), '-' );
		}
		$used = wp_list_pluck( $this->defined(), 'slug' );
		$slug = $base;
		$i    = 2;
		while ( in_array( $slug, $used, true ) ) {
			$slug = $base . '-' . $i;
			++$i;
		}

		return $slug;
	}

	/**
	 * Drop cached comment meta after a bulk meta change (WP 6.1+ object caches
	 * that can flush one group; elsewhere the cache is per request).
	 *
	 * @return void
	 */
	private static function flush_comment_meta() {
		if ( function_exists( 'wp_cache_flush_group' ) && ( ! function_exists( 'wp_cache_supports' ) || wp_cache_supports( 'flush_group' ) ) ) {
			wp_cache_flush_group( 'comment_meta' );
		}
	}

	/**
	 * Forget cached definitions.
	 *
	 * @return void
	 */
	public function flush() {
		$this->rows = null;
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}
}
