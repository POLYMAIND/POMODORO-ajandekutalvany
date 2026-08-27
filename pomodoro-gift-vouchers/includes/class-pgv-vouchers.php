<?php
/**
 * Voucher core — szigorú számadás.
 *
 * - Hézagmentes, per-egység + év sorszám (CASA-2026-000042), atomikusan.
 * - A sorszám CSAK a sikeres fizetés után születik (soha nem a kliens-redirectből).
 * - Minden státuszváltozás auditba kerül; törlés sehol (a sztornó is cancelled + audit).
 * - Beváltás: csak aktív + nem lejárt utalvány.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Vouchers {

	const STATUS_PENDING   = 'pending';
	const STATUS_ACTIVE    = 'active';
	const STATUS_REDEEMED  = 'redeemed';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_EXPIRED   = 'expired';

	/**
	 * Emberi (magyar) státusz-címkék.
	 */
	public static function status_labels() {
		return array(
			self::STATUS_PENDING   => __( 'Függőben', 'pomodoro-gift-vouchers' ),
			self::STATUS_ACTIVE    => __( 'Aktív', 'pomodoro-gift-vouchers' ),
			self::STATUS_REDEEMED  => __( 'Beváltva', 'pomodoro-gift-vouchers' ),
			self::STATUS_CANCELLED => __( 'Sztornó', 'pomodoro-gift-vouchers' ),
			self::STATUS_EXPIRED   => __( 'Lejárt', 'pomodoro-gift-vouchers' ),
		);
	}

	public static function status_label( $status ) {
		$labels = self::status_labels();
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * A kinyomtatott kód ábécéje: nincs benne 0/O és 1/I/L, hogy a kasszán ne
	 * lehessen félreolvasni vagy félregépelni.
	 */
	const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
	const CODE_LENGTH   = 8;

	/**
	 * Véletlen, egyedi utalvány-kód az adott egységre (előtag + 8 karakter).
	 * Ütközésnél újrapróbál; az adatbázis UNIQUE(unit_slug, serial) a végső védelem.
	 */
	public static function random_code( $unit_slug, $prefix ) {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$alpha = self::CODE_ALPHABET;
		$max   = strlen( $alpha ) - 1;

		for ( $try = 0; $try < 12; $try++ ) {
			$code = '';
			for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
				$code .= $alpha[ random_int( 0, $max ) ];
			}
			$serial = strtoupper( $prefix ) . '-' . $code;
			$taken  = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE unit_slug = %s AND serial = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					sanitize_key( $unit_slug ),
					$serial
				)
			);
			if ( ! $taken ) {
				return $serial;
			}
		}
		return new WP_Error( 'pgv_serial', __( 'Nem sikerült egyedi utalvány-kódot előállítani.', 'pomodoro-gift-vouchers' ) );
	}

	/**
	 * Hézagmentes sorszám-allokálás — atomikus (InnoDB tranzakció + FOR UPDATE).
	 * A hézagmentesség per egység + év garantált.
	 *
	 * Visszaad: array( serial, seq_no, seq_year ). A `serial` a kinyomtatott kód
	 * (beállítástól függően véletlen vagy folytonos), a `seq_no` pedig a
	 * könyveléshez megmaradó, hézagmentes sorszám — akkor is, ha a kód véletlen.
	 */
	public static function allocate_serial( $unit_slug, $prefix ) {
		global $wpdb;
		$table = PGV_Install::table( 'serial' );
		$year  = (int) current_time( 'Y' );
		$unit  = sanitize_key( $unit_slug );

		$wpdb->query( 'START TRANSACTION' );

		$current = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT last_value FROM {$table} WHERE unit_slug = %s AND year = %d FOR UPDATE", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$unit,
				$year
			)
		);

		if ( null === $current ) {
			$next = 1;
			$ok   = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (unit_slug, year, last_value) VALUES (%s, %d, %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$unit,
					$year,
					$next
				)
			);
		} else {
			$next = (int) $current + 1;
			$ok   = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET last_value = %d WHERE unit_slug = %s AND year = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$next,
					$unit,
					$year
				)
			);
		}

		if ( false === $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'pgv_serial', __( 'Sorszám-allokálás sikertelen.', 'pomodoro-gift-vouchers' ) );
		}

		$wpdb->query( 'COMMIT' );

		$sequential = sprintf( '%s-%d-%06d', strtoupper( $prefix ), $year, $next );

		if ( 'sequential' === PGV_Settings::get( 'serial_format', 'random' ) ) {
			return array( 'serial' => $sequential, 'seq_no' => $next, 'seq_year' => $year );
		}

		$code = self::random_code( $unit_slug, $prefix );
		if ( is_wp_error( $code ) ) {
			// Ha a véletlen kód nem sikerülne, inkább a folytonos formát adjuk,
			// mint hogy a vásárlás elhasaljon a fizetés után.
			$code = $sequential;
		}
		return array( 'serial' => $code, 'seq_no' => $next, 'seq_year' => $year );
	}

	/**
	 * Az utalvány kódjának újragenerálása (teszteléshez / elgépelt kód javításához).
	 *
	 * A belső, hézagmentes sorszám (seq_no) NEM változik — a könyvelés folytonos
	 * marad. Beváltott, sztornózott vagy importált utalványt nem érintünk: ott a
	 * kód cseréje elvágná az auditnyomot.
	 *
	 * @return array|WP_Error array( old, new )
	 */
	public static function regenerate_serial( $id ) {
		global $wpdb;
		$v = self::get( $id );
		if ( ! $v ) {
			return new WP_Error( 'pgv_regen', __( 'Nincs ilyen utalvány.', 'pomodoro-gift-vouchers' ) );
		}
		if ( ! empty( $v['is_legacy'] ) ) {
			return new WP_Error( 'pgv_regen', __( 'Importált (korábbi) utalvány kódja nem cserélhető.', 'pomodoro-gift-vouchers' ) );
		}
		if ( ! in_array( $v['status'], array( self::STATUS_ACTIVE, self::STATUS_PENDING ), true ) ) {
			return new WP_Error( 'pgv_regen', __( 'Csak aktív vagy függőben lévő utalvány kódja cserélhető — beváltottnál az auditnyom miatt nem.', 'pomodoro-gift-vouchers' ) );
		}

		$prefix = (string) PGV_Settings::get( 'serial_prefix', '' );
		$new    = self::random_code( $v['unit_slug'], $prefix );
		if ( is_wp_error( $new ) ) {
			return $new;
		}

		$ok = $wpdb->update(
			PGV_Install::table( 'vouchers' ),
			array( 'serial' => $new, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'pgv_regen', __( 'A kód cseréje nem sikerült.', 'pomodoro-gift-vouchers' ) );
		}

		self::audit(
			$id,
			'serial_changed',
			$v['status'],
			$v['status'],
			self::actor(),
			array( 'old_serial' => $v['serial'], 'new_serial' => $new )
		);

		// A vezérlőpulton az (egység + sorszám) a kulcs: meg kell mondanunk, melyik
		// régi sort nevezze át, különben ott két bejegyzés maradna ugyanarról.
		if ( class_exists( 'PGV_Push' ) ) {
			PGV_Push::push_renamed( self::get( $id ), $v['serial'] );
		}

		return array( 'old' => $v['serial'], 'new' => $new );
	}

	/**
	 * Utalvány rekord létrehozása (pending állapotban vagy már aktívan).
	 *
	 * @param array $data Mezők.
	 * @return int|WP_Error Az új rekord ID-je.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$now   = current_time( 'mysql' );

		$row = wp_parse_args(
			$data,
			array(
				'unit_slug'          => PGV_Settings::unit_slug(),
				'order_id'           => null,
				'order_item_id'      => null,
				'serial'             => '',
				'seq_no'             => null,
				'seq_year'           => null,
				'is_legacy'          => 0,
				'amount'             => 0,
				'denomination_label' => '',
				'status'             => self::STATUS_PENDING,
				'giver_name'         => '',
				'recipient_name'     => '',
				'message'            => '',
				'image_id'           => null,
				'image_name'         => '',
				'delivery_method'    => 'buyer',
				'delivery_email'     => '',
				'buyer_email'        => '',
				'marketing_opt_in'   => 0,
				'qr_token'           => null,
				'valid_from'         => null,
				'valid_until'        => null,
				'paid_at'            => null,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);

		$formats = array(
			'unit_slug'          => '%s',
			'order_id'           => '%d',
			'order_item_id'      => '%d',
			'serial'             => '%s',
			'seq_no'             => '%d',
			'seq_year'           => '%d',
			'is_legacy'          => '%d',
			'amount'             => '%d',
			'denomination_label' => '%s',
			'status'             => '%s',
			'giver_name'         => '%s',
			'recipient_name'     => '%s',
			'message'            => '%s',
			'image_id'           => '%d',
			'image_name'         => '%s',
			'delivery_method'    => '%s',
			'delivery_email'     => '%s',
			'buyer_email'        => '%s',
			'marketing_opt_in'   => '%d',
			'qr_token'           => '%s',
			'valid_from'         => '%s',
			'valid_until'        => '%s',
			'paid_at'            => '%s',
			'created_at'         => '%s',
			'updated_at'         => '%s',
		);

		$ok = $wpdb->insert( $table, $row, array_values( $formats ) );
		if ( false === $ok ) {
			return new WP_Error( 'pgv_create', __( 'Utalvány létrehozása sikertelen.', 'pomodoro-gift-vouchers' ) );
		}

		$id = (int) $wpdb->insert_id;
		self::audit(
			$id,
			$row['is_legacy'] ? 'imported' : 'created',
			null,
			$row['status'],
			self::actor()
		);

		/**
		 * Az utalvány elmentve (létrehozva) — a központi vezérlőpult push-ja ezt figyeli.
		 */
		do_action( 'pgv_voucher_saved', $id );

		return $id;
	}

	/**
	 * Egy voucher rekord (id / serial / qr_token szerint).
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function get_by_serial_or_token( $needle, $unit_slug = null ) {
		global $wpdb;
		$table  = PGV_Install::table( 'vouchers' );
		$needle = trim( $needle );
		$unit   = $unit_slug ? sanitize_key( $unit_slug ) : PGV_Settings::unit_slug();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE unit_slug = %s AND (serial = %s OR qr_token = %s) LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$unit,
				$needle,
				$needle
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Egy rendeléshez tartozó utalványok.
	 */
	public static function get_by_order( $order_id ) {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", (int) $order_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	/**
	 * Státuszváltás + audit egy lépésben.
	 */
	public static function set_status( $id, $new_status, $extra = array(), $actor = null ) {
		global $wpdb;
		$v = self::get( $id );
		if ( ! $v ) {
			return new WP_Error( 'pgv_notfound', __( 'Nincs ilyen utalvány.', 'pomodoro-gift-vouchers' ) );
		}
		$from = $v['status'];

		$data    = array_merge( array( 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ), $extra );
		$formats = array();
		foreach ( $data as $k => $val ) {
			$formats[] = in_array( $k, array( 'redeemed_by', 'image_id' ), true ) ? '%d' : '%s';
		}

		$wpdb->update( PGV_Install::table( 'vouchers' ), $data, array( 'id' => (int) $id ), $formats, array( '%d' ) );

		if ( $from !== $new_status ) {
			$action = self::action_for_status( $new_status );
			self::audit( $id, $action, $from, $new_status, $actor ?: self::actor(), $extra );
		}

		/**
		 * Az utalvány megváltozott (státusz/mezők) — felkerül a vezérlőpultra.
		 */
		do_action( 'pgv_voucher_saved', $id );

		return self::get( $id );
	}

	/**
	 * Szerkeszthető mezők módosítása (sorszám/összeg SOHA). Auditálva, és
	 * felkerül a vezérlőpultra is (pgv_voucher_saved).
	 */
	public static function update_editable( $id, array $fields ) {
		global $wpdb;
		$v = self::get( $id );
		if ( ! $v ) {
			return new WP_Error( 'pgv_notfound', __( 'Nincs ilyen utalvány.', 'pomodoro-gift-vouchers' ) );
		}
		$allowed = array( 'recipient_name', 'giver_name', 'message', 'delivery_email' );
		$data    = array();
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$data[ $k ] = $fields[ $k ];
			}
		}
		if ( ! $data ) {
			return $v;
		}
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update(
			PGV_Install::table( 'vouchers' ),
			$data,
			array( 'id' => (int) $id ),
			array_fill( 0, count( $data ), '%s' ),
			array( '%d' )
		);
		self::audit( $id, 'edited', $v['status'], $v['status'], self::actor(), array( 'fields' => array_keys( $data ) ) );
		do_action( 'pgv_voucher_saved', $id );
		return self::get( $id );
	}

	/**
	 * Beváltás — kizárólag aktív + nem lejárt utalvány.
	 */
	public static function redeem( $id, $actor = null ) {
		$v = self::get( $id );
		if ( ! $v ) {
			return new WP_Error( 'pgv_notfound', __( 'Nincs ilyen utalvány.', 'pomodoro-gift-vouchers' ) );
		}
		if ( self::STATUS_ACTIVE !== $v['status'] ) {
			return new WP_Error(
				'pgv_notredeemable',
				sprintf( __( 'Nem beváltható (állapot: %s).', 'pomodoro-gift-vouchers' ), self::status_label( $v['status'] ) )
			);
		}
		if ( ! empty( $v['valid_until'] ) && $v['valid_until'] < current_time( 'Y-m-d' ) ) {
			self::set_status( $id, self::STATUS_EXPIRED, array(), $actor );
			return new WP_Error(
				'pgv_expired',
				sprintf( __( 'Lejárt utalvány (érvényes volt: %s).', 'pomodoro-gift-vouchers' ), $v['valid_until'] )
			);
		}

		return self::set_status(
			$id,
			self::STATUS_REDEEMED,
			array(
				'redeemed_at' => current_time( 'mysql' ),
				'redeemed_by' => get_current_user_id(),
			),
			$actor
		);
	}

	/**
	 * Audit sor beszúrása.
	 */
	public static function audit( $voucher_id, $action, $from, $to, $actor = null, $detail = array() ) {
		global $wpdb;
		$v = self::get( $voucher_id );
		$wpdb->insert(
			PGV_Install::table( 'audit' ),
			array(
				'unit_slug'   => $v ? $v['unit_slug'] : PGV_Settings::unit_slug(),
				'voucher_id'  => (int) $voucher_id,
				'action'      => sanitize_key( $action ),
				'from_status' => $from,
				'to_status'   => $to,
				'actor'       => $actor ?: self::actor(),
				'detail'      => wp_json_encode( $detail ),
				'occurred_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Egy utalvány audit-előzményei.
	 */
	public static function get_audit( $voucher_id ) {
		global $wpdb;
		$table = PGV_Install::table( 'audit' );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE voucher_id = %d ORDER BY id ASC", (int) $voucher_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	private static function action_for_status( $status ) {
		switch ( $status ) {
			case self::STATUS_ACTIVE:
				return 'paid';
			case self::STATUS_REDEEMED:
				return 'redeemed';
			case self::STATUS_CANCELLED:
				return 'cancelled';
			case self::STATUS_EXPIRED:
				return 'expired';
			default:
				return 'created';
		}
	}

	/**
	 * Aktuális szereplő az audithoz.
	 */
	public static function actor() {
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		$user = wp_get_current_user();
		if ( $user && $user->ID ) {
			return $user->user_login;
		}
		return is_admin() ? 'admin' : 'system';
	}
}
