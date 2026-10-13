<?php
/**
 * REST routes used by the Vegax service.
 *
 * Access model (revised 1.0.9 after the WordPress.org review):
 * - /health          : admin only (current_user_can('manage_options')). It reports
 *                      versions, connection state and sync state, so it must never
 *                      be readable by an anonymous visitor.
 * - /order-lookup    : signature protected. The caller must prove possession of the
 * - /knowledge         store secret (HMAC-SHA256 over a canonical string including
 *                      the timestamp), so knowing the site URL is not enough.
 * The signature check lives in the route's permission_callback (the documented
 * place for authorisation), not only inside the callback body.
 *
 * @package Vegax_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers /wp-json/vegax/v1/*.
 */
class Vegax_AIS_Rest {

	const NAMESPACE_V1     = 'vegax/v1';
	const SIGNATURE_WINDOW = 300;   // seconds of clock skew accepted.
	const RATE_LIMIT_MAX   = 240;   // lookups per STORE per hour (was per IP).

	/**
	 * Hook the routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Route table.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => array( __CLASS__, 'can_manage_options' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/order-lookup',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'order_lookup' ),
				'permission_callback' => array( __CLASS__, 'permission_order_lookup' ),
				'args'                => array(
					'email' => array( 'required' => true ),
					'ts'    => array( 'required' => true ),
					'sig'   => array( 'required' => true ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/knowledge',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'knowledge' ),
				'permission_callback' => array( __CLASS__, 'permission_knowledge' ),
				'args'                => array(
					'ts'  => array( 'required' => true ),
					'sig' => array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * Canonical string that is signed.
	 *
	 * @param string $action  Action name.
	 * @param string $store   Store id.
	 * @param string $email   Customer e-mail (empty for other actions).
	 * @param string $order   Order number (may be empty).
	 * @param string $ts      Timestamp.
	 * @return string
	 */
	public static function canonical( $action, $store, $email, $order, $ts ) {
		return implode(
			'|',
			array(
				$action,
				strtolower( (string) $store ),
				strtolower( trim( (string) $email ) ),
				trim( (string) $order ),
				(string) $ts,
			)
		);
	}

	/**
	 * Permission callback for /health: WordPress administrators only.
	 *
	 * The endpoint reports plugin/WooCommerce/WordPress/PHP versions, connection
	 * state and synchronisation state, so anonymous access is not acceptable
	 * (WordPress.org review, 1.0.9).
	 *
	 * @return bool
	 */
	public static function can_manage_options() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for /order-lookup: enabled flag + HMAC signature.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_order_lookup( $request ) {
		if ( (int) Vegax_AIS_Settings::get( 'order_lookup_enabled' ) !== 1 ) {
			return new WP_Error( 'vegax_disabled', 'order_lookup_disabled', array( 'status' => 403 ) );
		}
		return self::verify(
			$request,
			'order-lookup',
			(string) $request->get_param( 'email' ),
			(string) $request->get_param( 'order' )
		);
	}

	/**
	 * Permission callback for /knowledge: HMAC signature.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_knowledge( $request ) {
		return self::verify( $request, 'knowledge' );
	}

	/**
	 * Verify HMAC + timestamp window.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $action  Action name.
	 * @param string          $email   E-mail used in the signature.
	 * @param string          $order   Order number used in the signature.
	 * @return true|WP_Error
	 */
	protected static function verify( $request, $action, $email = '', $order = '' ) {
		$secret = (string) Vegax_AIS_Settings::get( 'store_secret' );
		if ( '' === $secret || ! Vegax_AIS_Settings::is_connected() ) {
			return new WP_Error( 'vegax_not_connected', 'not_connected', array( 'status' => 403 ) );
		}
		$ts  = (string) $request->get_param( 'ts' );
		$sig = (string) $request->get_param( 'sig' );
		if ( ! ctype_digit( $ts ) || '' === $sig ) {
			return new WP_Error( 'vegax_bad_sig', 'bad_request', array( 'status' => 400 ) );
		}
		if ( abs( time() - (int) $ts ) > self::SIGNATURE_WINDOW ) {
			return new WP_Error( 'vegax_stale', 'stale_request', array( 'status' => 403 ) );
		}
		// The store parameter must belong to this site, so a signature cannot be replayed at another store.
		$store = (string) $request->get_param( 'store' );
		if ( '' !== $store && strtolower( $store ) !== Vegax_AIS_Settings::store_id() ) {
			return new WP_Error( 'vegax_wrong_store', 'wrong_store', array( 'status' => 403 ) );
		}
		$expected = hash_hmac( 'sha256', self::canonical( $action, Vegax_AIS_Settings::store_id(), $email, $order, $ts ), $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'vegax_bad_sig', 'bad_signature', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Per-store throttle. Keyed on the STORE, not the caller IP: every signed lookup
	 * arrives from the Vegax office (one IP shared by all tenants), so an IP bucket
	 * throttled every store at once and a stranger could exhaust it for everyone.
	 * The store identity is the correct unit of protection.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $max    Max hits per hour for this store.
	 * @return bool
	 */
	protected static function rate_limited( $bucket, $max ) {
		$key = 'vegax_ais_rl_' . md5( $bucket . '|' . Vegax_AIS_Settings::store_id() );
		$hit = (int) get_transient( $key );
		if ( $hit >= $max ) {
			return true;
		}
		set_transient( $key, $hit + 1, HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * GET /health - proves the plugin is installed and configured.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		return new WP_REST_Response(
			array(
				'ok'             => true,
				'plugin'         => 'vegax-support',
				'plugin_version' => VEGAX_AIS_VERSION,
				'store'          => Vegax_AIS_Settings::store_id(),
				'woocommerce'    => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'wp'             => get_bloginfo( 'version' ),
				'php'            => PHP_VERSION,
				'hpos'           => Vegax_AIS_Orders::hpos_enabled(),
				'order_lookup'   => (int) Vegax_AIS_Settings::get( 'order_lookup_enabled' ) === 1,
				'connected'      => Vegax_AIS_Settings::is_connected(),
				'product_count'  => Vegax_AIS_Knowledge::product_count(),
				'last_sync'      => (int) Vegax_AIS_Settings::get_status_value( 'last_sync', 0 ),
			),
			200
		);
	}

	/**
	 * GET /order-lookup - the WISMO path used during a chat.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function order_lookup( $request ) {
		// Authorisation (enabled flag + HMAC signature) already happened in
		// permission_order_lookup(), which WordPress runs before this callback.
		$email = (string) $request->get_param( 'email' );
		$order = (string) $request->get_param( 'order' );

		if ( self::rate_limited( 'lookup', self::RATE_LIMIT_MAX ) ) {
			return new WP_Error( 'vegax_rate', 'rate_limited', array( 'status' => 429 ) );
		}

		$orders = Vegax_AIS_Orders::lookup( $email, $order );
		return new WP_REST_Response(
			array(
				'ok'     => true,
				'store'  => Vegax_AIS_Settings::store_id(),
				'found'  => count( $orders ),
				'orders' => $orders,
			),
			200
		);
	}

	/**
	 * GET /knowledge - pull fallback when a pushed sync was missed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function knowledge( $request ) {
		// Authorisation (HMAC signature) already happened in permission_knowledge().
		if ( self::rate_limited( 'knowledge', 12 ) ) {
			return new WP_Error( 'vegax_rate', 'rate_limited', array( 'status' => 429 ) );
		}
		return new WP_REST_Response( Vegax_AIS_Knowledge::build(), 200 );
	}
}
