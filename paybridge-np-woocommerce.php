<?php
/**
 * Plugin Name: PayBridgeNP for WooCommerce
 * Plugin URI:  https://paybridgenp.com/integrations/woocommerce
 * Description: Accept payments via eSewa, Khalti, and Fonepay through PayBridgeNP.
 * Version:     1.3.0
 * Author:      PayBridgeNP
 * Author URI:  https://paybridgenp.com
 * Text Domain: paybridgenp-for-woocommerce
 * Domain Path: /languages
 *
 * Requires at least: 5.8
 * Tested up to:      7.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   10.7
 * Requires PHP:      7.4
 *
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Legacy-directory migration — 1.2.x and earlier.
 *
 * Until 1.3.0 the ZIP served from paybridgenp.com unpacked to
 * `paybridge-np-woocommerce`, while the WordPress.org slug is
 * `paybridgenp-for-woocommerce`. Uploading this ZIP over such an install writes a
 * second directory and leaves the OLD one active, while WordPress reports
 * "Plugin updated successfully". Verified on WordPress 7.0 / WooCommerce 11.0.1
 * (2026-08-16): with both present, PAYBRIDGENP_WC_VERSION resolved to 1.2.0 and
 * payments kept running on the old vendored SDK.
 *
 * Only the ZIP-upload path breaks. Measured the same day: WordPress matches a
 * legacy install to WP.org by the slug the API returns, so it IS offered updates,
 * and taking one migrates the directory cleanly; a fresh WP.org install over it
 * is refused as already installed. The download page is the one channel that
 * needs this guard — which is why it went unnoticed.
 *
 * The legacy directory sorts first, so by the time this file is reached its
 * constants are already defined. Rather than fight over them mid-request,
 * deactivate the old copy and bail — the next request comes up clean on this one.
 */
$paybridgenp_wc_legacy = 'paybridge-np-woocommerce/paybridge-np-woocommerce.php';
if ( plugin_basename( __FILE__ ) !== $paybridgenp_wc_legacy ) {
	$paybridgenp_wc_active = (array) get_option( 'active_plugins', array() );
	if ( in_array( $paybridgenp_wc_legacy, $paybridgenp_wc_active, true ) ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( $paybridgenp_wc_legacy );
		update_option( 'paybridgenp_wc_legacy_deactivated', '1' );
	}
}
unset( $paybridgenp_wc_legacy, $paybridgenp_wc_active );

// Tell the merchant what happened, and that the stale folder is theirs to
// remove. Settings are keyed on the gateway id (`paybridge_np`), not the
// directory, so nothing is lost by deleting it.
add_action( 'admin_notices', function () {
	if ( '1' !== get_option( 'paybridgenp_wc_legacy_deactivated' ) ) {
		return;
	}
	if ( ! is_dir( WP_PLUGIN_DIR . '/paybridge-np-woocommerce' ) ) {
		delete_option( 'paybridgenp_wc_legacy_deactivated' );
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>PayBridgeNP:</strong> '
		. esc_html__( 'an older copy of this plugin was installed in a differently named folder and has been deactivated, so your store now runs the current version. You can safely delete the old "paybridge-np-woocommerce" entry from the Plugins screen — your gateway settings are kept.', 'paybridgenp-for-woocommerce' )
		. '</p></div>';
} );

// The legacy copy already loaded and defined these this request. Bail rather
// than emit "already defined" warnings on every page load.
if ( defined( 'PAYBRIDGENP_WC_VERSION' ) ) {
	return;
}

define( 'PAYBRIDGENP_WC_VERSION', '1.3.0' );
define( 'PAYBRIDGENP_WC_FILE',    __FILE__ );
define( 'PAYBRIDGENP_WC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'PAYBRIDGENP_WC_URL',     plugin_dir_url( __FILE__ ) );

/**
 * "Settings" on the Plugins screen.
 *
 * WooCommerce puts one there and merchants look for it in the same place. Without
 * it the gateway is four clicks deep — Plugins, then WooCommerce, then Settings,
 * then Payments, then the gateway — with nothing on the Plugins row hinting where
 * it went.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paybridge_np' );
	array_unshift(
		$links,
		'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'paybridgenp-for-woocommerce' ) . '</a>'
	);
	return $links;
} );

// Open the "Visit plugin site" link on the Plugins list in a new tab.
add_filter( 'plugin_row_meta', function ( $links, $file ) {
	if ( $file !== plugin_basename( __FILE__ ) ) {
		return $links;
	}
	foreach ( $links as $i => $link ) {
		$links[ $i ] = preg_replace(
			'/<a\s+(?![^>]*\btarget=)([^>]*href=)/i',
			'<a target="_blank" rel="noopener noreferrer" $1',
			$link
		);
	}
	return $links;
}, 10, 2 );

// Declare HPOS compatibility
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

// Load Composer autoloader (vendor bundled in release ZIP)
if ( file_exists( PAYBRIDGENP_WC_DIR . 'vendor/autoload.php' ) ) {
	require_once PAYBRIDGENP_WC_DIR . 'vendor/autoload.php';
}

add_action( 'plugins_loaded', function () {
	// WooCommerce dependency is enforced by the "Requires Plugins" header in
	// WordPress 6.5+; activation is blocked when WooCommerce is missing.

	// PayBridgeNP PHP SDK must be loadable
	if ( ! class_exists( \PayBridgeNP\PayBridgeNP::class ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'PayBridgeNP: the PHP SDK is missing. Please re-upload the full plugin ZIP from wordpress.org.', 'paybridgenp-for-woocommerce' )
				. '</p></div>';
		} );
		return;
	}

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once PAYBRIDGENP_WC_DIR . 'includes/class-wc-gateway-paybridge.php';

	// Register classic gateway
	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'Paybridge_WC_Gateway';
		return $gateways;
	} );

	// Bridge Blocks `payment_data` into $_POST so process_payment() reads the
	// chosen provider the same way for classic + block checkouts.
	add_action(
		'woocommerce_rest_checkout_process_payment_with_context',
		function ( $context ) {
			if ( 'paybridge_np' !== $context->payment_method ) {
				return;
			}
			$data = isset( $context->payment_data ) && is_array( $context->payment_data ) ? $context->payment_data : [];
			if ( isset( $data['paybridge_wc_provider'] ) && is_string( $data['paybridge_wc_provider'] ) ) {
				$_POST['paybridge_wc_provider'] = sanitize_text_field( wp_unslash( $data['paybridge_wc_provider'] ) );
			}
		},
		10,
		1
	);

} );

// ── Block checkout support ───────────────────────────────────────────────────
// Registers the PayBridgeNP payment method with the WooCommerce Blocks cart
// and checkout, so merchants using the modern block-based checkout (the
// default for new WC installs since 2023) can accept PayBridgeNP payments
// alongside the classic shortcode checkout.
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once PAYBRIDGENP_WC_DIR . 'includes/class-wc-gateway-paybridge-blocks.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $payment_method_registry ) {
			$payment_method_registry->register( new Paybridge_WC_Gateway_Blocks() );
		}
	);
} );
