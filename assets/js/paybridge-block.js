( function () {
	const { registerPaymentMethod } = wc.wcBlocksRegistry;
	const { getSetting }            = wc.wcSettings;
	const { createElement, useState, useEffect } = wp.element;
	const { __ }                    = wp.i18n;

	const settings    = getSetting( 'paybridge_np_data', {} );
	const label       = settings.title       || 'PayBridgeNP';
	const description = settings.description || '';
	const isTiles     = settings.displayStyle === 'provider_tiles';
	const providers   = Array.isArray( settings.providers ) ? settings.providers : [];

	const Label = function () {
		return createElement( 'span', null, label );
	};

	const Content = function ( props ) {
		const { eventRegistration, emitResponse } = props || {};
		const onPaymentSetup = eventRegistration && eventRegistration.onPaymentSetup;

		const defaultId = isTiles && providers[ 0 ] ? providers[ 0 ].id : '';
		const [ chosen, setChosen ] = useState( defaultId );

		useEffect( function () {
			if ( ! onPaymentSetup ) {
				return;
			}
			const unsubscribe = onPaymentSetup( function () {
				if ( isTiles && ! chosen ) {
					return {
						type:    emitResponse.responseTypes.ERROR,
						message: __( 'Please pick a payment provider.', 'paybridgenp-for-woocommerce' ),
					};
				}
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: { paybridge_wc_provider: chosen },
					},
				};
			} );
			return unsubscribe;
		}, [ onPaymentSetup, chosen, emitResponse ] );

		const children = [];
		if ( description ) {
			children.push( createElement( 'p', { key: 'desc' }, description ) );
		}
		if ( isTiles && providers.length ) {
			children.push(
				createElement(
					'fieldset',
					{
						key: 'tiles',
						className: 'paybridge-wc-tiles',
						'aria-label': __( 'Choose a payment provider', 'paybridgenp-for-woocommerce' ),
					},
					providers.map( function ( p ) {
						const isActive = chosen === p.id;
						return createElement(
							'label',
							{
								key: p.id,
								className: 'paybridge-wc-tile' + ( isActive ? ' is-active' : '' ),
							},
							createElement( 'input', {
								type:     'radio',
								name:     'paybridge_wc_provider',
								value:    p.id,
								checked:  isActive,
								onChange: function () { setChosen( p.id ); },
							} ),
							createElement( 'img', {
								src:    p.logoUrl,
								alt:    '',
								width:  48,
								height: 48,
							} ),
							createElement( 'span', { className: 'paybridge-wc-tile__name' }, p.name )
						);
					} )
				)
			);
		}
		return children.length ? createElement( 'div', null, children ) : null;
	};

	registerPaymentMethod( {
		name:           'paybridge_np',
		label:          createElement( Label, null ),
		content:        createElement( Content, null ),
		edit:           createElement( Content, null ),
		canMakePayment: function () { return true; },
		ariaLabel:      label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
