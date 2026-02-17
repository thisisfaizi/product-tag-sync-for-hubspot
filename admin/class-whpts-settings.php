<?php
/**
 * Admin settings and product mapping UI.
 *
 * @package    Woo_HubSpot_Product_Tag_Sync
 * @subpackage Woo_HubSpot_Product_Tag_Sync/admin
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WHPTS_Settings
 *
 * Handles the admin settings page with two tabs:
 * 1. Settings — HubSpot token and property name
 * 2. Product Mappings — Map WooCommerce products to HubSpot tag values
 *
 * @since 1.0.0
 */
class WHPTS_Settings
{

    /**
     * Option name for plugin settings.
     *
     * @since 1.0.0
     * @var string
     */
    private $option_name = 'whpts_settings';

    /**
     * Option name for product mappings.
     *
     * @since 1.0.0
     * @var string
     */
    private $mappings_option = 'whpts_product_mappings';

    /**
     * The admin page hook suffix.
     *
     * @since 1.0.0
     * @var string
     */
    private $hook_suffix = '';

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     */
    public function init()
    {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_mapping_save'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX handler for connection test.
        add_action('wp_ajax_whpts_test_connection', array($this, 'ajax_test_connection'));
    }

    /**
     * Add the plugin settings page under the WooCommerce menu.
     *
     * @since 1.0.0
     */
    public function add_menu_page()
    {
        $this->hook_suffix = add_submenu_page(
            'woocommerce',
            __('HubSpot Product Tags', 'product-tag-sync-for-hubspot'),
            __('HubSpot Tags', 'product-tag-sync-for-hubspot'),
            'manage_woocommerce',
            'whpts-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register plugin settings using the Settings API.
     *
     * @since 1.0.0
     */
    public function register_settings()
    {
        register_setting(
            'whpts_settings_group',
            $this->option_name,
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => $this->get_defaults(),
            )
        );

        add_settings_section(
            'whpts_api_section',
            __('HubSpot API Configuration', 'product-tag-sync-for-hubspot'),
            array($this, 'render_api_section'),
            'whpts-settings'
        );

        add_settings_field(
            'whpts_hubspot_token',
            __('Private App Token', 'product-tag-sync-for-hubspot'),
            array($this, 'render_token_field'),
            'whpts-settings',
            'whpts_api_section'
        );

        add_settings_field(
            'whpts_property_name',
            __('Contact Property Name', 'product-tag-sync-for-hubspot'),
            array($this, 'render_property_field'),
            'whpts-settings',
            'whpts_api_section'
        );
    }

    /**
     * Sanitize settings input.
     *
     * @since 1.0.0
     *
     * @param array $input Raw settings input.
     * @return array Sanitized settings.
     */
    public function sanitize_settings($input)
    {
        $sanitized = array();

        $sanitized['hubspot_token'] = sanitize_text_field($input['hubspot_token'] ?? '');
        $sanitized['property_name'] = sanitize_key($input['property_name'] ?? 'product_tag');

        // Ensure property name has a default.
        if (empty($sanitized['property_name'])) {
            $sanitized['property_name'] = 'product_tag';
        }

        return $sanitized;
    }

    /**
     * Get default settings values.
     *
     * @since 1.0.0
     *
     * @return array Default settings.
     */
    public function get_defaults()
    {
        return array(
            'hubspot_token' => '',
            'property_name' => 'product_tag',
        );
    }

    /**
     * Enqueue admin CSS and JS on our settings page only.
     *
     * @since 1.0.0
     *
     * @param string $hook The current admin page hook suffix.
     */
    public function enqueue_admin_assets($hook)
    {
        if ($this->hook_suffix !== $hook) {
            return;
        }

        wp_enqueue_style(
            'whpts-admin',
            WHPTS_PLUGIN_URL . 'admin/css/whpts-admin.css',
            array(),
            WHPTS_VERSION
        );

        wp_enqueue_script(
            'whpts-admin',
            WHPTS_PLUGIN_URL . 'admin/js/whpts-admin.js',
            array('jquery'),
            WHPTS_VERSION,
            true
        );

        wp_localize_script(
            'whpts-admin',
            'whptsAdmin',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('whpts_admin_nonce'),
                'i18n' => array(
                    'testing' => __('Testing connection...', 'product-tag-sync-for-hubspot'),
                    'success' => __('Connection successful!', 'product-tag-sync-for-hubspot'),
                    'error' => __('Connection failed: ', 'product-tag-sync-for-hubspot'),
                    'filter' => __('Filter products...', 'product-tag-sync-for-hubspot'),
                ),
            )
        );
    }

