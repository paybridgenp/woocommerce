<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use PayBridgeNP\PayBridge;
use PayBridgeNP\Exceptions\PayBridgeException;
use PayBridgeNP\Exceptions\SignatureVerificationException;

/**
 * PayBridge NP WooCommerce Payment Gateway
 *
 * Flow:
 *   1. process_payment()  — creates a PayBridge checkout session, redirects customer
 *   2. handle_return()    — customer lands back after payment; sets order to on-hold
 *                           and redirects to the WooCommerce thank-you page
 *   3. handle_webhook()   — definitive confirmation; marks order processing/failed
 *
 * Both (2) and (3) are registered as WooCommerce API endpoints so they work even
 * when pretty-permalinks are disabled.
 */
class WC_Gateway_PayBridge extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'paybridge_np';
		$this->method_title       = 'PayBridge NP';
		$this->method_description = __( 'Accept payments via eSewa, Khalti, and more. Powered by PayBridge NP.', 'paybridge-np-woocommerce' );
		$this->icon               = apply_filters(
			'woocommerce_paybridge_np_icon',
			PAYBRIDGE_WC_URL . 'assets/icon.svg'
		);
		$this->has_fields         = false;
		$this->supports           = [ 'products' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// Save settings
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			[ $this, 'process_admin_options' ]
		);

		// WooCommerce API endpoints (work with any permalink structure)
		add_action( 'woocommerce_api_paybridge_return',  [ $this, 'handle_return' ] );
		add_action( 'woocommerce_api_paybridge_webhook', [ $this, 'handle_webhook' ] );
	}

	// ── Admin settings ────────────────────────────────────────────────────────

	public function init_form_fields(): void {
		$webhook_url = home_url( '/?wc-api=paybridge_webhook' );

		$this->form_fields = [
			'enabled'        => [
				'title'   => __( 'Enable/Disable', 'paybridge-np-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PayBridge NP', 'paybridge-np-woocommerce' ),
				'default' => 'no',
			],
			'title'          => [
				'title'       => __( 'Title', 'paybridge-np-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to customers at checkout.', 'paybridge-np-woocommerce' ),
				'default'     => 'PayBridge NP',
				'desc_tip'    => true,
			],
			'description'    => [
				'title'   => __( 'Description', 'paybridge-np-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely with eSewa, Khalti, and more.', 'paybridge-np-woocommerce' ),
			],
			'secret_key'     => [
				'title'       => __( 'Secret Key', 'paybridge-np-woocommerce' ),
				'type'        => 'password',
				/* translators: example key prefixes */
				'description' => __( 'Your PayBridge NP secret key (starts with sk_live_ or sk_test_).', 'paybridge-np-woocommerce' ),
				'desc_tip'    => true,
			],
			'webhook_secret' => [
				'title'       => __( 'Webhook Signing Secret', 'paybridge-np-woocommerce' ),
				'type'        => 'password',
				/* translators: %s: webhook listener URL */
				'description' => sprintf(
					__( 'Signing secret (whsec_…) from your PayBridge NP dashboard. Set your webhook endpoint URL to: %s', 'paybridge-np-woocommerce' ),
					'<br><code>' . esc_html( $webhook_url ) . '</code>'
				),
			],
		];
	}

	public function payment_fields(): void {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
	}

	// ── Checkout ──────────────────────────────────────────────────────────────

	/**
	 * Called when the customer confirms their order. Creates a PayBridge checkout
	 * session and redirects the customer to the hosted payment page.
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'paybridge-np-woocommerce' ), 'error' );
			return [ 'result' => 'failure' ];
		}

		$secret_key = $this->get_option( 'secret_key' );
		if ( empty( $secret_key ) ) {
			wc_add_notice(
				__( 'PayBridge NP is not configured. Please contact the store owner.', 'paybridge-np-woocommerce' ),
				'error'
			);
			return [ 'result' => 'failure' ];
		}

		// WooCommerce stores totals in the store currency (must be NPR).
		// Amount in paisa = total × 100 (rounded to avoid floating-point drift).
		$amount_paisa = (int) round( (float) $order->get_total() * 100 );

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

		try {
			$pb      = new PayBridge( [ 'api_key' => $secret_key ] );
			$session = $pb->checkout->create(
				[
					'amount'     => $amount_paisa,
					'currency'   => 'NPR',
					'return_url' => $return_url,
					'cancel_url' => $cancel_url,
					'metadata'   => [
						'order_id'  => (string) $order->get_id(),
						'order_key' => $order->get_order_key(),
						'source'    => 'woocommerce',
					],
				]
			);
		} catch ( PayBridgeException $e ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: error message from PayBridge API */
					__( 'Payment error: %s', 'paybridge-np-woocommerce' ),
					$e->getMessage()
				),
				'error'
			);
			return [ 'result' => 'failure' ];
		}

		// Persist session ID on the order for traceability
		$order->update_meta_data( '_paybridge_session_id', $session['id'] );
		$order->add_order_note(
			sprintf(
				/* translators: %s: PayBridge checkout session ID */
				__( 'PayBridge NP checkout session created: %s', 'paybridge-np-woocommerce' ),
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
	 * Customer is redirected here by PayBridge NP after payment (success or cancel).
	 * We update the order status then hand off to the WooCommerce thank-you page.
	 *
	 * NOTE: The webhook is the authoritative confirmation. Here we only set the
	 * order to "on-hold" (awaiting confirmation) on success, so customers see a
	 * thank-you page immediately. The webhook moves it to "processing" once the
	 * payment is verified server-to-server.
	 */
	public function handle_return(): void {
		$order_id   = isset( $_GET['order_id'] )   ? absint( $_GET['order_id'] )                              : 0;
		$order_key  = isset( $_GET['order_key'] )  ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) )  : '';
		$cancelled  = ! empty( $_GET['cancelled'] );
		$status     = isset( $_GET['status'] )     ? sanitize_text_field( wp_unslash( $_GET['status'] ) )     : '';
		$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';

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
				__( 'Customer cancelled the payment on PayBridge NP.', 'paybridge-np-woocommerce' )
			);
			wc_add_notice( __( 'Payment was cancelled.', 'paybridge-np-woocommerce' ), 'notice' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( 'success' === $status ) {
			// Mark on-hold; webhook will confirm and move to processing
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: %s: PayBridge session ID */
					__( 'PayBridge NP: payment submitted, awaiting server confirmation. Session: %s', 'paybridge-np-woocommerce' ),
					$session_id ?: __( 'unknown', 'paybridge-np-woocommerce' )
				)
			);
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		// Anything else (status=failed, missing status, etc.)
		$order->update_status(
			'failed',
			__( 'PayBridge NP: payment was not completed.', 'paybridge-np-woocommerce' )
		);
		wc_add_notice( __( 'Payment failed. Please try again.', 'paybridge-np-woocommerce' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	// ── Webhook handler ───────────────────────────────────────────────────────

	/**
	 * Receives payment.succeeded / payment.failed / payment.cancelled events
	 * from PayBridge NP and updates the WooCommerce order accordingly.
	 *
	 * Webhook URL: https://yourstore.com/?wc-api=paybridge_webhook
	 */
	public function handle_webhook(): void {
		$payload   = (string) file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_X_PAYBRIDGE_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PAYBRIDGE_SIGNATURE'] ) )
			: '';

		$webhook_secret = $this->get_option( 'webhook_secret' );

		// Refuse to process webhook events if a signing secret isn't configured.
		// Without HMAC verification, an attacker could forge a payment.succeeded
		// event and get free orders. The merchant must set the webhook signing
		// secret in their settings before PayBridge will move any orders.
		if ( empty( $webhook_secret ) ) {
			error_log( '[PayBridge NP] Webhook received but no signing secret is configured. Configure the "Webhook Signing Secret" field in WooCommerce → Settings → Payments → PayBridge NP.' );
			status_header( 400 );
			exit( 'Webhook signing secret not configured' );
		}

		// Verify HMAC-SHA256 signature — rejects replays older than 5 minutes
		try {
			$event = PayBridge::webhooks()->constructEvent( $payload, $signature, $webhook_secret );
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
						'meta_key'   => '_paybridge_session_id',
						'meta_value' => $session_id,
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
							__( 'PayBridge NP payment confirmed. Provider: %1$s | Ref: %2$s | Amount: NPR %3$s', 'paybridge-np-woocommerce' ),
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
								__( 'PayBridge NP payment failed: %s', 'paybridge-np-woocommerce' ),
								$reason
							)
							: __( 'PayBridge NP payment failed.', 'paybridge-np-woocommerce' )
					);
				}
				break;
		}
	}
}
