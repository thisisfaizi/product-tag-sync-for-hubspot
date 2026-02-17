<?php
/**
 * Fired during plugin activation.
 *
 * @package    Woo_HubSpot_Product_Tag_Sync
 * @subpackage Woo_HubSpot_Product_Tag_Sync/includes
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WHPTS_Activator
 *
 * Sets default options on first activation.
 *
 * @since 1.0.0
 */
class WHPTS_Activator
{

    /**
     * Run activation tasks.
     *
     * Sets default plugin options if they do not already exist.
     *
     * @since 1.0.0
     */
    public static function activate()
    {
        // Set default settings if not already configured.
        if (false === get_option('whpts_settings')) {
            $defaults = array(
                'hubspot_token' => '',
                'property_name' => 'product_tag',
            );
            add_option('whpts_settings', $defaults);
        }

        // Initialize empty product mappings if not set.
        if (false === get_option('whpts_product_mappings')) {
            add_option('whpts_product_mappings', array());
        }

        // Store the plugin version for future upgrade routines.
        update_option('whpts_version', WHPTS_VERSION);
    }
}
