<?php
/**
 * Tokenized review-collection links (sign, store, verify).
 *
 * @package NdvReviews
 */

namespace NdvReviews\Collection;

use NdvReviews\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Issues and validates opaque review-collection tokens.
 *
 * The URL carries a random token; the database stores only its SHA-256 hash
 * (and a hash of the email), so a database leak never exposes a usable link or
 * the customer's address. Tokens are revocable and can expire.
 */
class TokenRepository {

	/**
	 * Create a per-order token covering the given reviewable products.
	 *
	 * @param int      $order_id     Order id.
	 * @param string   $email        Customer email.
	 * @param int[]    $product_ids  Reviewable product ids.
	 * @param int|null $customer_id  Customer user id (nullable).
	 * @return string The raw token to embed in a URL.
	 */
	public function create_order_token( $order_id, $email, array $product_ids, $customer_id = null ) {
		return $this->create( 'order', $order_id, $customer_id, $email, $product_ids );
	}

	/**
	 * Create a customer "magic" token covering all current unreviewed products.
	 *
	 * @param int    $customer_id Customer user id.
	 * @param string $email       Customer email.
	 * @param int[]  $product_ids Reviewable product ids.
	 * @return string The raw token.
	 */
	public function create_customer_token( $customer_id, $email, array $product_ids ) {
		return $this->create( 'customer', null, $customer_id, $email, $product_ids );
	}

	/**
	 * Internal token creation.
	 *
	 * @param string   $type        order|customer.
	 * @param int|null $order_id    Order id.
	 * @param int|null $customer_id Customer id.
	 * @param string   $email       Email.
	 * @param int[]    $product_ids Product ids.
	 * @return string Raw token.
	 */
	private function create( $type, $order_id, $customer_id, $email, array $product_ids ) {
		global $wpdb;

		$raw     = wp_generate_password( 40, false );
		$expiry  = (int) apply_filters( 'ndv-reviews/token_expiry_days', 60 );
		$expires = $expiry > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( $expiry * DAY_IN_SECONDS ) ) : null;

		$products = array();
		foreach ( array_map( 'absint', $product_ids ) as $pid ) {
			if ( $pid ) {
				$products[] = array(
					'id'     => $pid,
					'status' => 'pending',
				);
			}
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table( 'review_tokens' ),
			array(
				'type'       => 'customer' === $type ? 'customer' : 'order',
				'order_id'   => $order_id ? absint( $order_id ) : null,
				'customer_id' => $customer_id ? absint( $customer_id ) : null,
				'email_hash' => $this->hash_email( $email ),
				'token_hash' => $this->hash_token( $raw ),
				'products'   => wp_json_encode( $products ),
				'status'     => 'active',
				'expires_at' => $expires,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $raw;
	}

	/**
	 * Resolve a raw token to its active, unexpired row.
	 *
	 * @param string $raw Raw token from the URL.
	 * @return object|null Row, or null if invalid/expired/used/revoked.
	 */
	public function resolve( $raw ) {
		global $wpdb;

		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return null;
		}

		$table = Db::table( 'review_tokens' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE token_hash = %s", $this->hash_token( $raw ) ) );

		if ( ! $row || 'active' !== $row->status ) {
			return null;
		}

		if ( ! empty( $row->expires_at ) && strtotime( $row->expires_at . ' UTC' ) < time() ) {
			$this->set_status( (int) $row->id, 'expired' );
			return null;
		}

		return $row;
	}

	/**
	 * Update the recorded review status for a product within a token.
	 *
	 * @param int $token_id   Token row id.
	 * @param int $product_id Product id.
	 * @param string $status  pending|reviewed.
	 * @return void
	 */
	public function mark_product( $token_id, $product_id, $status = 'reviewed' ) {
		global $wpdb;

		$table = Db::table( 'review_tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, products FROM `{$table}` WHERE id = %d", absint( $token_id ) ) );
		if ( ! $row ) {
			return;
		}

		$products = json_decode( (string) $row->products, true );
		$products = is_array( $products ) ? $products : array();
		$all_done = true;

		foreach ( $products as &$p ) {
			if ( (int) $p['id'] === absint( $product_id ) ) {
				$p['status'] = ( 'reviewed' === $status ) ? 'reviewed' : 'pending';
			}
			if ( 'reviewed' !== $p['status'] ) {
				$all_done = false;
			}
		}
		unset( $p );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'products' => wp_json_encode( $products ),
				'status'   => $all_done ? 'used' : 'active',
				'used_at'  => $all_done ? current_time( 'mysql', true ) : null,
			),
			array( 'id' => absint( $token_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Set a token's status (e.g. revoke).
	 *
	 * @param int    $token_id Token id.
	 * @param string $status   active|used|revoked|expired.
	 * @return void
	 */
	public function set_status( $token_id, $status ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Db::table( 'review_tokens' ),
			array( 'status' => sanitize_key( $status ) ),
			array( 'id' => absint( $token_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Whether a token's email hash matches the given email.
	 *
	 * @param object $row   Token row.
	 * @param string $email Email to compare.
	 * @return bool
	 */
	public function email_matches( $row, $email ) {
		return isset( $row->email_hash ) && hash_equals( (string) $row->email_hash, $this->hash_email( $email ) );
	}

	/**
	 * SHA-256 of a raw token (with the WP auth salt for defense in depth).
	 *
	 * @param string $raw Raw token.
	 * @return string
	 */
	private function hash_token( $raw ) {
		return hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) );
	}

	/**
	 * SHA-256 of a normalized email.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private function hash_email( $email ) {
		return hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), wp_salt( 'auth' ) );
	}
}
