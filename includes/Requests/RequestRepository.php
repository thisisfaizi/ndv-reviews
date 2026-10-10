<?php
/**
 * Review-request queue + log persistence.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Requests;

use NdvReviews\Installer;
use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for the ndvr_requests table: the durable record of intent behind every
 * scheduled reminder, so a missed cron can be recovered.
 *
 * Times are stored and compared in UTC ('Y-m-d H:i:s'), always passed as
 * values rather than the database's NOW(), so the server's time zone never
 * matters. Columns added by RR-09 (Installer::V_PIPELINE) are only written or
 * read once that schema is current.
 */
class RequestRepository {

	/**
	 * Transient prefix for cached stats (cleared on every tracking write).
	 */
	const STATS_TRANSIENT = 'ndvr_request_stats_';

	/**
	 * Insert a scheduled request in the original (pre-RR-09) shape. Kept for
	 * callers that predate the queue API; new code uses Scheduler::queue_for_order().
	 *
	 * @param array<string,mixed> $data order_id, customer_id, email, scheduled_at.
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
	 * Insert a request unless its dedupe key already exists (INSERT IGNORE on
	 * the UNIQUE dedupe key; a NULL key never collides).
	 *
	 * @param array<string,mixed> $data {
	 *     Row data.
	 *
	 *     @type int         $order_id     Order id (0 for list rows).
	 *     @type int|null    $customer_id  Customer user id.
	 *     @type string      $email        Recipient email.
	 *     @type int         $step         Step number.
	 *     @type string      $scheduled_at UTC datetime.
	 *     @type string      $source       auto|manual|followup|campaign.
	 *     @type string      $origin       free|pro.
	 *     @type string|null $dedupe_key   Dedupe key or null.
	 *     @type array       $meta         Row meta (JSON-encoded).
	 * }
	 * @return array{id:int,inserted:bool} Id 0 when the insert failed.
	 */
	public function insert_unique( array $data ) {
		global $wpdb;

		$table   = Db::table( 'requests' );
		$columns = array(
			'order_id'     => array( '%d', absint( $data['order_id'] ?? 0 ) ),
			'customer_id'  => array( '%d', empty( $data['customer_id'] ) ? null : absint( $data['customer_id'] ) ),
			'email'        => array( '%s', isset( $data['email'] ) ? strtolower( sanitize_email( (string) $data['email'] ) ) : null ),
			'channel'      => array( '%s', 'email' ),
			'step'         => array( '%d', max( 1, (int) ( $data['step'] ?? 1 ) ) ),
			'status'       => array( '%s', 'scheduled' ),
			'scheduled_at' => array( '%s', isset( $data['scheduled_at'] ) ? (string) $data['scheduled_at'] : gmdate( 'Y-m-d H:i:s' ) ),
			'source'       => array( '%s', sanitize_key( (string) ( $data['source'] ?? 'auto' ) ) ),
			'origin'       => array( '%s', 'pro' === ( $data['origin'] ?? 'free' ) ? 'pro' : 'free' ),
			'dedupe_key'   => array( '%s', isset( $data['dedupe_key'] ) && '' !== $data['dedupe_key'] ? substr( (string) $data['dedupe_key'], 0, 120 ) : null ),
			'meta'         => array( '%s', isset( $data['meta'] ) ? wp_json_encode( (array) $data['meta'] ) : null ),
		);

		$placeholders = array();
		$values       = array();
		foreach ( $columns as $column ) {
			if ( null === $column[1] ) {
				$placeholders[] = 'NULL';
			} else {
				$placeholders[] = $column[0];
				$values[]       = $column[1];
			}
		}

		$sql = "INSERT IGNORE INTO `{$table}` (" . implode( ', ', array_keys( $columns ) ) . ') VALUES (' . implode( ', ', $placeholders ) . ')';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table from Db::table(); one placeholder per non-null value.
		$affected = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		if ( 1 === (int) $affected ) {
			return array(
				'id'       => (int) $wpdb->insert_id,
				'inserted' => true,
			);
		}

		$key = $columns['dedupe_key'][1];
		if ( false !== $affected && null !== $key ) {
			$existing = $this->find_by_dedupe_key( $key );
			if ( $existing ) {
				return array(
					'id'       => (int) $existing->id,
					'inserted' => false,
				);
			}
		}

		return array(
			'id'       => 0,
			'inserted' => false,
		);
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
	 * Find a request by its dedupe key.
	 *
	 * @param string $key Dedupe key.
	 * @return object|null
	 */
	public function find_by_dedupe_key( $key ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE dedupe_key = %s", (string) $key ) );
	}

	/**
	 * Find the request whose email carried a token.
	 *
	 * @param int $token_id Token row id.
	 * @return object|null
	 */
	public function find_by_token( $token_id ) {
		global $wpdb;

		$token_id = absint( $token_id );
		if ( ! $token_id || ! Installer::is_current( Installer::V_PIPELINE ) ) {
			return null;
		}

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE token_id = %d ORDER BY id DESC LIMIT 1", $token_id ) );
	}

	/**
	 * Whether an (email) request already exists for an order. Kept for older
	 * callers (Pro BulkCampaign): any email row counts, whatever its source.
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
	 * The oldest email request for an order with one of the given sources.
	 *
	 * @param int      $order_id Order id.
	 * @param string[] $sources  Sources (for example `legacy`: rows from before RR-09).
	 * @return int Request id, or 0.
	 */
	public function first_id_for_order( $order_id, array $sources ) {
		global $wpdb;

		$sources = array_values( array_filter( array_map( 'sanitize_key', $sources ) ) );
		if ( ! $sources || ! Installer::is_current( Installer::V_PIPELINE ) ) {
			return 0;
		}

		$table        = Db::table( 'requests' );
		$placeholders = implode( ', ', array_fill( 0, count( $sources ), '%s' ) );
		$args         = array_merge( array( absint( $order_id ) ), $sources );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per source.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE order_id = %d AND channel = 'email' AND source IN ( {$placeholders} ) ORDER BY id ASC LIMIT 1", $args ) );
	}

	/**
	 * Atomically claim a request for sending. Only one caller can win: the
	 * UPDATE changes the row only while it is still scheduled or failed.
	 *
	 * @param int $id Request id.
	 * @return bool True when this caller now owns the send.
	 */
	public function claim( $id ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status = 'sending', claimed_at = %s WHERE id = %d AND status IN ('scheduled','failed')", gmdate( 'Y-m-d H:i:s' ), absint( $id ) ) );

		return 1 === (int) $changed;
	}

	/**
	 * Update a request's status (and optionally error / sent time).
	 *
	 * @param int    $id     Request id.
	 * @param string $status scheduled|sending|sent|failed|cancelled|converted.
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
			// A retried request that now went out should not keep the old error.
			$fields['error'] = '';
			$format[]        = '%s';
		} elseif ( '' !== $error ) {
			$fields['error'] = sanitize_text_field( $error );
			$format[]        = '%s';
		}

		$wpdb->update( Db::table( 'requests' ), $fields, array( 'id' => absint( $id ) ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->flush_stats();
	}

	/**
	 * Move a scheduled request's send time (for example a spread re-enqueue).
	 *
	 * @param int    $id           Request id.
	 * @param string $scheduled_at UTC datetime.
	 * @return void
	 */
	public function set_scheduled_at( $id, $scheduled_at ) {
		global $wpdb;

		$wpdb->update( Db::table( 'requests' ), array( 'scheduled_at' => (string) $scheduled_at ), array( 'id' => absint( $id ) ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Remember which token a sent request carried.
	 *
	 * @param int $id       Request id.
	 * @param int $token_id Token row id.
	 * @return void
	 */
	public function set_token( $id, $token_id ) {
		global $wpdb;

		$wpdb->update( Db::table( 'requests' ), array( 'token_id' => absint( $token_id ) ), array( 'id' => absint( $id ) ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Record the first time the review link (or the open pixel) was seen.
	 *
	 * @param int $id Request id.
	 * @return bool True on the first open only.
	 */
	public function mark_opened( $id ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET opened_at = %s WHERE id = %d AND opened_at IS NULL", gmdate( 'Y-m-d H:i:s' ), absint( $id ) ) );
		if ( $changed ) {
			$this->flush_stats();
		}

		return 1 === $changed;
	}

	/**
	 * Record the first review left through a request's link.
	 *
	 * @param int $id Request id.
	 * @return bool True on the first review only.
	 */
	public function mark_reviewed( $id ) {
		global $wpdb;

		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = Db::table( 'requests' );
		// A review proves the link was opened, even when the open wasn't recorded.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET reviewed_at = %s, opened_at = COALESCE(opened_at, %s), status = 'converted' WHERE id = %d AND reviewed_at IS NULL", $now, $now, absint( $id ) ) );
		if ( $changed ) {
			$this->flush_stats();
		}

		return 1 === $changed;
	}

	/**
	 * Most recent send to an order (any status, any source).
	 *
	 * @param int $order_id Order id.
	 * @return string|null UTC datetime.
	 */
	public function last_sent_at_for_order( $order_id ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sent_at) FROM `{$table}` WHERE order_id = %d AND sent_at IS NOT NULL", absint( $order_id ) ) );

		return $value ? (string) $value : null;
	}

	/**
	 * Most recent send to an email address (any status, any source).
	 *
	 * @param string $email Email.
	 * @return string|null UTC datetime.
	 */
	public function last_sent_at_for_email( $email ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sent_at) FROM `{$table}` WHERE email = %s AND sent_at IS NOT NULL", strtolower( trim( (string) $email ) ) ) );

		return $value ? (string) $value : null;
	}

	/**
	 * Cancel this plugin's pending requests for an order (for example when a
	 * manual send replaces the automatic one). Only free rows are touched, and
	 * their dedupe key is cleared so a later legitimate queue can happen.
	 *
	 * @param int      $order_id Order id.
	 * @param string[] $sources  Sources to cancel.
	 * @param string   $reason   Log text.
	 * @return int Rows cancelled.
	 */
	public function cancel_pending_for_order( $order_id, array $sources, $reason = '' ) {
		global $wpdb;

		$sources = array_values( array_filter( array_map( 'sanitize_key', $sources ) ) );
		if ( ! $sources || ! Installer::is_current( Installer::V_PIPELINE ) ) {
			return 0;
		}

		$table        = Db::table( 'requests' );
		$placeholders = implode( ', ', array_fill( 0, count( $sources ), '%s' ) );
		$args         = array_merge( array( sanitize_text_field( (string) $reason ), absint( $order_id ) ), $sources );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per source.
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status = 'cancelled', error = %s, dedupe_key = NULL WHERE order_id = %d AND origin = 'free' AND status = 'scheduled' AND source IN ( {$placeholders} )", $args ) );
		if ( $changed ) {
			$this->flush_stats();
		}

		return $changed;
	}

	/**
	 * Cancel every request to an email address that could still send (any
	 * origin; scheduled, and failed ones a Retry would send), for example
	 * after a privacy erasure.
	 *
	 * @param string $email   Email.
	 * @param string $message Log text.
	 * @return int Rows cancelled.
	 */
	public function cancel_pending_for_email( $email, $message ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status = 'cancelled', error = %s WHERE LOWER(email) = %s AND status IN ('scheduled','failed')", sanitize_text_field( (string) $message ), strtolower( trim( (string) $email ) ) ) );
		if ( $changed ) {
			$this->flush_stats();
		}

		return $changed;
	}

	/**
	 * Reset sends that were claimed but never finished (a crash or timeout
	 * mid-send) so they can be retried.
	 *
	 * @param string $claimed_before UTC datetime: claims older than this are stuck.
	 * @param string $message        Log text.
	 * @return int Rows reset.
	 */
	public function recover_stuck( $claimed_before, $message ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status = 'failed', error = %s WHERE status = 'sending' AND claimed_at < %s", sanitize_text_field( (string) $message ), (string) $claimed_before ) );
	}

	/**
	 * Scheduled requests past their send time (oldest first), for the orphan
	 * re-enqueue.
	 *
	 * @param string $due_before UTC datetime.
	 * @param int    $limit      Max rows.
	 * @return array<int,object> Rows with id and scheduled_at.
	 */
	public function overdue_scheduled( $due_before, $limit = 200 ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, scheduled_at FROM `{$table}` WHERE status = 'scheduled' AND scheduled_at < %s ORDER BY scheduled_at ASC LIMIT %d", (string) $due_before, max( 1, (int) $limit ) ) );
	}

	/**
	 * Delivery and conversion counts for the last N days (cached 10 minutes).
	 * Conversion is per order: orders with a reviewed request out of orders
	 * with a sent request.
	 *
	 * @param int $days Window in days.
	 * @return array{sent:int,opened:int,reviewed:int,orders_sent:int,orders_reviewed:int,conversion:float|null}
	 */
	public function stats( $days = 90 ) {
		global $wpdb;

		$days   = max( 1, (int) $days );
		$cached = get_transient( self::STATS_TRANSIENT . $days );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = array(
			'sent'            => 0,
			'opened'          => 0,
			'reviewed'        => 0,
			'orders_sent'     => 0,
			'orders_reviewed' => 0,
			'conversion'      => null,
		);

		if ( Installer::is_current( Installer::V_PIPELINE ) ) {
			$table = Db::table( 'requests' );
			$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Db::table(); the date is a placeholder.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS sent,
						SUM( CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END ) AS opened,
						SUM( CASE WHEN reviewed_at IS NOT NULL THEN 1 ELSE 0 END ) AS reviewed,
						COUNT( DISTINCT CASE WHEN order_id > 0 THEN order_id END ) AS orders_sent,
						COUNT( DISTINCT CASE WHEN order_id > 0 AND reviewed_at IS NOT NULL THEN order_id END ) AS orders_reviewed
					FROM `{$table}` WHERE sent_at IS NOT NULL AND sent_at >= %s",
					$since
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $row ) {
				$out['sent']            = (int) $row->sent;
				$out['opened']          = (int) $row->opened;
				$out['reviewed']        = (int) $row->reviewed;
				$out['orders_sent']     = (int) $row->orders_sent;
				$out['orders_reviewed'] = (int) $row->orders_reviewed;
				$out['conversion']      = $out['orders_sent'] > 0 ? $out['orders_reviewed'] / $out['orders_sent'] : null;
			}
		}

		set_transient( self::STATS_TRANSIENT . $days, $out, 10 * MINUTE_IN_SECONDS );

		return $out;
	}

	/**
	 * Request rows sent to an email address (privacy export).
	 *
	 * @param string $email Email.
	 * @return array<int,object>
	 */
	public function for_email( $email ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE LOWER(email) = %s ORDER BY id ASC", strtolower( trim( $email ) ) ) );
	}

	/**
	 * Remove an email address and what it reveals from the log (privacy
	 * erasure). Rows are kept, without the address, so delivery statistics stay
	 * correct; order and customer ids stay because WooCommerce keeps the order.
	 *
	 * @param string $email Email.
	 * @return int Rows changed.
	 */
	public function anonymize_email( $email ) {
		global $wpdb;

		$table = Db::table( 'requests' );
		$set   = Installer::is_current( Installer::V_PIPELINE )
			? 'email = NULL, meta = NULL, opened_at = NULL, reviewed_at = NULL'
			: 'email = NULL';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $set is one of two literals.
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET {$set} WHERE LOWER(email) = %s", strtolower( trim( $email ) ) ) );
		if ( $changed ) {
			$this->flush_stats();
		}

		return $changed;
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

		$table  = Db::table( 'requests' );
		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items' => (array) $items,
			'total' => $total,
		);
	}

	/**
	 * Decode a row's meta JSON.
	 *
	 * @param object $row Request row.
	 * @return array<string,mixed>
	 */
	public static function meta( $row ) {
		if ( ! is_object( $row ) || empty( $row->meta ) ) {
			return array();
		}
		$meta = json_decode( (string) $row->meta, true );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Forget cached stats.
	 *
	 * @return void
	 */
	private function flush_stats() {
		foreach ( array( 30, 90 ) as $days ) {
			delete_transient( self::STATS_TRANSIENT . $days );
		}
	}
}
