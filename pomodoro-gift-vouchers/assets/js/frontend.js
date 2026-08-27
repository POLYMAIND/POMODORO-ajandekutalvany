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

	// ------------------------------------------------------------------
	// Élő előnézet: a kiküldendő PDF-kártya mása, sorszám nélkül.
	// A szövegtördelés a PGV_PDF::wrap() pontos mása, hogy a vásárló azt
	// lássa, ami valóban ráfér az utalványra.
	// ------------------------------------------------------------------
	function wrapText( text, maxChars ) {
		var words = String( text || '' ).replace( /\s+/g, ' ' ).trim().split( ' ' );
		var lines = [];
		var cur = '';
		words.forEach( function ( w ) {
			if ( '' === w ) {
				return;
			}
			var tryLine = '' === cur ? w : cur + ' ' + w;
			if ( tryLine.length > maxChars ) {
				if ( '' !== cur ) {
					lines.push( cur );
				}
				cur = w;
			} else {
				cur = tryLine;
			}
		} );
		if ( '' !== cur ) {
			lines.push( cur );
		}
		return lines;
	}

	function initPreview() {
		var cfg = ( window.PGV && PGV.preview ) || null;
		var $box = $( '[data-pgv-preview]' );
		if ( ! cfg || ! $box.length ) {
			return;
		}
		var $card = $box.find( '[data-pgv-card]' );
		var $img = $box.find( '[data-pgv-img]' );
		var $amount = $box.find( '[data-pgv-amount]' );
		var $greeting = $box.find( '[data-pgv-greeting]' );
		var $message = $box.find( '[data-pgv-message]' );
		var $tooLong = $box.find( '[data-pgv-toolong]' );
		var price = null; // változó termékeknél a kiválasztott variáció ára

		// A kártya belső méretei em-ben vannak: 1em = a szélesség 1%-a,
		// így bármilyen széles oszlopban a PDF arányait tartja.
		function scale() {
			var w = $card.outerWidth();
			if ( w ) {
				$card.css( 'font-size', ( w / 100 ) + 'px' );
			}
		}

		function digitsOf( txt ) {
			// Az ezres elválasztókat eldobjuk, a tizedeseket levágjuk (Ft-nál nincs).
			var m = String( txt || '' ).replace( /[^\d]/g, '' );
			return m ? parseInt( m, 10 ) : null;
		}

		function currentPrice() {
			if ( null !== price ) {
				return price;
			}
			var $price = $( '.summary .price, .product .price' ).first();
			if ( ! $price.length ) {
				return null;
			}
			// Akciós árnál a .price a régi ÉS az új árat is tartalmazza (<del> + <ins>),
			// ezért az érvényes (ins), majd az első ár-elem szövegét nézzük, és csak
			// legvégső esetben a teljes szöveget.
			var $ins = $price.find( 'ins' ).first();
			var $amount = ( $ins.length ? $ins : $price ).find( '.woocommerce-Price-amount, bdi' ).first();
			if ( $amount.length ) {
				return digitsOf( $amount.text() );
			}
			if ( $ins.length ) {
				return digitsOf( $ins.text() );
			}
			return digitsOf( $price.clone().find( 'del' ).remove().end().text() );
		}

		function formatAmount( n ) {
			if ( ! n ) {
				return '';
			}
			return String( n ).replace( /\B(?=(\d{3})+(?!\d))/g, ' ' ) + ' Ft';
		}

		function render() {
			// Kép
			var id = $( 'input[name="pgv_image_id"]:checked' ).val();
			var url = ( id && cfg.images && cfg.images[ id ] ) || '';
			if ( url ) {
				$img.attr( 'src', url ).show();
			} else {
				$img.removeAttr( 'src' ).hide();
			}

			// Összeg
			$amount.text( formatAmount( currentPrice() ) );

			// Megajándékozott
			var name = ( $( '#pgv_recipient' ).val() || '' ).trim();
			if ( name ) {
				$greeting.text( cfg.greeting.replace( '%s', name ) ).prop( 'hidden', false );
			} else {
				$greeting.prop( 'hidden', true );
			}

			// Üzenet — ugyanazzal a tördeléssel és sorkorláttal, mint a PDF
			var lines = wrapText( $( '#pgv_message' ).val(), cfg.wrapChars );
			var shown = lines.slice( 0, cfg.maxLines );
			if ( shown.length ) {
				$message.text( shown.join( '\n' ) ).prop( 'hidden', false );
			} else {
				$message.prop( 'hidden', true );
			}
			$tooLong.prop( 'hidden', lines.length <= cfg.maxLines );

			scale();
		}

		$box.prop( 'hidden', false );

		var $wrap = $( '[data-pgv]' );
		$wrap.on( 'change', 'input[name="pgv_image_id"]', render );
		$( document ).on( 'input change', '#pgv_recipient, #pgv_message', render );

		// Változó termék: a kiválasztott variáció ára.
		$( document.body ).on( 'found_variation', function ( e, variation ) {
			if ( variation && variation.display_price ) {
				price = Math.round( variation.display_price );
				render();
			}
		} );
		$( document.body ).on( 'reset_data', function () {
			price = null;
			render();
		} );

		if ( window.ResizeObserver ) {
			new ResizeObserver( scale ).observe( $card.get( 0 ) );
		} else {
			$( window ).on( 'resize', scale );
		}
		$img.on( 'load', scale );

		render();
	}

	$( function () {
		initPreview();

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
