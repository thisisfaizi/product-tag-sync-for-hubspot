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
     * HubSpot API instance.
     *
     * @since 1.1.0
     * @var WHPTS_HubSpot_API
     */
    private $api;

    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param WHPTS_HubSpot_API $api API instance.
     */
    public function __construct($api) {
        $this->api = $api;
    }

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

        add_action('admin_init', array($this, 'handle_field_mapping_save'));
        add_action('admin_init', array($this, 'handle_product_rule_save'));
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
                    'error' => __('Error: ', 'product-tag-sync-for-hubspot'),
                ),
                'contactProperties' => $this->api->is_configured() ? $this->api->get_all_contact_properties() : array(),
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
        <?php
        $api = new WHPTS_HubSpot_API();
        if ($api->is_using_parent_token()) {
            ?>
            <div class="notice notice-info inline">
                <p>
                    <strong><?php esc_html_e('Connected via MakeWebBetter HubSpot for WooCommerce.', 'product-tag-sync-for-hubspot'); ?></strong>
                </p>
                <p>
                    <?php esc_html_e('We are using the API token from the main HubSpot plugin. You do not need to enter one here.', 'product-tag-sync-for-hubspot'); ?>
                </p>
            </div>
            <input type="hidden" name="<?php echo esc_attr($this->option_name . '[hubspot_token]'); ?>" value="" />
            <?php
        } else {
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
                <a href="<?php echo esc_url(admin_url('admin.php?page=whpts-settings&tab=fields')); ?>"
                    class="nav-tab <?php echo 'fields' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Field Mappings', 'product-tag-sync-for-hubspot'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=whpts-settings&tab=rules')); ?>"
                    class="nav-tab <?php echo 'rules' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Product Rules', 'product-tag-sync-for-hubspot'); ?>
                </a>
            </nav>

            <div class="whpts-tab-content">
                <?php
                if ('mappings' === $active_tab) {
                    $this->render_mappings_tab();
                } elseif ('fields' === $active_tab) {
                    $this->render_field_mappings_tab();
                } elseif ('rules' === $active_tab) {
                    $this->render_product_rules_tab();
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
     * Render the Field Mappings tab content.
     *
     * @since 1.0.0
     */
    private function render_field_mappings_tab()
    {
        $field_mappings = get_option('whpts_field_mappings', array());

        // Check for save success notice.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $saved = isset($_GET['whpts_fields_saved']) ? sanitize_key($_GET['whpts_fields_saved']) : '';
        if ('true' === $saved) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Field mappings saved successfully.', 'product-tag-sync-for-hubspot'); ?></p>
            </div>
            <?php
        }
        ?>

        <div class="whpts-mappings-header">
            <p>
                <?php esc_html_e('Map WooCommerce Order fields to HubSpot contact properties. These values will be synced when an order is completed.', 'product-tag-sync-for-hubspot'); ?>
            </p>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('whpts_save_field_mappings', 'whpts_fields_nonce'); ?>
            <input type="hidden" name="whpts_save_field_mappings" value="1" />

            <table class="wp-list-table widefat fixed striped whpts-field-mappings-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('WordPress Field', 'product-tag-sync-for-hubspot'); ?></th>
                        <th><?php esc_html_e('HubSpot Property Internal Name', 'product-tag-sync-for-hubspot'); ?></th>
                        <th><?php esc_html_e('Actions', 'product-tag-sync-for-hubspot'); ?></th>
                    </tr>
                </thead>
                <tbody id="whpts-field-mapping-rows">
                    <?php
                    // Predefined common fields
                    $common_fields = array(
                        'billing_first_name' => __('Billing First Name', 'product-tag-sync-for-hubspot'),
                        'billing_last_name' => __('Billing Last Name', 'product-tag-sync-for-hubspot'),
                        'billing_email' => __('Billing Email', 'product-tag-sync-for-hubspot'),
                        'billing_phone' => __('Billing Phone', 'product-tag-sync-for-hubspot'),
                        'billing_city' => __('Billing City', 'product-tag-sync-for-hubspot'),
                        'billing_company' => __('Billing Company', 'product-tag-sync-for-hubspot'),
                        'billing_country' => __('Billing Country', 'product-tag-sync-for-hubspot'),
                        'billing_state' => __('Billing State', 'product-tag-sync-for-hubspot'),
                        'billing_postcode' => __('Billing Postcode', 'product-tag-sync-for-hubspot'),
                    );

                    if (!empty($field_mappings)) {
                        foreach ($field_mappings as $index => $mapping) {
                            $this->render_field_mapping_row($index, $mapping, $common_fields);
                        }
                    } else {
                        // Render one empty row by default
                        $this->render_field_mapping_row(0, array(), $common_fields);
                    }
                    ?>
                </tbody>
            </table>

            <div style="margin-top: 10px;">
                <button type="button" class="button" id="whpts-add-field-row">
                    <?php esc_html_e('Add New Mapping', 'product-tag-sync-for-hubspot'); ?>
                </button>
            </div>

            <?php submit_button(__('Save Field Mappings', 'product-tag-sync-for-hubspot')); ?>
        </form>

        <!-- Template for new rows -->
        <script type="text/template" id="whpts-field-row-template">
            <?php $this->render_field_mapping_row('__INDEX__', array(), $common_fields); ?>
        </script>

        <script>
            jQuery(document).ready(function($) {
                var rowCount = <?php echo count($field_mappings) > 0 ? count($field_mappings) : 1; ?>;

                $('#whpts-add-field-row').on('click', function() {
                    var template = $('#whpts-field-row-template').html();
                    var newRow = template.replace(/__INDEX__/g, rowCount);
                    $('#whpts-field-mapping-rows').append(newRow);
                    rowCount++;
                });

                $(document).on('click', '.whpts-remove-row', function() {
                    $(this).closest('tr').remove();
                });
            });
        </script>
        <?php
    }

    /**
     * Render a single field mapping row.
     *
     * @since 1.0.0
     *
     * @param int   $index         Row index.
     * @param array $mapping       Mapping data (wp_field, hubspot_property).
     * @param array $common_fields Array of common WP fields for dropdown.
     */
    private function render_field_mapping_row($index, $mapping, $common_fields)
    {
        $wp_field = isset($mapping['wp_field']) ? $mapping['wp_field'] : '';
        $hubspot_property = isset($mapping['hubspot_property']) ? $mapping['hubspot_property'] : '';
        ?>
        <tr>
            <td>
                <select name="whpts_field_mapping[<?php echo esc_attr($index); ?>][wp_field]" class="regular-text">
                    <option value=""><?php esc_html_e('Select or type field key...', 'product-tag-sync-for-hubspot'); ?></option>
                    <?php foreach ($common_fields as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($wp_field, $key); ?>>
                            <?php echo esc_html($label . ' (' . $key . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <!-- Allow custom input if needed - effectively this select acts as a suggestion list, but for now we keep it simple -->
            </td>
            <td>
                <input type="text"
                    name="whpts_field_mapping[<?php echo esc_attr($index); ?>][hubspot_property]"
                    value="<?php echo esc_attr($hubspot_property); ?>"
                    class="regular-text"
                    placeholder="<?php esc_attr_e('e.g. mobilephone', 'product-tag-sync-for-hubspot'); ?>" />
            </td>
            <td>
                <button type="button" class="button whpts-remove-row">
                    <?php esc_html_e('Remove', 'product-tag-sync-for-hubspot'); ?>
                </button>
            </td>
        </tr>
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
     * Handle saving of field mappings.
     *
     * @since 1.0.0
     */
    public function handle_field_mapping_save()
    {
        if (!isset($_POST['whpts_save_field_mappings'])) {
            return;
        }

        if (
            !isset($_POST['whpts_fields_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['whpts_fields_nonce'])), 'whpts_save_field_mappings')
        ) {
            wp_die(esc_html__('Security check failed.', 'product-tag-sync-for-hubspot'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized.', 'product-tag-sync-for-hubspot'));
        }

        $field_mappings = array();

        if (isset($_POST['whpts_field_mapping']) && is_array($_POST['whpts_field_mapping'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw_mappings = wp_unslash($_POST['whpts_field_mapping']);

            foreach ($raw_mappings as $mapping) {
                $wp_field = isset($mapping['wp_field']) ? sanitize_text_field($mapping['wp_field']) : '';
                $hubspot_prop = isset($mapping['hubspot_property']) ? sanitize_text_field($mapping['hubspot_property']) : '';

                if (!empty($wp_field) && !empty($hubspot_prop)) {
                    $field_mappings[] = array(
                        'wp_field' => $wp_field,
                        'hubspot_property' => $hubspot_prop,
                    );
                }
            }
        }

        update_option('whpts_field_mappings', $field_mappings);

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'whpts-settings',
                    'tab' => 'fields',
                    'whpts_fields_saved' => 'true',
                ),
                admin_url('admin.php')
            )
        );
        exit;
    }

    /**
     * Render the Product Rules tab content.
     *
     * @since 1.1.0
     */
    private function render_product_rules_tab()
    {
        $rules = get_option('whpts_product_rules', array());
        $products = wc_get_products(array('status' => 'publish', 'limit' => -1, 'orderby' => 'title', 'order' => 'ASC'));
        
        // Fetch HubSpot properties for the dropdown.
        $properties = array();
        if ($this->api->is_configured()) {
            $props_result = $this->api->get_all_contact_properties();
            if (!is_wp_error($props_result)) {
                $properties = $props_result;
            }
        }

        // Check for save success notice.
        $saved = isset($_GET['whpts_rules_saved']) ? sanitize_key($_GET['whpts_rules_saved']) : '';
        if ('true' === $saved) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Product rules saved successfully.', 'product-tag-sync-for-hubspot'); ?></p>
            </div>
            <?php
        }
        ?>

        <div class="whpts-mappings-header">
            <p>
                <?php esc_html_e('Assign specific HubSpot property values when a product is purchased. These rules run in addition to standard field mappings.', 'product-tag-sync-for-hubspot'); ?>
            </p>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('whpts_save_product_rules', 'whpts_rules_nonce'); ?>
            <input type="hidden" name="whpts_save_product_rules" value="1" />

            <table class="wp-list-table widefat fixed striped whpts-product-rules-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('If Product is Purchased...', 'product-tag-sync-for-hubspot'); ?></th>
                        <th><?php esc_html_e('Set HubSpot Property (Internal Name)', 'product-tag-sync-for-hubspot'); ?></th>
                        <th><?php esc_html_e('To Value', 'product-tag-sync-for-hubspot'); ?></th>
                        <th><?php esc_html_e('Actions', 'product-tag-sync-for-hubspot'); ?></th>
                    </tr>
                </thead>
                <tbody id="whpts-rule-rows">
                    <?php
                    if (!empty($rules)) {
                        foreach ($rules as $index => $rule) {
                            $this->render_product_rule_row($index, $rule, $products, $properties);
                        }
                    } else {
                        $this->render_product_rule_row(0, array(), $products, $properties);
                    }
                    ?>
                </tbody>
            </table>

            <div style="margin-top: 10px;">
                <button type="button" class="button" id="whpts-add-rule-row">
                    <?php esc_html_e('Add New Rule', 'product-tag-sync-for-hubspot'); ?>
                </button>
            </div>

            <?php submit_button(__('Save Product Rules', 'product-tag-sync-for-hubspot')); ?>
        </form>

        <script type="text/template" id="whpts-rule-row-template">
            <?php $this->render_product_rule_row('__INDEX__', array(), $products, $properties); ?>
        </script>

        <script>
            jQuery(document).ready(function($) {
                var ruleCount = <?php echo count($rules) > 0 ? count($rules) : 1; ?>;

                $('#whpts-add-rule-row').on('click', function() {
                    var template = $('#whpts-rule-row-template').html();
                    var newRow = template.replace(/__INDEX__/g, ruleCount);
                    $('#whpts-rule-rows').append(newRow);
                    ruleCount++;
                });

                $(document).on('click', '.whpts-remove-rule', function() {
                    $(this).closest('tr').remove();
                });
            });
        </script>
        <?php
    }

    /**
     * Render a single product rule row.
     *
     * @since 1.1.0
     *
     * @param int|string $index    Row index.
     * @param array      $rule     Rule data.
     * @param array      $products List of WC products.
     */
    /**
     * Render a single product rule row.
     *
     * @since 1.1.0
     *
     * @param int|string $index      Row index.
     * @param array      $rule       Rule data.
     * @param array      $products   List of WC products.
     * @param array      $properties List of HubSpot properties.
     */
    private function render_product_rule_row($index, $rule, $products, $properties = array())
    {
        $product_id = isset($rule['product_id']) ? $rule['product_id'] : '';
        $property = isset($rule['hubspot_property']) ? $rule['hubspot_property'] : '';
        $value = isset($rule['value']) ? $rule['value'] : '';
        ?>
        <tr>
            <td>
                <select name="whpts_product_rule[<?php echo esc_attr($index); ?>][product_id]" class="regular-text">
                    <option value=""><?php esc_html_e('Select a product...', 'product-tag-sync-for-hubspot'); ?></option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?php echo esc_attr($product->get_id()); ?>" <?php selected($product_id, $product->get_id()); ?>>
                            <?php echo esc_html($product->get_name()); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <select name="whpts_product_rule[<?php echo esc_attr($index); ?>][hubspot_property]" class="whpts-hubspot-property-select regular-text">
                    <option value=""><?php esc_html_e('Select a property...', 'product-tag-sync-for-hubspot'); ?></option>
                    <?php 
                    $found_prop = false;
                    $selected_type = 'text'; 
                    foreach ($properties as $prop_data): 
                        if ($property === $prop_data['name']) {
                            $found_prop = true;
                            $selected_type = $prop_data['fieldType'];
                        }
                    ?>
                        <option value="<?php echo esc_attr($prop_data['name']); ?>" 
                            data-field-type="<?php echo esc_attr($prop_data['fieldType']); ?>"
                            <?php selected($property, $prop_data['name']); ?>>
                            <?php echo esc_html($prop_data['label']); ?> (<?php echo esc_html($prop_data['name']); ?>)
                        </option>
                    <?php endforeach; ?>
                    
                    <?php if (!empty($property) && !$found_prop): ?>
                        <option value="<?php echo esc_attr($property); ?>" selected="selected">
                            <?php echo esc_html($property); ?> (<?php esc_html_e('Unknown/Deleted', 'product-tag-sync-for-hubspot'); ?>)
                        </option>
                    <?php endif; ?>
                </select>
            </td>
            <td class="whpts-value-cell">
                <?php 
                // Determine if we should show a select for the value based on the current property.
                $options_markup = '';
                $is_select = false;
                
                // Find current property options
                if ($found_prop) {
                     foreach ($properties as $p) {
                         if ($p['name'] === $property && !empty($p['options'])) {
                             $is_select = true;
                             foreach ($p['options'] as $opt) {
                                 $opt_val = isset($opt['value']) ? $opt['value'] : '';
                                 $opt_label = isset($opt['label']) ? $opt['label'] : $opt_val;
                                 $selected = selected($value, $opt_val, false);
                                 $options_markup .= '<option value="' . esc_attr($opt_val) . '" ' . $selected . '>' . esc_html($opt_label) . '</option>';
                             }
                             break;
                         }
                     }
                }
                
                if ($is_select): ?>
                    <select name="whpts_product_rule[<?php echo esc_attr($index); ?>][value]" class="regular-text">
                        <option value=""><?php esc_html_e('Select value...', 'product-tag-sync-for-hubspot'); ?></option>
                        <?php echo $options_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </select>
                <?php else: ?>
                    <input type="text"
                        name="whpts_product_rule[<?php echo esc_attr($index); ?>][value]"
                        value="<?php echo esc_attr($value); ?>"
                        class="regular-text"
                        placeholder="<?php esc_attr_e('e.g. Consultant', 'product-tag-sync-for-hubspot'); ?>" />
                <?php endif; ?>
            </td>
            <td>
                <button type="button" class="button whpts-remove-rule">
                    <?php esc_html_e('Remove', 'product-tag-sync-for-hubspot'); ?>
                </button>
            </td>
        </tr>
        <?php
    }

    /**
     * Handle saving of product rules.
     *
     * @since 1.1.0
     */
    public function handle_product_rule_save()
    {
        if (!isset($_POST['whpts_save_product_rules'])) {
            return;
        }

        if (
            !isset($_POST['whpts_rules_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['whpts_rules_nonce'])), 'whpts_save_product_rules')
        ) {
            wp_die(esc_html__('Security check failed.', 'product-tag-sync-for-hubspot'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized.', 'product-tag-sync-for-hubspot'));
        }

        $rules = array();

        if (isset($_POST['whpts_product_rule']) && is_array($_POST['whpts_product_rule'])) {
            $raw_rules = wp_unslash($_POST['whpts_product_rule']);

            foreach ($raw_rules as $rule) {
                $product_id = isset($rule['product_id']) ? absint($rule['product_id']) : 0;
                $property = isset($rule['hubspot_property']) ? sanitize_text_field($rule['hubspot_property']) : '';
                $value = isset($rule['value']) ? sanitize_text_field($rule['value']) : '';

                if ($product_id > 0 && !empty($property) && !empty($value)) {
                    $rules[] = array(
                        'product_id' => $product_id,
                        'hubspot_property' => $property,
                        'value' => $value,
                    );
                }
            }
        }

        update_option('whpts_product_rules', $rules);

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'whpts-settings',
                    'tab' => 'rules',
                    'whpts_rules_saved' => 'true',
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
