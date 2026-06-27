<?php
/**
 * Review-request queue + log persistence.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for the ndvr_requests table — the durable record of intent behind every
 * scheduled reminder, so a missed cron can be recovered.
 */
class RequestRepository {

	/**
	 * Insert a scheduled request.
	 *
	 * @param array<string,mixed> $data order_id, customer_id, email, channel, scheduled_at.
	 * @return int Request id (0 on failure).
	 */
	public function insert( array $data ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table( 'requests' ),
			array(
				'order_id'     => absint( $data['order_id'] ?? 0 ),
				'customer_id'  => isset( $data['customer_id'] ) ? absint( $data['customer_id'] ) : null,
				'email'        => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : null,
				'phone'        => null,
				'channel'      => 'email',
				'step'         => 1,
				'status'       => 'scheduled',
				'scheduled_at' => isset( $data['scheduled_at'] ) ? $data['scheduled_at'] : current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Find a request by id.
	 *
	 * @param int $id Request id.
	 * @return object|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", absint( $id ) ) );
	}

	/**
	 * Whether an (email) request already exists for an order — idempotency guard.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public function exists_for_order( $order_id ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE order_id = %d AND channel = 'email'", absint( $order_id ) ) ) > 0;
	}

	/**
	 * Update a request's status (and optionally error / sent time).
	 *
	 * @param int    $id     Request id.
	 * @param string $status scheduled|sent|failed|cancelled|converted.
	 * @param string $error  Optional error text.
	 * @return void
	 */
	public function set_status( $id, $status, $error = '' ) {
		global $wpdb;

		$fields = array( 'status' => sanitize_key( $status ) );
		$format = array( '%s' );

		if ( 'sent' === $status ) {
			$fields['sent_at'] = current_time( 'mysql', true );
			$format[]          = '%s';
		}
		if ( '' !== $error ) {
			$fields['error'] = sanitize_text_field( $error );
			$format[]        = '%s';
		}

		$wpdb->update( Db::table( 'requests' ), $fields, array( 'id' => absint( $id ) ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Paginate the log (most recent first).
	 *
	 * @param int $page     1-based page.
	 * @param int $per_page Per page.
	 * @return array{items:array<int,object>,total:int}
	 */
	public function paginate( $page = 1, $per_page = 30 ) {
		global $wpdb;

		$table   = Db::table( 'requests' );
		$page    = max( 1, (int) $page );
		$offset  = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items' => (array) $items,
			'total' => $total,
		);
	}
}
