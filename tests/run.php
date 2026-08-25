<?php

declare( strict_types=1 );

// Lightweight gateway tests. WooCommerce cannot run on this machine, so these
// stubs exercise the gateway's order/session boundary without pretending to be
// a browser or a WordPress installation.

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ );
define( 'PAYBRIDGENP_WC_URL', 'https://shop.example.test/wp-content/plugins/paybridge/' );
define( 'PAYBRIDGENP_WC_VERSION', 'test' );

final class Redirected extends RuntimeException {
	public string $url;

	public function __construct( string $url ) {
		parent::__construct( $url );
		$this->url = $url;
	}
}

class WC_Payment_Gateway {
	public string $id = '';
	public string $method_title = '';
	public string $method_description = '';
	public string $icon = '';
	public array $supports = [];
	public bool $has_fields = false;
	public string $enabled = '';
	public string $title = '';
	public string $description = '';
	public array $form_fields = [];
	protected array $settings = [];

	public function init_settings(): void {
		$this->settings = $GLOBALS['paybridge_test_settings'] ?? [];
	}

	public function get_option( string $key, $default = '' ) {
		return $this->settings[ $key ] ?? $default;
	}

	public function process_admin_options() {
		return true;
	}

	public function get_title(): string {
		return $this->title;
	}

	public function get_return_url( $order ): string {
		return 'https://shop.example.test/checkout/order-received/' . $order->get_id() . '/?key=' . $order->get_order_key();
	}
}

final class TestOrder {
	private int $id;
	private string $key;
	private array $meta = [];
	private string $status = 'pending';
	public array $notes = [];

	public function __construct( int $id = 17, string $key = 'wc_order_secret' ) {
		$this->id  = $id;
		$this->key = $key;
	}

	public function get_id(): int { return $this->id; }
	public function get_order_key(): string { return $this->key; }
	public function get_total(): string { return '123.45'; }
	public function get_billing_first_name(): string { return 'Rita'; }
	public function get_billing_last_name(): string { return 'Shrestha'; }
	public function get_billing_email(): string { return 'rita@example.test'; }
	public function get_billing_phone(): string { return '9800000000'; }
	public function get_billing_address_1(): string { return 'Durbar Marg'; }
	public function get_billing_address_2(): string { return ''; }
	public function get_billing_city(): string { return 'Kathmandu'; }
	public function get_billing_state(): string { return ''; }
	public function get_billing_postcode(): string { return ''; }
	public function get_billing_country(): string { return 'NP'; }
	public function update_meta_data( string $key, $value ): void { $this->meta[ $key ] = $value; }
	public function get_meta( string $key, bool $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function add_order_note( string $note ): void { $this->notes[] = $note; }
	public function is_paid(): bool { return in_array( $this->status, [ 'processing', 'completed' ], true ); }
	public function update_status( string $status, string $note = '' ): void { $this->status = $status; if ( '' !== $note ) $this->notes[] = $note; }
	public function get_status(): string { return $this->status; }
	public function save(): void {}
	public function get_checkout_payment_url( bool $on_checkout = false ): string {
		return 'https://shop.example.test/checkout/order-pay/' . $this->id . '/?pay_for_order=true&key=' . $this->key;
	}
}

function __( string $text, string $domain = '' ): string { return $text; }
function esc_attr__( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function esc_attr( string $text ): string { return $text; }
function esc_html( string $text ): string { return $text; }
function esc_url( string $url ): string { return $url; }
function esc_url_raw( string $url ): string { return $url; }
function wp_kses_post( string $text ): string { return $text; }
function wpautop( string $text ): string { return $text; }
function wptexturize( string $text ): string { return $text; }
function absint( $value ): int { return abs( (int) $value ); }
function apply_filters( string $hook, $value ) {
	// Filters are pass-through unless a test installs an override, which is how
	// `paybridge_wc_api_base` gets exercised without a WordPress install.
	return $GLOBALS['paybridge_test_filter_overrides'][ $hook ] ?? $value;
}
function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['paybridge_test_actions'][ $hook ] = $callback; }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function home_url( string $path = '' ): string { return 'https://shop.example.test/store' . $path; }
function wp_parse_url( string $url, int $component = -1 ) {
	// Real wp_parse_url() takes a component argument; without it every host
	// comparison below collapsed to the string "Array" and matched everything.
	return parse_url( $url, $component );
}
function wc_get_order( int $id ) { return $GLOBALS['paybridge_test_orders'][ $id ] ?? null; }
function wc_get_checkout_url(): string { return 'https://shop.example.test/checkout/'; }
function wc_add_notice( string $message, string $type ): void { $GLOBALS['paybridge_test_notices'][] = [ $message, $type ]; }
function wp_unslash( string $value ): string { return $value; }
function sanitize_text_field( string $value ): string { return $value; }
function wp_list_pluck( array $items, string $field ): array { return array_map( static function ( array $item ) use ( $field ) { return $item[ $field ]; }, $items ); }
function admin_url( string $path = '' ): string { return 'https://shop.example.test/store/wp-admin/' . $path; }
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['paybridge_test_added_filters'][ $hook ][] = $callback; }
/**
 * Faithful enough to catch the bug the old stub hid.
 *
 * Real wp_safe_redirect() refuses any host that is not the site's own or in
 * `allowed_redirect_hosts`, and silently falls back to admin_url(). The previous
 * stub echoed whatever URL it was handed, so two tests asserted a redirect to a
 * PayBridgeNP checkout URL that WordPress would in fact have thrown away — the
 * buyer landing in wp-admin instead of paying. Confirmed on WordPress 7.0.
 */
