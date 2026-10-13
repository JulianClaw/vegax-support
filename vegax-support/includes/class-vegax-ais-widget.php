<?php
/**
 * Injects the chat widget into the storefront footer.
 *
 * The widget script is served by Vegax; this plugin only prints the tag with the
 * store key, which is what lets the agent answer on this exact domain.
 *
 * @package Vegax_Support
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end output.
 */
class Vegax_AIS_Widget {

	/**
	 * Hook the footer + the anti-cache guard.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 99 );
		// Run BEFORE any output so a personalised page can be flagged uncacheable while
		// headers are still editable (Intercom lesson: never let a cache reuse a user token).
		add_action( 'template_redirect', array( __CLASS__, 'maybe_disable_cache' ), 1 );
	}

	/**
	 * Should the widget be printed on this request?
	 *
	 * @return bool
	 */
	public static function should_render() {
		if ( (int) Vegax_AIS_Settings::get( 'widget_enabled' ) !== 1 ) {
			return false;
		}
		if ( ! Vegax_AIS_Settings::is_connected() ) {
			return false;
		}
		// Guideline 7 (13/10/2026): printing the widget tag is itself a request to the Vegax
		// service, so it is only printed while the administrator keeps the data-sharing
		// consent ticked. Unticking the box stops every outbound request within one page view.
		if ( (int) Vegax_AIS_Settings::get( 'consent_sync' ) !== 1 ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_preview() ) {
			return false;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
			// Keep the bubble away from the checkout form itself.
			return (bool) apply_filters( 'vegax_ais_widget_on_checkout', false );
		}
		return (bool) apply_filters( 'vegax_ais_widget_should_render', true );
	}

	/**
	 * Stop a page cache / CDN storing a PERSONALISED storefront.
	 *
	 * When Identity verification is on, the footer carries a per-shopper signed token. If a
	 * page cache (or a CDN) stored that HTML and re-served it to another visitor, the second
	 * visitor's chat could be attributed to the first shopper — the exact cache hazard Intercom
	 * warns about. So, whenever this request would carry a token, the response is marked
	 * uncacheable BEFORE output: the DONOTCACHEPAGE constant (honoured by WP Super Cache, W3TC,
	 * LiteSpeed Cache, ...) plus WordPress' own nocache_headers(), plus a Vary: Cookie hint so
	 * a cache that keys on the URL alone cannot reuse a personalised page.
	 *
	 * Anonymous visitors (no token) are unaffected: their page stays cacheable, so storefront
	 * performance is unchanged for the ordinary shopper.
	 *
	 * @return void
	 */
	public static function maybe_disable_cache() {
		if ( ! self::should_render() ) {
			return;
		}
		if ( ! Vegax_AIS_Identity::personalises_page() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, not ours.
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Vary: Cookie', false );
		}
		/**
		 * Fires after a personalised storefront has been marked uncacheable.
		 *
		 * @param string $store Store key.
		 */
		do_action( 'vegax_ais_personalised_page_uncached', Vegax_AIS_Settings::store_id() );
	}

	/**
	 * Print the script tag.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::should_render() ) {
			return;
		}
		$store = Vegax_AIS_Settings::store_id();
		if ( '' === $store ) {
			return;
		}
		$src = Vegax_AIS_Settings::api_base() . '/widget.js';
		if ( '' === (string) $src ) {
			return;
		}

		$attributes = array(
			'src'         => $src,
			'data-store'  => $store,
			'data-agent'  => (string) Vegax_AIS_Settings::get( 'agent_name' ),
			'data-color'  => (string) Vegax_AIS_Settings::get( 'brand_color' ),
			'data-plugin' => 'vegax-support/' . VEGAX_AIS_VERSION,
		);

		// Identity is only attached when it verifies: fail closed rather than print a token
		// the service would reject, or (worse) one that names the wrong shopper.
		$identity = Vegax_AIS_Identity::token();
		if ( '' !== $identity && false !== Vegax_AIS_Identity::verify( $identity ) ) {
			$attributes['data-identity'] = $identity;
		}

		/**
		 * Filter the <script> attributes printed on the storefront.
		 *
		 * Lets a site add or override an attribute without editing the plugin — for example
		 * change the bubble label (`data-label`), pin a colour, or add a data attribute the
		 * Vegax widget understands. Return the same shape: attribute name => raw value.
		 *
		 * @param array  $attributes Name => value.
		 * @param string $store      Store key.
		 */
		$attributes = (array) apply_filters( 'vegax_ais_widget_tag_attributes', $attributes, $store );

		$tag = '';
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- third-party tag served by Vegax; enqueuing would not change how it loads.
		foreach ( $attributes as $name => $value ) {
			$name = (string) $name;
			if ( '' === $name || '' === (string) $value ) {
				continue;
			}
			// src keeps esc_url (stricter protocol check); everything else is an attribute.
			$escaped = ( 'src' === $name ) ? Vegax_AIS_Safe::url( $value ) : Vegax_AIS_Safe::attr( $value );
			$tag    .= sprintf( ' %s="%s"', Vegax_AIS_Safe::attr( $name ), $escaped );
		}
		if ( '' !== $tag ) {
			printf( '<script%s></script>' . "\n", $tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part escaped above.
		}
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}
}
