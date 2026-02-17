<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin data from the database for a clean uninstall.
 *
 * @package Woo_HubSpot_Product_Tag_Sync
 * @since   1.0.0
 */

// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options.
delete_option('whpts_settings');
delete_option('whpts_product_mappings');
delete_option('whpts_version');

// Delete transients.
delete_transient('whpts_cache');

// Clean up order meta (optional — may want to preserve sync history).
// Uncomment the following if you want full cleanup:
// global $wpdb;
// $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_whpts_synced', '_whpts_synced_at')" );
