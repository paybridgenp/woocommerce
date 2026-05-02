<?php
/**
 * Plugin Name: PayBridgeNP for WooCommerce
 * Plugin URI:  https://paybridgenp.com/integrations/woocommerce
 * Description: Accept payments via eSewa, Khalti, and more through PayBridgeNP.
 * Version:     1.1.0
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

define( 'PAYBRIDGE_WC_VERSION', '1.1.0' );
define( 'PAYBRIDGE_WC_FILE',    __FILE__ );
define( 'PAYBRIDGE_WC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'PAYBRIDGE_WC_URL',     plugin_dir_url( __FILE__ ) );

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
if ( file_exists( PAYBRIDGE_WC_DIR . 'vendor/autoload.php' ) ) {
	require_once PAYBRIDGE_WC_DIR . 'vendor/autoload.php';
}

add_action( 'plugins_loaded', function () {
	// WooCommerce dependency is enforced by the "Requires Plugins" header in
	// WordPress 6.5+; activation is blocked when WooCommerce is missing.

	// PayBridge PHP SDK must be loadable
	if ( ! class_exists( \PayBridgeNP\PayBridge::class ) ) {
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

	require_once PAYBRIDGE_WC_DIR . 'includes/class-wc-gateway-paybridge.php';

	// Register classic gateway
	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'PayBridgeNP_Gateway';
		return $gateways;
	} );

} );

// ── Block checkout support ───────────────────────────────────────────────────
// Registers the PayBridgeNP payment method with the WooCommerce Blocks cart
// and checkout, so merchants using the modern block-based checkout (the
// default for new WC installs since 2023) can accept PayBridge payments
// alongside the classic shortcode checkout.
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	require_once PAYBRIDGE_WC_DIR . 'includes/class-wc-gateway-paybridge-blocks.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $payment_method_registry ) {
			$payment_method_registry->register( new PayBridgeNP_Gateway_Blocks() );
		}
	);
} );
