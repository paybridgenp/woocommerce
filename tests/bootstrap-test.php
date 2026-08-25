<?php

declare( strict_types=1 );

/**
 * Bootstrap tests — run as their OWN process by tests/run.php.
 *
 * These load `paybridge-np-woocommerce.php` itself, which the main harness never
 * does (it only loads the gateway class). They need a process where
 * PAYBRIDGENP_WC_VERSION is undefined, because that constant is exactly what the
 * bootstrap's double-load guard checks — run.php defines it, so this cannot live
 * in the same process.
 *
 * Both behaviours here were verified by hand on WordPress 7.0 / WooCommerce
 * 11.0.1 before being written down. These exist so a later edit cannot quietly
 * undo them.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_PLUGIN_DIR', __DIR__ . '/fixture-plugins' );

$GLOBALS['pb_options']     = [];
$GLOBALS['pb_deactivated'] = [];
$GLOBALS['pb_filters']     = [];
$GLOBALS['pb_actions']     = [];

function add_filter( string $hook, $cb, int $priority = 10, int $args = 1 ): void { $GLOBALS['pb_filters'][ $hook ] = $cb; }
function add_action( string $hook, $cb, int $priority = 10, int $args = 1 ): void { $GLOBALS['pb_actions'][ $hook ][] = $cb; }
function get_option( string $key, $default = false ) { return $GLOBALS['pb_options'][ $key ] ?? $default; }
function update_option( string $key, $value ): bool { $GLOBALS['pb_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['pb_options'][ $key ] ); return true; }
function deactivate_plugins( $plugins ): void { $GLOBALS['pb_deactivated'] = array_merge( $GLOBALS['pb_deactivated'], (array) $plugins ); }
function plugin_basename( string $file ): string { return 'paybridgenp-for-woocommerce/' . basename( $file ); }
function plugin_dir_path( string $file ): string { return dirname( $file ) . '/'; }
function plugin_dir_url( string $file ): string { return 'https://shop.example.test/wp-content/plugins/paybridgenp-for-woocommerce/'; }
function esc_url( string $url ): string { return $url; }
function esc_html( string $text ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function __( string $text, string $domain = '' ): string { return $text; }
function admin_url( string $path = '' ): string { return 'https://shop.example.test/wp-admin/' . $path; }

$failures = 0;
function expect( bool $cond, string $message ): void {
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL {$message}\n" );
		$GLOBALS['failures'] = ( $GLOBALS['failures'] ?? 0 ) + 1;
	}
}

// A merchant who installed the ZIP from paybridgenp.com has the plugin in the
// legacy directory. Uploading the renamed ZIP leaves that copy ACTIVE and the
// store serving it, so the bootstrap must switch it off.
$legacy                             = 'paybridge-np-woocommerce/paybridge-np-woocommerce.php';
$GLOBALS['pb_options']['active_plugins'] = [ $legacy, 'woocommerce/woocommerce.php' ];

require dirname( __DIR__ ) . '/paybridge-np-woocommerce.php';

expect(
	in_array( $legacy, $GLOBALS['pb_deactivated'], true ),
	'an active legacy-folder copy must be deactivated, or the store keeps running the old version'
);
expect(
	( $GLOBALS['pb_options']['paybridgenp_wc_legacy_deactivated'] ?? '' ) === '1',
	'deactivating the legacy copy must flag the admin notice, so the merchant is told what happened'
);
expect(
	defined( 'PAYBRIDGENP_WC_VERSION' ),
	'with no prior copy loaded, the bootstrap must continue past the double-load guard'
);

// The Plugins screen needs a Settings link. WooCommerce has one in that exact
// spot; without it the gateway settings are several clicks away with nothing on
// the row pointing at them.
$basename = plugin_basename( dirname( __DIR__ ) . '/paybridge-np-woocommerce.php' );
$cb       = $GLOBALS['pb_filters'][ 'plugin_action_links_' . $basename ] ?? null;

expect( is_callable( $cb ), 'the Plugins screen must get a Settings action link' );

if ( is_callable( $cb ) ) {
	$links = $cb( [ '<a href="#">Deactivate</a>' ] );
	expect( str_contains( $links[0] ?? '', 'section=paybridge_np' ), 'the Settings link must open the gateway settings section' );
	expect( str_contains( $links[0] ?? '', 'Settings' ), 'the Settings link must be labelled Settings' );
	expect( count( $links ) === 2, 'the Settings link must be prepended to the existing links, not replace them' );
}

if ( ( $GLOBALS['failures'] ?? 0 ) > 0 ) {
	fwrite( STDERR, "bootstrap tests FAILED\n" );
	exit( 1 );
}

echo "PASS test_legacy_folder_copy_is_deactivated_on_load\n";
echo "PASS test_settings_link_points_at_the_gateway_section\n";
