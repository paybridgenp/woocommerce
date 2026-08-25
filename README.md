# PayBridgeNP for WooCommerce

[![WordPress.org plugin version](https://img.shields.io/wordpress/plugin/v/paybridgenp-for-woocommerce?label=WordPress.org)](https://wordpress.org/plugins/paybridgenp-for-woocommerce/)
[![WordPress.org rating](https://img.shields.io/wordpress/plugin/rating/paybridgenp-for-woocommerce)](https://wordpress.org/plugins/paybridgenp-for-woocommerce/)
[![WordPress.org downloads](https://img.shields.io/wordpress/plugin/dt/paybridgenp-for-woocommerce)](https://wordpress.org/plugins/paybridgenp-for-woocommerce/)

Accept payments from Nepali customers via **eSewa**, **Khalti**, and **Fonepay** through a single, unified integration powered by [PayBridgeNP](https://paybridgenp.com).

**Available on the official [WordPress.org plugin directory](https://wordpress.org/plugins/paybridgenp-for-woocommerce/).** That's the recommended install path - search "PayBridgeNP" from your WordPress admin and click Install.

**[Discord](https://discord.gg/aquta4JwJt)** - community support and questions

## Installation

### From WordPress.org (recommended)

1. In your WordPress admin go to **Plugins → Add New**
2. Search for **PayBridgeNP**
3. Click **Install Now**, then **Activate**
4. Go to **WooCommerce → Settings → Payments** and enable **PayBridgeNP**
5. Enter your secret key from the [PayBridgeNP dashboard](https://dashboard.paybridgenp.com)

Auto-updates work through core WordPress - no manual upgrade steps.

### From ZIP

1. Download `paybridge-np-woocommerce.zip` from [paybridgenp.com/integrations/woocommerce](https://paybridgenp.com/integrations/woocommerce) or [GitHub Releases](https://github.com/paybridgenp/woocommerce/releases)
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and click **Install Now**, then **Activate**
4. Continue from step 4 above

### From source (developers)

```bash
cd wp-content/plugins
git clone https://github.com/paybridgenp/woocommerce.git paybridge-np-woocommerce
cd paybridge-np-woocommerce
composer install --no-dev
```

Activate the plugin in WordPress admin and follow the configuration steps above.

## Webhook setup

Orders move to **Processing** only after a signed webhook confirms payment server-to-server.

1. In your PayBridgeNP dashboard go to **Webhooks → Add endpoint**
2. Set the URL to: `https://yourstore.com/?wc-api=paybridge_webhook`
3. Subscribe to: `payment.succeeded`, `payment.failed`, `payment.cancelled`
4. Copy the signing secret and paste it into the **Webhook Signing Secret** field in WooCommerce settings

## Requirements

- WordPress 5.8+
- WooCommerce 7.0+
- PHP 7.4+
- Store currency set to **NPR**
- A [PayBridgeNP](https://paybridgenp.com) account

## Installing from source

```bash
composer install --no-dev
```

Merchants should install the packaged plugin from the
[WordPress.org directory](https://wordpress.org/plugins/paybridgenp-for-woocommerce/)
rather than building it.

## License

GPL-2.0-or-later - see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)