function wp_safe_redirect( string $url ): void {
	$allowed = [ (string) wp_parse_url( home_url(), PHP_URL_HOST ) ];
	foreach ( $GLOBALS['paybridge_test_added_filters']['allowed_redirect_hosts'] ?? [] as $cb ) {
		$allowed = $cb( $allowed );
	}
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( $host !== '' && ! in_array( $host, $allowed, true ) ) {
		throw new Redirected( admin_url() );
	}
	throw new Redirected( $url );
}
function wp_enqueue_script( string $handle, string $src, array $deps = [], $ver = false, bool $in_footer = false ): void { $GLOBALS['paybridge_test_scripts'][ $handle ] = $src; }
function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): void { $GLOBALS['paybridge_test_inline'][ $handle ] = $data; }
function wp_json_encode( $value ): string { return json_encode( $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); }

require dirname( __DIR__ ) . '/includes/class-wc-gateway-paybridge.php';

final class TestGateway extends Paybridge_WC_Gateway {
	/** @var array<int,array<string,mixed>> */
	public array $payloads = [];
	/** @var array<int,array<string,mixed>|Throwable> */
	public array $responses = [];
	/** @var array<int,string|null> */
	public array $idempotency_keys = [];
	/** @var array<int,array{session_id:string,provider:string}> */
	public array $provider_switches = [];
	public bool $provider_switch_allowed = true;

	protected function create_checkout_session( array $session_payload, string $secret_key, ?string $idempotency_key = null ): array {
		$this->payloads[] = $session_payload;
		$this->idempotency_keys[] = $idempotency_key;
		$response         = array_shift( $this->responses );
		if ( $response instanceof Throwable ) {
			throw $response;
		}
		return is_array( $response ) ? $response : [];
	}

	protected function change_checkout_provider( string $session_id, string $provider ): bool {
		$this->provider_switches[] = [ 'session_id' => $session_id, 'provider' => $provider ];
		return $this->provider_switch_allowed;
	}
}

function reset_test_state( array $settings ): TestOrder {
	$GLOBALS['paybridge_test_settings'] = $settings;
	$GLOBALS['paybridge_test_notices']  = [];
	$GLOBALS['paybridge_test_scripts']  = [];
	$GLOBALS['paybridge_test_inline']   = [];
	$GLOBALS['paybridge_test_added_filters'] = [];
	$_POST = [];
	$_GET  = [];
	$order = new TestOrder();
	$GLOBALS['paybridge_test_orders'] = [ $order->get_id() => $order ];
	return $order;
}

