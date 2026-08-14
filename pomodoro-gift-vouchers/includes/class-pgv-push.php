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
	 */
	public static function payload( array $v ) {
		return array(
			'unit'             => $v['unit_slug'],
			'serial'           => $v['serial'],
			'amount'           => (int) $v['amount'],
			'status'           => $v['status'],
			'giver_name'       => $v['giver_name'],
			'recipient_name'   => $v['recipient_name'],
			'delivery_email'   => $v['delivery_email'],
			'buyer_email'      => $v['buyer_email'],
			'marketing_opt_in' => (bool) $v['marketing_opt_in'],
			'valid_from'       => $v['valid_from'],
			'valid_until'      => $v['valid_until'],
			'redeemed_at'      => $v['redeemed_at'],
			'is_legacy'        => (bool) $v['is_legacy'],
			'created_at'       => $v['created_at'],
			'updated_at'       => $v['updated_at'],
		);
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
		self::send( array( self::payload( $v ) ), false );
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
		return $result;
	}
}
