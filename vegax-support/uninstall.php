<?php
/**
 * Removes everything the plugin stored when it is deleted from WordPress.
 *
 * @package Vegax_Support
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'vegax_ais_settings' );
delete_option( 'vegax_ais_status' );
delete_option( 'vegax_ais_version' );

// Clear the scheduled jobs and the rate-limit transients.
wp_clear_scheduled_hook( 'vegax_ais_cron_heartbeat' );
wp_clear_scheduled_hook( 'vegax_ais_cron_sync' );
wp_clear_scheduled_hook( 'vegax_ais_cron_refresh' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vegax_ais_%' OR option_name LIKE '_transient_timeout_vegax_ais_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
