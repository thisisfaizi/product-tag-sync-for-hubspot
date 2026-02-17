<?php
/**
 * Fired during plugin deactivation.
 *
 * @package    Woo_HubSpot_Product_Tag_Sync
 * @subpackage Woo_HubSpot_Product_Tag_Sync/includes
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WHPTS_Deactivator
 *
 * Cleans up scheduled events on deactivation.
 *
 * @since 1.0.0
 */
class WHPTS_Deactivator
{

    /**
     * Run deactivation tasks.
     *
     * Clears any scheduled cron events. Does NOT delete options
     * (that is handled by uninstall.php for clean removal).
     *
     * @since 1.0.0
     */
    public static function deactivate()
    {
        // Clear any scheduled cron events (for future Action Scheduler support).
        wp_clear_scheduled_hook('whpts_sync_cron');
    }
}
