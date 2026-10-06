<?php
/**
 * Expiring access-token pair — store, expiry, background renew, soft-fail, single-flight.
 *
 * These exercise Vegax_AIS_Tokens (and the Cloud soft-fail gate) WITHOUT a WordPress
 * install: wp_remote_post is a queued stub (see tests/bootstrap.php), so the renewal
 * behaviour — including the failure cases — is proven deterministically, no network.
 *
 * @package Vegax_Support
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/vegax-support/includes/class-vegax-ais-cloud.php';

class TokenTest extends TestCase {

	/**
	 * Base options: a store using the NEW expiring-token model.
	 *
	 * @param array $extra Overrides.
	 * @return array
	 */
	private function options( array $extra = array() ) {
		return array_merge(
			array(
				'store_token'              => 'vgx_static',
				'store_secret'             => 'static-secret',
				'access_token'             => '',
				'refresh_token'            => '',
				'access_token_expires_at'  => 0,
				'refresh_token_expires_at' => 0,
				'token_refresh_failed'     => 0,
				'consent_sync'             => 1,
			),
			$extra
		);
	}

	/**
	 * Queue one fake HTTP response.
	 *
	 * @param int   $code Status code.
	 * @param array $body Decoded body (will be JSON encoded).
	 * @return void
	 */
	private function queue_http( $code, array $body = array() ) {
		$GLOBALS['vegax_test_http'][] = array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $body ),
		);
	}

	/* ── store(): expiry is READ from the response, never hard-coded ───────────────── */

	public function test_store_reads_expires_in_from_response() {
		vegax_test_reset( $this->options() );
		$before = time();
		$ok     = Vegax_AIS_Tokens::store(
			array(
				'access_token'            => 'vgxt_acc',
				'refresh_token'           => 'vgxt_ref',
				'expires_in'              => 1234,
				'refresh_expires_in'      => 98765,
			)
		);
		$this->assertTrue( $ok );
		$acc_exp = (int) Vegax_AIS_Settings::get( 'access_token_expires_at' );
		$ref_exp = (int) Vegax_AIS_Settings::get( 'refresh_token_expires_at' );
		// Lifetime comes from expires_in (1234), NOT a constant in the code.
		$this->assertGreaterThanOrEqual( $before + 1234, $acc_exp );
		$this->assertLessThanOrEqual( time() + 1234, $acc_exp );
		$this->assertGreaterThanOrEqual( $before + 98765, $ref_exp );
	}

	public function test_store_ignores_response_without_a_pair() {
		vegax_test_reset( $this->options() );
		$this->assertFalse( Vegax_AIS_Tokens::store( array( 'access_token' => 'only-access' ) ) );
		$this->assertSame( '', (string) Vegax_AIS_Settings::get( 'refresh_token' ), 'A partial response must not be stored.' );
	}

	/* ── access_token(): empty once expired ───────────────────────────────────────── */

	public function test_access_token_empty_when_expired() {
		vegax_test_reset( $this->options( array( 'access_token' => 'vgxt_acc', 'access_token_expires_at' => time() - 5 ) ) );
		$this->assertSame( '', Vegax_AIS_Tokens::access_token() );
	}

	public function test_access_token_returned_while_valid() {
		vegax_test_reset( $this->options( array( 'access_token' => 'vgxt_acc', 'access_token_expires_at' => time() + 600 ) ) );
		$this->assertSame( 'vgxt_acc', Vegax_AIS_Tokens::access_token() );
	}

	/* ── needs_refresh(): decide BEFORE expiry ────────────────────────────────────── */

	public function test_needs_refresh_when_close_to_expiry() {
		vegax_test_reset( $this->options( array( 'refresh_token' => 'vgxt_ref', 'access_token_expires_at' => time() + 100 ) ) );
		$this->assertTrue( Vegax_AIS_Tokens::needs_refresh(), 'Within the margin ⇒ must renew.' );
	}

	public function test_no_refresh_needed_when_plenty_left() {
		vegax_test_reset( $this->options( array( 'refresh_token' => 'vgxt_ref', 'access_token_expires_at' => time() + 3000 ) ) );
		$this->assertFalse( Vegax_AIS_Tokens::needs_refresh() );
	}

	public function test_static_store_never_needs_refresh() {
		vegax_test_reset( $this->options() ); // no refresh_token
		$this->assertFalse( Vegax_AIS_Tokens::needs_refresh() );
		$this->assertFalse( Vegax_AIS_Tokens::has_pair() );
	}

	/* ── refresh(): success stores the NEW pair ───────────────────────────────────── */

	public function test_refresh_success_stores_new_pair() {
		vegax_test_reset( $this->options( array( 'refresh_token' => 'vgxt_old', 'access_token_expires_at' => time() - 1 ) ) );
		$this->queue_http( 200, array( 'ok' => true, 'access_token' => 'vgxt_new', 'refresh_token' => 'vgxt_newref', 'expires_in' => 3600, 'refresh_expires_in' => 100000 ) );
		$this->assertTrue( Vegax_AIS_Tokens::refresh() );
		$this->assertSame( 'vgxt_new', (string) Vegax_AIS_Settings::get( 'access_token' ) );
		$this->assertSame( 'vgxt_newref', (string) Vegax_AIS_Settings::get( 'refresh_token' ) );
		$this->assertFalse( Vegax_AIS_Tokens::failed() );
	}

	/* ── refresh(): failure ⇒ SOFT FAIL (no throw, flag set, network stops) ───────── */

	public function test_refresh_http_error_soft_fails() {
		vegax_test_reset( $this->options( array( 'refresh_token' => 'vgxt_old' ) ) );
		$this->queue_http( 403, array( 'detail' => 'invalid refresh token' ) );
		$this->assertFalse( Vegax_AIS_Tokens::refresh() );
		$this->assertTrue( Vegax_AIS_Tokens::failed() );
		$this->assertSame( 'vgxt_old', (string) Vegax_AIS_Settings::get( 'refresh_token' ), 'A failed refresh must not wipe the stored refresh token.' );
	}

	public function test_refresh_transport_error_soft_fails() {
		vegax_test_reset( $this->options( array( 'refresh_token' => 'vgxt_old' ) ) );
		// No queued response ⇒ the stub returns a WP_Error (transport error).
		$this->assertFalse( Vegax_AIS_Tokens::refresh() );
		$this->assertTrue( Vegax_AIS_Tokens::failed() );
	}

	public function test_refresh_without_pair_does_not_call_network() {
		vegax_test_reset( $this->options() );
		// No HTTP queued: if refresh() wrongly called the network it would set failed() via WP_Error.
		$this->assertFalse( Vegax_AIS_Tokens::refresh() );
		$this->assertFalse( Vegax_AIS_Tokens::failed(), 'A static-token store must not be marked failed.' );
	}

	/* ── single-flight lock: two ticks cannot refresh at once ─────────────────────── */

	public function test_refresh_is_single_flight() {
		vegax_test_reset( $this->options( array( 'refresh_token' => 'vgxt_old' ) ) );
		set_transient( 'vegax_ais_refresh_lock', 1, 120 ); // a sibling is already refreshing
		$this->queue_http( 200, array( 'ok' => true, 'access_token' => 'x', 'refresh_token' => 'y', 'expires_in' => 60 ) );
		$this->assertFalse( Vegax_AIS_Tokens::refresh(), 'Must no-op while the lock is held.' );
		$this->assertSame( 'vgxt_old', (string) Vegax_AIS_Settings::get( 'refresh_token' ), 'The locked-out tick must not overwrite the pair.' );
	}

	/* ── the Cloud soft-fail gate: no network once failed ─────────────────────────── */

	public function test_cloud_post_stops_when_token_failed() {
		vegax_test_reset( $this->options( array( 'token_refresh_failed' => 1 ) ) );
		$out = Vegax_AIS_Cloud::post( '/api/woo/heartbeat', array( 'x' => 1 ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'vegax_token_expired', $out->get_error_code() );
	}

	public function test_cloud_post_still_works_for_static_store() {
		vegax_test_reset( $this->options() ); // static store, not failed
		$this->queue_http( 200, array( 'ok' => true ) );
		$out = Vegax_AIS_Cloud::post( '/api/woo/heartbeat', array( 'x' => 1 ) );
		$this->assertIsArray( $out, 'Backward-compatible path must still post.' );
	}
}
