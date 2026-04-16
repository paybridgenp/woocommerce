( function () {
	const { registerPaymentMethod } = wc.wcBlocksRegistry;
	const { getSetting }            = wc.wcSettings;
	const { createElement }         = wp.element;

	const settings    = getSetting( 'paybridge_np_data', {} );
	const label       = settings.title       || 'PayBridge NP';
	const description = settings.description || '';

	const Content = function () {
		return description ? createElement( 'p', null, description ) : null;
	};

	const Label = function () {
		return createElement( 'span', null, label );
	};

	registerPaymentMethod( {
		name:          'paybridge_np',
		label:         createElement( Label, null ),
		content:       createElement( Content, null ),
		edit:          createElement( Content, null ),
		canMakePayment: function () { return true; },
		ariaLabel:     label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
