<?php
/**
 * Signed client for the Vegax service (plugin -> service).
 *
 * Every request carries the store token, a unix timestamp and an HMAC-SHA256
 * signature computed over "timestamp.raw_body" with the store secret, so the
 * service can verify the call without trusting the network. No WooCommerce
 * credentials ever leave this site.
 *
 * @package Vegax_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends signed payloads to Vegax and hooks the background jobs.
 */
class Vegax_AIS_Cloud {

	/**
	 * Register cron and order hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'vegax_ais_cron_heartbeat', array( __CLASS__, 'send_heartbeat' ) );
		add_action( 'vegax_ais_cron_sync', array( __CLASS__, 'send_knowledge' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * Signature for a raw body.
	 *
	 * @param string $timestamp Unix timestamp as string.
	 * @param string $body      Raw JSON body.
	 * @return string
	 */
	public static function sign( $timestamp, $body ) {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, (string) Vegax_AIS_Settings::get( 'store_secret' ) );
	}

	/**
	 * POST a signed JSON payload to the Vegax service.
	 *
	 * @param string $path Service path, e.g. /api/woo/heartbeat.
	 * @param array  $data Payload.
	 * @return array|WP_Error Decoded response or error.
	 */
	public static function post( $path, array $data ) {
		if ( ! Vegax_AIS_Settings::is_connected() ) {
			return new WP_Error( 'vegax_not_connected', __( 'The store is not connected to Vegax yet.', 'vegax-support' ) );
		}
		// 06/10/2026 — SOFT FAIL: when the expiring token pair could not be renewed, stop
		// calling the service at all (no retry storm, no slowdown) until the merchant
		// reconnects. The static-token stores never set this flag, so they are unaffected.
		if ( class_exists( 'Vegax_AIS_Tokens' ) && Vegax_AIS_Tokens::failed() ) {
			return new WP_Error( 'vegax_token_expired', __( 'The Vegax connection needs to be renewed. Open the Vegax Support settings and click Connect with Vegax again.', 'vegax-support' ) );
		}
		// Guideline 7: nothing leaves the site unless the administrator has agreed to the data
		// sharing described in the readme. The consent control lives on the settings screen.
		if ( (int) Vegax_AIS_Settings::get( 'consent_sync' ) !== 1 ) {
			return new WP_Error( 'vegax_no_consent', __( 'Data sharing with the Vegax service is switched off in the settings.', 'vegax-support' ) );
		}
		$ts   = (string) time();
		$body = wp_json_encode( $data );
		if ( false === $body ) {
			return new WP_Error( 'vegax_encode', __( 'Could not encode the payload.', 'vegax-support' ) );
		}
		$url     = Vegax_AIS_Settings::api_base() . $path;
		$headers = array(
			'Content-Type'      => 'application/json',
			'X-Vegax-Token'     => (string) Vegax_AIS_Settings::get( 'store_token' ),
			'X-Vegax-Timestamp' => $ts,
			'X-Vegax-Signature' => self::sign( $ts, $body ),
			'X-Vegax-Store'     => Vegax_AIS_Settings::store_id(),
			'X-Vegax-Plugin'    => 'vegax-support/' . VEGAX_AIS_VERSION,
			'User-Agent'        => 'Vegax-Support/' . VEGAX_AIS_VERSION . '; ' . home_url(),
		);
		// 06/10/2026 — also present the expiring access token when we hold a live one. The HMAC
		// (signed with the store secret) is unchanged, so a store still on the static path sends
		// exactly the same request it always did.
		if ( class_exists( 'Vegax_AIS_Tokens' ) ) {
			$access = Vegax_AIS_Tokens::access_token();
			if ( '' !== $access ) {
				$headers['X-Vegax-Access-Token'] = $access;
			}
		}
		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $res ) ) {
			Vegax_AIS_Settings::set_status( array( 'last_error' => $res->get_error_message() ) );
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			Vegax_AIS_Settings::set_status( array( 'last_error' => 'HTTP ' . $code ) );
			return new WP_Error( 'vegax_http_' . $code, 'HTTP ' . $code );
		}
		Vegax_AIS_Settings::set_status( array( 'last_error' => '' ) );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Small "I am installed and alive" payload.
	 *
	 * @return array|WP_Error
	 */
	public static function send_heartbeat() {
		$data = array(
			'store'          => Vegax_AIS_Settings::store_id(),
			'ts'             => time(),
			'plugin_version' => VEGAX_AIS_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'php_version'    => PHP_VERSION,
			'hpos'           => Vegax_AIS_Orders::hpos_enabled(),
			'order_lookup'   => (int) Vegax_AIS_Settings::get( 'order_lookup_enabled' ),
			'widget_enabled' => (int) Vegax_AIS_Settings::get( 'widget_enabled' ),
			'product_count'  => Vegax_AIS_Knowledge::product_count(),
			'site_url'       => home_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
		);
		/**
		 * Filter the daily heartbeat payload.
		 *
		 * Lets a site attach extra status fields (or remove one) without editing the plugin.
		 *
		 * @param array $data Heartbeat payload.
		 */
		$data = (array) apply_filters( 'vegax_ais_heartbeat_payload', $data );
		$out  = self::post( '/api/woo/heartbeat', $data );
		if ( ! is_wp_error( $out ) ) {
			Vegax_AIS_Settings::set_status( array( 'last_heartbeat' => time() ) );
		}
		return $out;
	}

	/**
	 * Push the catalogue + policies to Vegax.
	 *
	 * @return array|WP_Error
	 */
	public static function send_knowledge() {
		$payload = Vegax_AIS_Knowledge::build();
		$out     = self::post( '/api/woo/knowledge', $payload );
		if ( ! is_wp_error( $out ) ) {
			Vegax_AIS_Settings::set_status(
				array(
					'last_sync'     => time(),
					'product_count' => isset( $payload['product_total'] ) ? (int) $payload['product_total'] : 0,
				)
			);
		}
		return $out;
	}

	/**
	 * Tell Vegax that an order changed status (drives proactive updates).
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 * @param object $order    Order object.
	 * @return void
	 */
	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( ! Vegax_AIS_Settings::is_connected() ) {
			return;
		}
		$order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$event = array(
			'store'    => Vegax_AIS_Settings::store_id(),
			'ts'       => time(),
			'event'    => 'order_status_changed',
			'order'    => Vegax_AIS_Orders::public_summary( $order ),
			'customer' => array(
				'email_hash' => hash( 'sha256', strtolower( (string) $order->get_billing_email() ) ),
			),
			'from'     => (string) $from,
			'to'       => (string) $to,
		);
		// The customer e-mail is only sent when the merchant explicitly allows it.
		if ( (int) Vegax_AIS_Settings::get( 'share_customer_email' ) === 1 ) {
			$event['customer']['email'] = (string) $order->get_billing_email();
		}
		/**
		 * Filter the order-status event before it is sent to the Vegax service.
		 *
		 * @param array    $event Order-event payload.
		 * @param WC_Order $order The order.
		 */
		$event = (array) apply_filters( 'vegax_ais_order_event_payload', $event, $order );
		self::post( '/api/woo/order-event', $event );
	}
}
