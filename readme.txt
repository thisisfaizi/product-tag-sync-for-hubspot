=== Product Tag Sync for HubSpot ===
Contributors: nowdigiverse
Tags: woocommerce, hubspot, crm, product tags, contact sync
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically assign HubSpot contact tags based on WooCommerce product purchases.

== Description ==

**Product Tag Sync for WooCommerce & HubSpot** bridges WooCommerce and HubSpot by automatically assigning contact property tags when customers purchase specific products.

**How it works:**

1. Map your WooCommerce products to HubSpot tag values in the admin panel
2. When a customer places an order, the plugin creates or updates their HubSpot contact
3. The mapped tags are appended to the contact's custom property (without overwriting existing tags)

**Features:**

* Automatic contact sync on order processing
* Admin UI for product → tag mapping (no code changes needed)
* Appends tags without overwriting existing values
* Prevents duplicate tag assignments
* One-click HubSpot connection test
* Product search filter for easy mapping management
* Secure token storage (never exposed on frontend)
* Silent failure — never breaks the checkout flow
* Detailed logging for debugging (via WP debug log)

**HubSpot Setup Requirements:**

* A HubSpot Private App with scopes: `crm.objects.contacts.read`, `crm.objects.contacts.write`, `crm.schemas.contacts.read`
* A custom Contact property (Multiple Checkboxes type) with defined option values

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/product-tag-sync-for-hubspot/` or install through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to WooCommerce → HubSpot Tags to configure.
4. Enter your HubSpot Private App Token and test the connection.
5. Set your HubSpot Contact Property Name (e.g., `product_tag`).
6. Go to the Product Mappings tab and assign tag values to your products.

== Frequently Asked Questions ==

= What HubSpot property type should I use? =

Use a "Multiple Checkboxes" field type on the Contact object. This allows storing multiple semicolon-separated values.

= Will this overwrite existing contact tags? =

No. The plugin fetches existing tag values, merges with new ones, removes duplicates, and saves the combined result.

= What happens if the HubSpot API is down? =

The plugin fails silently and logs the error. Your customers' checkout experience is never affected.

= Can I map multiple products to the same tag? =

Yes. Multiple products can share the same tag value. If a customer purchases both, the tag will only appear once.

== External services ==

This plugin connects to the HubSpot CRM API (api.hubapi.com) to sync your WooCommerce customers as HubSpot contacts and tag them based on the products they purchase. HubSpot is a third-party service and is required for this plugin to function; you must provide your own HubSpot account and Private App token.

What data is sent, and when:

* When a customer places an order that contains a mapped product, the plugin sends that customer's email address and the mapped tag value(s) to HubSpot in order to create or update the matching contact record.
* When you click "Test Connection" in the plugin settings, the plugin sends a request to HubSpot using your token to verify that the connection works.
* When you open the Product Mappings tab or click "Refresh Tags", the plugin requests your configured contact property definition from HubSpot to load its available tag options.

Data is only ever sent after you have entered a valid HubSpot Private App token and configured your mappings. No data is sent from the site frontend, and the plugin does not transmit data to any service other than HubSpot.

This service is provided by HubSpot, Inc. Please review their policies:

* Terms of Service: https://legal.hubspot.com/terms-of-service
* Privacy Policy: https://legal.hubspot.com/privacy-policy

== Changelog ==

= 1.0.0 =
* Initial release.
* Contact sync on order processing.
* Admin settings with HubSpot token and property configuration.
* Product-to-tag mapping UI.
* Tag append logic with deduplication.
* Connection test button.
* Product filter in mapping table.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== Screenshots ==

1. screenshot-1.png Settings Tab: Configure your HubSpot Private App token and Contact Property.
2. screenshot-2.png Product Mappings: Easily map WooCommerce products to specific HubSpot tags.

