<?php
/**
 * Plugin Name:       Product Tag Sync for HubSpot
 * Description:       Automatically assign HubSpot contact tags based on WooCommerce product purchases. Map products to HubSpot custom property values and sync contacts on order completion.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            nowdigiverse
 * Author URI:        https://www.nowdigiverse.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       product-tag-sync-for-hubspot
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.0
 *
 * @package Woo_HubSpot_Product_Tag_Sync
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
	exit;
}

// Plugin constants.
define('WHPTS_VERSION', '1.0.0');
define('WHPTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WHPTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WHPTS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include core class files.
require_once WHPTS_PLUGIN_DIR . 'includes/class-whpts-logger.php';
require_once WHPTS_PLUGIN_DIR . 'includes/class-whpts-activator.php';
require_once WHPTS_PLUGIN_DIR . 'includes/class-whpts-deactivator.php';
require_once WHPTS_PLUGIN_DIR . 'includes/class-whpts-hubspot-api.php';
require_once WHPTS_PLUGIN_DIR . 'includes/class-whpts-order-handler.php';
require_once WHPTS_PLUGIN_DIR . 'admin/class-whpts-settings.php';
require_once WHPTS_PLUGIN_DIR . 'includes/class-whpts-plugin.php';

// Activation and deactivation hooks.
register_activation_hook(__FILE__, array('WHPTS_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('WHPTS_Deactivator', 'deactivate'));

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 *
 * The plugin reads and writes order data exclusively through the WooCommerce
 * CRUD API (wc_get_order(), $order->get_meta(), $order->update_meta_data()),
 * so it is fully HPOS-compatible.
 *
 * @since 1.0.0
 */
add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

/**
 * Begin plugin execution.
 *
 * @since 1.0.0
 */
function whpts_run()
{
	// Check WooCommerce dependency.
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', 'whpts_woocommerce_missing_notice');
		return;
	}

	$plugin = new WHPTS_Plugin();
	$plugin->init();
}
add_action('plugins_loaded', 'whpts_run');

/**
 * Display admin notice when WooCommerce is not active.
 *
 * @since 1.0.0
 */
function whpts_woocommerce_missing_notice()
{
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e('HubSpot Product Tag Sync for WooCommerce requires WooCommerce to be installed and activated.', 'product-tag-sync-for-hubspot'); ?>
		</p>
	</div>
	<?php
}
