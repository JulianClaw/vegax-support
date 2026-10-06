<?php
/**
 * End-user identity verification (Intercom lesson ②).
 *
 * When the merchant switches "Identity verification" on, the storefront widget is given a
 * short-lived HMAC-SHA256 token naming the signed-in customer. Vegax verifies the signature
 * and the expiry before it trusts that id, which is what stops a cached conversation being
 * answered for the WRONG person. The signing key is this store's own secret — it is never
 * printed anywhere and never leaves the site except inside the signed token.
 *
 * @package Vegax_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mints the signed identity token handed to the widget.
 */
class Vegax_AIS_Identity {

	/**
	 * Lifetime of a minted token, in seconds. Short on purpose: a stale token must be refused.
	 */
	const TTL = 900;

	/**
	 * Is end-user identity verification switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 1 === (int) Vegax_AIS_Settings::get( 'identity_verification', 0 );
	}

	/**
	 * Would this request carry a per-shopper identity token in the page HTML?
	 *
	 * True only when verification is switched on AND a customer is signed in. When it is
	 * true the storefront response is PERSONALISED and must never be reused for another
	 * visitor — see Vegax_AIS_Widget::maybe_disable_cache(). This is the exact case the
	 * Intercom cache warning is about: a cached page carrying a signed user token would
	 * hand one shopper's verified conversation to the next person who loads it.
	 *
	 * @return bool
	 */
	public static function personalises_page() {
		return self::enabled() && '' !== self::current_customer_id();
	}

	/**
	 * The customer id Vegax may trust for the current request, or '' when nobody is signed in.
	 *
	 * Namespaced so a WordPress user id can never be confused with another platform's id.
	 *
	 * @return string
	 */
	public static function current_customer_id() {
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( $user && ! empty( $user->ID ) ) {
				return 'wp:' . (int) $user->ID;
			}
		}
		return '';
	}

	/**
	 * A signed token for the signed-in customer, or '' when it must not be sent.
	 *
	 * Token shape: base64url(payload) . '.' . hex(hmac_sha256("identity|" . base64url(payload))).
	 * The signature is compared in constant time on the Vegax side.
	 *
	 * @return string
	 */
	public static function token() {
		if ( ! self::enabled() ) {
			return '';
		}
		$secret = (string) Vegax_AIS_Settings::get( 'store_secret' );
		if ( '' === $secret ) {
			return '';
		}
		$customer = self::current_customer_id();
		if ( '' === $customer ) {
			return '';
		}
		$payload = wp_json_encode(
			array(
				'cid'   => $customer,
				'store' => Vegax_AIS_Settings::store_id(),
				'exp'   => time() + self::TTL,
			)
		);
		if ( false === $payload ) {
			return '';
		}
		$part = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
		$sig  = hash_hmac( 'sha256', 'identity|' . $part, $secret );
		return $part . '.' . $sig;
	}

	/**
	 * Verify a token minted by token(): signature, then expiry.
	 *
	 * This is the SAME check the Vegax service performs, kept here so the widget can
	 * fail closed — it will simply not print a token it cannot itself verify. Returns the
	 * decoded payload on success, or false when the token is malformed, signed with a
	 * different key, tampered with, or expired.
	 *
	 * @param string $token Token to verify.
	 * @return array|false
	 */
	public static function verify( $token ) {
		$token = (string) $token;
		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return false;
		}
		$secret = (string) Vegax_AIS_Settings::get( 'store_secret' );
		if ( '' === $secret ) {
			return false;
		}
		list( $part, $sig ) = explode( '.', $token, 2 );
		if ( '' === $part || '' === $sig ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', 'identity|' . $part, $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			return false;
		}
		$json = base64_decode( strtr( $part, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own base64url payload, not obfuscation.
		if ( false === $json ) {
			return false;
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['exp'] ) ) {
			return false;
		}
		if ( (int) $data['exp'] < time() ) {
			return false;
		}
		return $data;
	}
}
