<?php
/**
 * Settings helpers — store key derivation and connection state.
 *
 * @package Vegax_Support
 */

use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	public function test_store_key_is_home_host_without_www() {
		// home_url() stub is https://shop.example.com
		vegax_test_reset( array() );
		$this->assertSame( 'shop.example.com', Vegax_AIS_Settings::store_id() );
	}

	public function test_override_host_is_normalised() {
		vegax_test_reset( array( 'store_id_override' => 'WWW.MyShop.Example.com' ) );
		$this->assertSame( 'myshop.example.com', Vegax_AIS_Settings::store_id() );
	}

	public function test_override_accepts_full_url() {
		vegax_test_reset( array( 'store_id_override' => 'https://cdn.shop.example.net/path' ) );
		$this->assertSame( 'cdn.shop.example.net', Vegax_AIS_Settings::store_id() );
	}

	public function test_api_base_has_no_trailing_slash() {
		vegax_test_reset( array( 'api_base' => 'https://office.vegaxclaw.com/' ) );
		$this->assertSame( 'https://office.vegaxclaw.com', Vegax_AIS_Settings::api_base() );
	}

	public function test_connected_requires_token_and_secret() {
		vegax_test_reset( array( 'store_token' => 't', 'store_secret' => '' ) );
		$this->assertFalse( Vegax_AIS_Settings::is_connected() );

		vegax_test_reset( array( 'store_token' => 't', 'store_secret' => 's' ) );
		$this->assertTrue( Vegax_AIS_Settings::is_connected() );
	}
}
