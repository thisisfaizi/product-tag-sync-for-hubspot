<?php
/**
 * Main plugin orchestrator class.
 *
 * @package    Woo_HubSpot_Product_Tag_Sync
 * @subpackage Woo_HubSpot_Product_Tag_Sync/includes
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WHPTS_Plugin
 *
 * Central orchestrator that initializes all plugin modules.
 *
 * @since 1.0.0
 */
class WHPTS_Plugin
{

    /**
     * HubSpot API instance.
     *
     * @since 1.0.0
     * @var WHPTS_HubSpot_API
     */
    private $api;

    /**
     * Order handler instance.
     *
     * @since 1.0.0
     * @var WHPTS_Order_Handler
     */
    private $order_handler;

    /**
     * Settings instance.
     *
     * @since 1.0.0
     * @var WHPTS_Settings
     */
    private $settings;

    /**
     * Initialize all plugin modules.
     *
     * @since 1.0.0
     */
    public function init()
    {

        // Initialize API client.
        $this->api = new WHPTS_HubSpot_API();

        // Initialize admin settings.
        if (is_admin()) {
            $this->settings = new WHPTS_Settings($this->api);
            $this->settings->init();
        }

        // Initialize order handler (always, since hooks fire on order status changes).
        $this->order_handler = new WHPTS_Order_Handler($this->api);
        $this->order_handler->init();

        // Add settings link to plugins page.
        add_filter('plugin_action_links_' . WHPTS_PLUGIN_BASENAME, array($this, 'add_action_links'));
    }


    /**
     * Add settings link to the plugins page.
     *
     * @since 1.0.0
     *
     * @param array $links Existing plugin action links.
     * @return array Modified action links.
     */
    public function add_action_links($links)
    {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=whpts-settings')),
            esc_html__('Settings', 'product-tag-sync-for-hubspot')
        );

        array_unshift($links, $settings_link);

        return $links;
    }
}
