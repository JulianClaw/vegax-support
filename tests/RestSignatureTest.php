<?php
/**
 * The signed REST routes the Vegax service calls back (order-lookup / knowledge).
 *
 * @package Vegax_Support
 */

use PHPUnit\Framework\TestCase;

/**
 * Exposes the protected verifier for testing.
 */
class Vegax_AIS_Rest_Testable extends Vegax_AIS_Rest {
	public static function check( $request, $action, $email = '', $order = '' ) {
		return self::verify( $request, $action, $email, $order );
	}
}

class RestSignatureTest extends TestCase {

	const SECRET = 'rest-unit-secret';

	private function options( array $extra = array() ) {
		return array_merge(
			array(
				'store_token'  => 'tok-abc',
				'store_secret' => self::SECRET,
			),
			$extra
		);
	}

	/**
	 * Build a request whose signature is valid.
	 *
	 * @param array  $over Extra/overriding params.
	 * @param string $pass The password-secret used to sign (to test wrong keys).
	 * @return WP_REST_Request
	 */
	private function signed_request( array $over = array(), $secret = self::SECRET ) {
		$ts    = isset( $over['ts'] ) ? $over['ts'] : (string) time();
		$email = isset( $over['email'] ) ? $over['email'] : 'buyer@example.com';
		$order = isset( $over['order'] ) ? $over['order'] : '';
		$store = Vegax_AIS_Settings::store_id();
		$sig   = hash_hmac( 'sha256', Vegax_AIS_Rest::canonical( 'order-lookup', $store, $email, $order, $ts ), $secret );
		return new WP_REST_Request(
			array_merge(
				array(
					'ts'    => $ts,
					'sig'   => $sig,
					'store' => $store,
					'email' => $email,
					'order' => $order,
				),
				$over
			)
		);
	}

	public function test_canonical_string_shape() {
		// Store is lower-cased; e-mail and order are trimmed + e-mail lower-cased.
		$this->assertSame(
			'order-lookup|shop.example.com|buyer@example.com|1001|1700000000',
			Vegax_AIS_Rest::canonical( 'order-lookup', 'SHOP.example.com', ' Buyer@Example.com ', ' 1001 ', '1700000000' )
		);
	}

	public function test_valid_signature_passes() {
		vegax_test_reset( $this->options() );
		$req = $this->signed_request();
		$this->assertTrue( Vegax_AIS_Rest_Testable::check( $req, 'order-lookup', 'buyer@example.com' ) );
	}

	public function test_stale_timestamp_is_refused() {
		vegax_test_reset( $this->options() );
		$req  = $this->signed_request( array( 'ts' => (string) ( time() - 1000 ) ) );
		$check = Vegax_AIS_Rest_Testable::check( $req, 'order-lookup', 'buyer@example.com' );
		$this->assertInstanceOf( WP_Error::class, $check );
		$this->assertSame( 'vegax_stale', $check->get_error_code() );
	}

	public function test_wrong_store_is_refused() {
		vegax_test_reset( $this->options() );
		$req  = $this->signed_request( array( 'store' => 'other.example.com' ) );
		$check = Vegax_AIS_Rest_Testable::check( $req, 'order-lookup', 'buyer@example.com' );
		$this->assertInstanceOf( WP_Error::class, $check );
		$this->assertSame( 'vegax_wrong_store', $check->get_error_code() );
	}

	public function test_bad_signature_is_refused() {
		vegax_test_reset( $this->options() );
		$req  = $this->signed_request( array(), 'the-wrong-secret' );
		$check = Vegax_AIS_Rest_Testable::check( $req, 'order-lookup', 'buyer@example.com' );
		$this->assertInstanceOf( WP_Error::class, $check );
		$this->assertSame( 'vegax_bad_sig', $check->get_error_code() );
	}

	public function test_non_connected_store_is_refused() {
		vegax_test_reset( array( 'store_token' => '', 'store_secret' => '' ) );
		$req  = $this->signed_request();
		$check = Vegax_AIS_Rest_Testable::check( $req, 'order-lookup', 'buyer@example.com' );
		$this->assertInstanceOf( WP_Error::class, $check );
		$this->assertSame( 'vegax_not_connected', $check->get_error_code() );
	}

	public function test_non_numeric_timestamp_is_refused() {
		vegax_test_reset( $this->options() );
		$req  = $this->signed_request( array( 'ts' => 'abc' ) );
		$check = Vegax_AIS_Rest_Testable::check( $req, 'order-lookup', 'buyer@example.com' );
		$this->assertInstanceOf( WP_Error::class, $check );
		$this->assertSame( 'vegax_bad_sig', $check->get_error_code() );
	}
}
