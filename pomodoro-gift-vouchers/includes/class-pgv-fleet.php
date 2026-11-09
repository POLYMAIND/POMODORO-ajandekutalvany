<?php
/**
 * Licenc és központi vezérlőpult (ajandekutalvany.polyapp.hu) — a „flotta”-kapcsolat.
 *
 * MIT CSINÁL
 *  • Licenc: a POLYMAIND vezérlőpultjában kiadott kulcs (vfl_…). Ezzel azonosítja magát a telepítés.
 *  • Arculat + egység: a vezérlőpultban beállított logó, e-mail-szín, feliratok, feladó, e-mail-szövegek
 *    és az egység adatai (név, cég, adószám, előtag, érvényesség) letöltődnek és bekerülnek a
 *    beállításokba. Egy WordPress-oldal = egy egység; a domain alapján ismeri fel, melyik.
 *  • Óránkénti jelzés: verzió, WordPress/WooCommerce/PHP verzió, havi összesítő (eladott/beváltott
 *    db + Ft), a kint lévő összeg, és a hibák (pl. sikertelen e-mail-küldés).
 *  • Frissítés: a WordPress bővítmény-frissítője a vezérlőpultból kapja az új verziót.
 *
 * MIT NEM KÜLD: vendégnevet, e-mail-címet, sorszámot, üzenetet — semmilyen személyes adatot.
 * Csak darabszám, összeg és technikai adat megy ki. (A vezérlőpult a hibaüzenetből is kiszűri az
 * esetleg belekerült e-mail-címet és telefonszámot.)
 *
 * BIZTONSÁG: a licenc csak a vezérlőpult felé megy, HTTPS-en, az Authorization fejlécben.
 * A licenc nélküli telepítés (pl. egy meglévő oldal a licenc beírása előtt) változatlanul működik.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Fleet {

	const OPTION     = 'pgv_fleet';
	const ERR_OPTION = 'pgv_fleet_errors';
	const CRON       = 'pgv_fleet_heartbeat';
	const PAGE       = 'pgv-vouchers-licence';
	const DEFAULT_BASE = 'https://ajandekutalvany.polyapp.hu';

	/**
	 * A vezérlőpult által kezelt beállítás-kulcsok (ezeket a helyi Beállítások oldal nem írja felül).
	 * A szövegesek csak akkor, ha a vezérlőpultban ki vannak töltve.
	 */
	const MANAGED_UNIT_KEYS = array( 'unit_name', 'serial_prefix', 'company_name', 'tax_number', 'validity_months', 'active' );

	public function __construct() {
		add_action( self::CRON, array( __CLASS__, 'sync' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
		add_action( 'pgv_error', array( __CLASS__, 'report' ), 10, 4 );

		// A helyi beállítás-mentés ne írja felül, amit a vezérlőpult kezel.
		add_filter( 'pgv_settings_before_save', array( __CLASS__, 'strip_managed' ) );

		// Frissítés a vezérlőpultból.
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth_download' ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 20 );
			add_action( 'admin_post_pgv_fleet', array( $this, 'handle' ) );
			add_action( 'admin_notices', array( $this, 'notice' ) );
		}
	}

	// ------------------------------------------------------------
	// Tárolt állapot
	// ------------------------------------------------------------
	public static function state() {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array(
			'licence'        => '',
			'config'         => array(),
			'config_version' => 0,
			'unit_id'        => '',   // kézi egységválasztás (ha a domain nem dönti el)
			'last'           => array(),
			'logo_src'       => '',
			'latest_version' => '',
		) );
	}
	private static function put( array $changes ) {
		update_option( self::OPTION, array_merge( self::state(), $changes ), false );
	}
	public static function base() {
		return untrailingslashit( (string) apply_filters( 'pgv_fleet_base', self::DEFAULT_BASE ) );
	}
	public static function has_licence() {
		return '' !== self::state()['licence'];
	}
	/** Kezeli-e a vezérlőpult az egység adatait (van licenc ÉS megvan az egység). */
	public static function managed() {
		return self::has_licence() && null !== self::current_unit();
	}

	// ------------------------------------------------------------
	// Hívás a vezérlőpult felé
	// ------------------------------------------------------------
	private static function call( $action, array $payload = array(), $timeout = 20 ) {
		$licence = self::state()['licence'];
		if ( '' === $licence ) {
			return new WP_Error( 'pgv_fleet_no_licence', 'Nincs beírva licenckulcs.' );
		}
		$res = wp_remote_post( self::base() . '/api/plugin', array(
			'timeout' => $timeout,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $licence,
			),
			'body'    => wp_json_encode( array_merge( array( 'action' => $action ), $payload ) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $json ) || empty( $json['ok'] ) ) {
			$msg = is_array( $json ) && ! empty( $json['hint'] ) ? $json['hint'] : ( 'HTTP ' . $code );
			return new WP_Error( 'pgv_fleet_http', $msg, array( 'status' => $code ) );
		}
		return $json;
	}

	/**
	 * A teljes kör: config letöltése (ha változott), alkalmazása, majd jelzés.
	 * Óránként a WP-Cron hívja; a Licenc oldalon kézzel is indítható.
	 */
	public static function sync( $force_config = false ) {
		if ( ! self::has_licence() ) {
			return new WP_Error( 'pgv_fleet_no_licence', 'Nincs beírva licenckulcs.' );
		}
		$st = self::state();

		if ( $force_config || empty( $st['config'] ) ) {
			$r = self::fetch_config();
			if ( is_wp_error( $r ) ) {
				self::remember( false, $r->get_error_message() );
				return $r;
			}
		}

		$unit = self::current_unit();
		$payload = array(
			'unit_id'        => $unit ? $unit['id'] : PGV_Settings::unit_slug(),
			'config_version' => (int) self::state()['config_version'],
			'env'            => array(
				'plugin_version' => PGV_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'php_version'    => PHP_VERSION,
				'site_url'       => home_url(),
				'admin_url'      => admin_url( 'admin.php?page=' . PGV_Admin_Slug() ),
			),
			'outstanding'    => self::outstanding(),
			'stats'          => self::monthly_stats(),
			'errors'         => self::take_errors(),
		);
		$r = self::call( 'heartbeat', $payload );
		if ( is_wp_error( $r ) ) {
			// A hibák nem vesznek el: visszatesszük a sorba a következő körre.
			self::requeue( $payload['errors'] );
			self::remember( false, $r->get_error_message() );
			return $r;
		}
		self::put( array( 'latest_version' => (string) ( $r['latest_version'] ?? '' ) ) );

		// Ha a vezérlőpultban azóta módosítottak, azonnal letöltjük (nem várunk a következő óráig).
		if ( (int) ( $r['config_version'] ?? 0 ) > (int) self::state()['config_version'] ) {
			$c = self::fetch_config();
			if ( is_wp_error( $c ) ) {
				self::remember( false, $c->get_error_message() );
				return $c;
			}
		}
		self::remember( true, '' );
		return true;
	}

	private static function fetch_config() {
		$r = self::call( 'config' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$cfg = is_array( $r['config'] ?? null ) ? $r['config'] : array();
		self::put( array( 'config' => $cfg, 'config_version' => (int) ( $cfg['config_version'] ?? 0 ) ) );
		self::apply_config( $cfg );
		return $cfg;
	}

	private static function remember( $ok, $error ) {
		self::put( array( 'last' => array( 'time' => time(), 'ok' => (bool) $ok, 'error' => (string) $error ) ) );
	}

	// ------------------------------------------------------------
	// Egység felismerése és a config alkalmazása
	// ------------------------------------------------------------
	private static function host_of( $url ) {
		$h = wp_parse_url( (string) $url, PHP_URL_HOST );
		return $h ? preg_replace( '/^www\./', '', strtolower( $h ) ) : '';
	}

	/**
	 * Melyik egység ez az oldal? Sorrend: kézi választás → a mostani egység-azonosító → a domain →
	 * ha csak egy egység van, az. Különben null (a Licenc oldalon ki kell választani).
	 */
	public static function current_unit() {
		$st    = self::state();
		$units = isset( $st['config']['units'] ) && is_array( $st['config']['units'] ) ? $st['config']['units'] : array();
		if ( ! $units ) {
			return null;
		}
		$by = function ( $fn ) use ( $units ) {
			foreach ( $units as $u ) {
				if ( $fn( $u ) ) {
					return $u;
				}
			}
			return null;
		};
		if ( $st['unit_id'] ) {
			$u = $by( function ( $u ) use ( $st ) { return $u['id'] === $st['unit_id']; } );
			if ( $u ) {
				return $u;
			}
		}
		$slug = PGV_Settings::unit_slug();
		$u    = $by( function ( $u ) use ( $slug ) { return $u['id'] === $slug; } );
		if ( $u ) {
			return $u;
		}
		$host = self::host_of( home_url() );
		$u    = $by( function ( $u ) use ( $host ) { return $host && self::host_of( $u['site_url'] ?? '' ) === $host; } );
		if ( $u ) {
			return $u;
		}
		return 1 === count( $units ) ? $units[0] : null;
	}

	private static function voucher_count( $unit_slug ) {
		global $wpdb;
		$t = PGV_Install::table( 'vouchers' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE unit_slug = %s", $unit_slug ) );
	}

	/**
	 * A letöltött config beírása a beállításokba. Csak a vezérlőpultban KITÖLTÖTT szöveg ír felül.
	 *
	 * ⛔ AZ EGYSÉG-AZONOSÍTÓ (unit_slug) VÉDETT: az utalványok, a sorszám-számláló és a képkészlet ehhez
	 * kötődik. Ha az oldalon már van utalvány, a vezérlőpult NEM írja át — csak jelez (figyelmeztetés),
	 * hogy a vezérlőpultban az egység azonosítóját kell a meglévőhöz igazítani.
	 */
	private static function apply_config( array $cfg ) {
		$unit = self::current_unit();
		if ( ! $unit ) {
			return;
		}
		$s    = PGV_Settings::all();
		$vals = array();

		if ( $s['unit_slug'] !== $unit['id'] ) {
			if ( 0 === self::voucher_count( $s['unit_slug'] ) ) {
				$vals['unit_slug'] = sanitize_key( $unit['id'] );
			} else {
				self::report( 'egyseg_azonosito', sprintf( 'Az oldal egység-azonosítója „%s”, a vezérlőpulté „%s”. Mivel már vannak utalványok, nem írtuk át — igazítsd a vezérlőpultban az egység azonosítóját.', $s['unit_slug'], $unit['id'] ), array(), 'warning' );
			}
		}
		if ( ! empty( $unit['name'] ) ) {
			$vals['unit_name'] = sanitize_text_field( $unit['name'] );
		}
		if ( ! empty( $unit['prefix'] ) ) {
			$vals['serial_prefix'] = strtoupper( sanitize_text_field( $unit['prefix'] ) );
		}
		foreach ( array( 'company' => 'company_name', 'tax_number' => 'tax_number' ) as $from => $to ) {
			if ( ! empty( $unit[ $from ] ) ) {
				$vals[ $to ] = sanitize_text_field( $unit[ $from ] );
			}
		}
		if ( ! empty( $unit['validity_months'] ) ) {
			$vals['validity_months'] = max( 1, (int) $unit['validity_months'] );
		}
		$vals['active'] = empty( $unit['active'] ) || 'paused' === ( $cfg['status'] ?? '' ) ? 0 : 1;

		$b   = is_array( $cfg['branding'] ?? null ) ? $cfg['branding'] : array();
		$map = array(
			'voucher_title'           => 'email_heading',
			'sender_name'             => 'from_name',
			'marketing_label'         => 'marketing_label',
			'email_subject_recipient' => 'email_subject_recipient',
			'email_subject_buyer'     => 'email_subject_buyer',
			'email_footer'            => 'email_footer',
		);
		foreach ( $map as $from => $to ) {
			if ( ! empty( $b[ $from ] ) ) {
				$vals[ $to ] = sanitize_text_field( $b[ $from ] );
			}
		}
		foreach ( array( 'email_intro_recipient', 'email_intro_buyer' ) as $k ) {
			if ( ! empty( $b[ $k ] ) ) {
				$vals[ $k ] = sanitize_textarea_field( $b[ $k ] );
			}
		}
		if ( ! empty( $b['sender_email'] ) && is_email( $b['sender_email'] ) ) {
			$vals['from_email'] = sanitize_email( $b['sender_email'] );
		}
		if ( ! empty( $b['color_accent'] ) && sanitize_hex_color( $b['color_accent'] ) ) {
			$vals['email_accent'] = sanitize_hex_color( $b['color_accent'] );
		}

		// A kasszás felület (az ügyfél saját vezérlőpultja) címe: ide küldi a bővítmény az utalványokat.
		// Titok nem kell hozzá — a kassza a licenc hash-ével hitelesít.
		$portal = isset( $cfg['portal_url'] ) ? esc_url_raw( $cfg['portal_url'] ) : '';
		if ( $portal && 0 === strpos( $portal, 'https://' ) ) {
			$vals['cockpit_url'] = untrailingslashit( $portal );
		}

		// Logó: ha a vezérlőpultban megadott URL változott, letöltjük a médiatárba (egyszer).
		$logo = isset( $b['logo_url'] ) ? esc_url_raw( $b['logo_url'] ) : '';
		if ( $logo && $logo !== self::state()['logo_src'] ) {
			$id = self::sideload_logo( $logo );
			if ( $id ) {
				$vals['logo_attachment_id'] = $id;
				self::put( array( 'logo_src' => $logo ) );
			}
		}

		remove_filter( 'pgv_settings_before_save', array( __CLASS__, 'strip_managed' ) );
		PGV_Settings::save( $vals );
		add_filter( 'pgv_settings_before_save', array( __CLASS__, 'strip_managed' ) );
	}

	private static function sideload_logo( $url ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_sideload_image( $url, 0, null, 'id' );
		if ( is_wp_error( $id ) ) {
			self::report( 'logo_letoltes', 'A logót nem sikerült letölteni: ' . $id->get_error_message(), array( 'url' => $url ), 'warning' );
			return 0;
		}
		return (int) $id;
	}

	/** A helyi Beállítások mentésénél a vezérlőpult által kezelt mezőket nem írjuk felül. */
	public static function strip_managed( $values ) {
		if ( ! self::managed() || ! is_array( $values ) ) {
			return $values;
		}
		unset( $values['unit_slug'] );
		foreach ( self::MANAGED_UNIT_KEYS as $k ) {
			unset( $values[ $k ] );
		}
		return $values;
	}

	// ------------------------------------------------------------
	// Összesítések (csak darabszám és összeg — személyes adat nincs)
	// ------------------------------------------------------------
	private static function monthly_stats() {
		global $wpdb;
		$t     = PGV_Install::table( 'vouchers' );
		$unit  = PGV_Settings::unit_slug();
		$since = gmdate( 'Y-m-01 00:00:00', strtotime( '-12 months', time() ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sold = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(paid_at, '%%Y-%%m') AS m, COUNT(*) AS c, COALESCE(SUM(amount),0) AS s
			 FROM {$t} WHERE unit_slug = %s AND paid_at >= %s AND status NOT IN ('pending','cancelled') GROUP BY m",
			$unit, $since
		), ARRAY_A );
		$red = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(redeemed_at, '%%Y-%%m') AS m, COUNT(*) AS c, COALESCE(SUM(amount),0) AS s
			 FROM {$t} WHERE unit_slug = %s AND redeemed_at >= %s AND status = 'redeemed' GROUP BY m",
			$unit, $since
		), ARRAY_A );
		// phpcs:enable
		$out = array();
		foreach ( (array) $sold as $r ) {
			$out[ $r['m'] ] = array( 'month' => $r['m'], 'unit_id' => $unit, 'sold_count' => (int) $r['c'], 'sold_huf' => (int) $r['s'], 'redeemed_count' => 0, 'redeemed_huf' => 0 );
		}
		foreach ( (array) $red as $r ) {
			if ( ! isset( $out[ $r['m'] ] ) ) {
				$out[ $r['m'] ] = array( 'month' => $r['m'], 'unit_id' => $unit, 'sold_count' => 0, 'sold_huf' => 0, 'redeemed_count' => 0, 'redeemed_huf' => 0 );
			}
			$out[ $r['m'] ]['redeemed_count'] = (int) $r['c'];
			$out[ $r['m'] ]['redeemed_huf']   = (int) $r['s'];
		}
		return array_values( $out );
	}

	private static function outstanding() {
		global $wpdb;
		$t     = PGV_Install::table( 'vouchers' );
		$unit  = PGV_Settings::unit_slug();
		$today = current_time( 'Y-m-d' );
		$in30  = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s,
			        SUM(CASE WHEN valid_until IS NOT NULL AND valid_until <= %s THEN 1 ELSE 0 END) AS ec,
			        COALESCE(SUM(CASE WHEN valid_until IS NOT NULL AND valid_until <= %s THEN amount ELSE 0 END),0) AS es
			 FROM {$t} WHERE unit_slug = %s AND status = 'active' AND (valid_until IS NULL OR valid_until >= %s)",
			$in30, $in30, $unit, $today
		), ARRAY_A );
		return array(
			'count'              => (int) ( $r['c'] ?? 0 ),
			'huf'                => (int) ( $r['s'] ?? 0 ),
			'expiring_30d_count' => (int) ( $r['ec'] ?? 0 ),
			'expiring_30d_huf'   => (int) ( $r['es'] ?? 0 ),
		);
	}

	// ------------------------------------------------------------
	// Hibajelentés
	// ------------------------------------------------------------
	/**
	 * Hiba felvétele a következő jelzésbe. Bárhonnan: do_action( 'pgv_error', $code, $message, $context, $severity ).
	 * A context-be NE kerüljön személyes adat (név, e-mail) — csak azonosító, állapot, szám.
	 */
	public static function report( $code, $message, $context = array(), $severity = 'error' ) {
		if ( ! self::has_licence() ) {
			return;
		}
		$q   = get_option( self::ERR_OPTION, array() );
		$q   = is_array( $q ) ? $q : array();
		$key = md5( $code . '|' . preg_replace( '/\d+/', '#', (string) $message ) );
		if ( isset( $q[ $key ] ) ) {
			$q[ $key ]['count']++;
		} else {
			$q[ $key ] = array(
				'code'     => (string) $code,
				'message'  => wp_strip_all_tags( (string) $message ),
				'context'  => is_array( $context ) ? $context : array(),
				'severity' => 'warning' === $severity ? 'warning' : 'error',
				'count'    => 1,
			);
		}
		update_option( self::ERR_OPTION, array_slice( $q, -50, null, true ), false );
	}
	private static function take_errors() {
		$q = get_option( self::ERR_OPTION, array() );
		delete_option( self::ERR_OPTION );
		return is_array( $q ) ? array_values( $q ) : array();
	}
	private static function requeue( array $errors ) {
		foreach ( $errors as $e ) {
			for ( $i = 0; $i < max( 1, (int) $e['count'] ); $i++ ) {
				self::report( $e['code'], $e['message'], $e['context'], $e['severity'] );
			}
		}
	}

	// ------------------------------------------------------------
	// Címlet-termékek
	// ------------------------------------------------------------
	/** A vezérlőpultban beállított címletekből a hiányzó WooCommerce-termékek létrehozása. */
	public static function create_missing_products() {
		$unit = self::current_unit();
		if ( ! $unit || empty( $unit['denominations'] ) ) {
			return new WP_Error( 'pgv_fleet_no_denoms', 'A vezérlőpultban ennél az egységnél nincs címlet beállítva.' );
		}
		$have = array();
		foreach ( wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'draft', 'private' ), 'meta_key' => PGV_Product::META_IS_VOUCHER, 'meta_value' => 'yes' ) ) as $p ) {
			$have[] = (int) round( (float) $p->get_regular_price() );
		}
		$made = 0;
		foreach ( (array) $unit['denominations'] as $amount ) {
			$amount = (int) $amount;
			if ( $amount <= 0 || in_array( $amount, $have, true ) ) {
				continue;
			}
			$p = new WC_Product_Simple();
			$p->set_name( sprintf( 'Ajándékutalvány %s Ft értékben', number_format( $amount, 0, ',', '.' ) ) );
			$p->set_regular_price( (string) $amount );
			$p->set_virtual( true );
			$p->set_sold_individually( false );
			$p->set_status( 'draft' ); // piszkozat: kép és leírás után élesítik
			$p->update_meta_data( PGV_Product::META_IS_VOUCHER, 'yes' );
			$p->save();
			$made++;
		}
		return $made;
	}

	// ------------------------------------------------------------
	// Frissítés a vezérlőpultból
	// ------------------------------------------------------------
	private static function update_info( $force = false ) {
		$cached = get_transient( 'pgv_fleet_update' );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$r = self::call( 'update_check', array(), 10 );
		$info = is_wp_error( $r ) ? array() : $r;
		set_transient( 'pgv_fleet_update', $info, 6 * HOUR_IN_SECONDS );
		return $info;
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) || ! self::has_licence() ) {
			return $transient;
		}
		$info = self::update_info();
		if ( empty( $info['version'] ) || version_compare( $info['version'], PGV_VERSION, '<=' ) ) {
			return $transient;
		}
		$transient->response[ PGV_PLUGIN_BASENAME ] = (object) array(
			'slug'         => dirname( PGV_PLUGIN_BASENAME ),
			'plugin'       => PGV_PLUGIN_BASENAME,
			'new_version'  => $info['version'],
			'package'      => $info['package'],
			'url'          => self::base(),
			'requires'     => $info['requires_wp'] ?? '',
			'requires_php' => $info['requires_php'] ?? '',
		);
		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( PGV_PLUGIN_BASENAME ) !== $args->slug || ! self::has_licence() ) {
			return $result;
		}
		$info = self::update_info();
		if ( empty( $info['version'] ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Ajándékutalvány (POLYMAIND)',
			'slug'          => $args->slug,
			'version'       => $info['version'],
			'author'        => 'POLYMAIND',
			'requires'      => $info['requires_wp'] ?? '',
			'requires_php'  => $info['requires_php'] ?? '',
			'last_updated'  => $info['published_at'] ?? '',
			'download_link' => $info['package'],
			'sections'      => array( 'changelog' => nl2br( esc_html( (string) ( $info['notes'] ?? '' ) ) ) ),
		);
	}

	/** A frissítő letöltésénél a licencet a fejlécben küldjük (nem az URL-ben). */
	public static function auth_download( $args, $url ) {
		if ( self::has_licence() && 0 === strpos( (string) $url, self::base() . '/api/plugin' ) ) {
			$args['headers']                  = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
			$args['headers']['Authorization'] = 'Bearer ' . self::state()['licence'];
		}
		return $args;
	}

	// ------------------------------------------------------------
	// Admin: Licenc oldal
	// ------------------------------------------------------------
	public function menu() {
		add_submenu_page( PGV_Admin_Slug(), 'Licenc és vezérlőpult', 'Licenc', 'manage_woocommerce', self::PAGE, array( $this, 'page' ) );
	}

	public function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nincs jogosultságod.' );
		}
		check_admin_referer( 'pgv_fleet' );
		$do  = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$msg = '';
		if ( 'save_licence' === $do ) {
			$key = trim( sanitize_text_field( wp_unslash( $_POST['licence'] ?? '' ) ) );
			if ( ! preg_match( '/^vfl_[A-Za-z0-9_-]{16,76}$/', $key ) ) {
				$msg = 'error:A licenckulcs formája nem megfelelő (vfl_ kezdetű).';
			} else {
				self::put( array( 'licence' => $key, 'config' => array(), 'config_version' => 0 ) );
				delete_transient( 'pgv_fleet_update' );
				$r   = self::sync( true );
				$msg = is_wp_error( $r ) ? 'error:' . $r->get_error_message() : 'ok:Kapcsolódva. Az arculat és az egység adatai letöltve.';
			}
		} elseif ( 'sync' === $do ) {
			delete_transient( 'pgv_fleet_update' );
			$r   = self::sync( true );
			$msg = is_wp_error( $r ) ? 'error:' . $r->get_error_message() : 'ok:Szinkronizálva.';
		} elseif ( 'choose_unit' === $do ) {
			self::put( array( 'unit_id' => sanitize_key( wp_unslash( $_POST['unit_id'] ?? '' ) ) ) );
			self::apply_config( self::state()['config'] );
			$r   = self::sync();
			$msg = is_wp_error( $r ) ? 'error:' . $r->get_error_message() : 'ok:Egység beállítva.';
		} elseif ( 'products' === $do ) {
			$r   = self::create_missing_products();
			$msg = is_wp_error( $r ) ? 'error:' . $r->get_error_message() : ( $r ? sprintf( 'ok:%d címlet-termék létrehozva piszkozatként — adj hozzá képet, és tedd közzé.', $r ) : 'ok:Minden címlethez van már termék.' );
		} elseif ( 'remove' === $do ) {
			self::put( array( 'licence' => '', 'config' => array(), 'config_version' => 0, 'unit_id' => '' ) );
			delete_transient( 'pgv_fleet_update' );
			$msg = 'ok:A licenc törölve. A beállítások megmaradtak, a vezérlőpult többé nem kezeli őket.';
		}
		set_transient( 'pgv_fleet_msg_' . get_current_user_id(), $msg, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, PGV_Admin_Slug() ) || ! self::has_licence() ) {
			return;
		}
		$last = self::state()['last'];
		if ( ! empty( $last ) && empty( $last['ok'] ) ) {
			echo '<div class="notice notice-warning"><p><strong>Ajándékutalvány:</strong> a vezérlőpulttal nem sikerült kapcsolódni (' . esc_html( $last['error'] ) . '). <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Licenc oldal</a></p></div>';
		}
		if ( self::has_licence() && ! empty( self::state()['config'] ) && null === self::current_unit() ) {
			echo '<div class="notice notice-warning"><p><strong>Ajándékutalvány:</strong> válaszd ki, melyik egység ez az oldal. <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Licenc oldal</a></p></div>';
		}
	}

	public function page() {
		$st   = self::state();
		$unit = self::current_unit();
		$msg  = get_transient( 'pgv_fleet_msg_' . get_current_user_id() );
		delete_transient( 'pgv_fleet_msg_' . get_current_user_id() );
		$field = function ( $do ) {
			wp_nonce_field( 'pgv_fleet' );
			echo '<input type="hidden" name="action" value="pgv_fleet"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		};
		$post = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap pgv-wrap">
			<h1 class="pgv-title">Licenc és vezérlőpult</h1>
			<?php if ( $msg ) : list( $kind, $text ) = array_pad( explode( ':', $msg, 2 ), 2, '' ); ?>
				<div class="notice notice-<?php echo 'ok' === $kind ? 'success' : 'error'; ?>"><p><?php echo esc_html( $text ); ?></p></div>
			<?php endif; ?>

			<div class="pgv-card">
				<h2>Licenc</h2>
				<?php if ( self::has_licence() ) : ?>
					<p>Licenc: <code>vfl_…<?php echo esc_html( substr( $st['licence'], -4 ) ); ?></code>
					<?php if ( ! empty( $st['last']['time'] ) ) : ?>
						· utolsó kapcsolat: <?php echo esc_html( human_time_diff( (int) $st['last']['time'] ) ); ?> ezelőtt
						(<?php echo ! empty( $st['last']['ok'] ) ? 'sikeres' : 'sikertelen: ' . esc_html( $st['last']['error'] ); ?>)
					<?php endif; ?></p>
					<form method="post" action="<?php echo $post; ?>" style="display:inline-block;margin-right:8px"><?php $field( 'sync' ); ?><button class="button button-primary">Szinkronizálás most</button></form>
					<form method="post" action="<?php echo $post; ?>" style="display:inline-block" onsubmit="return confirm('Törlöd a licencet? A vezérlőpult nem kezeli tovább az oldalt, és frissítést sem ajánl fel.');"><?php $field( 'remove' ); ?><button class="button">Licenc törlése</button></form>
				<?php else : ?>
					<p>A licenckulcsot a POLYMAIND-tól kapod. Beírás után a bővítmény letölti az arculatot és az egység adatait, óránként jelez a vezérlőpultnak, és innen kapja a frissítéseket.</p>
					<form method="post" action="<?php echo $post; ?>"><?php $field( 'save_licence' ); ?>
						<input type="text" name="licence" class="regular-text code" placeholder="vfl_…" autocomplete="off" required>
						<button class="button button-primary">Mentés és kapcsolódás</button>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( self::has_licence() && ! empty( $st['config'] ) ) : ?>
				<div class="pgv-card">
					<h2>Egység</h2>
					<?php if ( $unit ) : ?>
						<p>Ez az oldal: <strong><?php echo esc_html( $unit['name'] ); ?></strong> (<code><?php echo esc_html( $unit['id'] ); ?></code>).
						A név, cég, adószám, előtag és érvényesség a vezérlőpultból jön; a Beállítások oldalon ezek csak olvashatók.</p>
						<?php if ( ! empty( $unit['denominations'] ) ) : ?>
							<p>Címletek: <?php echo esc_html( implode( ', ', array_map( function ( $d ) { return number_format( (int) $d, 0, ',', '.' ) . ' Ft'; }, $unit['denominations'] ) ) ); ?></p>
							<form method="post" action="<?php echo $post; ?>"><?php $field( 'products' ); ?><button class="button">Hiányzó címlet-termékek létrehozása</button></form>
						<?php endif; ?>
					<?php endif; ?>
					<?php if ( count( (array) $st['config']['units'] ) > 1 || ! $unit ) : ?>
						<form method="post" action="<?php echo $post; ?>" style="margin-top:12px"><?php $field( 'choose_unit' ); ?>
							<label>Melyik egység ez az oldal?
								<select name="unit_id">
									<option value="">— automatikus (domain alapján) —</option>
									<?php foreach ( (array) $st['config']['units'] as $u ) : ?>
										<option value="<?php echo esc_attr( $u['id'] ); ?>" <?php selected( $st['unit_id'], $u['id'] ); ?>><?php echo esc_html( $u['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<button class="button">Mentés</button>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p class="description">A vezérlőpult felé csak darabszám, összeg és technikai adat megy (verziók, hibák) — vendégnév, e-mail-cím vagy sorszám soha.</p>
		</div>
		<?php
	}
}

/**
 * A bővítmény admin-menüjének slugja. Függvényként, mert a PGV_Admin osztály csak az adminban töltődik
 * be, a jelzés viszont WP-Cronból (frontend-kérésből) fut.
 */
if ( ! function_exists( 'PGV_Admin_Slug' ) ) {
	function PGV_Admin_Slug() {
		return 'pgv-vouchers';
	}
}
