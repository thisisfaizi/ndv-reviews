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
 *   stores `yes`|`no`), plus `_ndvr_ans_<id>` for filterable fields (Pro).
 * - Free allows 2 active questions; `ndv-reviews/max_review_fields` raises it.
 * - Every read returns nothing until the V_FIELDS upgrade has run.
 */
class ReviewFieldRepository {

	const FREE_MAX      = 2;
	const TYPES         = array( 'choice', 'text', 'yesno' );
	const ANSWERS_META  = '_ndvr_answers';
	const ANSWER_PREFIX = '_ndvr_ans_';
	const TEXT_MAX      = 120;
	const OPTION_MAX    = 60;
	const LABEL_MAX     = 191;
	const CACHE_GROUP   = 'ndvr';
	const CACHE_KEY     = 'review_fields';

	/**
	 * Definitions for this request.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private $all = null;

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
	 * Every question, in position order.
	 *
	 * @return array<int,array<string,mixed>> id => field.
	 */
	public function get_all() {
		if ( ! $this->ready() ) {
			return array();
		}
		if ( null !== $this->all ) {
			return $this->all;
		}
		$cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			$this->all = $cached;
			return $this->all;
		}
		global $wpdb;
		$table = Db::table( 'review_fields' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY position ASC, id ASC" );
		$out  = array();
		foreach ( $rows as $row ) {
			$field               = self::from_row( $row );
			$out[ $field['id'] ] = $field;
		}
		wp_cache_set( self::CACHE_KEY, $out, self::CACHE_GROUP );
		$this->all = $out;

		return $out;
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
	 * One question.
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
	 * Normalise a row.
	 *
	 * @param object $row Row.
	 * @return array<string,mixed>
	 */
	private static function from_row( $row ) {
		$options = json_decode( (string) $row->options, true );

		return array(
			'id'         => (int) $row->id,
			'label'      => (string) $row->label,
			'slug'       => (string) $row->slug,
			'type'       => in_array( $row->type, self::TYPES, true ) ? (string) $row->type : 'text',
			'options'    => is_array( $options ) ? array_values( array_map( 'strval', $options ) ) : array(),
			'required'   => (bool) $row->required,
			'filterable' => (bool) $row->filterable,
			'position'   => (int) $row->position,
			'status'     => 'inactive' === $row->status ? 'inactive' : 'active',
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
		$label = array_key_exists( 'label', $data ) ? trim( sanitize_text_field( (string) $data['label'] ) ) : ( $existing ? $existing['label'] : '' );
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
				$line = trim( sanitize_text_field( (string) $line ) );
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
	 * @param array<string,mixed> $data label, type, options, required, filterable, status.
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
		foreach ( $this->get_all() as $field ) {
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
	 * Change a question.
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

		return false === $updated ? new \WP_Error( 'ndvr_field_db', __( 'Could not save the question.', 'rosette-reviews' ) ) : true;
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
	 * Delete a question (its answers stay stored but stop showing).
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		$deleted = $wpdb->delete( Db::table( 'review_fields' ), array( 'id' => absint( $id ) ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->flush();

		return (bool) $deleted;
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
	 * Clean posted or imported answers against the definitions (any status).
	 * Unknown ids, choices not among the options and empty values are dropped.
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
			$value = trim( sanitize_text_field( (string) $value ) );
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
	 * Required questions answered? Only for customer-written sources, and only
	 * when the form said it rendered the questions (`answers_present`).
	 *
	 * @param array<int,string> $clean   From sanitize().
	 * @param string            $source  Review source.
	 * @param bool              $present Whether the form carried the questions.
	 * @return true|\WP_Error
	 */
	public function validate( array $clean, $source, $present ) {
		if ( ! $present || ! Sources::is_interactive( (string) $source ) ) {
			return true;
		}
		foreach ( $this->get_active() as $id => $field ) {
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
	 * @param array<int,string> $clean      From sanitize().
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
	 * Display text of an answer.
	 *
	 * @param array<string,mixed> $field Field.
	 * @param string              $value Stored value.
	 * @return string
	 */
	public static function display_value( array $field, $value ) {
		if ( 'yesno' === $field['type'] ) {
			return 'yes' === $value ? __( 'Yes', 'rosette-reviews' ) : __( 'No', 'rosette-reviews' );
		}

		return (string) $value;
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
	 * A slug not used by another question.
	 *
	 * @param string $base Base slug.
	 * @return string
	 */
	private function unique_slug( $base ) {
		$used = wp_list_pluck( $this->get_all(), 'slug' );
		$slug = $base;
		$i    = 2;
		while ( in_array( $slug, $used, true ) ) {
			$slug = $base . '-' . $i;
			++$i;
		}

		return $slug;
	}

	/**
	 * Forget cached definitions.
	 *
	 * @return void
	 */
	public function flush() {
		$this->all = null;
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}
}
