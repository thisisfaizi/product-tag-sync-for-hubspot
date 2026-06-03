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
    private $api_base = 'https://api.hubapi.com';

    /**
     * HubSpot Private App Token.
     *
     * @since 1.0.0
     * @var string
     */
    private $token;

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
     * @since 1.0.0
     *
     * @param string $token HubSpot Private App Token.
     */
    public function __construct($token = '')
    {
        if (empty($token)) {
            $settings = get_option('whpts_settings', array());
            $this->token = isset($settings['hubspot_token']) ? $settings['hubspot_token'] : '';
        } else {
            $this->token = $token;
        }
    }

    /**
     * Check if the API is configured with a valid token.
     *
     * @since 1.0.0
     *
     * @return bool True if token is set.
     */
    public function is_configured()
    {
        return !empty($this->token);
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
        $endpoint = '/crm/v3/objects/contacts?idProperty=email';

        $body = array(
            'properties' => array_merge(
                array('email' => sanitize_email($email)),
                $properties
            ),
        );

        return $this->make_request('POST', $endpoint, $body);
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
        if (!$this->is_configured()) {
            return new WP_Error(
                'whpts_no_token',
                __('HubSpot API token is not configured.', 'product-tag-sync-for-hubspot')
            );
        }

        $url = $this->api_base . $endpoint;

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
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
