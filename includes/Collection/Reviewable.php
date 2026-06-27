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

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id = $item->get_product_id();
			if ( $product_id && 'product' === get_post_type( $product_id ) && ! $this->has_reviewed( $email, $product_id ) ) {
				$ids[ $product_id ] = $product_id;
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
	 * Whether the given email has already left a review for the product.
	 *
	 * @param string $email      Email.
	 * @param int    $product_id Product id.
	 * @return bool
	 */
	public function has_reviewed( $email, $product_id ) {
		if ( ! is_email( $email ) ) {
			return false;
		}

		$existing = get_comments(
			array(
				'post_id'              => absint( $product_id ),
				'author_email'        => $email,
				'type__in'            => array( 'review', 'comment' ),
				'status'              => 'all',
				'count'               => true,
				'number'              => 1,
			)
		);

		return (int) $existing > 0;
	}
}
