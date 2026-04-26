=== PayBridgeNP for WooCommerce ===
Contributors:       paybridgenp
Tags:               payment gateway, nepal, esewa, khalti, woocommerce
Requires at least:  5.8
Tested up to:       7.0
Stable tag:         1.0.1
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
* ConnectIPS

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

= 1.0.1 =
* Add `Requires Plugins: woocommerce` header so WordPress 6.5+ enforces the WooCommerce dependency at activation
* Bundle the latest PayBridgeNP PHP SDK (2.0.0)

= 1.0.0 =
* Initial release
* eSewa, Khalti, and ConnectIPS support via PayBridgeNP hosted checkout
* Classic shortcode checkout and WooCommerce Blocks checkout support
* Webhook handler with HMAC-SHA256 signature verification (signing secret required)
* HPOS compatible