function expect( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function expect_redirect( callable $callback, string $expected, string $message ): void {
	try {
		$callback();
	} catch ( Redirected $redirect ) {
		expect( $redirect->url === $expected, $message . ' (got ' . $redirect->url . ')' );
		return;
	}
	throw new RuntimeException( $message . ' (no redirect)' );
}

function test_default_off_preserves_legacy_redirect(): void {
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example' ] );
	$gateway = new TestGateway();
	$gateway->responses = [ [ 'id' => 'cs_legacy', 'checkout_url' => 'https://checkout.paybridgenp.com/checkout/cs_legacy' ] ];

	expect( $gateway->form_fields['embedded_checkout']['default'] === 'no', 'embedded checkout must default to off' );
	$result = $gateway->process_payment( $order->get_id() );

	expect( $result['redirect'] === 'https://checkout.paybridgenp.com/checkout/cs_legacy', 'off must retain the legacy checkout_url redirect' );
	expect( ! isset( $gateway->payloads[0]['embedOrigin'] ), 'off must not send embedOrigin' );
	expect( $order->get_meta( '_paybridge_checkout_url', true ) === 'https://checkout.paybridgenp.com/checkout/cs_legacy', 'every mode must retain the checkout URL for safe retry reuse' );
	expect( $gateway->idempotency_keys[0] === 'woocommerce-order-17-attempt-1', 'session creation must use a stable per-order attempt key' );
}

function test_second_place_order_reuses_unexpired_session(): void {
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes', 'display_style' => 'provider_tiles' ] );
	$_POST['paybridge_wc_provider'] = 'fonepay';
	$gateway = new TestGateway();
	$gateway->responses = [ [
		'id'           => 'cs_once',
		'checkout_url' => 'https://checkout.paybridgenp.com/checkout/cs_once',
		'expires_at'   => gmdate( 'c', time() + 1800 ),
	] ];

	$first  = $gateway->process_payment( $order->get_id() );
	$_POST['paybridge_wc_provider'] = 'khalti';
	$second = $gateway->process_payment( $order->get_id() );

	expect( count( $gateway->payloads ) === 1, 'a second Place Order tap must not create another payable session' );
	expect( $first['redirect'] === $second['redirect'], 'the buyer must resume the same order-pay overlay' );
	expect( $order->get_meta( '_paybridge_session_id', true ) === 'cs_once', 'the stored session must not be overwritten' );
	expect( $gateway->provider_switches === [ [ 'session_id' => 'cs_once', 'provider' => 'khalti' ] ], 'a changed tile must retarget the reusable session' );
	expect( $order->get_meta( '_paybridge_provider', true ) === 'khalti', 'the order must remember the provider actually requested for the reused session' );
}

function test_unsafe_provider_switch_keeps_the_existing_session(): void {
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example', 'display_style' => 'provider_tiles' ] );
	$_POST['paybridge_wc_provider'] = 'fonepay';
	$gateway = new TestGateway();
	$gateway->responses = [ [
		'id'           => 'cs_active',
		'checkout_url' => 'https://checkout.paybridgenp.com/checkout/cs_active',
		'expires_at'   => gmdate( 'c', time() + 1800 ),
	] ];
	$gateway->process_payment( $order->get_id() );

	$gateway->provider_switch_allowed = false;
	$_POST['paybridge_wc_provider'] = 'esewa';
	$result = $gateway->process_payment( $order->get_id() );

	expect( count( $gateway->payloads ) === 1, 'a blocked switch must not create another payable session' );
	expect( $order->get_meta( '_paybridge_provider', true ) === 'fonepay', 'a blocked switch must retain the active provider' );
	expect( strpos( $result['redirect'], 'switch_blocked=1' ) !== false, 'the reused checkout must explain that its active provider could not be changed' );
}

function test_expired_session_advances_attempt_key(): void {
	$order = reset_test_state( [ 'secret_key' => 'sk_test_example' ] );
	$order->update_meta_data( '_paybridge_checkout_url', 'https://checkout.paybridgenp.com/checkout/cs_old' );
	$order->update_meta_data( '_paybridge_expires_at', gmdate( 'c', time() - 1 ) );
	$order->update_meta_data( '_paybridge_session_attempt', 1 );
	$gateway = new TestGateway();
	$gateway->responses = [ [ 'id' => 'cs_new', 'checkout_url' => 'https://checkout.paybridgenp.com/checkout/cs_new' ] ];

	$result = $gateway->process_payment( $order->get_id() );

	expect( $result['redirect'] === 'https://checkout.paybridgenp.com/checkout/cs_new', 'an expired session must be replaceable' );
	expect( $gateway->idempotency_keys[0] === 'woocommerce-order-17-attempt-2', 'a replacement must use a fresh idempotency attempt' );
}

function test_embed_origin_is_store_origin_and_order_pay_keeps_key(): void {
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes' ] );
	$gateway = new TestGateway();
	$gateway->responses = [ [ 'id' => 'cs_embed', 'checkout_url' => 'https://checkout.paybridgenp.com/checkout/cs_embed' ] ];

	$result = $gateway->process_payment( $order->get_id() );

	expect( $gateway->payloads[0]['embedOrigin'] === 'https://shop.example.test', 'embedOrigin must be the shop origin without its path' );
	expect( $result['redirect'] === $order->get_checkout_payment_url( true ), 'embedded checkout must use the keyed order-pay URL' );
	expect( $order->get_meta( '_paybridge_checkout_url', true ) === 'https://checkout.paybridgenp.com/checkout/cs_embed', 'checkout URL must be stored on the order' );
	expect( $order->get_meta( '_paybridge_embed_permitted', true ) === 'yes', 'a server-accepted embed request must be marked permitted' );
}

function test_provider_tile_keeps_legacy_direct_redirect_when_opt_out(): void {
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example', 'display_style' => 'provider_tiles' ] );
	$_POST['paybridge_wc_provider'] = 'fonepay';
	$gateway = new TestGateway();
	$gateway->responses = [ [ 'id' => 'cs_direct', 'checkout_url' => 'https://checkout.paybridgenp.com/checkout/cs_direct' ] ];

	$result = $gateway->process_payment( $order->get_id() );

	expect( $gateway->payloads[0]['flow'] === 'redirect', 'provider tiles must retain flow=redirect while embedded checkout is off' );
	expect( $result['redirect'] === 'https://checkout.paybridgenp.com/checkout/cs_direct', 'provider tiles must retain the direct hosted URL redirect while embedded checkout is off' );
}

function test_provider_tile_is_preselected_not_redirected_when_embedded(): void {
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes', 'display_style' => 'provider_tiles' ] );
	$_POST['paybridge_wc_provider'] = 'fonepay';
	$gateway = new TestGateway();
	$gateway->responses = [ [ 'id' => 'cs_picker', 'checkout_url' => 'https://checkout.paybridgenp.com/checkout/cs_picker' ] ];

	$gateway->process_payment( $order->get_id() );

	expect( $gateway->payloads[0]['provider'] === 'fonepay', 'the selected tile provider must be retained' );
	expect( ! isset( $gateway->payloads[0]['flow'] ), 'embedded sessions must use the hosted picker, not flow=redirect' );
}

function test_embed_rejection_falls_back_to_plain_redirect_on_receipt(): void {
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes' ] );
	$gateway = new TestGateway();
	$gateway->responses = [
		new \PayBridgeNP\Exceptions\PayBridgeException( 'Embedded Checkout requires a Growth plan or higher.', 403, 'permission_error' ),
		[ 'id' => 'cs_fallback', 'checkout_url' => 'https://checkout.paybridgenp.com/checkout/cs_fallback' ],
	];

	$gateway->process_payment( $order->get_id() );
	expect( count( $gateway->payloads ) === 2, 'a rejected optional embed request must retry once without embedOrigin' );
	expect( ! isset( $gateway->payloads[1]['embedOrigin'] ), 'the fallback session must be an ordinary hosted session' );
	expect( $order->get_meta( '_paybridge_embed_permitted', true ) === 'no', 'server rejection must disable embedding for this order' );

	$_GET['key'] = $order->get_order_key();
	expect_redirect(
		static function () use ( $gateway, $order ): void { $gateway->render_receipt( $order->get_id() ); },
		'https://checkout.paybridgenp.com/checkout/cs_fallback',
		'receipt must fall back to the plain hosted checkout when embedding is not permitted'
	);
}

function test_provider_tile_embed_rejection_restores_direct_provider_flow(): void {
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes', 'display_style' => 'provider_tiles' ] );
	$_POST['paybridge_wc_provider'] = 'fonepay';
	$gateway = new TestGateway();
	$gateway->responses = [
		new \PayBridgeNP\Exceptions\PayBridgeException( 'Embedded Checkout requires a Growth plan or higher.', 403, 'permission_error' ),
		[ 'id' => 'cs_fallback_direct', 'checkout_url' => 'https://checkout.paybridgenp.com/checkout/cs_fallback_direct' ],
	];

	$gateway->process_payment( $order->get_id() );

	expect( $gateway->payloads[1]['provider'] === 'fonepay', 'the fallback must retain the selected provider' );
	expect( $gateway->payloads[1]['flow'] === 'redirect', 'the fallback must restore direct provider flow after dropping embedOrigin' );
}

function test_unregistered_embed_domain_falls_back_to_plain_redirect(): void {
	// The 403 case above is a merchant on the wrong plan. THIS is the likelier
	// mistake: an entitled merchant who never added their shop domain under
	// Project -> Embed domains. The API answers that with a 400 naming
	// embedOrigin, not a 403, so it takes a separate branch — which was
	// untested until 2026-08-16 (disabling it left all 7 tests green).
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes' ] );
	$gateway = new TestGateway();
	$gateway->responses = [
		new \PayBridgeNP\Exceptions\PayBridgeException(
			"embedOrigin is not registered under this project's embed domains for this mode. Add it in the dashboard under Project -> Embed domains, then retry.",
			400,
			'invalid_request_error'
		),
		[ 'id' => 'cs_unreg', 'checkout_url' => 'https://checkout.paybridgenp.com/checkout/cs_unreg' ],
	];

	$gateway->process_payment( $order->get_id() );
	expect( count( $gateway->payloads ) === 2, 'an unregistered embed domain must retry once without embedOrigin' );
	expect( ! isset( $gateway->payloads[1]['embedOrigin'] ), 'the retry must drop embedOrigin' );
	expect( $order->get_meta( '_paybridge_embed_permitted', true ) === 'no', 'an unregistered domain must disable embedding for this order' );

	$_GET['key'] = $order->get_order_key();
	expect_redirect(
		static function () use ( $gateway, $order ): void { $gateway->render_receipt( $order->get_id() ); },
		'https://checkout.paybridgenp.com/checkout/cs_unreg',
		'the buyer must still reach a working checkout when the domain is unregistered'
	);
}

function test_unrelated_client_errors_are_not_retried(): void {
	// The retry must stay narrow. A 400 that has nothing to do with embedding
	// is a real merchant error and must surface, not be silently retried and
	// swallowed — otherwise a misconfigured amount or currency looks like a
	// generic failure.
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes' ] );
	$gateway = new TestGateway();
	$gateway->responses = [
		new \PayBridgeNP\Exceptions\PayBridgeException( 'currency must be NPR', 400, 'invalid_request_error' ),
	];

	$result = $gateway->process_payment( $order->get_id() );
	expect( count( $gateway->payloads ) === 1, 'an unrelated 400 must NOT trigger the embed retry' );
	expect( isset( $result['result'] ) && 'failure' === $result['result'], 'an unrelated 400 must fail visibly' );
}

function test_receipt_mount_has_script_and_non_js_fallback(): void {
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes' ] );
	$gateway = new TestGateway();
	$order->update_meta_data( '_paybridge_checkout_url', 'https://checkout.paybridgenp.com/checkout/cs_mount' );
	$order->update_meta_data( '_paybridge_embed_permitted', 'yes' );
	$_GET['key'] = $order->get_order_key();

	ob_start();
	$gateway->render_receipt( $order->get_id() );
	$html = (string) ob_get_clean();

	expect( $GLOBALS['paybridge_test_scripts']['paybridge-wc-embed'] === 'https://api.paybridgenp.com/js/v1/button.js', 'receipt must load the supported embed runtime' );
	expect( strpos( $GLOBALS['paybridge_test_inline']['paybridge-wc-embed'], 'PayBridgeNP.openSession' ) !== false, 'receipt must mount the stored checkout URL through openSession' );
	expect( strpos( $GLOBALS['paybridge_test_inline']['paybridge-wc-embed'], 'providerReturn=false' ) !== false, 'an ordinary order-pay visit must not be mistaken for a provider popup return' );
	expect( strpos( $GLOBALS['paybridge_test_inline']['paybridge-wc-embed'], 'window.location.href=url' ) !== false, 'a failed embed runtime must redirect to the hosted checkout' );
	expect( strpos( $GLOBALS['paybridge_test_inline']['paybridge-wc-embed'], 'link.addEventListener("click"' ) !== false, 'closing the overlay must leave a link that can reopen it' );
	expect( strpos( $html, 'id="paybridge-wc-continue"' ) !== false && strpos( $html, 'Open payment' ) !== false, 'the receipt must show an accurate reopen action after the overlay closes' );
	expect( strpos( $html, '<noscript>' ) !== false && strpos( $html, 'https://checkout.paybridgenp.com/checkout/cs_mount' ) !== false, 'buyers without JavaScript must receive a plain payment link' );

	$_GET['paybridge_provider_return'] = '1';
	ob_start();
	$gateway->render_receipt( $order->get_id() );
	ob_end_clean();
	expect( strpos( $GLOBALS['paybridge_test_inline']['paybridge-wc-embed'], 'providerReturn=true' ) !== false, 'a provider return must close its opener-backed popup before mounting a duplicate checkout' );
	unset( $_GET['paybridge_provider_return'] );

	$GLOBALS['paybridge_test_filter_overrides'] = [
		'paybridge_wc_api_base'         => 'http://host.docker.internal:3000',
		'paybridge_wc_browser_api_base' => 'http://localhost:3000',
	];
	reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes' ] );
	$gateway = new TestGateway();
	$order->update_meta_data( '_paybridge_embed_permitted', 'yes' );
	$GLOBALS['paybridge_test_orders'][ $order->get_id() ] = $order;
	$_GET['key'] = $order->get_order_key();
	ob_start();
	$gateway->render_receipt( $order->get_id() );
	ob_end_clean();
	expect( $GLOBALS['paybridge_test_scripts']['paybridge-wc-embed'] === 'http://localhost:3000/js/v1/button.js', 'browser assets must support a public base distinct from the server API base' );
	$GLOBALS['paybridge_test_filter_overrides'] = [];
}

function test_tampered_order_key_is_rejected(): void {
	$order   = reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes' ] );
	$gateway = new TestGateway();
	$order->update_meta_data( '_paybridge_checkout_url', 'https://checkout.paybridgenp.com/checkout/cs_safe' );
	$order->update_meta_data( '_paybridge_embed_permitted', 'yes' );
	$_GET['key'] = 'wc_order_tampered';

	expect_redirect(
		static function () use ( $gateway, $order ): void { $gateway->render_receipt( $order->get_id() ); },
		wc_get_checkout_url(),
		'a tampered order-pay key must never receive the stored checkout URL'
	);
	expect( $GLOBALS['paybridge_test_scripts'] === [], 'tampered key must not load the embed script' );
}

function test_embedded_provider_cancel_reopens_the_same_order(): void {
	$order = reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes' ] );
	$order->update_meta_data( '_paybridge_session_id', 'cs_retry' );
	$order->update_meta_data( '_paybridge_session_attempt', 1 );
	$order->update_meta_data( '_paybridge_embed_permitted', 'yes' );
	$_GET = [
		'order_id'   => (string) $order->get_id(),
		'order_key'  => $order->get_order_key(),
		'session_id' => 'cs_retry',
		'paybridge_attempt' => '1',
		'status'     => 'cancelled',
	];
	$gateway = new TestGateway();

	expect_redirect(
		static function () use ( $gateway ): void { $gateway->handle_return(); },
		add_query_arg( [ 'paybridge_provider_return' => '1' ], $order->get_checkout_payment_url( true ) ),
		'an embedded provider cancel must reopen the same order-pay overlay'
	);
	expect( 'pending' === $order->get_status(), 'an embedded provider cancel must keep the Woo order payable' );
	expect( [] === $GLOBALS['paybridge_test_notices'], 'a retryable embedded cancel must not claim that the whole order was cancelled' );
}

function test_return_status_must_match_the_stored_session(): void {
	$order = reset_test_state( [ 'secret_key' => 'sk_test_example', 'embedded_checkout' => 'yes' ] );
	$order->update_meta_data( '_paybridge_session_id', 'cs_expected' );
	$order->update_meta_data( '_paybridge_session_attempt', 1 );
	$_GET = [
		'order_id'   => (string) $order->get_id(),
		'order_key'  => $order->get_order_key(),
		'session_id' => 'cs_other',
		'paybridge_attempt' => '1',
		'status'     => 'success',
	];
	$gateway = new TestGateway();

	expect_redirect(
		static function () use ( $gateway ): void { $gateway->handle_return(); },
		$order->get_checkout_payment_url( true ),
		'a return from another session must go back to the original order payment page'
	);
	expect( 'pending' === $order->get_status(), 'a mismatched session must not change the Woo order status' );
}

function test_legacy_return_without_attempt_keeps_session_binding(): void {
	$order = reset_test_state( [ 'secret_key' => 'sk_test_example' ] );
	$order->update_meta_data( '_paybridge_session_id', 'cs_legacy' );
	$_GET = [
		'order_id'   => (string) $order->get_id(),
		'order_key'  => $order->get_order_key(),
		'session_id' => 'cs_legacy',
		'status'     => 'success',
	];
	$gateway = new TestGateway();

	expect_redirect(
		static function () use ( $gateway ): void { $gateway->handle_return(); },
		$gateway->get_return_url( $order ),
		'a pre-attempt return with the matching stored session must remain valid'
	);
	expect( 'on-hold' === $order->get_status(), 'a valid legacy success return must not leave the buyer on a payable order' );
}

function test_stale_explicit_cancel_cannot_cancel_the_current_attempt(): void {
	$order = reset_test_state( [ 'secret_key' => 'sk_test_example' ] );
	$order->update_meta_data( '_paybridge_session_id', 'cs_current' );
	$order->update_meta_data( '_paybridge_session_attempt', 2 );
	$_GET = [
		'order_id'          => (string) $order->get_id(),
		'order_key'         => $order->get_order_key(),
		'cancelled'         => '1',
		'session_id'        => 'cs_stale',
		'paybridge_attempt' => '1',
	];
	$gateway = new TestGateway();

	expect_redirect(
		static function () use ( $gateway ): void { $gateway->handle_return(); },
		$order->get_checkout_payment_url( true ),
		'a stale cancel callback must return to the current order payment page'
	);
	expect( 'pending' === $order->get_status(), 'a stale cancel callback must not cancel the current order attempt' );
}

function test_current_explicit_cancel_still_cancels_the_order(): void {
	$order = reset_test_state( [ 'secret_key' => 'sk_test_example' ] );
	$order->update_meta_data( '_paybridge_session_id', 'cs_current' );
	$order->update_meta_data( '_paybridge_session_attempt', 2 );
	$_GET = [
		'order_id'          => (string) $order->get_id(),
		'order_key'         => $order->get_order_key(),
		'cancelled'         => '1',
		'paybridge_attempt' => '2',
	];
	$gateway = new TestGateway();

	expect_redirect(
		static function () use ( $gateway ): void { $gateway->handle_return(); },
		wc_get_checkout_url(),
		'the current attempt cancel URL must retain the explicit WooCommerce cancellation flow'
	);
	expect( 'cancelled' === $order->get_status(), 'the current explicit cancel must cancel the Woo order' );
}

function test_webhook_session_must_match_the_stored_session(): void {
	$order = reset_test_state( [ 'secret_key' => 'sk_test_example' ] );
	$order->update_meta_data( '_paybridge_session_id', 'cs_expected' );
	$gateway = new TestGateway();
	$apply = new ReflectionMethod( $gateway, 'process_webhook_event' );
	$apply->invoke(
		$gateway,
		[
			'type' => 'payment.succeeded',
			'data' => [
				'id'         => 'pay_other',
				'session_id' => 'cs_other',
				'metadata'   => [
					'order_id'  => (string) $order->get_id(),
					'order_key' => $order->get_order_key(),
				],
			],
		]
	);

	expect( 'pending' === $order->get_status(), 'a webhook for another session must not change the Woo order status' );
	expect( [] === $order->notes, 'a webhook for another session must not add order notes' );
}

function test_api_base_filter_reaches_the_sdk(): void {
	// The filter was honoured for the provider list and the embed script but not
	// for checkout creation, so a store pointed at another environment listed THAT
	// environment's providers and then paid against production. The list and the
	// money must agree on the host.
	reset_test_state( [ 'secret_key' => 'sk_test_example' ] );
	$gateway = new TestGateway();

	// No setAccessible(): it is a deprecated no-op since PHP 8.1.
	$make = new ReflectionMethod( $gateway, 'make_client' );

	$read_base = static function ( $client ): string {
		$http = new ReflectionProperty( $client, 'httpClient' );
		$inner = $http->getValue( $client );
		$base  = new ReflectionProperty( $inner, 'baseUrl' );
		return (string) $base->getValue( $inner );
	};

	$GLOBALS['paybridge_test_filter_overrides'] = [];
	expect(
		$read_base( $make->invoke( $gateway, 'sk_test_example' ) ) === 'https://api.paybridgenp.com',
		'with no filter the SDK must default to production'
	);

	$GLOBALS['paybridge_test_filter_overrides'] = [ 'paybridge_wc_api_base' => 'http://localhost:3000' ];
	expect(
		$read_base( $make->invoke( $gateway, 'sk_test_example' ) ) === 'http://localhost:3000',
		'paybridge_wc_api_base must redirect the SDK, not just the provider list'
	);

	$GLOBALS['paybridge_test_filter_overrides'] = [ 'paybridge_wc_api_base' => 'http://localhost:3000/' ];
	expect(
		$read_base( $make->invoke( $gateway, 'sk_test_example' ) ) === 'http://localhost:3000',
		'a trailing slash must not produce a double slash in request paths'
	);

	$GLOBALS['paybridge_test_filter_overrides'] = [];
}

$tests = [
	'test_default_off_preserves_legacy_redirect',
	'test_second_place_order_reuses_unexpired_session',
	'test_unsafe_provider_switch_keeps_the_existing_session',
	'test_expired_session_advances_attempt_key',
	'test_embed_origin_is_store_origin_and_order_pay_keeps_key',
	'test_provider_tile_keeps_legacy_direct_redirect_when_opt_out',
	'test_provider_tile_is_preselected_not_redirected_when_embedded',
	'test_embed_rejection_falls_back_to_plain_redirect_on_receipt',
	'test_provider_tile_embed_rejection_restores_direct_provider_flow',
	'test_unregistered_embed_domain_falls_back_to_plain_redirect',
	'test_unrelated_client_errors_are_not_retried',
	'test_receipt_mount_has_script_and_non_js_fallback',
	'test_tampered_order_key_is_rejected',
	'test_embedded_provider_cancel_reopens_the_same_order',
	'test_return_status_must_match_the_stored_session',
	'test_legacy_return_without_attempt_keeps_session_binding',
	'test_stale_explicit_cancel_cannot_cancel_the_current_attempt',
	'test_current_explicit_cancel_still_cancels_the_order',
	'test_webhook_session_must_match_the_stored_session',
	'test_api_base_filter_reaches_the_sdk',
];

foreach ( $tests as $test ) {
	$test();
	echo "PASS {$test}\n";
}

// The bootstrap tests load paybridge-np-woocommerce.php itself, which needs a
// process where PAYBRIDGENP_WC_VERSION is undefined — this file defines it at
// the top, and that constant is the bootstrap's own double-load guard. So they
// run as a separate process rather than being skipped.
$bootstrap = __DIR__ . '/bootstrap-test.php';
$output    = [];
$status    = 0;
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $bootstrap ) . ' 2>&1', $output, $status );
foreach ( $output as $line ) {
	echo $line . "\n";
}
if ( $status !== 0 ) {
	fwrite( STDERR, "bootstrap tests failed\n" );
	exit( 1 );
}

echo 'WooCommerce gateway tests passed (' . ( count( $tests ) + 2 ) . ").\n";
