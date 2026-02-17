<?php
/**
 * Handles WooCommerce order processing and HubSpot contact sync.
 *
 * @package    Woo_HubSpot_Product_Tag_Sync
 * @subpackage Woo_HubSpot_Product_Tag_Sync/includes
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WHPTS_Order_Handler
 *
 * Listens for WooCommerce order status changes and syncs
 * purchased product tags to HubSpot contacts.
 *
 * @since 1.0.0
 */
class WHPTS_Order_Handler
{

    /**
     * HubSpot API instance.
     *
     * @since 1.0.0
     * @var WHPTS_HubSpot_API
     */
    private $api;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param WHPTS_HubSpot_API $api HubSpot API instance.
     */
    public function __construct(WHPTS_HubSpot_API $api)
    {
        $this->api = $api;
    }

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     */
    public function init()
    {
        add_action('woocommerce_order_status_processing', array($this, 'process_order'), 10, 1);
    }

    /**
     * Process a WooCommerce order and sync tags to HubSpot.
     *
     * @since 1.0.0
     *
     * @param int $order_id The WooCommerce order ID.
     */
    public function process_order($order_id)
    {
        // Wrap in try-catch to never break checkout.
        try {
            $this->sync_order_tags($order_id);
        } catch (\Exception $e) {
            WHPTS_Logger::error(
                sprintf('Exception processing order %d: %s', $order_id, $e->getMessage())
            );
        }
    }

    /**
     * Sync product tags from an order to HubSpot.
     *
     * @since 1.0.0
     *
     * @param int $order_id The WooCommerce order ID.
     */
    private function sync_order_tags($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            WHPTS_Logger::error(sprintf('Order %d not found.', $order_id));
            return;
        }

        // Check if already synced to prevent re-processing.
        if ('yes' === $order->get_meta('_whpts_synced')) {
            WHPTS_Logger::info(sprintf('Order %d already synced. Skipping.', $order_id));
            return;
        }

        // Check API configuration.
        if (!$this->api->is_configured()) {
            WHPTS_Logger::warning('HubSpot API token not configured. Skipping sync.');
            return;
        }

        // Get billing info.
        $email = $order->get_billing_email();
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();

        // Skip if no email.
        if (empty($email)) {
            WHPTS_Logger::warning(sprintf('Order %d has no billing email. Skipping.', $order_id));
            return;
        }

        // Get product-to-tag mappings.
        $mappings = get_option('whpts_product_mappings', array());

        if (empty($mappings)) {
            WHPTS_Logger::info('No product mappings configured. Skipping tag sync.');
            return;
        }

        // Collect tags from order items.
        $new_tags = $this->collect_order_tags($order, $mappings);

        if (empty($new_tags)) {
            WHPTS_Logger::info(
                sprintf('Order %d has no mapped products. Skipping tag sync.', $order_id)
            );
            return;
        }

        // Get the property name from settings.
        $settings = get_option('whpts_settings', array());
        $property_name = isset($settings['property_name']) ? $settings['property_name'] : 'product_tag';

        // Fetch existing contact tags from HubSpot and merge.
        $merged_tags = $this->merge_with_existing_tags($email, $property_name, $new_tags);

        if (is_wp_error($merged_tags)) {
            WHPTS_Logger::error(
                sprintf('Failed to merge tags for %s: %s', $email, $merged_tags->get_error_message())
            );
            // Fall back to just the new tags.
            $merged_tags = $new_tags;
        }

        // Build contact properties.
        $properties = array(
            'firstname' => sanitize_text_field($first_name),
            'lastname' => sanitize_text_field($last_name),
            'lifecyclestage' => 'customer',
            $property_name => implode(';', $merged_tags),
        );

        // Upsert contact in HubSpot.
        $result = $this->api->upsert_contact($email, $properties);

        if (is_wp_error($result)) {
            WHPTS_Logger::error(
                sprintf(
                    'Failed to sync contact %s for order %d: %s',
                    $email,
                    $order_id,
                    $result->get_error_message()
                )
            );
            return;
        }

        // Mark order as synced.
        $order->update_meta_data('_whpts_synced', 'yes');
        $order->update_meta_data('_whpts_synced_at', current_time('mysql'));
        $order->save();

        WHPTS_Logger::info(
            sprintf(
                'Successfully synced contact %s for order %d with tags: %s',
                $email,
                $order_id,
                implode(';', $merged_tags)
            )
        );
    }

    /**
     * Collect mapped tag values from order items.
     *
     * @since 1.0.0
     *
     * @param WC_Order $order    The WooCommerce order.
     * @param array    $mappings Product-to-tag mappings.
     * @return array Array of tag values.
     */
    private function collect_order_tags($order, $mappings)
    {
        $tags = array();

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();

            if (isset($mappings[$product_id]) && !empty($mappings[$product_id])) {
                $tag_value = sanitize_text_field($mappings[$product_id]);

                if (!in_array($tag_value, $tags, true)) {
                    $tags[] = $tag_value;
                }
            }
        }

        return $tags;
    }

    /**
     * Merge new tags with existing contact tags from HubSpot.
     *
     * Fetches the contact's current tag property value, splits by semicolon,
     * merges with new tags, removes duplicates, and returns the combined array.
     *
     * @since 1.0.0
     *
     * @param string $email         The contact email.
     * @param string $property_name The HubSpot property name.
     * @param array  $new_tags      New tag values to add.
     * @return array|WP_Error Merged tag values or WP_Error on failure.
     */
    private function merge_with_existing_tags($email, $property_name, $new_tags)
    {
        $contact = $this->api->get_contact($email, array($property_name));

        // If contact doesn't exist yet, just return new tags.
        if (is_wp_error($contact)) {
            $error_data = $contact->get_error_data();
            // 404 means contact doesn't exist yet — that's fine.
            if (isset($error_data['status']) && 404 === $error_data['status']) {
                return $new_tags;
            }
            return $contact;
        }

        // Extract existing tag values.
        $existing_tags = array();
        if (isset($contact['properties'][$property_name]) && !empty($contact['properties'][$property_name])) {
            $existing_value = $contact['properties'][$property_name];
            $existing_tags = array_map('trim', explode(';', $existing_value));
            $existing_tags = array_filter($existing_tags); // Remove empty values.
        }

        // Merge and deduplicate.
        $merged = array_unique(array_merge($existing_tags, $new_tags));

        return array_values($merged);
    }
}
