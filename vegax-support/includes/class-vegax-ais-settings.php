<?php
/**
 * Settings storage and helpers.
 *
 * @package Vegax_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes the single option that holds the plugin configuration.
 */
class Vegax_AIS_Settings {

	/**
	 * Default configuration.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_base'                 => VEGAX_AIS_API_DEFAULT,
			'store_token'              => '',
			'store_secret'             => '',
			// 06/10/2026 — expiring token pair (rotating access + refresh). Empty ⇒ the store uses the
			// static store_token/store_secret path exactly as before (backward compatible).
			'access_token'             => '',
			'refresh_token'            => '',
			'access_token_expires_at'  => 0,
			'refresh_token_expires_at' => 0,
			'token_refresh_failed'     => 0,
			'token_last_error'         => '',
			'agent_name'               => 'Vegax Support',
			'brand_color'              => '#07A87F',
			'widget_enabled'           => 1,
			'order_lookup_enabled'     => 1,
			'share_customer_email'     => 0,
			'consent_sync'             => 0,
			'identity_verification'    => 0,
			'max_products'             => 300,
			'store_id_override'        => '',
			'faqs_text'                => '',
			'custom_rules_text'        => '',
		);
	}

	/**
	 * All settings, merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( VEGAX_AIS_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = '' ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Persist a partial update.
	 *
	 * @param array $patch Key/value pairs.
	 * @return void
	 */
	public static function update( array $patch ) {
		$all = self::all();
		foreach ( $patch as $k => $v ) {
			$all[ $k ] = $v;
		}
		update_option( VEGAX_AIS_OPTION, $all );
	}

	/**
	 * Base URL of the Vegax service, always without a trailing slash.
	 *
	 * @return string
	 */
	public static function api_base() {
		$base = trim( (string) self::get( 'api_base', VEGAX_AIS_API_DEFAULT ) );
		if ( '' === $base ) {
			$base = VEGAX_AIS_API_DEFAULT;
		}
		return untrailingslashit( $base );
	}

	/**
	 * The store key Vegax knows this site by: the bare host of the site URL.
	 *
	 * A manual override wins - useful when WordPress runs behind a proxy, a CDN
	 * or a staging URL that does not match the public shop domain.
	 *
	 * @return string
	 */
	public static function store_id() {
		$override = trim( (string) self::get( 'store_id_override', '' ) );
		if ( '' !== $override ) {
			if ( false !== strpos( $override, '://' ) ) {
				$parsed   = wp_parse_url( $override, PHP_URL_HOST );
				$override = is_string( $parsed ) ? $parsed : '';
			}
			$override = strtolower( ltrim( $override, '.' ) );
			if ( '' !== $override ) {
				return ( 0 === strpos( $override, 'www.' ) ) ? substr( $override, 4 ) : $override;
			}
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}

	/**
	 * Is the plugin ready to talk to Vegax?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return ( '' !== (string) self::get( 'store_token' ) && '' !== (string) self::get( 'store_secret' ) );
	}

	/**
	 * Human readable status of the last sync/heartbeat, stored as a small array.
	 *
	 * @return array
	 */
	public static function status() {
		$status = get_option( 'vegax_ais_status', array() );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * One field out of the stored status array.
	 *
	 * @param string $key     Field name.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get_status_value( $key, $fallback = '' ) {
		$status = self::status();
		return array_key_exists( $key, $status ) ? $status[ $key ] : $fallback;
	}

	/**
	 * Drop every stored status field (13/10/2026).
	 *
	 * Used when the store is disconnected, so a stale "last sync" / "last heartbeat" is never
	 * shown for a store that is no longer connected.
	 *
	 * @return void
	 */
	public static function clear_status() {
		update_option( 'vegax_ais_status', array() );
	}

	/**
	 * Merge fields into the stored status.
	 *
	 * @param array $patch Fields to merge.
	 * @return void
	 */
	public static function set_status( array $patch ) {
		$status = self::status();
		foreach ( $patch as $k => $v ) {
			$status[ $k ] = $v;
		}
		update_option( 'vegax_ais_status', $status );
	}
}
