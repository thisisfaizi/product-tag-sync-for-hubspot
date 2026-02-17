<?php
/**
 * HubSpot CRM v3 API wrapper.
 *
 * @package    Woo_HubSpot_Product_Tag_Sync
 * @subpackage Woo_HubSpot_Product_Tag_Sync/includes
 * @since      1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class WHPTS_HubSpot_API
 *
 * Handles all communication with the HubSpot CRM v3 API.
 * Uses Bearer token authentication via WordPress HTTP API.
 *
 * @since 1.0.0
 */
class WHPTS_HubSpot_API
{

    /**
     * HubSpot API base URL.
     *
     * @since 1.0.0
     * @var string
     */
    private $base_url = 'https://api.hubapi.com';

    /**
     * HubSpot API access token.
     *
     * @var string
     */
    private $access_token;

    /**
     * Whether we are using the parent plugin's token.
     *
     * @var bool
     */
    private $using_parent_token = false;

    /**
     * API request timeout in seconds.
     *
     * @since 1.0.0
     * @var int
     */
    private $timeout = 15;

    /**
     * Constructor.
     *
     * @param string $access_token Optional API access token.
     */
    public function __construct($access_token = '')
    {
        if (!empty($access_token)) {
            $this->access_token = $access_token;
        } else {
            $this->resolve_access_token();
        }
    }

    /**
     * Resolve the access token from available sources.
     *
     * Checks our plugin's settings first, then falls back to the
     * parent HubWoo plugin's token (with automatic OAuth refresh).
     *
     * @since 1.0.0
     */
    private function resolve_access_token()
    {
        // Check for our plugin's own token setting first.
        $options = get_option('whpts_settings');
        if (!empty($options['hubspot_token'])) {
            $this->access_token = $options['hubspot_token'];
            $this->using_parent_token = false;
            return;
        }

        // Use the parent HubWoo plugin's method if available
        // (handles OAuth token refresh automatically).
        if (class_exists('Hubwoo') && method_exists('Hubwoo', 'hubwoo_get_access_token')) {
            $parent_token = Hubwoo::hubwoo_get_access_token();
            if (!empty($parent_token)) {
                $this->access_token = $parent_token;
                $this->using_parent_token = true;
                return;
            }
        }

        // Fallback: read the raw option directly.
        $parent_token = get_option('hubwoo_pro_access_token');
        if (!empty($parent_token)) {
            $this->access_token = $parent_token;
            $this->using_parent_token = true;
        }
    }

    /**
     * Check if the API client is configured.
     *
     * @return bool True if configured, false otherwise.
     */
    public function is_configured()
    {
        return !empty($this->access_token);
    }

    /**
     * Check if using parent plugin's token.
     *
     * @return bool
     */
    public function is_using_parent_token()
    {
        return $this->using_parent_token;
    }

    /**
     * Fetch a contact by email from HubSpot.
     *
     * @since 1.0.0
     *
     * @param string $email      The contact email address.
     * @param array  $properties Optional. Properties to retrieve.
     * @return array|WP_Error Contact data array or WP_Error on failure.
     */
    public function get_contact($email, $properties = array())
    {
        $endpoint = '/crm/v3/objects/contacts/' . rawurlencode($email);
        $endpoint .= '?idProperty=email';

        if (!empty($properties)) {
            $endpoint .= '&properties=' . implode(',', array_map('sanitize_key', $properties));
        }

        return $this->make_request('GET', $endpoint);
    }

    /**
     * Create or update (upsert) a contact in HubSpot.
     *
     * Uses the email as the unique identifier via idProperty=email.
     * If the contact exists, it will be updated; otherwise, it will be created.
     *
     * @since 1.0.0
     *
     * @param string $email      The contact email address.
     * @param array  $properties Key-value pairs of contact properties to set.
     * @return array|WP_Error Contact data array or WP_Error on failure.
     */
    public function upsert_contact($email, $properties)
    {
        $all_properties = array_merge(
            array('email' => sanitize_email($email)),
            $properties
        );

        $body = array(
            'properties' => $all_properties,
        );

        // Try to create the contact first.
        $result = $this->make_request('POST', '/crm/v3/objects/contacts', $body);

        // If 409 conflict, the contact already exists — update via PATCH instead.
        if (is_wp_error($result)) {
            $error_data = $result->get_error_data();

            if (isset($error_data['status']) && 409 === $error_data['status']) {
                WHPTS_Logger::info(
                    sprintf('Contact %s already exists, updating via PATCH.', $email)
                );

                // Retrieve the existing contact ID.
                $existing = $this->get_contact($email);

                if (is_wp_error($existing)) {
                    return $existing;
                }

                $contact_id = isset($existing['id']) ? $existing['id'] : '';

                if (empty($contact_id)) {
                    return new WP_Error(
                        'whpts_no_contact_id',
                        __('Could not retrieve existing contact ID for update.', 'product-tag-sync-for-hubspot')
                    );
                }

                $update_endpoint = '/crm/v3/objects/contacts/' . $contact_id;

                return $this->make_request('PATCH', $update_endpoint, $body);
            }

            // Some other error — return as-is.
            return $result;
        }

        return $result;
    }

