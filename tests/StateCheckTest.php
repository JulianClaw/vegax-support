<?php
/**
 * OAuth `state` check — the CSRF guard on the connect callback.
 *
 * @package Vegax_Support
 */

use PHPUnit\Framework\TestCase;

class StateCheckTest extends TestCase {

	public function test_accepts_identical_state() {
		$this->assertTrue( Vegax_AIS_Admin::state_matches( 'abc123', 'abc123' ) );
	}

	public function test_rejects_different_state() {
		$this->assertFalse( Vegax_AIS_Admin::state_matches( 'abc123', 'abc124' ) );
	}

	public function test_rejects_empty_expected() {
		// No state was stored before the trip => cannot be a valid callback.
		$this->assertFalse( Vegax_AIS_Admin::state_matches( '', 'abc123' ) );
	}

	public function test_rejects_empty_given() {
		// Callback without a state => refuse.
		$this->assertFalse( Vegax_AIS_Admin::state_matches( 'abc123', '' ) );
	}

	public function test_rejects_both_empty() {
		$this->assertFalse( Vegax_AIS_Admin::state_matches( '', '' ) );
	}

	public function test_is_case_sensitive() {
		$this->assertFalse( Vegax_AIS_Admin::state_matches( 'ABC', 'abc' ) );
	}
}
