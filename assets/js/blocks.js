/**
 * Lunixi — WooCommerce Cart/Checkout Blocks integration.
 *
 * The card form (incl. 3-D Secure) is rendered by the gateway-returned bootstrap
 * on the order-pay page — the same model as the classic checkout. So in Blocks we
 * register a redirect-based payment method: it contributes a label + description,
 * and on "Place order" WooCommerce calls the server `process_payment`, which
 * returns the order-pay redirect that Blocks follows automatically.
 *
 * No build step / JSX: uses the globals WordPress exposes (wp.element, wp.i18n,
 * wp.htmlEntities, wc.wcBlocksRegistry, wc.wcSettings).
 */
(function () {
	'use strict';

	if (
		typeof window.wc === 'undefined' ||
		!window.wc.wcBlocksRegistry ||
		!window.wc.wcSettings ||
		!window.wp ||
		!window.wp.element
	) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var createElement = window.wp.element.createElement;
	var decodeEntities = ( window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ) || function ( s ) { return s; };
	var __ = ( window.wp.i18n && window.wp.i18n.__ ) || function ( s ) { return s; };

	var data = getSetting( 'lunixi_data', {} );
	var title = decodeEntities( data.title || __( 'Credit / Debit Card', 'lunixi' ) );
	var description = decodeEntities( data.description || '' );

	var Label = function () {
		return createElement( 'span', { className: 'lunixi-blocks-label' }, title );
	};

	var Content = function () {
		return description
			? createElement( 'p', { className: 'lunixi-blocks-description' }, description )
			: null;
	};

	registerPaymentMethod( {
		name: 'lunixi',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		ariaLabel: title,
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: ( data.supports && data.supports.length ) ? data.supports : [ 'products', 'refunds' ],
		},
	} );
})();
