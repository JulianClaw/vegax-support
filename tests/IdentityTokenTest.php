<?php
/**
 * Signed identity token — mint, verify, expire, tamper, switch-off.
 *
 * @package Vegax_Support
 */

use PHPUnit\Framework\TestCase;

class IdentityTokenTest extends TestCase {

	const SECRET = 'unit-test-store-secret';

	/**
	 * Base options: connected store with a known secret, identity OFF.
	 *
	 * @param array $extra Overrides.
	 * @return array
	 */
	private function options( array $extra = array() ) {
		return array_merge(
			array(
				'store_token'           => 'tok-abc',
				'store_secret'          => self::SECRET,
				'identity_verification' => 0,
			),
			$extra
		);
	}

	public function test_no_token_when_switch_off() {
		vegax_test_reset( $this->options() );
		$GLOBALS['vegax_test_logged_in'] = true;
		$this->assertSame( '', Vegax_AIS_Identity::token(), 'No token may be minted while the switch is off.' );
	}

	public function test_no_token_when_nobody_signed_in() {
		vegax_test_reset( $this->options( array( 'identity_verification' => 1 ) ) );
		$GLOBALS['vegax_test_logged_in'] = false;
		$this->assertSame( '', Vegax_AIS_Identity::token() );
	}

	public function test_no_token_without_secret() {
		vegax_test_reset( $this->options( array( 'identity_verification' => 1, 'store_secret' => '' ) ) );
		$GLOBALS['vegax_test_logged_in'] = true;
		$this->assertSame( '', Vegax_AIS_Identity::token() );
	}

	public function test_token_is_signed_and_verifies() {
		vegax_test_reset( $this->options( array( 'identity_verification' => 1 ) ) );
		$GLOBALS['vegax_test_logged_in'] = true;
		$GLOBALS['vegax_test_user_id']   = 42;

		$token = Vegax_AIS_Identity::token();
		$this->assertNotSame( '', $token, 'A signed-in shopper with the switch on must get a token.' );
		$this->assertStringContainsString( '.', $token );

		list( $part, $sig ) = explode( '.', $token, 2 );
		$expected = hash_hmac( 'sha256', 'identity|' . $part, self::SECRET );
		$this->assertTrue( hash_equals( $expected, $sig ), 'Signature must match HMAC over the payload.' );

		$data = Vegax_AIS_Identity::verify( $token );
		$this->assertIsArray( $data );
		$this->assertSame( 'wp:42', $data['cid'] );
		$this->assertSame( 'shop.example.com', $data['store'] );
	}

	public function test_token_expires_in_about_fifteen_minutes() {
		vegax_test_reset( $this->options( array( 'identity_verification' => 1 ) ) );
		$GLOBALS['vegax_test_logged_in'] = true;

		$before = time();
		$data   = Vegax_AIS_Identity::verify( Vegax_AIS_Identity::token() );
		$this->assertIsArray( $data );
		$this->assertGreaterThanOrEqual( $before + 895, (int) $data['exp'] );
		$this->assertLessThanOrEqual( $before + 905, (int) $data['exp'] );
	}

	public function test_verify_rejects_expired_token() {
		vegax_test_reset( $this->options( array( 'identity_verification' => 1 ) ) );
		$token = $this->mint( array( 'cid' => 'wp:7', 'store' => 'shop.example.com', 'exp' => time() - 10 ) );
		$this->assertFalse( Vegax_AIS_Identity::verify( $token ), 'An expired token must be refused.' );
	}

	public function test_verify_rejects_tampered_signature() {
		vegax_test_reset( $this->options( array( 'identity_verification' => 1 ) ) );
		$GLOBALS['vegax_test_logged_in'] = true;
		$token = Vegax_AIS_Identity::token();
		// Flip the last hex character of the signature.
		$token[ strlen( $token ) - 1 ] = ( '0' === $token[ strlen( $token ) - 1 ] ) ? '1' : '0';
		$this->assertFalse( Vegax_AIS_Identity::verify( $token ), 'A tampered signature must be refused.' );
	}

	public function test_verify_rejects_wrong_secret() {
		vegax_test_reset( $this->options( array( 'identity_verification' => 1 ) ) );
		$token = $this->mint(
			array( 'cid' => 'wp:7', 'store' => 'shop.example.com', 'exp' => time() + 900 ),
			'a-different-secret'
		);
		$this->assertFalse( Vegax_AIS_Identity::verify( $token ), 'A token signed with another key must be refused.' );
	}

	public function test_verify_rejects_garbage() {
		vegax_test_reset( $this->options( array( 'identity_verification' => 1 ) ) );
		$this->assertFalse( Vegax_AIS_Identity::verify( 'not-a-token' ) );
		$this->assertFalse( Vegax_AIS_Identity::verify( '' ) );
		$this->assertFalse( Vegax_AIS_Identity::verify( 'a.' ) );
	}

	public function test_personalises_page_only_when_on_and_signed_in() {
		vegax_test_reset( $this->options() );
		$GLOBALS['vegax_test_logged_in'] = true;
		$this->assertFalse( Vegax_AIS_Identity::personalises_page(), 'Off => not personalised.' );

		vegax_test_reset( $this->options( array( 'identity_verification' => 1 ) ) );
		$GLOBALS['vegax_test_logged_in'] = false;
		$this->assertFalse( Vegax_AIS_Identity::personalises_page(), 'Anonymous => not personalised.' );

		vegax_test_reset( $this->options( array( 'identity_verification' => 1 ) ) );
		$GLOBALS['vegax_test_logged_in'] = true;
		$this->assertTrue( Vegax_AIS_Identity::personalises_page(), 'On + signed in => personalised.' );
	}

	/**
	 * Build a token with an arbitrary payload / secret (mirrors token()).
	 *
	 * @param array  $payload Payload.
	 * @param string $secret  Signing key.
	 * @return string
	 */
	private function mint( array $payload, $secret = self::SECRET ) {
		$part = rtrim( strtr( base64_encode( wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
		return $part . '.' . hash_hmac( 'sha256', 'identity|' . $part, $secret );
	}
}
