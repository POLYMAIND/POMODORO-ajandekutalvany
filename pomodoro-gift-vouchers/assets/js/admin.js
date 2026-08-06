/* global PGVAdmin, jQuery, wp */
( function ( $ ) {
	'use strict';

	$( function () {
		// --- Médiatár kép-választó ---
		var frame;
		$( '#pgv-pick-image' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( frame ) {
				frame.open();
				return;
			}
			frame = wp.media( {
				title: PGVAdmin.i18n.pickImage,
				button: { text: PGVAdmin.i18n.pickImage },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				$( '#pgv_attachment_id' ).val( att.id );
				var url = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
				$( '#pgv-picked-preview' ).html( '<img src="' + url + '" style="max-width:80px;height:auto;vertical-align:middle;margin-left:8px;border-radius:4px">' );
				$( '#pgv-add-image-submit' ).prop( 'disabled', false );
			} );
			frame.open();
		} );

		// --- E-mail újraküldés ---
		$( document ).on( 'click', '.pgv-resend', function () {
			var $btn = $( this );
			var id = $btn.data( 'id' );
			$btn.prop( 'disabled', true );
			$.post( PGVAdmin.ajaxUrl, { action: 'pgv_resend', nonce: PGVAdmin.nonce, id: id } )
				.done( function ( res ) {
					window.alert( res && res.data ? res.data.message : 'OK' );
				} )
				.fail( function () {
					window.alert( 'Hiba' );
				} )
				.always( function () {
					$btn.prop( 'disabled', false );
				} );
		} );

		// --- Audit előzmény toggle ---
		$( document ).on( 'click', '.pgv-toggle-audit', function () {
			$( '#pgv-audit-' + $( this ).data( 'id' ) ).toggle();
		} );

		// --- Kassza: keresés ---
		function cashierLookup() {
			var needle = $( '#pgv-cashier-input' ).val().trim();
			if ( ! needle ) {
				return;
			}
			var $res = $( '#pgv-cashier-result' );
			var $msg = $( '#pgv-cashier-msg' );
			$msg.text( '' ).removeClass( 'pgv-ok pgv-err' );

			$.post( PGVAdmin.ajaxUrl, { action: 'pgv_lookup', nonce: PGVAdmin.nonce, needle: needle } )
				.done( function ( res ) {
					if ( ! res.success ) {
						$res.hide();
						$msg.text( res.data.message ).addClass( 'pgv-err' );
						return;
					}
					var d = res.data;
					$res.show();
					$res.find( '[data-field="serial"]' ).text( d.serial );
					$res.find( '[data-field="amount"]' ).text( d.amount );
					$res.find( '[data-field="recipient"]' ).text( d.recipient || '—' );
					$res.find( '[data-field="valid_until"]' ).text( d.valid_until || '—' );
					$res.find( '[data-field="status"]' ).text( d.status ).attr( 'class', 'pgv-v pgv-status-badge pgv-status-' + d.status_key );

					var $redeem = $( '#pgv-cashier-redeem' );
					$redeem.data( 'id', d.id );
					if ( d.redeemable ) {
						$redeem.prop( 'disabled', false ).show();
					} else {
						$redeem.prop( 'disabled', true ).hide();
						$msg.text( 'Ez az utalvány nem beváltható (' + d.status + ').' ).addClass( 'pgv-err' );
					}
				} )
				.fail( function () {
					$msg.text( 'Hiba a keresés során.' ).addClass( 'pgv-err' );
				} );
		}

		$( '#pgv-cashier-lookup' ).on( 'click', cashierLookup );
		$( '#pgv-cashier-input' ).on( 'keydown', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault();
				cashierLookup();
			}
		} );

		// --- Kassza: beváltás ---
		$( '#pgv-cashier-redeem' ).on( 'click', function () {
			var id = $( this ).data( 'id' );
			if ( ! id || ! window.confirm( PGVAdmin.i18n.confirmRedeem ) ) {
				return;
			}
			var $btn = $( this );
			var $msg = $( '#pgv-cashier-msg' );
			$btn.prop( 'disabled', true );
			$.post( PGVAdmin.ajaxUrl, { action: 'pgv_redeem', nonce: PGVAdmin.nonce, id: id } )
				.done( function ( res ) {
					if ( res.success ) {
						$msg.text( res.data.message + ' (' + res.data.status + ')' ).removeClass( 'pgv-err' ).addClass( 'pgv-ok' );
						$( '#pgv-cashier-result [data-field="status"]' ).text( res.data.status );
						$btn.hide();
					} else {
						$msg.text( res.data.message ).addClass( 'pgv-err' );
						$btn.prop( 'disabled', false );
					}
				} )
				.fail( function () {
					$msg.text( 'Hiba a beváltás során.' ).addClass( 'pgv-err' );
					$btn.prop( 'disabled', false );
				} );
		} );
	} );
} )( jQuery );
