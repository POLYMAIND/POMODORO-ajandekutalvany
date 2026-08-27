<?php
/**
 * Push a központi vezérlőpultba: az utalványokat kimenő HTTP-hívással küldjük fel a
 * Neon DB-t tápláló /api/ingest végpontnak. Kimenő kérés → a tárhely bot-védelme
 * NEM fogja meg (szemben a bejövő lekérdezéssel), és valós idejű a szinkron.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Push {

	public function __construct() {
		// Minden mentett/megváltozott utalvány azonnal felkerül.
		add_action( 'pgv_voucher_saved', array( $this, 'on_saved' ), 20, 1 );
	}

	private static function url() {
		$u = trim( (string) PGV_Settings::get( 'cockpit_url', '' ) );
		return $u ? untrailingslashit( $u ) : '';
	}
	private static function secret() {
		return trim( (string) PGV_Settings::get( 'cockpit_secret', '' ) );
	}
	public static function configured() {
		return self::url() && self::secret();
	}

	/**
	 * Voucher rekord → a vezérlőpult által várt mezők.
	 * A CRM-mezőket (számlázási név, telefon, cím, megjegyzés) a WooCommerce
	 * rendelésből is kiegészítjük, hogy a vezérlőpult exportja teljes legyen.
	 */
	public static function payload( array $v, $include_pdf = false, $previous_serial = '' ) {
		$data = array(
			'unit'             => $v['unit_slug'],
			'serial'           => $v['serial'],
			'site_url'         => home_url(),
			'order_ref'        => ! empty( $v['order_id'] ) ? (string) $v['order_id'] : '',
			'transaction_id'   => '',
			'label'            => isset( $v['denomination_label'] ) ? $v['denomination_label'] : '',
			'amount'           => (int) $v['amount'],
			'status'           => $v['status'],
			'giver_name'       => $v['giver_name'],
			'recipient_name'   => $v['recipient_name'],
			'message'          => isset( $v['message'] ) ? $v['message'] : '',
			'delivery_email'   => $v['delivery_email'],
			'buyer_email'      => $v['buyer_email'],
			'buyer_name'       => '',
			'buyer_phone'      => '',
			'country'          => '',
			'postcode'         => '',
			'city'             => '',
			'street'           => '',
			'buyer_note'       => '',
			'payment_provider' => '',
			'marketing_opt_in' => (bool) $v['marketing_opt_in'],
			'valid_from'       => $v['valid_from'],
			'valid_until'      => $v['valid_until'],
			'paid_at'          => isset( $v['paid_at'] ) ? $v['paid_at'] : null,
			'redeemed_at'      => $v['redeemed_at'],
			'is_legacy'        => (bool) $v['is_legacy'],
			'seq_no'           => isset( $v['seq_no'] ) && '' !== $v['seq_no'] ? (int) $v['seq_no'] : null,
			'seq_year'         => isset( $v['seq_year'] ) && '' !== $v['seq_year'] ? (int) $v['seq_year'] : null,
			'created_at'       => $v['created_at'],
			'updated_at'       => $v['updated_at'],
		);

		// Kód-csere: a vezérlőpulton a (egység + sorszám) a kulcs, ezért meg kell
		// mondanunk, melyik régi sort kell átnevezni — különben ott duplikátum lenne.
		if ( $previous_serial && $previous_serial !== $v['serial'] ) {
			$data['previous_serial'] = (string) $previous_serial;
		}

		if ( ! empty( $v['order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $v['order_id'] );
			if ( $order ) {
				$data['order_ref']        = (string) $order->get_order_number();
				$data['transaction_id']   = (string) $order->get_transaction_id();
				$name                     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				$data['buyer_name']       = $name;
				$data['buyer_phone']      = $order->get_billing_phone();
				$data['country']          = $order->get_billing_country();
				$data['postcode']         = $order->get_billing_postcode();
				$data['city']             = $order->get_billing_city();
				$data['street']           = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
				$data['buyer_note']       = $order->get_customer_note();
				$data['payment_provider'] = $order->get_payment_method_title();
				if ( empty( $data['buyer_email'] ) ) {
					$data['buyer_email'] = $order->get_billing_email();
				}
				$paid = $order->get_date_paid();
				if ( empty( $data['paid_at'] ) && $paid ) {
					$data['paid_at'] = $paid->format( 'Y-m-d H:i:s' );
				}
			}
		}

		// Az utalvány-PDF feltöltése (base64) — így az emlékeztetőnél a vezérlőpult
		// tudja csatolni anélkül, hogy visszahívná a boltot (a bejövő kérést a tárhely
		// bot-védelme blokkolná). Csak nem-legacy, sorszámmal bíró utalványnál.
		if ( $include_pdf && empty( $v['is_legacy'] ) && ! empty( $v['serial'] )
			&& class_exists( 'PGV_Voucher_PDF' ) && PGV_Voucher_PDF::enabled() ) {
			$bytes = PGV_Voucher_PDF::bytes( $v );
			if ( is_string( $bytes ) && '' !== $bytes ) {
				$data['pdf_base64'] = base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}

		return $data;
	}

	/**
	 * Egy utalvány felküldése (nem blokkoló — nem lassítja a vásárlást).
	 */
	public function on_saved( $voucher_id ) {
		if ( ! self::configured() ) {
			return;
		}
		$v = PGV_Vouchers::get( $voucher_id );
		if ( ! $v || empty( $v['serial'] ) ) {
			// Sorszám nélküli (függő) utalványt még nem küldünk fel — a fizetéskor,
			// a sorszám kiosztása után úgyis ismét lefut ez a hook.
			return;
		}
		// A mentés/kibocsátás azonnali felküldése, az utalvány PDF-jével együtt.
		self::send( array( self::payload( $v, true ) ), false );
	}

	/**
	 * Egy utalvány azonnali felküldése kód-csere után (a régi sor átnevezésével).
	 */
	public static function push_renamed( array $v, $previous_serial ) {
		if ( ! self::configured() ) {
			return;
		}
		self::send( array( self::payload( $v, true, $previous_serial ) ), false );
	}

	/**
	 * Utalványok küldése az /api/ingest végpontnak.
	 *
	 * @param array $vouchers Payload tömbök.
	 * @param bool  $blocking Várjunk-e a válaszra (bulk syncnél igen).
	 * @return array|WP_Error {count} vagy hiba.
	 */
	public static function send( array $vouchers, $blocking = true ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'pgv_push_cfg', __( 'A vezérlőpult URL/titok nincs beállítva.', 'pomodoro-gift-vouchers' ) );
		}
		$resp = wp_remote_post(
			self::url() . '/api/ingest',
			array(
				'timeout'  => $blocking ? 20 : 1,
				'blocking' => (bool) $blocking,
				'headers'  => array(
					'Content-Type'    => 'application/json',
					'x-ingest-secret' => self::secret(),
				),
				'body'     => wp_json_encode( array( 'vouchers' => array_values( $vouchers ) ) ),
			)
		);
		if ( ! $blocking ) {
			return array( 'count' => count( $vouchers ) );
		}
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'pgv_push_http', sprintf( __( 'Push hiba (HTTP %d): %s', 'pomodoro-gift-vouchers' ), $code, is_array( $body ) && isset( $body['error'] ) ? $body['error'] : '' ) );
		}
		return array( 'count' => is_array( $body ) && isset( $body['count'] ) ? (int) $body['count'] : count( $vouchers ) );
	}

	/**
	 * Az összes (aktuális egységbeli) utalvány felküldése kötegelve.
	 *
	 * @return array {sent:int, errors:string[]}
	 */
	public static function sync_all() {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$unit  = PGV_Settings::unit_slug();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE unit_slug = %s AND serial <> '' ORDER BY id ASC", $unit ), // phpcs:ignore
			ARRAY_A
		);

		$result = array( 'sent' => 0, 'errors' => array() );
		$batch  = array();
		foreach ( (array) $rows as $v ) {
			$batch[] = self::payload( $v );
			if ( count( $batch ) >= 100 ) {
				$r = self::send( $batch, true );
				if ( is_wp_error( $r ) ) {
					$result['errors'][] = $r->get_error_message();
				} else {
					$result['sent'] += (int) $r['count'];
				}
				$batch = array();
			}
		}
		if ( $batch ) {
			$r = self::send( $batch, true );
			if ( is_wp_error( $r ) ) {
				$result['errors'][] = $r->get_error_message();
			} else {
				$result['sent'] += (int) $r['count'];
			}
		}

		// PDF-ek back-fillje: tételenként (egy PDF/kérés), hogy ne lépjük túl a
		// serverless kérés-törzs korlátot. Csak nem-legacy, sorszámmal bíró utalvány.
		$result['pdfs'] = 0;
		if ( class_exists( 'PGV_Voucher_PDF' ) && PGV_Voucher_PDF::enabled() ) {
			foreach ( (array) $rows as $v ) {
				if ( ! empty( $v['is_legacy'] ) || empty( $v['serial'] ) ) {
					continue;
				}
				$p = self::payload( $v, true );
				if ( empty( $p['pdf_base64'] ) ) {
					continue;
				}
				$r = self::send( array( $p ), true );
				if ( ! is_wp_error( $r ) ) {
					$result['pdfs']++;
				}
			}
		}
		return $result;
	}
}
