<?php
/**
 * Expiring access token + refresh token handling for the Woo connect.
 *
 * The Vegax service hands the plugin a SHORT-LIVED access token together with a
 * longer-lived refresh token (the same shape hosted commerce Admin APIs use). The
 * plugin stores BOTH, reads `expires_in` from the service response (never a hard-coded
 * lifetime) and renews the pair from a WordPress background job BEFORE it expires — so
 * a storefront request never waits on the network and no visitor ever triggers a refresh.
 *
 * Backward compatibility: a store connected with the older static store token/secret pair
 * simply has no refresh token here; every method degrades to "not applicable" and the
 * static path keeps working exactly as before.
 *
 * @package Vegax_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores, renews and reports the expiring token pair.
 */
class Vegax_AIS_Tokens {

	/** Renew this many seconds before the access token expires (30 min, so a 15-min cron
	 *  always has at least one tick inside the window before the token dies). */
	const REFRESH_MARGIN = 1800;

	/** Refresh endpoint on the Vegax service. */
	const REFRESH_PATH = '/api/woo/token/refresh';

	/** Single-flight lock lifetime, so two cron ticks never refresh at once. */
	const LOCK_TTL = 120;

	/**
	 * Persist the token pair returned by the service.
	 *
	 * The lifetime comes from the response (`expires_in` / `refresh_expires_in`) — it is
	 * NEVER assumed. A response without the pair is ignored so the static credentials that
	 * were saved alongside it are not disturbed.
	 *
	 * @param array $body Decoded service response.
	 * @return bool True when a pair was stored.
	 */
	public static function store( array $body ) {
		$access  = isset( $body['access_token'] ) ? trim( (string) $body['access_token'] ) : '';
		$refresh = isset( $body['refresh_token'] ) ? trim( (string) $body['refresh_token'] ) : '';
		if ( '' === $access || '' === $refresh ) {
			return false;
		}
		$exp_in  = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 0;
		$rexp_in = isset( $body['refresh_expires_in'] ) ? (int) $body['refresh_expires_in'] : 0;
		$now     = time();
		Vegax_AIS_Settings::update(
			array(
				'access_token'             => $access,
				'refresh_token'            => $refresh,
				'access_token_expires_at'  => $now + max( 0, $exp_in ),
				'refresh_token_expires_at' => $now + max( 0, $rexp_in ),
				'token_refresh_failed'     => 0,
				'token_last_error'         => '',
			)
		);
		return true;
	}

	/**
	 * Does this store hold a refresh token (i.e. uses the expiring-token model)?
	 *
	 * @return bool
	 */
	public static function has_pair() {
		return '' !== (string) Vegax_AIS_Settings::get( 'refresh_token' );
	}

	/**
	 * A currently-valid access token, or '' when there is none / it has expired.
	 *
	 * Callers must treat '' as "use the static token path instead".
	 *
	 * @return string
	 */
	public static function access_token() {
		$token = trim( (string) Vegax_AIS_Settings::get( 'access_token' ) );
		if ( '' === $token ) {
			return '';
		}
		$exp = (int) Vegax_AIS_Settings::get( 'access_token_expires_at' );
		if ( $exp > 0 && time() >= $exp ) {
			return '';
		}
		return $token;
	}

	/**
	 * Has the last refresh attempt failed (so sync should stop and the admin be told)?
	 *
	 * @return bool
	 */
	public static function failed() {
		return 1 === (int) Vegax_AIS_Settings::get( 'token_refresh_failed' );
	}

	/**
	 * Does the access token need renewing soon (or is it already gone)?
	 *
	 * @return bool
	 */
	public static function needs_refresh() {
		if ( ! self::has_pair() ) {
			return false;
		}
		$exp = (int) Vegax_AIS_Settings::get( 'access_token_expires_at' );
		if ( $exp <= 0 ) {
			return true;
		}
		return ( $exp - time() ) < self::REFRESH_MARGIN;
	}

	/**
	 * Exchange the refresh token for a new pair. Safe to call from cron only.
	 *
	 * Soft-fail by design: any failure — network, 4xx, a malformed body — is caught, recorded
	 * as a status flag and returned as false. Nothing is thrown, so a storefront page can never
	 * be taken down by a dead token; the settings screen instead shows a reconnect prompt.
	 *
	 * @return bool True when a fresh pair was stored.
	 */
	public static function refresh() {
		if ( ! self::has_pair() ) {
			return false;
		}
		// Single-flight: if another tick is already refreshing, do nothing this round.
		if ( get_transient( 'vegax_ais_refresh_lock' ) ) {
			return false;
		}
		set_transient( 'vegax_ais_refresh_lock', 1, self::LOCK_TTL );

		$resp = wp_remote_post(
			Vegax_AIS_Settings::api_base() . self::REFRESH_PATH,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'store'         => Vegax_AIS_Settings::store_id(),
						'refresh_token' => (string) Vegax_AIS_Settings::get( 'refresh_token' ),
						'plugin'        => VEGAX_AIS_VERSION,
					)
				),
			)
		);

		delete_transient( 'vegax_ais_refresh_lock' );

		if ( is_wp_error( $resp ) ) {
			self::mark_failed( $resp->get_error_message() );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $body ) || empty( $body['ok'] ) ) {
			$why = is_array( $body ) && ! empty( $body['detail'] ) ? (string) $body['detail'] : ( 'HTTP ' . $code );
			self::mark_failed( $why );
			return false;
		}
		if ( ! self::store( $body ) ) {
			self::mark_failed( 'the service returned no token pair' );
			return false;
		}
		Vegax_AIS_Settings::set_status( array( 'last_token_refresh' => time() ) );
		return true;
	}

	/**
	 * Record a refresh failure without throwing.
	 *
	 * @param string $why Short reason (no secrets).
	 * @return void
	 */
	public static function mark_failed( $why ) {
		Vegax_AIS_Settings::update( array( 'token_refresh_failed' => 1 ) );
		Vegax_AIS_Settings::set_status(
			array(
				'token_last_error' => (string) $why,
				'token_failed_at'  => time(),
			)
		);
	}

	/**
	 * Cron callback: renew the pair when it is close to expiry.
	 *
	 * @return void
	 */
	public static function cron_refresh() {
		if ( self::needs_refresh() ) {
			self::refresh();
		}
	}

	/**
	 * Register the background hooks. Called once from the plugin bootstrap.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'vegax_ais_cron_refresh', array( __CLASS__, 'cron_refresh' ) );
	}
}
