<?php
/**
 * Determines which products a customer can still review.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves reviewable products for an order or a customer, excluding products
 * the customer has already reviewed (no duplicate review per order+product).
 */
class Reviewable {

	/**
	 * Reviewable product ids for an order.
	 *
	 * @param \WC_Order $order Order object.
	 * @return int[]
	 */
	public function for_order( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}

		$email = $order->get_billing_email();
		$ids   = array();
		$seen  = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id = $item->get_product_id();
			if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
				continue;
			}

			// Two products that share reviews (one pool) get one form: keyed by
			// pool, keeping the first ordered product (RR-00b E4).
			$pool_id = \NdvReviews\Reviews\Pool::resolve_id( $product_id );
			if ( isset( $ids[ $pool_id ] ) || isset( $seen[ $pool_id ] ) ) {
				continue;
			}
			$seen[ $pool_id ] = true;

			if ( ! $this->has_reviewed( $email, $product_id, (int) $order->get_customer_id() ) ) {
				$ids[ $pool_id ] = $product_id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Reviewable product ids across all of a customer's orders.
	 *
	 * @param string $email   Customer email.
	 * @param int    $user_id Customer user id (0 if guest).
	 * @return int[]
	 */
	public function for_customer( $email, $user_id = 0 ) {
		$ids = array();

		$orders = wc_get_orders(
			array(
				'limit'       => 50,
				'customer'    => $email ? $email : $user_id,
				'status'      => array( 'wc-completed', 'wc-processing' ),
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		foreach ( (array) $orders as $order ) {
			foreach ( $this->for_order( $order ) as $pid ) {
				$ids[ $pid ] = $pid;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Whether a customer has already reviewed a product: the one "already
	 * reviewed" test (PRD-00 §6). Reviews are stored on the product's pool, so
	 * both the product and its pool are checked, matching the email, or the
	 * account when the email changed.
	 *
	 * @param string $email      Email.
	 * @param int    $product_id Product id.
	 * @param int    $user_id    Customer user id (0 for guests and list rows).
	 * @return bool
	 */
	public function has_reviewed( $email, $product_id, $user_id = 0 ) {
		$product_id = absint( $product_id );
		$user_id    = absint( $user_id );
		if ( ! $product_id ) {
			return false;
		}

		$posts = array_values( array_unique( array( $product_id, \NdvReviews\Reviews\Pool::resolve_id( $product_id ) ) ) );
		$base  = array(
			'post__in' => $posts,
			'type__in' => array( 'review', 'comment' ),
			'status'   => 'all',
			'count'    => true,
			'number'   => 1,
		);

		if ( is_email( $email ) && (int) get_comments( $base + array( 'author_email' => $email ) ) > 0 ) {
			return true;
		}

		return $user_id > 0 && (int) get_comments( $base + array( 'user_id' => $user_id ) ) > 0;
	}
}