    /**
     * Render the API section description.
     *
     * @since 1.0.0
     */
    public function render_api_section()
    {
        ?>
        <p>
            <?php esc_html_e('Configure your HubSpot Private App credentials. The token requires scopes: crm.objects.contacts.read, crm.objects.contacts.write, crm.schemas.contacts.read', 'product-tag-sync-for-hubspot'); ?>
        </p>
        <?php
    }

    /**
     * Render the HubSpot token field.
     *
     * @since 1.0.0
     */
    public function render_token_field()
    {
        $options = get_option($this->option_name, $this->get_defaults());
        $value = isset($options['hubspot_token']) ? $options['hubspot_token'] : '';
        ?>
        <input type="password" id="whpts_hubspot_token" name="<?php echo esc_attr($this->option_name . '[hubspot_token]'); ?>"
            value="<?php echo esc_attr($value); ?>" class="regular-text" autocomplete="off" />
        <button type="button" id="whpts-test-connection" class="button button-secondary">
            <?php esc_html_e('Test Connection', 'product-tag-sync-for-hubspot'); ?>
        </button>
        <span id="whpts-connection-status"></span>
        <p class="description">
            <?php esc_html_e('Enter your HubSpot Private App token. It will be stored securely and never exposed on the frontend.', 'product-tag-sync-for-hubspot'); ?>
        </p>
        <?php
    }

    /**
     * Render the property name field.
     *
     * @since 1.0.0
     */
    public function render_property_field()
    {
        $options = get_option($this->option_name, $this->get_defaults());
        $value = isset($options['property_name']) ? $options['property_name'] : 'product_tag';
        ?>
        <input type="text" id="whpts_property_name" name="<?php echo esc_attr($this->option_name . '[property_name]'); ?>"
            value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description">
            <?php esc_html_e('The internal name of your HubSpot Contact property (Multiple Checkboxes type). Example: product_tag', 'product-tag-sync-for-hubspot'); ?>
        </p>
        <?php
    }

    /**
     * Render the main settings page with tabs.
     *
     * @since 1.0.0
     */
    public function render_settings_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce not needed for tab display.
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        ?>
        <div class="wrap whpts-wrap">
            <h1>
                <?php esc_html_e('HubSpot Product Tag Sync', 'product-tag-sync-for-hubspot'); ?>
            </h1>

            <nav class="nav-tab-wrapper whpts-nav-tabs">
                <a href="<?php echo esc_url(admin_url('admin.php?page=whpts-settings&tab=settings')); ?>"
                    class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'product-tag-sync-for-hubspot'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=whpts-settings&tab=mappings')); ?>"
                    class="nav-tab <?php echo 'mappings' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Product Mappings', 'product-tag-sync-for-hubspot'); ?>
                </a>
            </nav>

