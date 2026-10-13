<?php
/**
 * Storefront widget tag: what is printed, and the cache guard.
 *
 * @package Vegax_Support
 */

use PHPUnit\Framework\TestCase;

class WidgetRenderTest extends TestCase {

	private function connected( array $extra = array() ) {
		return array_merge(
			array(
				'widget_enabled'        => 1,
				'consent_sync'          => 1,
				'store_token'           => 'tok',
				'store_secret'          => 'secret',
				'agent_name'            => 'Vegax Support',
				'brand_color'           => '#07A87F',
				'identity_verification' => 0,
			),
			$extra
		);
	}

	private function rendered() {
		ob_start();
		Vegax_AIS_Widget::render();
		return ob_get_clean();
	}

	public function test_no_tag_when_consent_withdrawn() {
		// 13/10/2026 — the widget tag is itself a request to the Vegax service, so withdrawing
		// data-sharing consent must remove it from the storefront even while connected.
		vegax_test_reset( $this->connected( array( 'consent_sync' => 0 ) ) );
		$this->assertSame( '', $this->rendered() );
		$this->assertFalse( Vegax_AIS_Widget::should_render() );
	}

	public function test_no_tag_when_not_connected() {
		vegax_test_reset( array( 'widget_enabled' => 1, 'store_token' => '', 'store_secret' => '' ) );
		$this->assertSame( '', $this->rendered() );
	}

	public function test_tag_has_core_attributes() {
		vegax_test_reset( $this->connected() );
		$html = $this->rendered();
		$this->assertStringContainsString( 'widget.js', $html );
		$this->assertStringContainsString( 'data-store="shop.example.com"', $html );
		$this->assertStringContainsString( 'data-plugin="vegax-support/1.0.9"', $html );
	}

	public function test_no_identity_attribute_when_switch_off() {
		vegax_test_reset( $this->connected() );
		$GLOBALS['vegax_test_logged_in'] = true;
		$this->assertStringNotContainsString( 'data-identity', $this->rendered(), 'Switch off => no token may be printed.' );
	}

	public function test_identity_attribute_present_when_on_and_signed_in() {
		vegax_test_reset( $this->connected( array( 'identity_verification' => 1 ) ) );
		$GLOBALS['vegax_test_logged_in'] = true;
		$this->assertStringContainsString( 'data-identity="', $this->rendered() );
	}

	public function test_cache_guard_silent_for_anonymous() {
		vegax_test_reset( $this->connected( array( 'identity_verification' => 1 ) ) );
		$GLOBALS['vegax_test_logged_in'] = false;
		Vegax_AIS_Widget::maybe_disable_cache();
		$this->assertFalse( $GLOBALS['vegax_test_nocache_called'], 'Anonymous page stays cacheable.' );
	}

	public function test_cache_guard_fires_for_personalised_page() {
		vegax_test_reset( $this->connected( array( 'identity_verification' => 1 ) ) );
		$GLOBALS['vegax_test_logged_in'] = true;
		Vegax_AIS_Widget::maybe_disable_cache();
		$this->assertTrue( $GLOBALS['vegax_test_nocache_called'], 'Personalised page must be marked uncacheable.' );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ), 'Page-cache plugins honour DONOTCACHEPAGE.' );
		// The `Vary: Cookie` header is also emitted, but PHP CLI defines header() natively so a
		// unit test cannot intercept it; it is verified by code review (see the report).
	}

	public function test_cache_guard_silent_when_switch_off() {
		vegax_test_reset( $this->connected() );
		$GLOBALS['vegax_test_logged_in'] = true;
		Vegax_AIS_Widget::maybe_disable_cache();
		$this->assertFalse( $GLOBALS['vegax_test_nocache_called'] );
	}
}
