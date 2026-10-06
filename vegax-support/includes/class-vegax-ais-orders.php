<?php
/**
 * Order access for the signed order-lookup endpoint (WISMO).
 *
 * Reads orders only through the WooCommerce CRUD API so it works with both the
 * legacy post tables and High-Performance Order Storage. An order is only ever
 * returned when the requester supplies the billing e-mail that belongs to it,
 * which stops order-number enumeration.
 *
 * @package Vegax_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order queries + privacy-safe serialisation.
 */
class Vegax_AIS_Orders {

	/**
	 * Is HPOS in use? (reported to Vegax for diagnostics)
	 *
	 * @return bool
	 */
	public static function hpos_enabled() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}
		if ( ! method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			return false;
		}
		return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Status labels without the "wc-" prefix.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function status_label( $order ) {
		$status = $order->get_status();
		if ( function_exists( 'wc_get_order_status_name' ) ) {
			$name = wc_get_order_status_name( $status );
			if ( $name ) {
				return $name;
			}
		}
		return $status;
	}

	/**
	 * Tracking numbers added by the common shipment tracking plugins/meta.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function tracking( $order ) {
		$items = array();

		$shipment_items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( is_array( $shipment_items ) ) {
			foreach ( $shipment_items as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$items[] = array(
					'number'         => isset( $row['tracking_number'] ) ? (string) $row['tracking_number'] : '',
					'provider'       => isset( $row['tracking_provider'] ) ? (string) $row['tracking_provider'] : '',
					'url'            => isset( $row['custom_tracking_link'] ) ? (string) $row['custom_tracking_link'] : '',
					'date_shipped'   => isset( $row['date_shipped'] ) ? (string) $row['date_shipped'] : '',
				);
			}
		}

		$plain = (string) $order->get_meta( '_tracking_number' );
		if ( '' !== $plain ) {
			$items[] = array(
				'number'   => $plain,
				'provider' => (string) $order->get_meta( '_tracking_provider' ),
				'url'      => (string) $order->get_meta( '_tracking_link' ),
			);
		}

		/**
		 * Let a site add tracking from its own source.
		 *
		 * @param array    $items Tracking rows.
		 * @param WC_Order $order Order.
		 */
		return apply_filters( 'vegax_ais_tracking_items', $items, $order );
	}

	/**
	 * Privacy-safe order summary: no addresses, no phone, no customer e-mail.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function public_summary( $order ) {
		return array(
			'number'       => $order->get_order_number(),
			'status'       => $order->get_status(),
			'status_label' => self::status_label( $order ),
			'date'         => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
			'total'        => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
			'items_count'  => (int) $order->get_item_count(),
			'shipped'      => (bool) $order->get_date_completed(),
			'tracking'     => self::tracking( $order ),
		);
	}

	/**
	 * Look up a customer's own orders.
	 *
	 * @param string $email    Billing e-mail supplied by the customer.
	 * @param string $order_id Optional order number/id, must belong to that e-mail.
	 * @return array List of order summaries (empty when nothing matched).
	 */
	public static function lookup( $email, $order_id = '' ) {
		$email = sanitize_email( $email );
		if ( ! $email || ! is_email( $email ) ) {
			return array();
		}

		if ( '' !== $order_id ) {
			$order = self::find_by_number_or_id( $order_id );
			if ( ! $order ) {
				return array();
			}
			// Ownership check: the e-mail must belong to the order.
			$owns = ( strtolower( $order->get_billing_email() ) === strtolower( $email ) );
			// Guests sometimes used the account e-mail of the paying account.
			$owns = $owns || ( $order->get_customer_id() && self::user_matches( $order->get_customer_id(), $email ) );
			if ( ! $owns ) {
				return array();
			}
			return array( self::public_summary( $order ) );
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 5,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'type'          => 'shop_order',
			)
		);
		$out = array();
		foreach ( $orders as $order ) {
			$out[] = self::public_summary( $order );
		}
		return $out;
	}

	/**
	 * Resolve an order by order number (may include a custom prefix) or by id.
	 *
	 * @param string $needle Order number or id.
	 * @return WC_Order|false
	 */
	protected static function find_by_number_or_id( $needle ) {
		$needle = trim( (string) $needle );
		if ( '' === $needle ) {
			return false;
		}
		// Strip a leading "#" and any non-numeric characters used by custom numbering.
		$digits = preg_replace( '/\D+/', '', $needle );

		if ( $digits ) {
			$order = wc_get_order( (int) $digits );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}
		if ( function_exists( 'wc_get_orders' ) ) {
			$found = wc_get_orders(
				array(
					'limit'        => 1,
					'type'         => 'shop_order',
					'meta_query'   => array(
						array(
							'key'   => '_order_number',
							'value' => $needle,
						),
					),
				)
			);
			if ( ! empty( $found ) ) {
				return $found[0];
			}
		}
		return false;
	}

	/**
	 * Does this WordPress user own the given e-mail?
	 *
	 * @param int    $user_id User id.
	 * @param string $email   E-mail.
	 * @return bool
	 */
	protected static function user_matches( $user_id, $email ) {
		$user = get_userdata( (int) $user_id );
		return ( $user && strtolower( (string) $user->user_email ) === strtolower( $email ) );
	}

	/**
	 * Has this order already been reported as shipped?
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_shipped( $order ) {
		return (bool) $order->get_date_completed() || in_array( $order->get_status(), array( 'completed', 'shipped' ), true );
	}
}
