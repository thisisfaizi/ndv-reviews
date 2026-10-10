<?php
/**
 * Criteria repository (CRUD + seeding + free cap).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rating criteria. Free tier caps at 3 active criteria;
 * Pro raises the cap via the `ndv-reviews/max_criteria` filter.
 */
class CriteriaRepository {

	/**
	 * Default free-tier active-criteria cap.
	 */
	const FREE_MAX = 3;

	/**
	 * The maximum number of active criteria allowed (filterable for Pro).
	 *
	 * @return int
	 */
	public function max_active() {
		/**
		 * Filter the maximum number of active criteria. Pro raises this.
		 *
		 * @param int $max Default free cap.
		 */
		return (int) apply_filters( 'ndv-reviews/max_criteria', self::FREE_MAX );
	}

	/**
	 * Get active criteria (optionally for a product, honoring overrides later).
	 *
	 * @param int|null $product_id Product id for scope resolution (future use).
	 * @return Criteria[]
	 */
	public function get_active( $product_id = null ) {
		global $wpdb;

		$table = Db::table( 'criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE status = 'active' ORDER BY position ASC, id ASC" );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = Criteria::from_row( $row );
		}

		// Enforce the active cap defensively at read time.
		return array_slice( $out, 0, $this->max_active() );
	}

	/**
	 * Get all criteria (any status).
	 *
	 * @return Criteria[]
	 */
	public function get_all() {
		global $wpdb;

		$table = Db::table( 'criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY position ASC, id ASC" );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = Criteria::from_row( $row );
		}

		return $out;
	}

	/**
	 * Get a single criterion by id.
	 *
	 * @param int $id Criterion id.
	 * @return Criteria|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = Db::table( 'criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ) );

		return $row ? Criteria::from_row( $row ) : null;
	}

	/**
	 * Count active criteria.
	 *
	 * @return int
	 */
	public function count_active() {
		global $wpdb;

		$table = Db::table( 'criteria' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'active'" );
	}

	/**
	 * Insert a criterion. Refuses to add an active one beyond the cap.
	 *
	 * @param array<string,mixed> $data name, status, position, scope, scope_id.
	 * @return int|\WP_Error New id, or WP_Error on cap/validation failure.
	 */
	public function insert( array $data ) {
		global $wpdb;

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'ndvr_criteria_name', __( 'Criterion name is required.', 'rosette-reviews' ) );
		}

		$status = ( isset( $data['status'] ) && 'inactive' === $data['status'] ) ? 'inactive' : 'active';

