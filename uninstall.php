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

// Remove cached HubSpot property-option transients before deleting settings.
// Transients are keyed by the configured property name (default: product_tag).
$whpts_settings = get_option('whpts_settings');
$whpts_property_names = array('product_tag');
if (is_array($whpts_settings) && !empty($whpts_settings['property_name'])) {
    $whpts_property_names[] = sanitize_key($whpts_settings['property_name']);
}
foreach (array_unique($whpts_property_names) as $whpts_property) {
    delete_transient('whpts_prop_opts_' . sanitize_key($whpts_property));
}

// Delete plugin options.
delete_option('whpts_settings');
delete_option('whpts_product_mappings');
delete_option('whpts_version');

// Clean up order meta (optional — may want to preserve sync history).
// Uncomment the following if you want full cleanup:
// global $wpdb;
// $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_whpts_synced', '_whpts_synced_at')" );
