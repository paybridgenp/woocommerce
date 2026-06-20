=== PayBridgeNP for WooCommerce ===
Contributors:       paybridgenp
Tags:               payment gateway, nepal, esewa, khalti, woocommerce
Requires at least:  5.8
Tested up to:       7.0
Stable tag:         1.2.0
Requires PHP:       7.4
WC requires at least: 7.0
WC tested up to:    10.7
License:            GPL-2.0-or-later
License URI:        https://www.gnu.org/licenses/gpl-2.0.html

Accept payments from Nepali customers via eSewa, Khalti, and more, powered by PayBridgeNP.

== Description ==

PayBridgeNP for WooCommerce lets your store accept payments through the most popular Nepali
digital wallets and payment methods without writing a single line of code.

**Supported payment methods**

* eSewa
* Khalti
* Fonepay

Customers choose their preferred method on a branded, mobile-friendly checkout page hosted by
PayBridgeNP. Your store never handles raw payment credentials.

**Features**

* One-time setup: enter your PayBridgeNP secret key and you're live
* Sandbox mode: use test API keys for safe development and QA
* Webhook support: reliable order updates via signed server-to-server callbacks
* HMAC-SHA256 signature verification on all webhooks
* HPOS (High-Performance Order Storage) compatible
* Block checkout compatible: works with both the classic shortcode checkout and the modern WooCommerce Blocks checkout
* Works with any WordPress permalink structure

**How it works**

1. Customer places an order and selects *PayBridgeNP* at checkout
2. They are redirected to the PayBridgeNP hosted payment page where they pick a wallet
3. After payment they return to your store's order-received page; the order is set to *On Hold* immediately
4. A signed `payment.succeeded` webhook from PayBridgeNP confirms the payment server-to-server and moves the order to *Processing*

The webhook is what provides authoritative confirmation. Without it, orders stay On Hold indefinitely.

== External services ==

This plugin connects to the PayBridgeNP payment API to process payments. PayBridgeNP is the
payment service this plugin is built for, and a PayBridgeNP account and API key are required for
the plugin to function.

The plugin contacts the PayBridgeNP API (https://api.paybridgenp.com) in these cases:

* When a customer places an order and chooses PayBridgeNP, the plugin creates a checkout session.
  It sends the order amount and currency, your store's return and cancel URLs, the WooCommerce
  order id and order key, and the customer's billing details entered at checkout (name, email,
  phone, and billing address) so the hosted payment page can be pre-filled for the customer.
* When "Provider tiles" display mode is enabled, the plugin requests the list of payment providers
  enabled for your account so it only offers providers you can actually accept. Only your secret
  API key is sent for this request.
* PayBridgeNP sends signed server-to-server webhooks back to your store to confirm payment results.
  The plugin verifies each one with an HMAC-SHA256 signature before updating the order.

No data is sent to any other third party. Payment card and wallet credentials are never handled by
your store; the customer enters them on PayBridgeNP's hosted page.

Use of the PayBridgeNP service is governed by its terms and privacy policy:

* Terms of Service: https://paybridgenp.com/terms
* Privacy Policy: https://paybridgenp.com/privacy

== Installation ==

**From ZIP (recommended)**

1. Download the latest `paybridge-np-woocommerce.zip` from the releases page
2. In your WordPress admin go to *Plugins → Add New → Upload Plugin*
3. Upload the ZIP and click *Install Now*, then *Activate*
4. Go to *WooCommerce → Settings → Payments* and enable *PayBridgeNP*
5. Enter your secret key and (optionally) your webhook signing secret

**From source (developers)**

1. Clone or copy the `packages/woocommerce` directory into `wp-content/plugins/paybridge-np-woocommerce`
2. Run `composer install --no-dev` inside the plugin directory
3. Activate the plugin in WordPress admin and follow steps 4-5 above

**Webhook setup (required for orders to reach Processing)**

