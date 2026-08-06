/* global PGV, jQuery */
( function ( $ ) {
	'use strict';

	// A szerveroldali PGV_Corporate regexeit tükrözi.
	var RE_CORP = /\b(kft|bt|zrt|nyrt|kkt|e\.?\s?v\.?)\b/i;
	var RE_TAX = /\d{8}[-\s]?\d?[-\s]?\d{0,2}/;

	function looksCorporate( text ) {
		if ( ! text ) {
			return false;
		}
		return RE_CORP.test( text ) || RE_TAX.test( text );
	}

	$( function () {
		// --- Kézbesítés: e-mail mező mutatása/rejtése ---
		var $wrap = $( '[data-pgv]' );

		function syncDelivery() {
			var val = $( 'input[name="pgv_delivery"]:checked', $wrap ).val();
			var $email = $( '.pgv-field-delivery-email', $wrap );
			var $input = $( '#pgv_delivery_email', $wrap );
			if ( 'recipient' === val ) {
				$email.show();
				$input.attr( 'required', 'required' );
			} else {
				$email.hide();
				$input.removeAttr( 'required' );
			}
		}

		if ( $wrap.length ) {
			syncDelivery();
			$wrap.on( 'change', 'input[name="pgv_delivery"]', syncDelivery );
		}

		// --- Cégnév / adószám élő figyelmeztetés ---
		if ( ! PGV || ! PGV.corporateWarn ) {
			return;
		}

		var $warn = null;

		function ensureWarn() {
			if ( $warn && $warn.length ) {
				return $warn;
			}
			$warn = $( '<div class="pgv-corp-warn" role="alert" style="display:none"></div>' ).text( PGV.corporateMessage );
			if ( $wrap.length ) {
				$wrap.append( $warn );
			} else {
				$( 'form.checkout, form.cart' ).first().append( $warn );
			}
			return $warn;
		}

		function collectText() {
			var parts = [];
			[ '#pgv_recipient', '#billing_company', '#billing_first_name', '#billing_last_name' ].forEach( function ( sel ) {
				var $el = $( sel );
				if ( $el.length ) {
					parts.push( $el.val() || '' );
				}
			} );
			return parts.join( ' ' );
		}

		function checkCorporate() {
			var w = ensureWarn();
			if ( looksCorporate( collectText() ) ) {
				w.slideDown( 120 );
			} else {
				w.slideUp( 120 );
			}
		}

		$( document ).on(
			'input change',
			'#pgv_recipient, #billing_company, #billing_first_name, #billing_last_name',
			checkCorporate
		);
		checkCorporate();
	} );
} )( jQuery );
