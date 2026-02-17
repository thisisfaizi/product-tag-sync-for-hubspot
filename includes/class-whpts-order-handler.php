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
        add_action('woocommerce_order_status_completed', array($this, 'process_order'), 10, 1);
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
        WHPTS_Logger::info(sprintf('>>> process_order fired for order %d', $order_id));

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

        WHPTS_Logger::info(sprintf('Order %d: API configured, proceeding with sync.', $order_id));

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

        // Collect tags from order items (may be empty if no mappings configured).
        $new_tags = array();
        if (!empty($mappings)) {
            $new_tags = $this->collect_order_tags($order, $mappings);
            WHPTS_Logger::info(sprintf('Order %d: Collected %d tags from product mappings.', $order_id, count($new_tags)));
        } else {
            WHPTS_Logger::info(sprintf('Order %d: No product mappings configured.', $order_id));
        }

        // Get the property name from settings.
        $settings = get_option('whpts_settings', array());
        $property_name = isset($settings['property_name']) ? $settings['property_name'] : 'product_tag';

        // Merge new tags with existing tags in HubSpot.
        $merged_tags = $new_tags;
        if (!empty($new_tags)) {
            $result = $this->merge_with_existing_tags($email, $property_name, $new_tags);
            if (!is_wp_error($result)) {
                $merged_tags = $result;
            }
        }

        // Build contact properties.
        $properties = array(
            'firstname' => sanitize_text_field($first_name),
            'lastname' => sanitize_text_field($last_name),
            'lifecyclestage' => 'customer',
        );

        // Only set the tag property if we have tags.
        if (!empty($merged_tags)) {
            $properties[$property_name] = implode(';', $merged_tags);
        }

        // Process Field Mappings.
        $field_mappings = get_option('whpts_field_mappings', array());
        if (!empty($field_mappings) && is_array($field_mappings)) {
            foreach ($field_mappings as $mapping) {
                $wp_field = isset($mapping['wp_field']) ? $mapping['wp_field'] : '';
                $hubspot_property = isset($mapping['hubspot_property']) ? $mapping['hubspot_property'] : '';

                if (!empty($wp_field) && !empty($hubspot_property)) {
                    $value = $this->get_mapped_field_value($order, $wp_field);
                    if (!empty($value)) {
                        $properties[$hubspot_property] = $value;
                    }
                }
            }
        }

        // Process Product Property Rules.
        $rule_properties = $this->collect_product_rule_properties($order);
        WHPTS_Logger::info(sprintf('Order %d: Collected %d product rule properties.', $order_id, count($rule_properties)));
        foreach ($rule_properties as $prop => $values) {
            $unique_values = array_unique($values);

            if ($prop === $property_name) {
                // Merge with existing tags if targeting the main tag property.
                $merged_tags = array_unique(array_merge($merged_tags, $unique_values));
                $properties[$property_name] = implode(';', $merged_tags);
            } else {
                // For product rules targeting other HubSpot properties, merge with
                // any existing value on the contact to avoid overwriting.
                $existing_contact = $this->api->get_contact($email, array($prop));
                $existing_values = array();
                if (!is_wp_error($existing_contact) && isset($existing_contact['properties'][$prop]) && !empty($existing_contact['properties'][$prop])) {
                    $existing_values = array_map('trim', explode(';', $existing_contact['properties'][$prop]));
                    $existing_values = array_filter($existing_values);
                }
                $merged = array_unique(array_merge($existing_values, $unique_values));
                $properties[$prop] = implode(';', $merged);
            }
        }

        // If we have nothing beyond the basics (firstname, lastname, lifecyclestage), skip.
        if (empty($new_tags) && empty($rule_properties) && count($properties) <= 3) {
            WHPTS_Logger::info(
                sprintf('Order %d has no mapped products, product rules, or field mappings. Skipping sync.', $order_id)
            );
            return;
        }

        // Upsert contact in HubSpot.
        WHPTS_Logger::info(sprintf('Order %d: Upserting contact %s with properties: %s', $order_id, $email, wp_json_encode(array_keys($properties))));
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
    /**
     * Collect HubSpot property values based on product rules.
     *
     * @since 1.1.0
     *
     * @param WC_Order $order The WooCommerce order.
     * @return array Array of properties and values [ 'property_name' => ['value1', 'value2'] ].
     */
    private function collect_product_rule_properties($order)
    {
        $rules = get_option('whpts_product_rules', array());
        $collected_properties = array();

        if (empty($rules)) {
            return $collected_properties;
        }

        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();

            foreach ($rules as $rule) {
                if (isset($rule['product_id']) && (int) $rule['product_id'] === $product_id) {
                    $property = isset($rule['hubspot_property']) ? $rule['hubspot_property'] : '';
                    $value = isset($rule['value']) ? $rule['value'] : '';

                    if (!empty($property)) {
                        if (!isset($collected_properties[$property])) {
                            $collected_properties[$property] = array();
                        }
                        // Only add value if not empty, or maybe we want to support clearing?
                        // For now assuming we only set values.
                        if ('' !== $value) {
                            $collected_properties[$property][] = $value;
                        }
                    }
                }
            }
        }

        return $collected_properties;
    }
    /**
     * Get a mapped field value from a WooCommerce order.
     *
     * Supports billing_ and shipping_ prefixed fields, order meta,
     * and user meta as fallback.
     *
     * @since 1.1.0
     *
     * @param WC_Order $order    The WooCommerce order object.
     * @param string   $wp_field The WordPress/WooCommerce field key.
     * @return string The field value, or empty string if not found.
     */
    private function get_mapped_field_value($order, $wp_field)
    {
        $value = '';

        // Check standard billing/shipping getters first.
        $getter = 'get_' . $wp_field;
        if (method_exists($order, $getter)) {
            $value = $order->$getter();
        } elseif (0 === strpos($wp_field, 'billing_')) {
            $value = $order->get_meta('_' . $wp_field);
        } elseif (0 === strpos($wp_field, 'shipping_')) {
            $value = $order->get_meta('_' . $wp_field);
        } else {
            // Try order meta.
            $value = $order->get_meta($wp_field);

            // Fallback to user meta if order meta is empty.
            if (empty($value)) {
                $user_id = $order->get_user_id();
                if ($user_id) {
                    $value = get_user_meta($user_id, $wp_field, true);
                }
            }
        }

        return is_string($value) ? sanitize_text_field($value) : '';
    }
}