            <div class="whpts-tab-content">
                <?php
                if ('mappings' === $active_tab) {
                    $this->render_mappings_tab();
                } else {
                    $this->render_settings_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Settings tab content.
     *
     * @since 1.0.0
     */
    private function render_settings_tab()
    {
        ?>
        <form action="options.php" method="post">
            <?php
            settings_fields('whpts_settings_group');
            do_settings_sections('whpts-settings');
            submit_button();
            ?>
        </form>
        <?php
    }

    /**
     * Render the Product Mappings tab content.
     *
     * @since 1.0.0
     */
    private function render_mappings_tab()
    {
        $mappings = get_option($this->mappings_option, array());
        $settings = get_option($this->option_name, $this->get_defaults());
        $property_name = isset($settings['property_name']) ? $settings['property_name'] : 'product_tag';

        // Get all published WooCommerce products.
        $products = wc_get_products(
            array(
                'status' => 'publish',
                'limit' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
            )
        );

        // Fetch HubSpot property options.
        $tag_options = array();
        $options_error = '';
        $api = new WHPTS_HubSpot_API();

        if ($api->is_configured()) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce not needed for cache refresh.
            $force_refresh = isset($_GET['whpts_refresh']) && 'true' === sanitize_key($_GET['whpts_refresh']);
            $result = $api->get_property_options($property_name, $force_refresh);

            if (is_wp_error($result)) {
                $options_error = $result->get_error_message();
            } else {
                $tag_options = $result;
            }
        } else {
            $options_error = __('HubSpot API token not configured. Please set your token in the Settings tab first.', 'product-tag-sync-for-hubspot');
        }

        // Check for save success notice.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce not needed for display notice.
        $saved = isset($_GET['whpts_saved']) ? sanitize_key($_GET['whpts_saved']) : '';
        if ('true' === $saved) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php esc_html_e('Product mappings saved successfully.', 'product-tag-sync-for-hubspot'); ?>
                </p>
            </div>
            <?php
        }

