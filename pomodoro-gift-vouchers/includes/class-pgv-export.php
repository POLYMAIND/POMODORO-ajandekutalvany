<?php
/**
 * NAV-formátumú CSV export + legacy (RESnWEB) import.
 *
 * Formátum: `;` elválasztó, minden mező `"` idézőjelben, UTF-8 BOM, CRLF sorvég.
 * Összeg egész, tagolás nélkül. Teljes időpont, ill. csak dátum (oszloptól függ).
 *
 * Kívánt export-oszlopok:
 *   Azonosító · Státusz · Vásárlás dátuma · Fizetés dátuma · Felhasználás dátuma ·
 *   Számlázási név · Megajándékozott neve · Email · Marketing feliratkozás · Lejárat ·
 *   Érték · Vendég megjegyzése
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Export {

	/**
	 * Export-oszlopfejlécek (sorrendben).
	 */
	public static function columns() {
		return array(
			'Azonosító',
			'Belső sorszám',
			'Státusz',
			'Vásárlás dátuma',
			'Fizetés dátuma',
			'Felhasználás dátuma',
			'Számlázási név',
			'Megajándékozott neve',
			'Email',
			'Marketing feliratkozás',
			'Lejárat',
			'Érték',
			'Vendég megjegyzése',
		);
	}

	/**
	 * Státusz → magyar címke a NAV-kimutatáshoz.
	 */
	private static function status_hu( $status ) {
		return PGV_Vouchers::status_label( $status );
	}

	/**
	 * Egy voucher rekord → export-mező tömb.
	 */
	private static function row( array $v ) {
		$email = ! empty( $v['delivery_email'] ) ? $v['delivery_email'] : $v['buyer_email'];
		return array(
			$v['serial'],
			empty( $v['seq_no'] ) ? '' : sprintf( '%1$s/%2$06d', $v['seq_year'], $v['seq_no'] ),
			self::status_hu( $v['status'] ),
			self::fmt_datetime( $v['created_at'] ),
			self::fmt_datetime( $v['paid_at'] ),
			self::fmt_datetime( $v['redeemed_at'] ),
			$v['giver_name'],
			$v['recipient_name'],
			$email,
			( (int) $v['marketing_opt_in'] ) ? 'igen' : 'nem',
			self::fmt_date( $v['valid_until'] ),
			(string) (int) $v['amount'],
			(string) $v['message'],
		);
	}

	private static function fmt_datetime( $val ) {
		if ( empty( $val ) || '0000-00-00 00:00:00' === $val ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', strtotime( $val ) );
	}

	private static function fmt_date( $val ) {
		if ( empty( $val ) || '0000-00-00' === $val ) {
			return '';
		}
		return gmdate( 'Y-m-d', strtotime( $val ) );
	}

	/**
	 * Egy mező CSV-idézése (minden mező idézőjelben, `"` duplázva).
	 */
	private static function quote( $field ) {
		return '"' . str_replace( '"', '""', (string) $field ) . '"';
	}

	/**
	 * Teljes CSV string legenerálása a megadott (szűrt) rekordokból.
	 */
	public static function build_csv( array $vouchers ) {
		$lines   = array();
		$lines[] = implode( ';', array_map( array( __CLASS__, 'quote' ), self::columns() ) );
		foreach ( $vouchers as $v ) {
			$lines[] = implode( ';', array_map( array( __CLASS__, 'quote' ), self::row( $v ) ) );
		}
		// UTF-8 BOM + CRLF sorvég.
		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Export letöltése (a voucher lista aktuális szűrésével).
	 */
	public static function download( array $vouchers ) {
		$unit     = PGV_Settings::unit_slug();
		$filename = sprintf( 'utalvanyok-%s-%s.csv', $unit, gmdate( 'Ymd-His' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo self::build_csv( $vouchers ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// ============================================================
	// Import (legacy RESnWEB CSV) — idempotens az „Azonosító"-ra.
	// ============================================================

	/**
	 * Státusz-szöveg leképezése belső státuszra.
	 */
	private static function map_status( $raw ) {
		$s = strtolower( trim( $raw ) );
		$map = array(
			'fizetve'     => PGV_Vouchers::STATUS_ACTIVE,
			'aktív'       => PGV_Vouchers::STATUS_ACTIVE,
			'aktiv'       => PGV_Vouchers::STATUS_ACTIVE,
			'active'      => PGV_Vouchers::STATUS_ACTIVE,
			'felhasználva' => PGV_Vouchers::STATUS_REDEEMED,
			'felhasznalva' => PGV_Vouchers::STATUS_REDEEMED,
			'beváltva'    => PGV_Vouchers::STATUS_REDEEMED,
			'bevaltva'    => PGV_Vouchers::STATUS_REDEEMED,
			'redeemed'    => PGV_Vouchers::STATUS_REDEEMED,
			'sztornó'     => PGV_Vouchers::STATUS_CANCELLED,
			'sztorno'     => PGV_Vouchers::STATUS_CANCELLED,
			'cancelled'   => PGV_Vouchers::STATUS_CANCELLED,
			'lejárt'      => PGV_Vouchers::STATUS_EXPIRED,
			'lejart'      => PGV_Vouchers::STATUS_EXPIRED,
			'expired'     => PGV_Vouchers::STATUS_EXPIRED,
		);
		return isset( $map[ $s ] ) ? $map[ $s ] : PGV_Vouchers::STATUS_ACTIVE;
	}

	/**
	 * CSV importálása fájlból. Idempotens: a már meglévő sorszámot kihagyja.
	 *
	 * @return array{imported:int,skipped:int,errors:string[]}
	 */
	public static function import_file( $path ) {
		global $wpdb;
		$result = array( 'imported' => 0, 'skipped' => 0, 'errors' => array() );

		if ( ! file_exists( $path ) ) {
			$result['errors'][] = __( 'A fájl nem található.', 'pomodoro-gift-vouchers' );
			return $result;
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// BOM eltávolítása.
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );
		$lines   = preg_split( '/\r\n|\r|\n/', $content );
		$lines   = array_values( array_filter( $lines, static function ( $l ) { return '' !== trim( $l ); } ) );

		if ( count( $lines ) < 2 ) {
			$result['errors'][] = __( 'Üres vagy hibás CSV.', 'pomodoro-gift-vouchers' );
			return $result;
		}

		$header = self::parse_line( array_shift( $lines ) );
		$idx    = self::header_index( $header );

		if ( ! isset( $idx['azonosito'] ) ) {
			$result['errors'][] = __( 'Hiányzó „Azonosító" oszlop.', 'pomodoro-gift-vouchers' );
			return $result;
		}

		$unit  = PGV_Settings::unit_slug();
		$table = PGV_Install::table( 'vouchers' );

		foreach ( $lines as $line ) {
			$cols   = self::parse_line( $line );
			$serial = isset( $cols[ $idx['azonosito'] ] ) ? trim( $cols[ $idx['azonosito'] ] ) : '';
			if ( '' === $serial ) {
				continue;
			}

			// Idempotencia: kulcs a (unit, serial).
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE unit_slug = %s AND serial = %s", $unit, $serial ) // phpcs:ignore
			);
			if ( $exists ) {
				$result['skipped']++;
				continue;
			}

			$get = static function ( $key ) use ( $cols, $idx ) {
				return isset( $idx[ $key ], $cols[ $idx[ $key ] ] ) ? trim( $cols[ $idx[ $key ] ] ) : '';
			};

			$status      = self::map_status( $get( 'statusz' ) );
			$redeemed_at = ( PGV_Vouchers::STATUS_REDEEMED === $status ) ? self::to_mysql( $get( 'felhasznalas' ) ?: $get( 'vasarlas' ) ) : null;

			$data = array(
				'unit_slug'          => $unit,
				'serial'             => $serial,
				'is_legacy'          => 1,
				'amount'             => (int) preg_replace( '/[^\d]/', '', $get( 'ertek' ) ),
				'denomination_label' => '',
				'status'             => $status,
				'giver_name'         => $get( 'szamlazasi_nev' ),
				'recipient_name'     => $get( 'megajandekozott' ),
				'message'            => $get( 'vendeg_megjegyzes' ),
				'delivery_method'    => 'buyer',
				'delivery_email'     => '',
				'buyer_email'        => sanitize_email( $get( 'email' ) ),
				'marketing_opt_in'   => in_array( strtolower( $get( 'marketing' ) ), array( 'igen', 'yes', '1', 'true' ), true ) ? 1 : 0,
				'qr_token'           => PGV_Order::new_qr_token(),
				'valid_until'        => self::to_date( $get( 'lejarat' ) ),
				'paid_at'            => self::to_mysql( $get( 'fizetes' ) ?: $get( 'vasarlas' ) ),
				'redeemed_at'        => $redeemed_at,
				'created_at'         => self::to_mysql( $get( 'vasarlas' ) ) ?: current_time( 'mysql' ),
			);

			$id = PGV_Vouchers::create( $data );
			if ( is_wp_error( $id ) ) {
				$result['errors'][] = $serial . ': ' . $id->get_error_message();
			} else {
				$result['imported']++;
			}
		}

		return $result;
	}

	/**
	 * Fejléc-nevek → belső kulcs → oszlopindex.
	 */
	private static function header_index( array $header ) {
		$aliases = array(
			'azonosito'         => array( 'azonosító', 'azonosito', 'serial', 'sorszám', 'sorszam' ),
			'statusz'           => array( 'státusz', 'statusz', 'status' ),
			'vasarlas'          => array( 'vásárlás dátuma', 'vasarlas datuma', 'vásárlás', 'vasarlas' ),
			'fizetes'           => array( 'fizetés dátuma', 'fizetes datuma', 'fizetés', 'fizetes' ),
			'felhasznalas'      => array( 'felhasználás dátuma', 'felhasznalas datuma', 'felhasználás', 'felhasznalas' ),
			'szamlazasi_nev'    => array( 'számlázási név', 'szamlazasi nev', 'számlázási nev' ),
			'megajandekozott'   => array( 'megajándékozott neve', 'megajandekozott neve', 'megajándékozott', 'megajandekozott' ),
			'email'             => array( 'email', 'e-mail', 'e-mail cím' ),
			'marketing'         => array( 'marketing feliratkozás', 'marketing feliratkozas', 'marketing' ),
			'lejarat'           => array( 'lejárat', 'lejarat', 'érvényesség' ),
			'ertek'             => array( 'érték', 'ertek', 'összeg', 'osszeg', 'amount' ),
			'vendeg_megjegyzes' => array( 'vendég megjegyzése', 'vendeg megjegyzese', 'megjegyzés', 'megjegyzes' ),
		);

		$norm = array();
		foreach ( $header as $i => $h ) {
			$norm[ $i ] = strtolower( trim( $h ) );
		}

		$idx = array();
		foreach ( $aliases as $key => $names ) {
			foreach ( $norm as $i => $h ) {
				if ( in_array( $h, $names, true ) ) {
					$idx[ $key ] = $i;
					break;
				}
			}
		}
		return $idx;
	}

	/**
	 * Egy CSV-sor szétbontása (`;` elválasztó, `"` idézőjel, `""` escape).
	 */
	private static function parse_line( $line ) {
		return str_getcsv( $line, ';', '"' );
	}

	private static function to_mysql( $val ) {
		$val = trim( (string) $val );
		if ( '' === $val ) {
			return null;
		}
		$ts = strtotime( $val );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}

	private static function to_date( $val ) {
		$val = trim( (string) $val );
		if ( '' === $val ) {
			return null;
		}
		$ts = strtotime( $val );
		return $ts ? gmdate( 'Y-m-d', $ts ) : null;
	}
}
