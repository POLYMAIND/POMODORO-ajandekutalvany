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

	/** A sikertelen felküldések várólistája (utalvány-azonosítók). */
	const QUEUE_OPTION = 'pgv_push_queue';
	/** A legutóbbi felküldés eredménye (idő, siker, hibaszöveg). */
	const LAST_OPTION  = 'pgv_push_last';
	const RETRY_HOOK   = 'pgv_push_retry';

	public function __construct() {
		// Minden mentett/megváltozott utalvány azonnal felkerül.
		add_action( 'pgv_voucher_saved', array( $this, 'on_saved' ), 20, 1 );
		// Ami nem ment fel elsőre (a vezérlőpult nem elérhető, rossz titok, hálózati
		// hiba), az óránként újrapróbálkozik — enélkül némán kimaradna a kasszáról.
		add_action( self::RETRY_HOOK, array( __CLASS__, 'run_retry' ) );
		if ( ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::RETRY_HOOK );
		}
	}

	/** A legutóbbi felküldés állapota az admin figyelmeztetéshez. */
	public static function last_result() {
		$r = get_option( self::LAST_OPTION, array() );
		return is_array( $r ) ? $r : array();
	}
	private static function remember( $ok, $error = '' ) {
		update_option( self::LAST_OPTION, array(
			'time'  => time(),
			'ok'    => (bool) $ok,
			'error' => (string) $error,
		), false );
	}

	/** Várólista kezelése. */
	public static function queue() {
		$q = get_option( self::QUEUE_OPTION, array() );
		return is_array( $q ) ? array_values( array_unique( array_map( 'intval', $q ) ) ) : array();
	}
	private static function enqueue( $id ) {
		$id = (int) $id;
		if ( ! $id ) {
			return;
		}
		$q = self::queue();
		if ( ! in_array( $id, $q, true ) ) {
			$q[] = $id;
			update_option( self::QUEUE_OPTION, array_slice( $q, -500 ), false );
		}
	}
	private static function dequeue( $id ) {
		$q = array_values( array_diff( self::queue(), array( (int) $id ) ) );
		update_option( self::QUEUE_OPTION, $q, false );
	}

	/**
	 * A várólista feldolgozása (óránként, illetve kézzel az adminból).
	 *
	 * @return array{sent:int,failed:int}
	 */
	public static function run_retry() {
		$out = array( 'sent' => 0, 'failed' => 0 );
		foreach ( self::queue() as $id ) {
			$v = PGV_Vouchers::get( $id );
			if ( ! $v || empty( $v['serial'] ) ) {
				self::dequeue( $id );
				continue;
			}
			$r = self::send( array( self::payload( $v, true ) ), true );
			if ( is_wp_error( $r ) ) {
				$out['failed']++;
			} else {
				self::dequeue( $id );
				$out['sent']++;
			}
		}
		return $out;
	}

	/**
	 * A kapcsolat ellenőrzése: valódi, üres kérés a vezérlőpultnak.
	 *
	 * @return true|WP_Error
	 */
	public static function test_connection() {
		$r = self::send( array(), true );
		return is_wp_error( $r ) ? $r : true;
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
		//
		// Korábban ez „elküldöm és nem várom meg” módon ment, 1 másodperces
		// időkorláttal: ha a vezérlőpult épp lassabban ébredt (hidegindítás), a
		// kérés elveszett, és az utalvány NÉMÁN kimaradt a kasszáról. Ezért most
		// megvárjuk a választ, és ami nem ment fel, azt várólistára tesszük —
		// onnan óránként (vagy kézzel) újrapróbálkozik.
		$r = self::send( array( self::payload( $v, true ) ), true );
		if ( is_wp_error( $r ) ) {
			self::enqueue( $voucher_id );
		}
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
	 * Egyetlen utalvány PDF-jének frissítése a vezérlőpulton.
	 *
	 * A PDF-et nem tároljuk: mindig a friss kódból generáljuk. A vezérlőpult
	 * viszont a kiküldéskori példányt őrzi, ezért a sablon változása (pl. az
	 * emoji-támogatás) csak ezzel a felküldéssel jut el oda.
	 *
	 * @return true|WP_Error
	 */
	public static function push_pdf( array $v ) {
		if ( ! self::configured() ) {
			return new WP_Error( 'pgv_not_configured', __( 'A vezérlőpult nincs beállítva.', 'pomodoro-gift-vouchers' ) );
		}
		if ( ! empty( $v['is_legacy'] ) || empty( $v['serial'] ) ) {
			return new WP_Error( 'pgv_no_serial', __( 'Ehhez az utalványhoz nem tartozik kiadott kód.', 'pomodoro-gift-vouchers' ) );
		}
		$p = self::payload( $v, true );
		if ( empty( $p['pdf_base64'] ) ) {
			return new WP_Error( 'pgv_no_pdf', __( 'A PDF-et nem sikerült előállítani.', 'pomodoro-gift-vouchers' ) );
		}
		$r = self::send( array( $p ), true );
		return is_wp_error( $r ) ? $r : true;
	}

	/**
	 * Az összes kiadott utalvány PDF-jének újraküldése a vezérlőpultra.
	 * Tételenként egy kérés, hogy ne lépjük túl a kérés-törzs korlátot.
	 *
	 * @return array{sent:int,failed:int,skipped:int}
	 */
	public static function push_all_pdfs() {
		$out = array( 'sent' => 0, 'failed' => 0, 'skipped' => 0 );
		if ( ! self::configured() || ! class_exists( 'PGV_Voucher_PDF' ) || ! PGV_Voucher_PDF::enabled() ) {
			return $out;
		}
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$unit  = PGV_Settings::unit_slug();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE unit_slug = %s AND serial <> '' ORDER BY id ASC", $unit ), // phpcs:ignore
			ARRAY_A
		);
		foreach ( (array) $rows as $v ) {
			if ( ! empty( $v['is_legacy'] ) || empty( $v['serial'] ) ) {
				$out['skipped']++;
				continue;
			}
			$r = self::push_pdf( $v );
			if ( is_wp_error( $r ) ) {
				$out['failed']++;
			} else {
				$out['sent']++;
			}
		}
		return $out;
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
			$e = new WP_Error( 'pgv_push_cfg', __( 'A vezérlőpult URL/titok nincs beállítva.', 'pomodoro-gift-vouchers' ) );
			self::remember( false, $e->get_error_message() );
			return $e;
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
			self::remember( false, $resp->get_error_message() );
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$e = new WP_Error( 'pgv_push_http', sprintf( __( 'Push hiba (HTTP %d): %s', 'pomodoro-gift-vouchers' ), $code, is_array( $body ) && isset( $body['error'] ) ? $body['error'] : '' ) );
			self::remember( false, $e->get_error_message() );
			return $e;
		}
		self::remember( true );
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
