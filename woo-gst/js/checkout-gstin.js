jQuery( function ( $ ) {
	/**
	 * Classic checkout (shortcode).
	 */
	function toggleClassicGstinField() {
		var $checkbox = $( '#woo_gst_has_gstin_number' );

		if ( ! $checkbox.length ) {
			return;
		}

		var $wrapper  = $( '#woo_gst_gstin_wrapper' );
		var $gstinRow = $( '#woo_gst_gstin_number_field' );

		if ( $checkbox.is( ':checked' ) ) {
			$wrapper.removeAttr( 'hidden' ).css( 'display', '' );
			$gstinRow.removeAttr( 'hidden' ).css( 'display', '' );
			$( '#has_gstin_number' ).addClass( 'woo-gst-show-gstin' );
			$gstinRow.addClass( 'validate-required' );
			$( '#woo_gst_gstin_number' ).attr( 'aria-required', 'true' );
		} else {
			$wrapper.attr( 'hidden', 'hidden' ).hide();
			$gstinRow.attr( 'hidden', 'hidden' ).hide();
			$( '#has_gstin_number' ).removeClass( 'woo-gst-show-gstin' );
			$gstinRow.removeClass( 'validate-required woocommerce-invalid' );
			$( '#woo_gst_gstin_number' ).val( '' ).removeAttr( 'aria-required' );
		}
	}

	/**
	 * Checkout block (contact fields).
	 */
	function toggleBlockGstinField() {
		var $checkbox = $(
			'#contact-woo-gst-has-gstin-number, input[name="contact_woo-gst/has-gstin-number"]'
		);

		if ( ! $checkbox.length ) {
			return;
		}

		var $gstinWrap = $(
			'.wc-block-components-address-form__woo-gst-customer-gstin, #contact-woo-gst-customer-gstin'
		).closest( '.wc-block-components-text-input' );

		if ( ! $gstinWrap.length ) {
			$gstinWrap = $( '.wc-block-components-address-form__woo-gst-customer-gstin' );
		}

		var $checkout = $( '.wc-block-checkout, .wp-block-woocommerce-checkout' );

		if ( $checkbox.is( ':checked' ) ) {
			$checkout.addClass( 'woo-gst-show-gstin' );
			$gstinWrap.removeAttr( 'hidden' ).css( 'display', '' );
		} else {
			$checkout.removeClass( 'woo-gst-show-gstin' );
			$gstinWrap.attr( 'hidden', 'hidden' ).hide();
			$( '#contact-woo-gst-customer-gstin' ).val( '' );
		}
	}

	function toggleAllGstinFields() {
		toggleClassicGstinField();
		toggleBlockGstinField();
	}

	function initBlockGstinObserver() {
		var checkout = document.querySelector( '.wc-block-checkout, .wp-block-woocommerce-checkout' );

		if ( ! checkout || checkout.dataset.wooGstObserver ) {
			return;
		}

		checkout.dataset.wooGstObserver = '1';

		var observer = new MutationObserver( function () {
			toggleBlockGstinField();
		} );

		observer.observe( checkout, { childList: true, subtree: true } );
	}

	toggleAllGstinFields();
	initBlockGstinObserver();

	$( document.body ).on(
		'change',
		'#woo_gst_has_gstin_number, #contact-woo-gst-has-gstin-number, input[name="contact_woo-gst/has-gstin-number"]',
		toggleAllGstinFields
	);

	$( document.body ).on( 'updated_checkout', toggleAllGstinFields );

	// Block checkout may render after DOM ready.
	setTimeout( toggleAllGstinFields, 300 );
	setTimeout( toggleAllGstinFields, 1000 );
} );