		if ( 'active' === $status && $this->count_active() >= $this->max_active() ) {
			return new \WP_Error(
				'ndvr_criteria_cap',
				/* translators: %d: maximum number of active criteria. */
				sprintf( __( 'The free version supports up to %d active criteria. Upgrade to Pro for unlimited criteria.', 'rosette-reviews' ), $this->max_active() )
			);
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table( 'criteria' ),
			array(
				'name'     => $name,
				'slug'     => $this->unique_slug( $name ),
				'scope'    => isset( $data['scope'] ) ? sanitize_key( $data['scope'] ) : 'global',
				'scope_id' => isset( $data['scope_id'] ) ? absint( $data['scope_id'] ) : null,
				'position' => isset( $data['position'] ) ? (int) $data['position'] : $this->count_active(),
				'status'   => $status,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : new \WP_Error( 'ndvr_criteria_db', __( 'Could not save the criterion.', 'rosette-reviews' ) );
	}

	/**
	 * Update a criterion.
	 *
	 * @param int                 $id   Criterion id.
	 * @param array<string,mixed> $data Fields to update.
	 * @return bool|\WP_Error
	 */
	public function update( $id, array $data ) {
		global $wpdb;

		$existing = $this->find( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'ndvr_criteria_missing', __( 'Criterion not found.', 'rosette-reviews' ) );
		}

		$fields = array();
		$format = array();

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );
			if ( '' === $name ) {
				return new \WP_Error( 'ndvr_criteria_name', __( 'Criterion name is required.', 'rosette-reviews' ) );
			}
			$fields['name'] = $name;
			$format[]       = '%s';
		}

		if ( isset( $data['status'] ) ) {
			$status = ( 'inactive' === $data['status'] ) ? 'inactive' : 'active';
			if ( 'inactive' === $status && 'active' === $existing->status && $this->is_last_active( $existing ) ) {
				return $this->last_active_error();
			}
			// Re-activating must respect the cap.
			if ( 'active' === $status && 'active' !== $existing->status && $this->count_active() >= $this->max_active() ) {
				return new \WP_Error(
					'ndvr_criteria_cap',
					/* translators: %d: maximum number of active criteria. */
					sprintf( __( 'The free version supports up to %d active criteria.', 'rosette-reviews' ), $this->max_active() )
				);
			}
			$fields['status'] = $status;
			$format[]         = '%s';
		}

		if ( isset( $data['position'] ) ) {
			$fields['position'] = (int) $data['position'];
			$format[]           = '%d';
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$updated = $wpdb->update( Db::table( 'criteria' ), $fields, array( 'id' => $id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $updated;
	}

	/**
	 * Delete a criterion and its recorded scores, then recompute the overall
	 * rating of every review that had a score for it (and those products).
	 *
	 * A review whose only score was this criterion keeps its last integer
	 * `rating` (RatingCache falls back to it), so it never becomes unrated.
	 *
	 * @param int $id Criterion id.
	 * @return bool|\WP_Error False if nothing was deleted; WP_Error when it is the last active criterion.
	 */
	public function delete( $id ) {
		global $wpdb;

		$id       = absint( $id );
		$existing = $this->find( $id );
		if ( ! $existing ) {
			return false;
		}
		if ( 'active' === $existing->status && $this->is_last_active( $existing ) ) {
			return $this->last_active_error();
		}

		$scores = Db::table( 'review_criteria' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$comment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT comment_id FROM `{$scores}` WHERE criteria_id = %d", $id ) );

		$wpdb->delete( $scores, array( 'criteria_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete( Db::table( 'criteria' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$ratings  = new RatingCache();
		$products = array();
		foreach ( (array) $comment_ids as $comment_id ) {
			$comment = get_comment( (int) $comment_id );
			if ( ! $comment ) {
				continue;
			}
			$ratings->recalc_review( (int) $comment_id );
			$products[ (int) $comment->comment_post_ID ] = true;
		}
		foreach ( array_keys( $products ) as $product_id ) {
			$ratings->recalc_product( $product_id );
		}

		return (bool) $deleted;
	}

	/**
	 * Whether a criterion is the only active one left (removing it would leave
	 * the review form with no rating field, so every submission would fail).
	 *
	 * @param Criteria $criterion Criterion.
	 * @return bool
	 */
	private function is_last_active( Criteria $criterion ) {
		return 'active' === $criterion->status && $this->count_active() <= 1;
	}

	/**
	 * Error returned when an action would leave no active criterion.
	 *
	 * @return \WP_Error
	 */
	private function last_active_error() {
		return new \WP_Error(
			'ndvr_criteria_last_active',
			__( 'At least one criterion must stay active: reviews need a star rating. Add or activate another criterion first.', 'rosette-reviews' )
		);
	}

	/**
	 * Seed default criteria on first install if none exist.
	 *
	 * @return void
	 */
	public function seed_defaults() {
		if ( ! empty( $this->get_all() ) ) {
			return;
		}

		$defaults = array(
			__( 'Quality', 'rosette-reviews' ),
			__( 'Value', 'rosette-reviews' ),
			__( 'Service', 'rosette-reviews' ),
		);

		$position = 0;
		foreach ( $defaults as $name ) {
			$this->insert(
				array(
					'name'     => $name,
					'status'   => 'active',
					'position' => $position++,
				)
			);
		}
	}

	/**
	 * Generate a unique slug for a criterion name.
	 *
	 * @param string $name Criterion name.
	 * @return string
	 */
	private function unique_slug( $name ) {
		global $wpdb;

		$base  = sanitize_title( $name );
		$base  = '' !== $base ? $base : 'criterion';
		$slug  = $base;
		$table = Db::table( 'criteria' );
		$i     = 2;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE slug = %s", $slug ) ) > 0 ) {
			$slug = $base . '-' . $i;
			++$i;
		}

		return $slug;
	}
}