        if (!empty($options_error)) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: error message */
                            __('Could not load HubSpot tag options: %s', 'product-tag-sync-for-hubspot'),
                            $options_error
                        )
                    );
                    ?>
                </p>
            </div>
            <?php
        }
        ?>

        <div class="whpts-mappings-header">
            <p>
                <?php esc_html_e('Assign a HubSpot tag value to each WooCommerce product. Only products with a tag value will be synced to HubSpot when purchased.', 'product-tag-sync-for-hubspot'); ?>
            </p>
            <div class="whpts-mappings-actions">
                <input type="text" id="whpts-product-filter"
                    placeholder="<?php esc_attr_e('Filter products by name...', 'product-tag-sync-for-hubspot'); ?>"
                    class="regular-text" />
                <a href="<?php echo esc_url(admin_url('admin.php?page=whpts-settings&tab=mappings&whpts_refresh=true')); ?>"
                   class="button button-secondary" title="<?php esc_attr_e('Reload tag options from HubSpot', 'product-tag-sync-for-hubspot'); ?>">
                    <?php esc_html_e('Refresh Tags', 'product-tag-sync-for-hubspot'); ?>
                </a>
            </div>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('whpts_save_mappings', 'whpts_mappings_nonce'); ?>
            <input type="hidden" name="whpts_save_mappings" value="1" />

            <table class="wp-list-table widefat fixed striped whpts-mappings-table">
                <thead>
                    <tr>
                        <th class="whpts-col-id">
                            <?php esc_html_e('ID', 'product-tag-sync-for-hubspot'); ?>
                        </th>
                        <th class="whpts-col-product">
                            <?php esc_html_e('Product Name', 'product-tag-sync-for-hubspot'); ?>
                        </th>
                        <th class="whpts-col-sku">
                            <?php esc_html_e('SKU', 'product-tag-sync-for-hubspot'); ?>
                        </th>
                        <th class="whpts-col-tag">
                            <?php esc_html_e('HubSpot Tag', 'product-tag-sync-for-hubspot'); ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="4">
                                <?php esc_html_e('No WooCommerce products found.', 'product-tag-sync-for-hubspot'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $product_id = $product->get_id();
                            $tag_value = isset($mappings[$product_id]) ? $mappings[$product_id] : '';
                            ?>
                            <tr class="whpts-mapping-row" data-product-name="<?php echo esc_attr(strtolower($product->get_name())); ?>">
                                <td class="whpts-col-id">
                                    <?php echo esc_html($product_id); ?>
                                </td>
                                <td class="whpts-col-product">
                                    <strong>
                                        <?php echo esc_html($product->get_name()); ?>
                                    </strong>
                                </td>
                                <td class="whpts-col-sku">
                                    <?php echo esc_html($product->get_sku()); ?>
                                </td>
                                <td class="whpts-col-tag">
                                    <?php if (!empty($tag_options)): ?>
                                        <select name="<?php echo esc_attr('whpts_mapping[' . $product_id . ']'); ?>"
                                                class="whpts-tag-select">
                                            <option value="">
                                                <?php esc_html_e('— None —', 'product-tag-sync-for-hubspot'); ?>
                                            </option>
                                            <?php foreach ($tag_options as $option): ?>
                                                <option value="<?php echo esc_attr($option['value']); ?>"
                                                    <?php selected($tag_value, $option['value']); ?>>
                                                    <?php echo esc_html($option['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="text"
                                            name="<?php echo esc_attr('whpts_mapping[' . $product_id . ']'); ?>"
                                            value="<?php echo esc_attr($tag_value); ?>"
                                            class="regular-text whpts-tag-input"
                                            placeholder="<?php esc_attr_e('e.g. stock_solace', 'product-tag-sync-for-hubspot'); ?>" />
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (!empty($products)): ?>
                <?php submit_button(__('Save Mappings', 'product-tag-sync-for-hubspot')); ?>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * Handle saving of product mappings.
     *
     * @since 1.0.0
     */
    public function handle_mapping_save()
    {
        // Check if our form was submitted.
        if (!isset($_POST['whpts_save_mappings'])) {
            return;
        }

        // Verify nonce.
        if (
            !isset($_POST['whpts_mappings_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['whpts_mappings_nonce'])), 'whpts_save_mappings')
        ) {
            wp_die(esc_html__('Security check failed.', 'product-tag-sync-for-hubspot'));
        }

        // Check capabilities.
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to manage these settings.', 'product-tag-sync-for-hubspot'));
        }

        // Sanitize and save mappings.
        $mappings = array();

        if (isset($_POST['whpts_mapping']) && is_array($_POST['whpts_mapping'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
            $raw_mappings = wp_unslash($_POST['whpts_mapping']);

            foreach ($raw_mappings as $product_id => $tag_value) {
                $product_id = absint($product_id);
                $tag_value = sanitize_text_field($tag_value);

                // Only store non-empty mappings.
                if ($product_id > 0 && !empty($tag_value)) {
                    $mappings[$product_id] = $tag_value;
                }
            }
        }

        update_option($this->mappings_option, $mappings);

        // Redirect back to mappings tab with success notice.
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'whpts-settings',
                    'tab' => 'mappings',
                    'whpts_saved' => 'true',
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * AJAX handler for testing HubSpot connection.
     *
     * @since 1.0.0
     */
    public function ajax_test_connection()
    {
        check_ajax_referer('whpts_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(
                array('message' => __('Unauthorized.', 'product-tag-sync-for-hubspot')),
                403
            );
        }

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';

        if (empty($token)) {
            wp_send_json_error(
                array('message' => __('Please enter a token first.', 'product-tag-sync-for-hubspot'))
            );
        }

        $api = new WHPTS_HubSpot_API($token);
        $result = $api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(
                array('message' => $result->get_error_message())
            );
        }

        // Save the token on successful connection test so it persists on refresh.
        $settings = get_option('whpts_settings', $this->get_defaults());
        $settings['hubspot_token'] = $token;
        update_option('whpts_settings', $settings);

        wp_send_json_success(
            array('message' => __('Connection successful! Token saved.', 'product-tag-sync-for-hubspot'))
        );
    }
}
