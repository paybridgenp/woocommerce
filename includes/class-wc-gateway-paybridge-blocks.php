<?php

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class PayBridgeNP_Gateway_Blocks extends AbstractPaymentMethodType {

	protected $name = 'paybridge_np';

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_paybridge_np_settings', [] );
	}

	public function is_active(): bool {
		return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
	}

	public function get_payment_method_script_handles(): array {
		wp_register_script(
			'paybridge-np-blocks',
			PAYBRIDGE_WC_URL . 'assets/js/paybridge-block.js',
			[ 'wc-blocks-registry', 'wc-settings', 'wp-element' ],
			PAYBRIDGE_WC_VERSION,
			true
		);

		return [ 'paybridge-np-blocks' ];
	}

	public function get_payment_method_data(): array {
		return [
			'title'       => $this->get_setting( 'title', 'PayBridge NP' ),
			'description' => $this->get_setting( 'description', '' ),
			'supports'    => $this->get_supported_features(),
		];
	}
}