1. In your PayBridgeNP dashboard go to *Webhooks → Add endpoint*
2. Set the URL to: `https://yourstore.com/?wc-api=paybridge_webhook`
3. Subscribe to: `payment.succeeded`, `payment.failed`, `payment.cancelled`
4. Copy the signing secret and paste it into the *Webhook Signing Secret* field in WooCommerce settings

**Local development webhook testing**

PayBridgeNP needs a publicly reachable URL to deliver webhooks. Use a tunnel tool to expose your local server:

```bash
ngrok http 80
```

Then use the generated `https://xxx.ngrok.io/paynep/?wc-api=paybridge_webhook` as your webhook endpoint URL in the dashboard.

== Frequently Asked Questions ==

= What currency does this gateway support? =

Your WooCommerce store currency must be set to NPR (Nepalese Rupee).

= Do I need a PayBridgeNP account? =

Yes. Sign up at paybridgenp.com to get your API keys.

= Is sandbox/test mode available? =

Yes. Create a sandbox project in your PayBridgeNP dashboard, use the `sk_test_` key, and all
payments will go through the eSewa/Khalti sandbox environments.

= What happens if the webhook is not configured? =

Orders will still be created and marked *On Hold* when the customer returns from payment.
Without a webhook the order will not automatically move to *Processing*. Webhooks are strongly
recommended for a reliable store experience.

= Is this plugin compatible with the WooCommerce block checkout? =

Yes. PayBridgeNP works with both the classic shortcode checkout and the modern WooCommerce Blocks checkout out of the box.

== Changelog ==

= 1.2.0 =
* New: "Provider tiles" display mode. In WooCommerce > Settings > Payments > PayBridgeNP, switch "Display style" to "Provider tiles (direct redirect)" to show your enabled providers as branded tiles on the checkout. The customer picks one and is sent straight to that provider, skipping the PayBridgeNP hosted picker.
* New: provider tiles show only the providers enabled for your PayBridgeNP account, so customers never see a method you cannot accept.
* New: the customer's billing details (name, email, phone, and address) are now forwarded to the PayBridgeNP hosted checkout, so they are pre-filled and the customer does not have to re-enter them.
* Fix: webhook signature verification now reads the X-PayBridgeNP-Signature header that PayBridgeNP sends, so payment confirmation works reliably and paid orders move from On Hold to Processing.
* Fix: the gateway icon now renders at a consistent small size on every theme, including block themes.
* The chosen provider is stored on the order as `_paybridge_provider` meta and noted in the order log.
* Block checkout (WooCommerce Blocks) is fully supported for the provider tiles and all of the above.
* Default remains "Single button (hosted picker)" so existing installs behave exactly as before until a merchant opts in.

= 1.1.1 =
* Rename gateway classes and the icon filter to use a consistent `Paybridge_WC` / `paybridge_wc_` prefix, matching the existing `PAYBRIDGE_WC_*` constants (WordPress.org plugin guidelines on unique prefixes)

= 1.1.0 =
* Bundle the latest PayBridgeNP PHP SDK (3.0.0) with the new typed exception hierarchy and nested error envelope handling
* Errors thrown from PayBridgeNP API calls are now typed exceptions (AuthenticationException, AccountException, PermissionException, InvalidRequestException, IdempotencyException, RateLimitException). Branch with instanceof in custom integrations.
* Every exception now carries getErrorType(), getErrorCode(), and getRequestId(). Quote getRequestId() in support requests for fastest triage.
* Backward compatible: the SDK still parses the legacy flat error shape during the API transition window

= 1.0.1 =
* Add `Requires Plugins: woocommerce` header so WordPress 6.5+ enforces the WooCommerce dependency at activation
* Bundle the latest PayBridgeNP PHP SDK (2.0.0)

= 1.0.0 =
* Initial release
* eSewa, Khalti, and Fonepay support via PayBridgeNP hosted checkout
* Classic shortcode checkout and WooCommerce Blocks checkout support
* Webhook handler with HMAC-SHA256 signature verification (signing secret required)
* HPOS compatible
