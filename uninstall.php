<?php
/**
 * Uninstall routine for Foundation: Pathless
 *
 * Runs when the plugin is deleted from WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

/**
 * -------------------------------------------------------------------------
 * Drop custom database tables
 * -------------------------------------------------------------------------
 */
$table_name = $wpdb->prefix . 'fp_links';
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );

/**
 * -------------------------------------------------------------------------
 * Delete plugin options / transients
 * -------------------------------------------------------------------------
 */
$options = [
    'fp_last_post_scanned_id',
    'fp_scan_status',
    'fp_enable_scheduled_scan',
    'fp_a11y_blacklist',
    'fnd_conversa_options', // onboarding/settings consolidated
];

foreach ($options as $option) {
    delete_option($option);
    delete_site_option($option); // in case used on multisite
}

/**
 * -------------------------------------------------------------------------
 * Clear scheduled events (cron jobs)
 * -------------------------------------------------------------------------
 */
$hook = 'fp_weekly_scan_event';
while ( $timestamp = wp_next_scheduled( $hook ) ) {
    wp_unschedule_event( $timestamp, $hook );
}
