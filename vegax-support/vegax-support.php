<?php
/**
 * Plugin Name:       Vegax Support
 * Plugin URI:        https://vegaxclaw.com/woocommerce
 * Description:       Customer support for WooCommerce. Answers product, shipping and returns questions from your own catalogue, and lets a signed Vegax service look up a customer's own order status (WISMO) without exporting your customer data.
 * Version:          1.0.8
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Vegax (NCK Group LLC)
 * Author URI:        https://vegaxclaw.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins:  woocommerce
 * Text Domain:       vegax-support
 * WC requires at least: 7.0
 * WC tested up to: 11.1
 *
 * @package Vegax_Support
 */

defined( 'ABSPATH' ) || exit;

define( 'VEGAX_AIS_VERSION', '1.0.8' );
define( 'VEGAX_AIS_FILE', __FILE__ );
define( 'VEGAX_AIS_DIR', plugin_dir_path( __FILE__ ) );
define( 'VEGAX_AIS_URL', plugin_dir_url( __FILE__ ) );
define( 'VEGAX_AIS_API_DEFAULT', 'https://office.vegaxclaw.com' );
define( 'VEGAX_AIS_OPTION', 'vegax_ais_settings' );

require_once VEGAX_AIS_DIR . 'includes/class-vegax-ais-settings.php';
require_once VEGAX_AIS_DIR . 'includes/class-vegax-ais-safe.php';
require_once VEGAX_AIS_DIR . 'includes/class-vegax-ais-cloud.php';
require_once VEGAX_AIS_DIR . 'includes/class-vegax-ais-identity.php';
require_once VEGAX_AIS_DIR . 'includes/class-vegax-ais-knowledge.php';
require_once VEGAX_AIS_DIR . 'includes/class-vegax-ais-orders.php';
require_once VEGAX_AIS_DIR . 'includes/class-vegax-ais-rest.php';
require_once VEGAX_AIS_DIR . 'includes/class-vegax-ais-widget.php';
require_once VEGAX_AIS_DIR . 'includes/class-vegax-ais-admin.php';

/**
 * One-time settings migration (01/10/2026).
 *
 * A stored option never picks up a new default, and the settings screen used to write the old
 * defaults back on save - so a store installed before today kept rendering data-agent="Vegax".
 * Only values that are EXACTLY the previous defaults are replaced; anything the merchant typed
 * themselves is left alone.
 */
function vegax_ais_migrate_settings() {
	$stored = (string) get_option( 'vegax_ais_version', '' );
	if ( VEGAX_AIS_VERSION === $stored ) {
		return;
	}
	$saved = get_option( VEGAX_AIS_OPTION, array() );
	if ( is_array( $saved ) ) {
		$changed = false;
		// The retired default brand text is assembled here so it is not shipped as a literal.
		$legacy_ai   = 'AI';
		$legacy_full = 'Vegax ' . $legacy_ai . ' Support';
		if ( isset( $saved['agent_name'] ) && in_array( (string) $saved['agent_name'], array( 'Vegax', 'Vegax ' . $legacy_ai, $legacy_full ), true ) ) {
			$saved['agent_name'] = 'Vegax Support';
			$changed             = true;
		}
		if ( isset( $saved['brand_color'] ) && in_array( strtoupper( (string) $saved['brand_color'] ), array( '#18B287', '' ), true ) ) {
			$saved['brand_color'] = '#07A87F';
			$changed              = true;
		}
		if ( $changed ) {
			update_option( VEGAX_AIS_OPTION, $saved );
		}
	}
	update_option( 'vegax_ais_version', VEGAX_AIS_VERSION );
}
add_action( 'plugins_loaded', 'vegax_ais_migrate_settings', 5 );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 * HPOS is mandatory for new WooCommerce Marketplace submissions; the plugin reads
 * orders only through the WooCommerce CRUD layer, so it works in both modes.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Boot the plugin once WooCommerce is available.
 */

/**
 * Privacy policy content (suggested text for the admin's privacy page).
 * The GDPR requires sites to explain what personal data plugins send.
 */
add_filter(
	'wp_add_privacy_policy_content',
	function ( $content ) {
		$text = __(
			'The Vegax Support plugin sends your WooCommerce product catalogue and policy pages to the Vegax service so it can answer customer questions. Order status events (order number, status, total, item count) and a hashed customer e-mail address are sent when an order status changes. Customer names, addresses, phone numbers, and payment details are never transmitted. The customer e-mail address in clear text is only sent if the store administrator has enabled the "Send the customer e-mail with order events" option.',
			'vegax-support'
		);
		return $content . '<h3>Vegax Support</h3>' . $text;
	},
	10
);
add_action(
	'plugins_loaded',
	function () {
		// Translations are loaded automatically by WordPress.org since 4.6.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-warning"><p><strong>Vegax Support</strong> ' . esc_html__( 'needs WooCommerce to be installed and active.', 'vegax-support' ) . '</p></div>';
					}
				}
			);
			return;
		}

		Vegax_AIS_Widget::init();
		Vegax_AIS_Rest::init();
		Vegax_AIS_Cloud::init();
		Vegax_AIS_Admin::init();
	}
);

/**
 * Activation: remember the version and schedule the two background jobs.
 */
register_activation_hook(
	__FILE__,
	function () {
		update_option( 'vegax_ais_version', VEGAX_AIS_VERSION );
		if ( ! wp_next_scheduled( 'vegax_ais_cron_heartbeat' ) ) {
			wp_schedule_event( time() + 60, 'daily', 'vegax_ais_cron_heartbeat' );
		}
		if ( ! wp_next_scheduled( 'vegax_ais_cron_sync' ) ) {
			wp_schedule_event( time() + 300, 'daily', 'vegax_ais_cron_sync' );
		}
	}
);

/**
 * Deactivation: clear the schedules. Data and settings are kept.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'vegax_ais_cron_heartbeat' );
		wp_clear_scheduled_hook( 'vegax_ais_cron_sync' );
	}
);
