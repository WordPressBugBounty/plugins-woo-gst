jQuery( function ( $ ) {
	var config = window.wooGstCheckout || {};
	var isBlockCheckout = !! config.isBlockCheckout;

	/**
	 * Classic checkout — toggle visibility via body class only (no .hide()).
	 * Avoids conflicts with WooCommerce Checkout Manager conditional logic.
	 */
	function toggleClassicGstinField() {
		var $checkbox = $( '#woo_gst_has_gstin_number' );

		if ( ! $checkbox.length ) {
			return;
		}

		var $gstinRow = $( '#woo_gst_gstin_number_field' );

		if ( $checkbox.is( ':checked' ) ) {
			$( 'body' ).addClass( 'woo-gst-gstin-visible' );
			$gstinRow.addClass( 'validate-required' );
			$( '#woo_gst_gstin_number' ).attr( 'aria-required', 'true' );
		} else {
			$( 'body' ).removeClass( 'woo-gst-gstin-visible' );
			$gstinRow.removeClass( 'validate-required woocommerce-invalid' );
			$( '#woo_gst_gstin_number' ).val( '' ).removeAttr( 'aria-required' );
		}
	}

	/**
	 * Block checkout — toggle via CSS class on the checkout container only.
	 */
	function toggleBlockGstinField() {
		var $checkbox = $(
			'#contact-woo-gst-has-gstin-number, input[name="contact_woo-gst/has-gstin-number"]'
		);

		if ( ! $checkbox.length ) {
			return;
		}

		var $checkout = $( '.wc-block-checkout, .wp-block-woocommerce-checkout' );

		if ( $checkbox.is( ':checked' ) ) {
			$checkout.addClass( 'woo-gst-show-gstin' );
		} else {
			$checkout.removeClass( 'woo-gst-show-gstin' );
			$( '#contact-woo-gst-customer-gstin' ).val( '' );
		}
	}

	function toggleGstinFields() {
		if ( isBlockCheckout ) {
			toggleBlockGstinField();
		} else {
			toggleClassicGstinField();
		}
	}

	toggleGstinFields();

	$( document.body ).on(
		'change',
		'#woo_gst_has_gstin_number, #contact-woo-gst-has-gstin-number, input[name="contact_woo-gst/has-gstin-number"]',
		toggleGstinFields
	);

	if ( ! isBlockCheckout ) {
		$( document.body ).on( 'updated_checkout', toggleClassicGstinField );
	} else {
		// Block checkout may render fields after DOM ready.
		setTimeout( toggleBlockGstinField, 300 );
		setTimeout( toggleBlockGstinField, 1000 );
	}
} );