    /**
     * Test the API connection by retrieving account info.
     *
     * @since 1.0.0
     *
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function test_connection()
    {
        $result = $this->make_request('GET', '/crm/v3/objects/contacts?limit=1');

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Fetch all HubSpot contact properties.
     *
     * @since 1.1.0
     *
     * @param bool $force_refresh Whether to bypass the cache.
     * @return array|WP_Error Array of properties or WP_Error.
     */
    public function get_all_contact_properties($force_refresh = false)
    {
        $transient_key = 'whpts_all_contact_props';

        if (!$force_refresh) {
            $cached = get_transient($transient_key);
            if (false !== $cached) {
                return $cached;
            }
        }

        $endpoint = '/crm/v3/properties/contacts';
        $result = $this->make_request('GET', $endpoint);

        if (is_wp_error($result)) {
            return $result;
        }

        $properties = array();

        if (isset($result['results']) && is_array($result['results'])) {
            foreach ($result['results'] as $prop) {
                if (isset($prop['hidden']) && $prop['hidden']) {
                    continue;
                }

                $properties[] = array(
                    'name' => $prop['name'],
                    'label' => isset($prop['label']) ? $prop['label'] : $prop['name'],
                    'type' => $prop['type'],
                    'fieldType' => $prop['fieldType'],
                    'options' => isset($prop['options']) ? $prop['options'] : array(),
                );
            }
        }

        // Sort by label.
        usort($properties, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        set_transient($transient_key, $properties, HOUR_IN_SECONDS);

        return $properties;
    }

    /**
     * Fetch the available options for a HubSpot contact property.
     *
     * Uses the CRM Properties API to retrieve the property definition,
     * then extracts the options (for Dropdown or Multiple Checkboxes fields).
     * Results are cached for 1 hour using WordPress transients.
     *
     * @since 1.0.0
     *
     * @param string $property_name The internal property name.
     * @param bool   $force_refresh Whether to bypass the cache.
     * @return array|WP_Error Array of options [ ['value' => '', 'label' => ''], ... ] or WP_Error.
     */
    public function get_property_options($property_name, $force_refresh = false)
    {
        $transient_key = 'whpts_prop_opts_' . sanitize_key($property_name);

        // Check cache first unless force refresh.
        if (!$force_refresh) {
            $cached = get_transient($transient_key);
            if (false !== $cached) {
                return $cached;
            }
        }

        $endpoint = '/crm/v3/properties/contacts/' . rawurlencode($property_name);
        $result = $this->make_request('GET', $endpoint);

        if (is_wp_error($result)) {
            return $result;
        }

        $options = array();

        if (isset($result['options']) && is_array($result['options'])) {
            foreach ($result['options'] as $option) {
                if (!isset($option['hidden']) || false === $option['hidden']) {
                    $options[] = array(
                        'value' => isset($option['value']) ? $option['value'] : '',
                        'label' => isset($option['label']) ? $option['label'] : $option['value'],
                    );
                }
            }
        }

        // Cache for 1 hour.
        set_transient($transient_key, $options, HOUR_IN_SECONDS);

        return $options;
    }

    /**
     * Make an HTTP request to the HubSpot API.
     *
     * @since 1.0.0
     *
     * @param string     $method   HTTP method (GET, POST, PATCH, DELETE).
     * @param string     $endpoint API endpoint path (e.g., /crm/v3/objects/contacts).
     * @param array|null $body     Optional. Request body for POST/PATCH requests.
     * @return array|WP_Error Decoded response body or WP_Error on failure.
     */
    private function make_request($method, $endpoint, $body = null)
    {
        // Re-resolve the access token before each request to handle
        // OAuth token refresh from the parent HubWoo plugin.
        if ($this->using_parent_token) {
            $this->resolve_access_token();
        }

        if (!$this->is_configured()) {
            return new WP_Error(
                'whpts_no_token',
                __('HubSpot API token is not configured.', 'product-tag-sync-for-hubspot')
            );
        }

        $url = $this->base_url . $endpoint;

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => $this->timeout,
        );

        if (null !== $body) {
            $args['body'] = wp_json_encode($body);
        }

        WHPTS_Logger::info(sprintf('API %s request to: %s', $method, $endpoint));

        $response = wp_remote_request($url, $args);

        // Handle connection errors.
        if (is_wp_error($response)) {
            WHPTS_Logger::error(sprintf('API request failed: %s', $response->get_error_message()));
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($response_body, true);

        // Handle HTTP error responses.
        if ($response_code >= 400) {
            $error_message = isset($decoded_body['message']) ? $decoded_body['message'] : __('Unknown API error.', 'product-tag-sync-for-hubspot');

            WHPTS_Logger::error(
                sprintf(
                    'API error %d: %s',
                    $response_code,
                    $error_message
                )
            );

            return new WP_Error(
                'whpts_api_error',
                $error_message,
                array('status' => $response_code)
            );
        }

        return $decoded_body;
    }
}
