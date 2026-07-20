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
				'description' => __( 'Single button shows one PayBridgeNP option at checkout and uses the hosted picker. Provider tiles show eSewa, Khalti, and Fonepay inline and redirect customers straight to the chosen provider.', 'paybridgenp-for-woocommerce' ),
				'default'     => 'single_button',
				'options'     => [
					'single_button'  => __( 'Single button (hosted picker)', 'paybridgenp-for-woocommerce' ),
					'provider_tiles' => __( 'Provider tiles (direct redirect)', 'paybridgenp-for-woocommerce' ),
				],
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

		// When provider tiles are enabled, the customer has already picked the
		// provider on the checkout form. Pass it to the session so PayBridgeNP
		// skips its own picker and 302s straight to that provider's flow.
		$chosen_provider = null;
		if ( 'provider_tiles' === $this->display_style ) {
			// WooCommerce verifies the checkout nonce on its end before invoking
			// process_payment(), so we don't need a second nonce here.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = isset( $_POST['paybridge_wc_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['paybridge_wc_provider'] ) ) : '';
			$valid_ids = wp_list_pluck( self::providers(), 'id' );
			if ( ! in_array( $raw, $valid_ids, true ) ) {
				wc_add_notice( __( 'Please pick a payment provider.', 'paybridgenp-for-woocommerce' ), 'error' );
				return [ 'result' => 'failure' ];
			}
			$chosen_provider = $raw;
		}

		// Return URL: our intermediary endpoint so we can update the order first
		$return_url = add_query_arg(
			[
				'wc-api'    => 'paybridge_return',
				'order_id'  => $order->get_id(),
				'order_key' => $order->get_order_key(),
			],
			home_url( '/' )
		);

		// Cancel URL: same endpoint with cancelled flag — lands back at checkout
		$cancel_url = add_query_arg(
			[
				'wc-api'    => 'paybridge_return',
				'order_id'  => $order->get_id(),
				'order_key' => $order->get_order_key(),
				'cancelled' => '1',
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

		$session_payload = [
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
		if ( null !== $chosen_provider ) {
			$session_payload['flow']     = 'redirect';
			$session_payload['provider'] = $chosen_provider;
		}

		try {
			$pb      = new PayBridgeNP( [ 'api_key' => $secret_key ] );
			$session = $pb->checkout->create( $session_payload );
		} catch ( PayBridgeException $e ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: error message from PayBridgeNP API */
					__( 'Payment error: %s', 'paybridgenp-for-woocommerce' ),
					$e->getMessage()
				),
				'error'
			);
			return [ 'result' => 'failure' ];
		}

		// Persist session ID on the order for traceability
		$order->update_meta_data( '_paybridge_session_id', $session['id'] );
		if ( null !== $chosen_provider ) {
			$order->update_meta_data( '_paybridge_provider', $chosen_provider );
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

		return [
			'result'   => 'success',
			'redirect' => $session['checkout_url'],
		];
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

		if ( $cancelled ) {
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
