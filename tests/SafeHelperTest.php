<?php
/**
 * Shared sanitiser / escaper (Vegax_AIS_Safe).
 *
 * @package Vegax_Support
 */

use PHPUnit\Framework\TestCase;

class SafeHelperTest extends TestCase {

	public function test_post_text_sanitises() {
		$_POST = array( 'x' => "  <b>hi</b>\n" );
		$this->assertSame( 'hi', Vegax_AIS_Safe::post_text( 'x' ) );
		unset( $_POST );
	}

	public function test_post_text_default_when_absent() {
		$_POST = array();
		$this->assertSame( 'fallback', Vegax_AIS_Safe::post_text( 'missing', 'fallback' ) );
		unset( $_POST );
	}

	public function test_post_bool() {
		$_POST = array( 'on' => '1' );
		$this->assertSame( 1, Vegax_AIS_Safe::post_bool( 'on' ) );
		$this->assertSame( 0, Vegax_AIS_Safe::post_bool( 'off' ) );
		unset( $_POST );
	}

	public function test_post_int_clamps_to_range() {
		$_POST = array( 'lo' => '0', 'hi' => '9999' );
		$this->assertSame( 1, Vegax_AIS_Safe::post_int( 'lo', 300, 1, 2000 ) );
		$this->assertSame( 2000, Vegax_AIS_Safe::post_int( 'hi', 300, 1, 2000 ) );
		$this->assertSame( 300, Vegax_AIS_Safe::post_int( 'missing', 300, 1, 2000 ) );
		unset( $_POST );
	}

	public function test_post_color_rejects_invalid() {
		$_POST = array( 'bad' => 'red', 'good' => '#0a0b0c' );
		$this->assertSame( '#07A87F', Vegax_AIS_Safe::post_color( 'bad', '#07A87F' ) );
		$this->assertSame( '#0a0b0c', Vegax_AIS_Safe::post_color( 'good', '#07A87F' ) );
		unset( $_POST );
	}

	public function test_output_helpers_escape() {
		$this->assertSame( '&lt;b&gt;', Vegax_AIS_Safe::text( '<b>' ) );
		$this->assertSame( '&quot;x&quot;', Vegax_AIS_Safe::attr( '"x"' ) );
		$this->assertSame( '', Vegax_AIS_Safe::url( 'javascript:alert(1)' ) );
		$this->assertSame( 'https://ok.example/', Vegax_AIS_Safe::url( 'https://ok.example/' ) );
	}
}
