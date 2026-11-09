<?php
/**
 * CRM olvasó REST API — a supabase/CRM_API.md-t tükrözi, de a WooCommerce-táblákra.
 *
 * A CRM a WooCommerce beépített orders/customers REST-jét is használhatja; ez a
 * végpont az egyedi utalvány-adatot (sorszám, státusz, beváltás, marketing) adja,
 * API-kulcsos hitelesítéssel, inkrementális szinkronnal.
 *
 * Base: /wp-json/pgv/v1/{vouchers|orders|customers}
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_REST {

	const NS = 'pgv/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$args = array(
			'permission_callback' => array( $this, 'check_key' ),
			'methods'             => WP_REST_Server::READABLE,
		);

		register_rest_route( self::NS, '/vouchers', array_merge( $args, array( 'callback' => array( $this, 'get_vouchers' ) ) ) );
		register_rest_route( self::NS, '/orders', array_merge( $args, array( 'callback' => array( $this, 'get_orders' ) ) ) );
		register_rest_route( self::NS, '/customers', array_merge( $args, array( 'callback' => array( $this, 'get_customers' ) ) ) );

		// Beváltás a központi appból (kassza). Csak POST, API-kulccsal.
		register_rest_route(
			self::NS,
			'/redeem',
			array(
				'permission_callback' => array( $this, 'check_key' ),
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'redeem' ),
			)
		);

		// Egy utalvány lekérése sorszám alapján (a kassza-előnézethez).
		register_rest_route( self::NS, '/voucher', array_merge( $args, array( 'callback' => array( $this, 'get_one' ) ) ) );

		// Utalvány-PDF a központi vezérlőpultnak (emlékeztető-csatolás). A push-titokkal
		// (cockpit_secret) hitelesítve, hogy ne kelljen az API-kulcsot újraéleszteni.
		register_rest_route(
			self::NS,
			'/voucher-pdf',
			array(
				'permission_callback' => array( $this, 'check_cockpit_secret' ),
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pdf' ),
			)
		);

		// A megajándékozott nevének módosítása a vezérlőpult adatlapjáról. A hitelesítés
		// a kérésben hozott, aláírt jegy (lásd recipient_change), ezért nyitott a jogosultság.
		register_rest_route(
			self::NS,
			'/recipient-change',
			array(
				'permission_callback' => '__return_true',
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'recipient_change' ),
			)
		);
	}

	/**
	 * GET /recipient-change?p=…&s=…&l=… — a megajándékozott nevének módosítása a
	 * kasszás vezérlőpultról.
	 *
	 * Miért böngészős GET? A tárhely bot-védelme a vezérlőpult SZERVERÉNEK minden
	 * bejövő kérését elfogja (mérésileg igazolt, lásd /api/diag), a kolléga
	 * böngészőjét viszont rendesen kiszolgálja. Ezért a vezérlőpult egy aláírt,
	 * 15 percig érvényes, egyszer használható jegyet ad, a böngésző ezzel nyitja
	 * meg ezt a címet egy kis ablakban, és a módosítás itt, AZONNAL lefut: a
	 * WooCommerce-rendelés, az utalvány, az audit és a PDF-újragenerálás + a
	 * felküldés a vezérlőpultra — egyetlen kérésen belül, várólista nélkül.
	 *
	 * Hitelesítés: a jegy HMAC-aláírása a közös titokkal (cockpit_secret), vagy —
	 * ha nincs titok — a POLYMAIND-licenc sha256-hash-ével (a vezérlőpult csak ezt
	 * ismeri). A válasz egy kis HTML-oldal, ami az eredményt postMessage-dzsel
	 * visszaszól a vezérlőpultnak, és bezárja magát.
	 */
	public function recipient_change( WP_REST_Request $request ) {
		$ticket = self::verify_ticket(
			(string) $request->get_param( 'p' ),
			(string) $request->get_param( 's' ),
			(string) $request->get_param( 'l' )
		);
		if ( is_wp_error( $ticket ) ) {
			self::render_result_page( array( 'ok' => false, 'error' => $ticket->get_error_message() ), '' );
		}
		$ret   = isset( $ticket['ret'] ) ? (string) $ticket['ret'] : '';
		$nonce = (string) $ticket['nonce'];

		// Egyszer használható: a második megnyitás (pl. frissítés) az első eredményét mutatja.
		$key  = 'pgv_rc_' . md5( $nonce );
		$prev = get_transient( $key );
		if ( is_array( $prev ) ) {
			self::render_result_page( $prev, $ret );
		}
		set_transient( $key, array( 'ok' => false, 'nonce' => $nonce, 'error' => __( 'A módosítás már folyamatban van.', 'pomodoro-gift-vouchers' ) ), HOUR_IN_SECONDS );

		$out = self::apply_recipient_ticket( $ticket );
		set_transient( $key, $out, HOUR_IN_SECONDS );
		self::render_result_page( $out, $ret );
	}

	/**
	 * A jegyben kért névváltozás végrehajtása.
	 *
	 * @return array Az eredmény (ok, error, order, pdf, pushed, name…).
	 */
	private static function apply_recipient_ticket( array $t ) {
		$base = array( 'ok' => false, 'nonce' => (string) $t['nonce'], 'serial' => (string) $t['serial'] );

		if ( sanitize_key( (string) $t['unit'] ) !== PGV_Settings::unit_slug() ) {
			return $base + array( 'error' => __( 'A jegy egy másik egységnek szól, nem ennek a boltnak.', 'pomodoro-gift-vouchers' ) );
		}
		$v = PGV_Vouchers::get_by_serial_or_token( (string) $t['serial'] );
		if ( ! $v || (string) $v['serial'] !== (string) $t['serial'] ) {
			return $base + array( 'error' => __( 'Ez az utalvány nem található a boltban.', 'pomodoro-gift-vouchers' ) );
		}

		$new = self::clean_name( (string) $t['name'] );
		if ( '' === $new ) {
			return $base + array( 'error' => __( 'Az új név üres.', 'pomodoro-gift-vouchers' ) );
		}
		$cur = (string) $v['recipient_name'];
		$old = (string) $t['old'];
		// Ha közben a boltban már átírták, nem írjuk felül vakon.
		if ( $cur !== $old && $cur !== $new ) {
			return $base + array(
				'error' => sprintf(
					/* translators: %s: current name */
					__( 'A név időközben megváltozott a boltban (most: „%s”). Nyisd meg újra az adatlapot, és próbáld újra.', 'pomodoro-gift-vouchers' ),
					'' !== $cur ? $cur : '—'
				),
			);
		}

		$actor = 'kassza: ' . (string) $t['by'];
		$actor = function_exists( 'mb_substr' ) ? mb_substr( $actor, 0, 190 ) : substr( $actor, 0, 190 );
		$push0 = time();
		$r     = PGV_Vouchers::change_recipient( (int) $v['id'], $new, $actor, array( 'nonce' => (string) $t['nonce'] ) );
		if ( is_wp_error( $r ) ) {
			return $base + array( 'error' => $r->get_error_message() );
		}

		$pdf    = class_exists( 'PGV_Voucher_PDF' ) && PGV_Voucher_PDF::enabled();
		$last   = PGV_Push::last_result();
		$pushed = ! empty( $r['unchanged'] ) ? null
			: ( PGV_Push::configured() && ! empty( $last['ok'] ) && (int) ( $last['time'] ?? 0 ) >= $push0 );

		return array_merge( $base, array(
			'ok'        => true,
			'old'       => $r['old'],
			'name'      => $new,
			'unchanged' => ! empty( $r['unchanged'] ),
			'order'     => $r['order'],
			'order_ref' => ! empty( $v['order_id'] ) ? (string) $v['order_id'] : '',
			'pdf'       => $pdf,
			'pushed'    => $pushed,
			'push_error' => ( false === $pushed && ! empty( $last['error'] ) ) ? (string) $last['error'] : '',
		) );
	}

	/** Név tisztítása: egy sor, vezérlőkarakter nélkül, legfeljebb 120 karakter (mint a termékoldalon). */
	private static function clean_name( $s ) {
		$s = sanitize_text_field( $s );
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 120 ) : substr( $s, 0, 120 );
	}

	/**
	 * A vezérlőpult által aláírt jegy ellenőrzése.
	 *
	 * @return array|WP_Error A jegy mezői.
	 */
	public static function verify_ticket( $p, $sig_secret, $sig_licence ) {
		$bad = new WP_Error( 'pgv_bad_ticket', __( 'Érvénytelen vagy sérült hivatkozás. Indítsd újra a módosítást a kasszás felületen.', 'pomodoro-gift-vouchers' ) );
		if ( '' === $p || strlen( $p ) > 4000 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $p ) ) {
			return $bad;
		}

		$ok     = false;
		$secret = trim( (string) PGV_Settings::get( 'cockpit_secret', '' ) );
		if ( '' !== $secret && '' !== $sig_secret ) {
			$ok = hash_equals( hash_hmac( 'sha256', $p, $secret ), strtolower( $sig_secret ) );
		}
		if ( ! $ok && '' !== $sig_licence && class_exists( 'PGV_Fleet' ) ) {
			$licence = (string) PGV_Fleet::state()['licence'];
			if ( '' !== $licence ) {
				$ok = hash_equals( hash_hmac( 'sha256', $p, hash( 'sha256', $licence ) ), strtolower( $sig_licence ) );
			}
		}
		if ( ! $ok ) {
			return $bad;
		}

		$raw = base64_decode( strtr( $p, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $p ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$t   = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $t ) || 'recipient' !== ( $t['op'] ?? '' )
			|| empty( $t['nonce'] ) || empty( $t['serial'] ) || empty( $t['unit'] ) || ! isset( $t['name'], $t['old'], $t['exp'] ) ) {
			return $bad;
		}
		$now = time();
		if ( (int) $t['exp'] < $now ) {
			return new WP_Error( 'pgv_ticket_expired', __( 'A hivatkozás lejárt (15 percig érvényes). Indítsd újra a módosítást a kasszás felületen.', 'pomodoro-gift-vouchers' ) );
		}
		if ( (int) $t['exp'] > $now + 30 * MINUTE_IN_SECONDS ) {
			return $bad;
		}
		$t['ret'] = ( isset( $t['ret'] ) && preg_match( '#^https?://[A-Za-z0-9.-]+(:\d+)?$#', (string) $t['ret'] ) ) ? (string) $t['ret'] : '';
		$t['by']  = isset( $t['by'] ) ? sanitize_text_field( (string) $t['by'] ) : '';
		return $t;
	}

	/**
	 * Eredmény-oldal a kis ablakban: visszaszól a vezérlőpultnak, és sikernél bezárul.
	 */
	private static function render_result_page( array $r, $ret ) {
		$ok    = ! empty( $r['ok'] );
		$order = isset( $r['order'] ) ? (string) $r['order'] : '';
		$lines = array();
		if ( $ok ) {
			if ( ! empty( $r['unchanged'] ) ) {
				$lines[] = __( 'A név már így szerepelt — nem kellett módosítani.', 'pomodoro-gift-vouchers' );
			} else {
				$lines[] = sprintf( __( 'Új név: %s', 'pomodoro-gift-vouchers' ), $r['name'] );
				$lines[] = 'no_order' === $order
					? __( 'WooCommerce: ehhez az utalványhoz nincs rendelés (importált tétel).', 'pomodoro-gift-vouchers' )
					: __( 'WooCommerce-rendelés frissítve (+ rendelés-jegyzet).', 'pomodoro-gift-vouchers' );
				$lines[] = ! empty( $r['pdf'] )
					? __( 'Az utalvány PDF-je újragenerálva.', 'pomodoro-gift-vouchers' )
					: __( 'A beépített PDF ki van kapcsolva — PDF nem készült.', 'pomodoro-gift-vouchers' );
				if ( true === ( $r['pushed'] ?? null ) ) {
					$lines[] = __( 'A kasszás felület megkapta a változást.', 'pomodoro-gift-vouchers' );
				} elseif ( false === ( $r['pushed'] ?? null ) ) {
					$lines[] = __( 'A kasszás felületre küldés most nem sikerült — a bolt óránként újrapróbálja.', 'pomodoro-gift-vouchers' );
				}
			}
		}

		$msg = array(
			'type'   => 'pgv-recipient',
			'ok'     => $ok,
			'nonce'  => isset( $r['nonce'] ) ? (string) $r['nonce'] : '',
			'serial' => isset( $r['serial'] ) ? (string) $r['serial'] : '',
			'name'   => isset( $r['name'] ) ? (string) $r['name'] : '',
			'order'  => $order,
			'pdf'    => ! empty( $r['pdf'] ),
			'pushed' => $r['pushed'] ?? null,
			'error'  => isset( $r['error'] ) ? (string) $r['error'] : '',
		);

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		status_header( 200 );
		$title = $ok ? __( 'Megajándékozott módosítva', 'pomodoro-gift-vouchers' ) : __( 'A módosítás nem sikerült', 'pomodoro-gift-vouchers' );
		echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">';
		echo '<title>' . esc_html( $title ) . '</title><style>'
			. 'body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#efe9df;color:#221d18;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;font-size:15px;line-height:1.55}'
			. '.c{background:#fff;border:1px solid #e4dccd;border-radius:14px;max-width:440px;padding:24px 26px;box-shadow:0 8px 24px -12px rgba(40,30,18,.18)}'
			. 'h1{margin:0 0 10px;font-size:17px}h1.ok{color:#2f7d4f}h1.err{color:#b3261e}p{margin:0 0 8px;color:#6d6357}'
			. 'button{margin-top:10px;background:#e2552b;color:#fff;border:0;border-radius:9px;padding:9px 16px;font:inherit;font-weight:600;cursor:pointer}'
			. '</style></head><body><div class="c">';
		echo '<h1 class="' . ( $ok ? 'ok' : 'err' ) . '">' . esc_html( $title ) . '</h1>';
		if ( ! $ok ) {
			echo '<p>' . esc_html( $msg['error'] ) . '</p>';
		}
		foreach ( $lines as $l ) {
			echo '<p>' . esc_html( $l ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Ez az ablak bezárható — a kasszás felület magától frissül.', 'pomodoro-gift-vouchers' ) . '</p>';
		echo '<button type="button" onclick="window.close()">' . esc_html__( 'Bezárás', 'pomodoro-gift-vouchers' ) . '</button>';
		echo '</div><script>(function(){var m=' . wp_json_encode( $msg, JSON_HEX_TAG | JSON_HEX_AMP ) . ',o=' . wp_json_encode( $ret, JSON_HEX_TAG | JSON_HEX_AMP ) . ';'
			. 'try{if(o&&window.opener){window.opener.postMessage(m,o);}}catch(e){}'
			. ( $ok ? 'setTimeout(function(){try{window.close();}catch(e){}},1800);' : '' )
			. '})();</script></body></html>';
		exit;
	}

	/**
	 * A vezérlőpult (push) közös titkának ellenőrzése — a PDF-lekéréshez.
	 */
	public function check_cockpit_secret( WP_REST_Request $request ) {
		$secret = $request->get_header( 'x-ingest-secret' );
		if ( ! $secret ) {
			$secret = (string) $request->get_param( 'secret' );
		}
		$stored = trim( (string) PGV_Settings::get( 'cockpit_secret', '' ) );
		if ( '' === $stored || ! is_string( $secret ) || ! hash_equals( $stored, trim( $secret ) ) ) {
			return new WP_Error( 'pgv_bad_secret', __( 'Érvénytelen titok.', 'pomodoro-gift-vouchers' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * GET /voucher-pdf?serial=… — a beépített utalvány-PDF base64-ben.
	 */
	public function get_pdf( WP_REST_Request $request ) {
		$needle = sanitize_text_field( (string) $request->get_param( 'serial' ) );
		if ( '' === $needle ) {
			return new WP_Error( 'pgv_no_serial', __( 'Hiányzó sorszám.', 'pomodoro-gift-vouchers' ), array( 'status' => 400 ) );
		}
		$v = PGV_Vouchers::get_by_serial_or_token( $needle );
		if ( ! $v ) {
			return new WP_Error( 'pgv_notfound', __( 'Nincs ilyen utalvány.', 'pomodoro-gift-vouchers' ), array( 'status' => 404 ) );
		}
		if ( ! class_exists( 'PGV_Voucher_PDF' ) || ! PGV_Voucher_PDF::enabled() ) {
			return new WP_Error( 'pgv_pdf_off', __( 'A beépített PDF nincs bekapcsolva.', 'pomodoro-gift-vouchers' ), array( 'status' => 409 ) );
		}
		$bytes = PGV_Voucher_PDF::bytes( $v );
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return new WP_Error( 'pgv_pdf_fail', __( 'A PDF előállítása sikertelen.', 'pomodoro-gift-vouchers' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'ok'       => true,
				'serial'   => $v['serial'],
				'filename' => 'ajandekutalvany-' . sanitize_file_name( $v['serial'] ) . '.pdf',
				'pdf_base64' => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			)
		);
	}

	/**
	 * Egy utalvány sorszám (vagy QR-token) alapján.
	 */
	public function get_one( WP_REST_Request $request ) {
		$needle = sanitize_text_field( (string) $request->get_param( 'serial' ) );
		$v      = PGV_Vouchers::get_by_serial_or_token( $needle );
		if ( ! $v ) {
			return new WP_Error( 'pgv_notfound', __( 'Nincs ilyen utalvány.', 'pomodoro-gift-vouchers' ), array( 'status' => 404 ) );
		}
		$redeemable = ( PGV_Vouchers::STATUS_ACTIVE === $v['status'] )
			&& ( empty( $v['valid_until'] ) || $v['valid_until'] >= current_time( 'Y-m-d' ) );
		// Tájékoztató jelzés: a lejárt utalványt a KASSZA bírálhatja felül, ember
		// által megerősítve. Ez a végpont nem: API-kulccsal hívják, a beváltó
		// személye nem azonosítható, ezért itt a lejárat marad akadály.
		$expired = ( ! empty( $v['valid_until'] ) && $v['valid_until'] < current_time( 'Y-m-d' ) && PGV_Vouchers::STATUS_ACTIVE === $v['status'] )
			|| PGV_Vouchers::STATUS_EXPIRED === $v['status'];
		return rest_ensure_response(
			array(
				'serial'      => $v['serial'],
				'unit'        => $v['unit_slug'],
				'amount'      => (int) $v['amount'],
				'status'      => $v['status'],
				'recipient'   => $v['recipient_name'],
				'valid_until' => $v['valid_until'],
				'redeemable'  => $redeemable,
				'expired'     => $expired,
				'is_legacy'   => (bool) $v['is_legacy'],
			)
		);
	}

	/**
	 * Beváltás sorszám alapján. Az API-kulcs egységéhez tartozó utalvány váltható be.
	 */
	public function redeem( WP_REST_Request $request ) {
		$serial = sanitize_text_field( (string) $request->get_param( 'serial' ) );
		if ( '' === $serial ) {
			return new WP_Error( 'pgv_no_serial', __( 'Hiányzó sorszám.', 'pomodoro-gift-vouchers' ), array( 'status' => 400 ) );
		}
		$v = PGV_Vouchers::get_by_serial_or_token( $serial );
		if ( ! $v ) {
			return new WP_Error( 'pgv_notfound', __( 'Nincs ilyen utalvány ennél az egységnél.', 'pomodoro-gift-vouchers' ), array( 'status' => 404 ) );
		}
		$result = PGV_Vouchers::redeem( (int) $v['id'], 'app' );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 409 ) );
		}
		return rest_ensure_response(
			array(
				'ok'     => true,
				'serial' => $result['serial'],
				'unit'   => $result['unit_slug'],
				'amount' => (int) $result['amount'],
				'status' => $result['status'],
			)
		);
	}

	/**
	 * API-kulcs ellenőrzés (fejléc vagy query).
	 */
	public function check_key( WP_REST_Request $request ) {
		$key = $request->get_header( 'x-api-key' );
		if ( ! $key ) {
			$auth = $request->get_header( 'authorization' );
			if ( $auth && preg_match( '/Bearer\s+(.+)/i', $auth, $m ) ) {
				$key = trim( $m[1] );
			}
		}
		if ( ! $key ) {
			$key = $request->get_param( 'api_key' );
		}
		if ( ! $key ) {
			return new WP_Error( 'pgv_no_key', __( 'Hiányzó API kulcs', 'pomodoro-gift-vouchers' ), array( 'status' => 401 ) );
		}

		$stored = get_option( 'pgv_api_key_hash' );
		if ( ! $stored || ! hash_equals( $stored, hash( 'sha256', $key ) ) ) {
			return new WP_Error( 'pgv_bad_key', __( 'Érvénytelen API kulcs', 'pomodoro-gift-vouchers' ), array( 'status' => 401 ) );
		}
		update_option( 'pgv_api_key_last_used', current_time( 'mysql' ), false );
		return true;
	}

	private function limit( $request ) {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 100;
		}
		return min( 500, $limit );
	}

	/**
	 * GET /vouchers — lapozott, updated_since / cursor szűréssel.
	 */
	public function get_vouchers( WP_REST_Request $request ) {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$unit  = PGV_Settings::unit_slug();
		$limit = $this->limit( $request );

		$where  = array( 'unit_slug = %s' );
		$params = array( $unit );

		$since  = $request->get_param( 'updated_since' );
		$cursor = $request->get_param( 'cursor' );
		$point  = $cursor ?: $since;
		if ( $point ) {
			$where[]  = 'updated_at > %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( $point ) );
		}

		$sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at ASC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $limit ) ) ), ARRAY_A ); // phpcs:ignore

		$data = array();
		foreach ( (array) $rows as $r ) {
			$data[] = array(
				'serial'         => $r['serial'],
				'unit'           => $r['unit_slug'],
				'amount'         => (int) $r['amount'],
				'status'         => $r['status'],
				'giver_name'     => $r['giver_name'],
				'recipient_name' => $r['recipient_name'],
				'delivery_email' => $r['delivery_email'],
				'buyer_email'    => $r['buyer_email'],
				'marketing_opt_in' => (bool) $r['marketing_opt_in'],
				'valid_from'     => $r['valid_from'],
				'valid_until'    => $r['valid_until'],
				'redeemed_at'    => $r['redeemed_at'],
				'is_legacy'      => (bool) $r['is_legacy'],
				'created_at'     => $r['created_at'],
				'updated_at'     => $r['updated_at'],
			);
		}
		$next = ( count( $data ) === $limit ) ? end( $data )['updated_at'] : null;
		return rest_ensure_response( array( 'data' => $data, 'next_cursor' => $next ) );
	}

	/**
	 * GET /orders — utalványokból aggregálva rendelésenként.
	 */
	public function get_orders( WP_REST_Request $request ) {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$unit  = PGV_Settings::unit_slug();
		$limit = $this->limit( $request );

		$where  = array( 'unit_slug = %s', 'order_id IS NOT NULL' );
		$params = array( $unit );

		$point = $request->get_param( 'cursor' ) ?: $request->get_param( 'updated_since' );
		if ( $point ) {
			$where[]  = 'updated_at > %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( $point ) );
		}

		$sql = "SELECT order_id,
			MIN(buyer_email) buyer_email,
			MIN(giver_name) giver_name,
			SUM(amount) amount,
			MAX(marketing_opt_in) marketing_opt_in,
			MIN(paid_at) paid_at,
			MIN(created_at) created_at,
			MAX(updated_at) updated_at
			FROM {$table} WHERE " . implode( ' AND ', $where ) . '
			GROUP BY order_id ORDER BY updated_at ASC LIMIT %d';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $limit ) ) ), ARRAY_A ); // phpcs:ignore

		$data = array();
		foreach ( (array) $rows as $r ) {
			$order = wc_get_order( (int) $r['order_id'] );
			$data[] = array(
				'order_ref'        => $order ? $order->get_order_number() : (string) $r['order_id'],
				'unit'             => $unit,
				'buyer_name'       => $r['giver_name'],
				'buyer_email'      => $r['buyer_email'],
				'amount'           => (int) $r['amount'],
				'status'           => $order ? $order->get_status() : 'unknown',
				'marketing_opt_in' => (bool) $r['marketing_opt_in'],
				'paid_at'          => $r['paid_at'],
				'created_at'       => $r['created_at'],
				'updated_at'       => $r['updated_at'],
			);
		}
		$next = ( count( $data ) === $limit ) ? end( $data )['updated_at'] : null;
		return rest_ensure_response( array( 'data' => $data, 'next_cursor' => $next ) );
	}

	/**
	 * GET /customers — vevők e-mail szerint aggregálva (szegmentáláshoz).
	 */
	public function get_customers( WP_REST_Request $request ) {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$unit  = PGV_Settings::unit_slug();

		$where  = array( 'unit_slug = %s', "buyer_email <> ''" );
		$params = array( $unit );

		$since = $request->get_param( 'updated_since' );
		if ( $since ) {
			$where[]  = 'updated_at > %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( $since ) );
		}

		$sql = "SELECT buyer_email email,
			MIN(giver_name) name,
			COUNT(DISTINCT order_id) orders,
			SUM(amount) total_spent,
			MAX(marketing_opt_in) marketing_opt_in,
			MIN(created_at) first_purchase,
			MAX(created_at) last_purchase
			FROM {$table} WHERE " . implode( ' AND ', $where ) . '
			GROUP BY buyer_email ORDER BY last_purchase DESC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore

		$data = array();
		foreach ( (array) $rows as $r ) {
			$data[] = array(
				'email'            => $r['email'],
				'name'             => $r['name'],
				'orders'           => (int) $r['orders'],
				'total_spent'      => (int) $r['total_spent'],
				'units'            => array( $unit ),
				'marketing_opt_in' => (bool) $r['marketing_opt_in'],
				'first_purchase'   => $r['first_purchase'],
				'last_purchase'    => $r['last_purchase'],
			);
		}
		return rest_ensure_response( array( 'data' => $data ) );
	}
}
