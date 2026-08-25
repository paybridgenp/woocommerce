<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PayBridgeNP\PayBridgeNP;
use PayBridgeNP\Exceptions\PayBridgeException;
use PayBridgeNP\Exceptions\SignatureVerificationException;

/**
 * PayBridgeNP WooCommerce Payment Gateway
 *
 * Flow:
 *   1. process_payment()  — creates a PayBridgeNP checkout session, redirects customer
 *   2. handle_return()    — customer lands back after payment; sets order to on-hold
 *                           and redirects to the WooCommerce thank-you page
 *   3. handle_webhook()   — definitive confirmation; marks order processing/failed
 *
 * Both (2) and (3) are registered as WooCommerce API endpoints so they work even
 * when pretty-permalinks are disabled.
 */
class Paybridge_WC_Gateway extends WC_Payment_Gateway {

	/** Display style: "single_button" (hosted picker) or "provider_tiles" (direct redirect). */
	public string $display_style = 'single_button';

	public function __construct() {
		$this->id                 = 'paybridge_np';
		$this->method_title       = 'PayBridgeNP';
		$this->method_description = __( 'Accept payments via eSewa, Khalti, and Fonepay. Powered by PayBridgeNP.', 'paybridgenp-for-woocommerce' );
		$this->icon               = apply_filters(
			'paybridge_wc_gateway_icon',
			PAYBRIDGENP_WC_URL . 'assets/icon.svg'
		);
		$this->supports           = [ 'products' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled       = $this->get_option( 'enabled' );
		$this->title         = $this->get_option( 'title' );
		$this->description   = $this->get_option( 'description' );
		$this->display_style = $this->get_option( 'display_style', 'single_button' );
		$this->has_fields    = ( 'provider_tiles' === $this->display_style );

		// Save settings
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			[ $this, 'process_admin_options' ]
		);

		// WooCommerce API endpoints (work with any permalink structure)
		add_action( 'woocommerce_api_paybridge_return',  [ $this, 'handle_return' ] );
		add_action( 'woocommerce_api_paybridge_webhook', [ $this, 'handle_webhook' ] );

		// An order exists before this hook runs, which makes the order-pay page the
		// right place to open an embedded checkout. Never open the provider itself
		// in the frame: the hosted checkout decides which rails stay in-frame and
		// which hand off to a provider-owned popup/top-level page.
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'render_receipt' ] );

		// Tile styles, only when tiles are enabled and only on the checkout page
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_checkout_assets' ] );
	}

	/**
	 * Canonical provider list rendered on the checkout when display_style = provider_tiles.
	 * Shared by classic payment_fields() and the Blocks payment-method-data payload so the
	 * UI never disagrees with itself.
	 *
	 * @return array<int,array{id:string,name:string,logoUrl:string}>
	 */
	public static function providers(): array {
		return [
			[ 'id' => 'esewa',   'name' => 'eSewa',   'logoUrl' => PAYBRIDGENP_WC_URL . 'assets/images/esewa.png' ],
			[ 'id' => 'khalti',  'name' => 'Khalti',  'logoUrl' => PAYBRIDGENP_WC_URL . 'assets/images/khalti.png' ],
			[ 'id' => 'fonepay', 'name' => 'Fonepay', 'logoUrl' => PAYBRIDGENP_WC_URL . 'assets/images/fonepay.png' ],
		];
	}

	/**
	 * The provider tiles to actually render: the canonical list filtered to the
	 * providers the merchant has enabled+configured for this key's mode, via
	 * GET /v1/providers. Prevents a customer landing on a tile for a provider
	 * that isn't set up (which would 400 with "provider not configured").
	 *
	 * Fail-open: if the key is empty (not configured yet) or the API is
	 * unreachable, fall back to the full list rather than break checkout. Never
	 * returns an empty set.
	 *
	 * @return array<int,array{id:string,name:string,logoUrl:string}>
	 */
	public static function enabled_providers( string $secret_key ): array {
		$all = self::providers();
		if ( '' === trim( $secret_key ) ) {
			return $all;
		}

		$enabled_ids = self::fetch_enabled_provider_ids( $secret_key );
		if ( null === $enabled_ids ) {
			return $all; // API unreachable — fail open.
		}

		$filtered = array_values(
			array_filter(
				$all,
				static function ( $p ) use ( $enabled_ids ) {
					return in_array( $p['id'], $enabled_ids, true );
				}
			)
		);

		// Never render zero tiles (e.g. the API returns a provider we don't have
		// a tile for) — fall back to the full list so checkout stays usable.
		return ! empty( $filtered ) ? $filtered : $all;
	}

	/**
	 * Fetch + cache (5 min) the enabled provider ids for the key's project/mode.
	 * Cached per-key so sandbox and live keys don't share. Returns null on any
	 * failure so the caller can fail open. Cache is busted when settings save
	 * (see process_admin_options below).
	 *
	 * @return array<int,string>|null
	 */
	private static function fetch_enabled_provider_ids( string $secret_key ): ?array {
		$cache_key = 'paybridge_wc_providers_' . md5( $secret_key );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$base = apply_filters( 'paybridge_wc_api_base', 'https://api.paybridgenp.com' );
		$resp = wp_remote_get(
			rtrim( $base, '/' ) . '/v1/providers',
			[
				'timeout' => 5,
				'headers' => [ 'Authorization' => 'Bearer ' . $secret_key ],
			]
		);

		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || ! isset( $body['providers'] ) || ! is_array( $body['providers'] ) ) {
			return null;
		}

		$ids = array_values( array_map( 'strval', $body['providers'] ) );
		set_transient( $cache_key, $ids, 5 * MINUTE_IN_SECONDS );
		return $ids;
	}

	/**
	 * Bust the cached provider list when the gateway settings are saved, so a
	 * merchant who just changed their key (or toggled providers) sees the new
	 * set without waiting out the 5-minute TTL.
	 */
	public function process_admin_options() {
		$old_key = $this->get_option( 'secret_key' );
		$saved   = parent::process_admin_options();
		$new_key = $this->get_option( 'secret_key' );
		foreach ( array_unique( array_filter( [ $old_key, $new_key ] ) ) as $key ) {
			delete_transient( 'paybridge_wc_providers_' . md5( $key ) );
		}
		return $saved;
	}

	/**
	 * Render the gateway icon at a consistent size on every theme.
	 *
	 * WooCommerce's default get_icon() emits a bare <img> and leaves sizing to
	 * the active theme's CSS. Classic themes cap payment icons at ~24px, but
	 * block themes (and minimal themes) often don't — so our 260x260 icon.svg
	 * can render full-size at checkout. We constrain it inline here so it looks
	 * right everywhere, independent of the theme.
	 */
	public function get_icon(): string {
		$icon = sprintf(
			'<img src="%1$s" alt="%2$s" style="max-height:24px;width:auto;display:inline-block;vertical-align:middle" />',
			esc_url( $this->icon ),
			esc_attr( $this->get_title() )
		);

		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	public function maybe_enqueue_checkout_assets(): void {
		if ( 'provider_tiles' !== $this->display_style ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_style(
			'paybridge-wc-checkout',
			PAYBRIDGENP_WC_URL . 'assets/css/paybridge-checkout.css',
			[],
			PAYBRIDGENP_WC_VERSION
		);
	}

	// ── Admin settings ────────────────────────────────────────────────────────

	public function init_form_fields(): void {
		$webhook_url = home_url( '/?wc-api=paybridge_webhook' );

		$this->form_fields = [
			'enabled'        => [
				'title'   => __( 'Enable/Disable', 'paybridgenp-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PayBridgeNP', 'paybridgenp-for-woocommerce' ),
				'default' => 'no',
			],
			'title'          => [
				'title'       => __( 'Title', 'paybridgenp-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to customers at checkout.', 'paybridgenp-for-woocommerce' ),
				'default'     => 'PayBridgeNP',
				'desc_tip'    => true,
			],
			'description'    => [
				'title'   => __( 'Description', 'paybridgenp-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely with eSewa, Khalti, or Fonepay.', 'paybridgenp-for-woocommerce' ),
			],
			'display_style'  => [
				'title'       => __( 'Display style', 'paybridgenp-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Single button shows one PayBridgeNP option and opens the hosted picker. Provider tiles show enabled providers inline; with embedded checkout on, the selected provider is preselected in the overlay, otherwise the customer goes straight to that provider.', 'paybridgenp-for-woocommerce' ),
				'default'     => 'single_button',
				'options'     => [
					'single_button'  => __( 'Single button (hosted picker)', 'paybridgenp-for-woocommerce' ),
					'provider_tiles' => __( 'Provider tiles (direct redirect)', 'paybridgenp-for-woocommerce' ),
				],
			],
			'embedded_checkout' => [
				'title'       => __( 'Embedded checkout', 'paybridgenp-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Open payment in an overlay instead of redirecting', 'paybridgenp-for-woocommerce' ),
				'description' => __( 'Uses the WooCommerce order-pay page. If embedding is unavailable, customers continue to the hosted payment page.', 'paybridgenp-for-woocommerce' ),
				'default'     => 'no',
			],
			'secret_key'     => [
				'title'       => __( 'Secret Key', 'paybridgenp-for-woocommerce' ),
				'type'        => 'password',
				/* translators: example key prefixes */
				'description' => __( 'Your PayBridgeNP secret key (starts with sk_live_ or sk_test_).', 'paybridgenp-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'webhook_secret' => [
				'title'       => __( 'Webhook Signing Secret', 'paybridgenp-for-woocommerce' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: webhook listener URL */
					__( 'Signing secret (whsec_…) from your PayBridgeNP dashboard. Set your webhook endpoint URL to: %s', 'paybridgenp-for-woocommerce' ),
					'<br><code>' . esc_html( $webhook_url ) . '</code>'
				),
			],
		];
	}

	public function payment_fields(): void {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		if ( 'provider_tiles' !== $this->display_style ) {
			return;
		}

		$providers = self::enabled_providers( $this->get_option( 'secret_key' ) );
		echo '<fieldset class="paybridge-wc-tiles" aria-label="' . esc_attr__( 'Choose a payment provider', 'paybridgenp-for-woocommerce' ) . '">';
		foreach ( $providers as $i => $p ) {
			printf(
				'<label class="paybridge-wc-tile"><input type="radio" name="paybridge_wc_provider" value="%1$s"%2$s required><img src="%3$s" alt="" width="48" height="48"><span class="paybridge-wc-tile__name">%4$s</span></label>',
				esc_attr( $p['id'] ),
				0 === $i ? ' checked' : '',
				esc_url( $p['logoUrl'] ),
				esc_html( $p['name'] )
			);
		}
		echo '</fieldset>';
	}

	// ── Checkout ──────────────────────────────────────────────────────────────

	/**
	 * Called when the customer confirms their order. Creates a PayBridgeNP checkout
	 * session and redirects the customer to the hosted payment page.
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'paybridgenp-for-woocommerce' ), 'error' );
			return [ 'result' => 'failure' ];
		}

		$secret_key = $this->get_option( 'secret_key' );
		if ( empty( $secret_key ) ) {
			wc_add_notice(
				__( 'PayBridgeNP is not configured. Please contact the store owner.', 'paybridgenp-for-woocommerce' ),
				'error'
			);
			return [ 'result' => 'failure' ];
		}

		// WooCommerce stores totals in the store currency (must be NPR).
		// Amount in paisa = total × 100 (rounded to avoid floating-point drift).
		$amount_paisa = (int) round( (float) $order->get_total() * 100 );

		// Read the buyer's current tile before considering session reuse. Woo
		// Blocks may reuse one pending order after the buyer comes back and picks a
		// different provider.
		$chosen_provider = null;
		if ( 'provider_tiles' === $this->display_style ) {
			// WooCommerce verifies the checkout nonce before process_payment().
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = isset( $_POST['paybridge_wc_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['paybridge_wc_provider'] ) ) : '';
			$valid_ids = wp_list_pluck( self::providers(), 'id' );
			if ( ! in_array( $raw, $valid_ids, true ) ) {
				wc_add_notice( __( 'Please pick a payment provider.', 'paybridgenp-for-woocommerce' ), 'error' );
				return [ 'result' => 'failure' ];
			}
			$chosen_provider = $raw;
		}

		// Browser Back and a second Place Order tap must not mint another payable
		// session for the same Woo order. Reopen the stored session while its
		// server-issued TTL is still live; the checkout itself owns safe provider
		// switching. This also keeps a buyer from paying two provider references.
		$stored_checkout_url = (string) $order->get_meta( '_paybridge_checkout_url', true );
		$stored_expires_at   = (string) $order->get_meta( '_paybridge_expires_at', true );
		$stored_expiry       = '' !== $stored_expires_at ? strtotime( $stored_expires_at ) : false;
		if ( '' !== $stored_checkout_url && false !== $stored_expiry && $stored_expiry > time() ) {
			$stored_provider = (string) $order->get_meta( '_paybridge_provider', true );
			$session_id      = (string) $order->get_meta( '_paybridge_session_id', true );
			$switch_blocked  = false;
			if ( null !== $chosen_provider && '' !== $session_id && $chosen_provider !== $stored_provider ) {
				if ( $this->change_checkout_provider( $session_id, $chosen_provider ) ) {
					$order->update_meta_data( '_paybridge_provider', $chosen_provider );
					$order->save();
				} else {
					$switch_blocked = true;
					wc_add_notice( __( 'Your previous payment is still active. Finish or cancel it before switching providers.', 'paybridgenp-for-woocommerce' ), 'notice' );
				}
			}
			$stored_embedded = 'yes' === $order->get_meta( '_paybridge_embed_permitted', true );
			$redirect        = $stored_embedded ? $order->get_checkout_payment_url( true ) : $stored_checkout_url;
			if ( $switch_blocked && ! $stored_embedded ) {
				$redirect = add_query_arg( [ 'switch_blocked' => '1' ], $redirect );
			}
			return [
				'result'   => 'success',
				'redirect' => $redirect,
			];
		}

		$session_attempt = max( 1, (int) $order->get_meta( '_paybridge_session_attempt', true ) );
		if ( '' !== $stored_checkout_url && false !== $stored_expiry && $stored_expiry <= time() ) {
			$session_attempt++;
		}
		$idempotency_key = 'woocommerce-order-' . $order->get_id() . '-attempt-' . $session_attempt;

		// Return URL: our intermediary endpoint so we can update the order first
		$return_url = add_query_arg(
			[
				'wc-api'           => 'paybridge_return',
				'order_id'         => $order->get_id(),
				'order_key'        => $order->get_order_key(),
				'paybridge_attempt' => $session_attempt,
			],
			home_url( '/' )
		);

		// Cancel URL: same endpoint with cancelled flag — lands back at checkout
		$cancel_url = add_query_arg(
			[
				'wc-api'           => 'paybridge_return',
				'order_id'         => $order->get_id(),
				'order_key'        => $order->get_order_key(),
				'paybridge_attempt' => $session_attempt,
				'cancelled'        => '1',
			],
			home_url( '/' )
		);

		// Forward the buyer's WooCommerce billing details so the hosted checkout
		// prefills "Payer details" instead of making the customer re-enter what
		// they already typed. Empty fields are dropped so we never send blanks.
		$drop_empty = static function ( $v ) {
			return '' !== $v && null !== $v;
		};
		$customer = array_filter(
			[
				'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email' => $order->get_billing_email(),
				'phone' => $order->get_billing_phone(),
			],
			$drop_empty
		);
		$address = array_filter(
			[
				'line1'      => $order->get_billing_address_1(),
				'line2'      => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'postalCode' => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
			],
			$drop_empty
		);
		// The API requires line1 + city for an address; only attach when present.
		if ( ! empty( $address['line1'] ) && ! empty( $address['city'] ) ) {
			$customer['address'] = $address;
		}

		$embedded_checkout = 'yes' === $this->get_option( 'embedded_checkout', 'no' );
		$session_payload   = [
			'amount'     => $amount_paisa,
			'currency'   => 'NPR',
			'return_url' => $return_url,
			'cancel_url' => $cancel_url,
			'metadata'   => [
				'order_id'  => (string) $order->get_id(),
				'order_key' => $order->get_order_key(),
				'source'    => 'woocommerce',
			],
		];
		if ( ! empty( $customer ) ) {
			$session_payload['customer'] = $customer;
		}
		if ( $embedded_checkout ) {
			// The API validates this origin against the project's Embed domains and
			// entitlement. It is deliberately the storefront origin, never a
			// PayBridgeNP or plugin URL.
			$session_payload['embedOrigin'] = $this->shop_origin();
		}
		if ( null !== $chosen_provider ) {
			// The API rejects flow=redirect with embedOrigin: its hosted picker is
			// the document that owns the buyer's tap and can safely decide whether
			// a provider stays in-frame or opens a provider-owned popup. Keeping the
			// provider makes a tile choice the picker's preselection when embedding
			// is enabled. With the setting off this remains byte-for-byte the direct
			// provider redirect flow shipped previously.
			if ( ! $embedded_checkout ) {
				$session_payload['flow'] = 'redirect';
			}
			$session_payload['provider'] = $chosen_provider;
		}

		$embedded_permitted = $embedded_checkout;
		try {
			$session = $this->create_checkout_session( $session_payload, $secret_key, $idempotency_key );
		} catch ( PayBridgeException $e ) {
			// No plan names or entitlement rules live in the plugin. A rejected
			// optional embed request simply retries as the ordinary hosted checkout,
			// so the buyer keeps a usable payment path.
			if ( $embedded_checkout && $this->embed_request_was_rejected( $e ) ) {
				unset( $session_payload['embedOrigin'] );
				if ( null !== $chosen_provider ) {
					$session_payload['flow'] = 'redirect';
				}
				$embedded_permitted = false;
				try {
					$session = $this->create_checkout_session( $session_payload, $secret_key, $idempotency_key . '-embed-fallback' );
				} catch ( PayBridgeException $fallback_error ) {
					$this->add_payment_error( $fallback_error );
					return [ 'result' => 'failure' ];
				}
			} else {
				$this->add_payment_error( $e );
				return [ 'result' => 'failure' ];
			}
		}

		if ( ! isset( $session['checkout_url'] ) || ! is_string( $session['checkout_url'] ) || '' === $session['checkout_url'] ) {
			wc_add_notice(
				__( 'Payment error: PayBridgeNP did not return a checkout URL.', 'paybridgenp-for-woocommerce' ),
				'error'
			);
			return [ 'result' => 'failure' ];
		}

		// Persist session ID on the order for traceability
		$order->update_meta_data( '_paybridge_session_id', $session['id'] );
		$order->update_meta_data( '_paybridge_checkout_url', esc_url_raw( $session['checkout_url'] ) );
		$order->update_meta_data( '_paybridge_session_attempt', $session_attempt );
		if ( isset( $session['expires_at'] ) && is_string( $session['expires_at'] ) ) {
			$order->update_meta_data( '_paybridge_expires_at', $session['expires_at'] );
		}
		if ( null !== $chosen_provider ) {
			$order->update_meta_data( '_paybridge_provider', $chosen_provider );
		}
		if ( $embedded_checkout ) {
			$order->update_meta_data( '_paybridge_embed_permitted', $embedded_permitted ? 'yes' : 'no' );
		} else {
			$order->update_meta_data( '_paybridge_embed_permitted', 'no' );
		}
		$order->add_order_note(
			null !== $chosen_provider
				? sprintf(
					/* translators: 1: provider name, 2: PayBridgeNP checkout session ID */
					__( 'PayBridgeNP checkout session created (provider: %1$s): %2$s', 'paybridgenp-for-woocommerce' ),
					$chosen_provider,
					$session['id']
				)
				: sprintf(
					/* translators: %s: PayBridgeNP checkout session ID */
					__( 'PayBridgeNP checkout session created: %s', 'paybridgenp-for-woocommerce' ),
					$session['id']
				)
		);
		$order->save();

		$result = [
			'result'   => 'success',
			'redirect' => $session['checkout_url'],
		];
		if ( $embedded_checkout ) {
			$result['redirect'] = $order->get_checkout_payment_url( true );
		}

		return $result;
	}

	/**
	 * Create a session in one small seam so the gateway can be tested without a
	 * live WordPress/WooCommerce install.
	 *
	 * @param array<string,mixed> $session_payload
	 * @return array<string,mixed>
	 */
	protected function create_checkout_session( array $session_payload, string $secret_key, ?string $idempotency_key = null ): array {
		// `paybridge_wc_api_base` has to reach the SDK as well. It was honoured for
		// the provider list and the embed script but not here, so the one call that
		// moves money ignored the filter and always went to production: a store
		// pointed at another environment listed THAT environment's providers and
		// then tried to pay against prod, with a key prod had never seen. Found
		// 2026-08-16 doing exactly that.
		return $this->make_client( $secret_key )->checkout->create( $session_payload, $idempotency_key );
	}

	/** Retarget one reusable checkout without creating another payable session. */
	protected function change_checkout_provider( string $session_id, string $provider ): bool {
		$base     = rtrim( (string) apply_filters( 'paybridge_wc_api_base', 'https://api.paybridgenp.com' ), '/' );
		$response = wp_remote_post(
			$base . '/checkout/' . rawurlencode( $session_id ) . '/change-provider',
			[
				'timeout'     => 8,
				'redirection' => 0,
				'body'        => [ 'provider' => $provider ],
			]
		);
		if ( is_wp_error( $response ) || 303 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		return '' !== $location && false === strpos( $location, 'switch_blocked=1' );
	}

	/**
	 * Redirect the buyer to a PayBridgeNP-hosted page.
	 *
	 * wp_safe_redirect() refuses any host that is not the store's own and falls
	 * back to admin_url() without saying so, which meant a buyer sent to the
	 * hosted checkout landed in wp-admin — or on the login screen, if they were
	 * not staff. It only showed up on the order-pay fallback, because the ordinary
	 * redirect goes through WooCommerce rather than this call.
	 *
	 * The allowlist is scoped to the exact host being used, so this keeps the
	 * open-redirect protection instead of trading it for wp_redirect().
	 */
	protected function redirect_offsite( string $url ): void {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( array $hosts ) use ( $host ): array {
					$hosts[] = $host;
					return $hosts;
				}
			);
		}
		wp_safe_redirect( $url );
	}

	/**
	 * Build the API client. Separate from the call above so a test can assert
	 * which host it is pointed at without making a request.
	 */
	protected function make_client( string $secret_key ): PayBridgeNP {
		return new PayBridgeNP(
			[
				'api_key'  => $secret_key,
				'base_url' => rtrim( (string) apply_filters( 'paybridge_wc_api_base', 'https://api.paybridgenp.com' ), '/' ),
			]
		);
	}

	private function add_payment_error( PayBridgeException $e ): void {
		wc_add_notice(
			sprintf(
				/* translators: %s: error message from PayBridgeNP API */
				__( 'Payment error: %s', 'paybridgenp-for-woocommerce' ),
				$e->getMessage()
			),
			'error'
		);
	}

	private function embed_request_was_rejected( PayBridgeException $e ): bool {
		if ( 403 === $e->getStatusCode() ) {
			return true;
		}

		// A missing/revoked Embed domain is also a rejection of this optional
		// request. Do not retry unrelated client errors, which need to remain
		// visible to the merchant.
		return 400 === $e->getStatusCode() && false !== strpos( $e->getMessage(), 'embedOrigin' );
	}

	/** Return the storefront's origin (scheme + host + optional port only). */
	private function shop_origin(): string {
		$site_url = home_url( '/' );
		$parts    = wp_parse_url( $site_url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $site_url;
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		return $origin;
	}

	/**
	 * Render the WooCommerce order-pay receipt. The script owns its own
	 * alive/ready handshake and navigates to the stored hosted checkout if the
	 * iframe cannot load; the visible link and noscript copy cover script errors
	 * and buyers without JavaScript.
	 */
	public function render_receipt( $order_id ): void {
		$order = wc_get_order( $order_id );
		// The order key is the order-pay capability token. WooCommerce checks it
		// before rendering this hook too; verify it again so this gateway never
		// turns a direct receipt-hook invocation into an information leak.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$checkout_url      = (string) $order->get_meta( '_paybridge_checkout_url', true );
		$session_id        = (string) $order->get_meta( '_paybridge_session_id', true );
		$embedded_permitted = 'yes' === $order->get_meta( '_paybridge_embed_permitted', true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$provider_return   = ! empty( $_GET['paybridge_provider_return'] );
		if ( ! $embedded_permitted || '' === $checkout_url ) {
			$this->redirect_offsite( $checkout_url ?: wc_get_checkout_url() );
			exit;
		}

		// PHP may reach a private/container hostname that the buyer's browser
		// cannot resolve. Keep the public asset origin independently overridable.
		$script_url = rtrim( (string) apply_filters( 'paybridge_wc_browser_api_base', apply_filters( 'paybridge_wc_api_base', 'https://api.paybridgenp.com' ) ), '/' ) . '/js/v1/button.js';
		wp_enqueue_script( 'paybridge-wc-embed', $script_url, [], null, true );
		wp_add_inline_script(
			'paybridge-wc-embed',
			'(function(){var url=' . wp_json_encode( $checkout_url ) . ',popup=' . wp_json_encode( 'pbnp_pay_' . $session_id ) . ',providerReturn=' . ( $provider_return ? 'true' : 'false' ) . ';if(window.name===popup||(providerReturn&&window.opener&&!window.opener.closed)){document.documentElement.style.visibility="hidden";window.close();return;}function open(){return window.PayBridgeNP&&typeof window.PayBridgeNP.openSession==="function"&&window.PayBridgeNP.openSession(url);}var link=document.getElementById("paybridge-wc-continue");if(link){link.addEventListener("click",function(event){if(open()){event.preventDefault();}});}if(!open()){window.location.href=url;}}());',
			'after'
		);

		echo '<p>' . esc_html__( 'Complete your payment securely.', 'paybridgenp-for-woocommerce' ) . ' <a id="paybridge-wc-continue" href="' . esc_url( $checkout_url ) . '">' . esc_html__( 'Open payment', 'paybridgenp-for-woocommerce' ) . '</a></p>';
		echo '<noscript><p><a href="' . esc_url( $checkout_url ) . '">' . esc_html__( 'Continue to payment', 'paybridgenp-for-woocommerce' ) . '</a></p></noscript>';
	}

	// ── Return handler ────────────────────────────────────────────────────────

	/**
	 * Customer is redirected here by PayBridgeNP after payment (success or cancel).
	 * We update the order status then hand off to the WooCommerce thank-you page.
	 *
	 * NOTE: The webhook is the authoritative confirmation. Here we only set the
	 * order to "on-hold" (awaiting confirmation) on success, so customers see a
	 * thank-you page immediately. The webhook moves it to "processing" once the
	 * payment is verified server-to-server.
	 */
	public function handle_return(): void {
		// These parameters are set by PayBridgeNP in the redirect URL — not user-submitted
		// form data — so nonce verification does not apply here.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$order_id   = isset( $_GET['order_id'] )   ? absint( $_GET['order_id'] )                              : 0;
		$order_key  = isset( $_GET['order_key'] )  ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) )  : '';
		$cancelled  = ! empty( $_GET['cancelled'] );
		$status     = isset( $_GET['status'] )     ? sanitize_text_field( wp_unslash( $_GET['status'] ) )     : '';
		$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
		$attempt    = isset( $_GET['paybridge_attempt'] ) ? absint( $_GET['paybridge_attempt'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Already paid (webhook arrived before the customer's browser returned)
		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		// Hosted embedded sessions remain retryable after a provider cancel: the
		// API has already reset the session to its picker. Send the buyer back to
		// the keyed order-pay page so receipt_page() reopens that same session.
		// An explicit cancel_url (`cancelled=1`) keeps the longstanding
		// WooCommerce cancellation behaviour below.
		$stored_session_id = (string) $order->get_meta( '_paybridge_session_id', true );
		$stored_attempt    = (int) $order->get_meta( '_paybridge_session_attempt', true );
		$attempt_is_bound  = 0 !== $attempt || 0 !== $stored_attempt;
		if (
			( $cancelled || '' !== $status ) &&
			(
				( $attempt_is_bound && ( 0 === $attempt || 0 === $stored_attempt || $stored_attempt !== $attempt ) ) ||
				( ! $cancelled && ( '' === $session_id || '' === $stored_session_id || ! hash_equals( $stored_session_id, $session_id ) ) ) ||
				( $cancelled && '' !== $session_id && ( '' === $stored_session_id || ! hash_equals( $stored_session_id, $session_id ) ) )
			)
		) {
			wc_add_notice( __( 'We could not verify the payment return. Please continue from the original checkout.', 'paybridgenp-for-woocommerce' ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url( true ) );
			exit;
		}
		if (
			! $cancelled &&
			'cancelled' === $status &&
			'yes' === $order->get_meta( '_paybridge_embed_permitted', true ) &&
			'' !== $session_id &&
			'' !== $stored_session_id &&
			hash_equals( $stored_session_id, $session_id )
		) {
			$order->add_order_note( __( 'Customer cancelled the provider payment and returned to PayBridgeNP payment methods.', 'paybridgenp-for-woocommerce' ) );
			wp_safe_redirect( add_query_arg( [ 'paybridge_provider_return' => '1' ], $order->get_checkout_payment_url( true ) ) );
			exit;
		}

		if ( $cancelled || 'cancelled' === $status ) {
			$order->update_status(
				'cancelled',
				__( 'Customer cancelled the payment on PayBridgeNP.', 'paybridgenp-for-woocommerce' )
			);
			wc_add_notice( __( 'Payment was cancelled.', 'paybridgenp-for-woocommerce' ), 'notice' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( 'success' === $status ) {
			// Mark on-hold; webhook will confirm and move to processing
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: %s: PayBridgeNP session ID */
					__( 'PayBridgeNP: payment submitted, awaiting server confirmation. Session: %s', 'paybridgenp-for-woocommerce' ),
					$session_id ?: __( 'unknown', 'paybridgenp-for-woocommerce' )
				)
			);
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		// Anything else (status=failed, missing status, etc.)
		$order->update_status(
			'failed',
			__( 'PayBridgeNP: payment was not completed.', 'paybridgenp-for-woocommerce' )
		);
		wc_add_notice( __( 'Payment failed. Please try again.', 'paybridgenp-for-woocommerce' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	// ── Webhook handler ───────────────────────────────────────────────────────

	/**
	 * Receives payment.succeeded / payment.failed / payment.cancelled events
	 * from PayBridgeNP and updates the WooCommerce order accordingly.
	 *
	 * Webhook URL: https://yourstore.com/?wc-api=paybridge_webhook
	 */
	public function handle_webhook(): void {
		$payload = (string) file_get_contents( 'php://input' );
		// PayBridgeNP signs deliveries with the `X-PayBridgeNP-Signature` header
		// (PHP: HTTP_X_PAYBRIDGENP_SIGNATURE). The older `X-PayBridge-Signature`
		// name is read as a fallback for forward/backward compatibility.
		$signature = '';
		if ( isset( $_SERVER['HTTP_X_PAYBRIDGENP_SIGNATURE'] ) ) {
			$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PAYBRIDGENP_SIGNATURE'] ) );
		} elseif ( isset( $_SERVER['HTTP_X_PAYBRIDGE_SIGNATURE'] ) ) {
			$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PAYBRIDGE_SIGNATURE'] ) );
		}

		$webhook_secret = $this->get_option( 'webhook_secret' );

		// Refuse to process webhook events if a signing secret isn't configured.
		// Without HMAC verification, an attacker could forge a payment.succeeded
		// event and get free orders. The merchant must set the webhook signing
		// secret in their settings before PayBridgeNP will move any orders.
		if ( empty( $webhook_secret ) ) {
			wc_get_logger()->error(
				'Webhook received but no signing secret is configured. Configure the "Webhook Signing Secret" field in WooCommerce → Settings → Payments → PayBridgeNP.',
				[ 'source' => 'paybridgenp-for-woocommerce' ]
			);
			status_header( 400 );
			exit( 'Webhook signing secret not configured' );
		}

		// Verify HMAC-SHA256 signature — rejects replays older than 5 minutes
		try {
			$event = PayBridgeNP::webhooks()->constructEvent( $payload, $signature, $webhook_secret );
		} catch ( SignatureVerificationException $e ) {
			status_header( 400 );
			exit( 'Invalid signature' );
		}

		$this->process_webhook_event( $event );

		status_header( 200 );
		exit( 'OK' );
	}

	/**
	 * Apply the webhook event to the matching WooCommerce order.
	 *
	 * @param array<string,mixed> $event
	 */
	private function process_webhook_event( array $event ): void {
		$event_type = $event['type']           ?? '';
		$data       = $event['data']           ?? [];
		$metadata   = $data['metadata']        ?? [];
		$order_id   = isset( $metadata['order_id'] ) ? absint( $metadata['order_id'] ) : 0;

		// Fallback: if the event omits metadata, look up the order by the
		// session_id we persisted at checkout time (_paybridge_session_id).
		if ( ! $order_id ) {
			$session_id = isset( $data['session_id'] ) ? (string) $data['session_id'] : '';
			if ( $session_id !== '' ) {
				$found = wc_get_orders(
					[
						'limit'      => 1,
						'meta_key'   => '_paybridge_session_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => $session_id,             // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						'return'     => 'ids',
					]
				);
				if ( ! empty( $found ) ) {
					$order_id = (int) $found[0];
				}
			}
		}

		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$session_id        = isset( $data['session_id'] ) ? (string) $data['session_id'] : '';
		$stored_session_id = (string) $order->get_meta( '_paybridge_session_id', true );
		if (
			'' === $session_id ||
			'' === $stored_session_id ||
			! hash_equals( $stored_session_id, $session_id )
		) {
			return;
		}

		// Validate order key to prevent cross-order tampering
		$order_key = $metadata['order_key'] ?? '';
		if ( ! empty( $order_key ) && ! hash_equals( $order->get_order_key(), (string) $order_key ) ) {
			return;
		}

		switch ( $event_type ) {
			case 'payment.succeeded':
				if ( ! $order->is_paid() ) {
					$provider  = isset( $data['provider'] )     ? strtoupper( (string) $data['provider'] )     : '';
					$pay_id    = isset( $data['id'] )            ? (string) $data['id']                         : '';
					$prov_ref  = isset( $data['provider_ref'] )  ? (string) $data['provider_ref']               : '';
					$amount_nr = isset( $data['amount'] )
						? number_format( (float) $data['amount'] / 100, 2 )
						: '';

					// payment_complete() sets status to processing and records transaction ID
					$order->payment_complete( $pay_id );
					$order->add_order_note(
						sprintf(
							/* translators: 1: provider, 2: provider ref, 3: amount */
							__( 'PayBridgeNP payment confirmed. Provider: %1$s | Ref: %2$s | Amount: NPR %3$s', 'paybridgenp-for-woocommerce' ),
							$provider,
							$prov_ref,
							$amount_nr
						)
					);
				}
				break;

			case 'payment.failed':
			case 'payment.cancelled':
				if ( ! $order->is_paid() ) {
					$reason = isset( $data['reason'] ) ? (string) $data['reason'] : '';
					$order->update_status(
						'failed',
						$reason
							? sprintf(
								/* translators: %s: failure reason */
								__( 'PayBridgeNP payment failed: %s', 'paybridgenp-for-woocommerce' ),
								$reason
							)
							: __( 'PayBridgeNP payment failed.', 'paybridgenp-for-woocommerce' )
					);
				}
				break;
		}
	}
}
