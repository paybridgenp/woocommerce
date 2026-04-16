# PayBridgeNP for WooCommerce

Accept payments from Nepali customers via **eSewa**, **Khalti**, and **ConnectIPS** — through a single, unified integration powered by [PayBridgeNP](https://paybridgenp.com).

## Installation

### From ZIP (recommended for most stores)

1. Download the latest `paybridge-np-woocommerce.zip` from [Releases](https://github.com/paybridgenp/woocommerce/releases)
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and click **Install Now**, then **Activate**
4. Go to **WooCommerce → Settings → Payments** and enable **PayBridgeNP**
5. Enter your secret key from the [PayBridgeNP dashboard](https://dashboard.paybridgenp.com)

### From source (developers)

```bash
cd wp-content/plugins
git clone https://github.com/paybridgenp/woocommerce.git paybridge-np-woocommerce
cd paybridge-np-woocommerce
composer install --no-dev
```

Activate the plugin in WordPress admin and follow steps 4–5 above.

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

## Building a release ZIP

```bash
composer run build
```

This produces `packages/paybridge-np-woocommerce.zip` with vendor bundled, ready to upload.

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)
