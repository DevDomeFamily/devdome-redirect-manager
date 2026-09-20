<?php
/**
 * Runs only when the plugin is deleted from the WordPress admin.
 * Removes all DevDome Redirect Manager data (settings table, options, transients).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$devdredi_table = $wpdb->prefix . 'devdredi_settings';
// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB -- one-time uninstall cleanup of the plugin's own data; table from $wpdb->prefix; static LIKE literals.
$wpdb->query( "DROP TABLE IF EXISTS {$devdredi_table}" );
$devdredi_passes = $wpdb->prefix . 'devdredi_passes';
$wpdb->query( "DROP TABLE IF EXISTS {$devdredi_passes}" );

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'devdredi_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_devdredi_%' OR option_name LIKE '_transient_timeout_devdredi_%'" );
// phpcs:enable WordPress.DB, PluginCheck.Security.DirectDB

// Coordinate shared-core cleanup (only if this is the last DevDome plugin installed).
require_once __DIR__ . '/lib/devdome-core/uninstall.php';
devdcorev1_uninstall_cleanup( 'devdome-redirect-manager/devdome-redirect-manager.php' );

if ( function_exists( 'wp_cache_flush' ) ) {
	wp_cache_flush();
}
