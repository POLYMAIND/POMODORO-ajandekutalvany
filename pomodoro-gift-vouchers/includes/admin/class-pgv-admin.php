<?php
/**
 * Admin felület: menü, beállítások, képkészlet, utalványlista + CSV export/import,
 * kasszás beváltás. Stílus: letisztult fehér kártyák, coral/tomato akcentus.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Admin {

	const CAP  = 'manage_woocommerce';
	const SLUG = 'pgv-vouchers';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		// A korábban plaintextben tárolt CRM API-kulcs eltávolítása (biztonság).
		add_action( 'admin_init', static function () {
			if ( false !== get_option( 'pgv_api_key', false ) ) {
				delete_option( 'pgv_api_key' );
			}
		} );

		// Export + PDF letöltés + AJAX beváltás/újraküldés.
		add_action( 'admin_post_pgv_export', array( $this, 'do_export' ) );
		add_action( 'admin_post_pgv_download_pdf', array( $this, 'do_download_pdf' ) );
		add_action( 'admin_post_pgv_test_email', array( $this, 'do_test_email' ) );
		add_action( 'wp_ajax_pgv_redeem', array( $this, 'ajax_redeem' ) );
		add_action( 'wp_ajax_pgv_resend', array( $this, 'ajax_resend' ) );
		add_action( 'wp_ajax_pgv_lookup', array( $this, 'ajax_lookup' ) );
	}

	public function menu() {
		$name = PGV_Settings::get( 'unit_name', 'Ajándékutalvány' );

		add_menu_page(
			__( 'Ajándékutalvány', 'pomodoro-gift-vouchers' ),
			__( 'Ajándékutalvány', 'pomodoro-gift-vouchers' ),
			self::CAP,
			self::SLUG,
			array( $this, 'page_vouchers' ),
			'dashicons-tickets-alt',
			56
		);
		add_submenu_page( self::SLUG, __( 'Utalványok', 'pomodoro-gift-vouchers' ), __( 'Utalványok', 'pomodoro-gift-vouchers' ), self::CAP, self::SLUG, array( $this, 'page_vouchers' ) );
		add_submenu_page( self::SLUG, __( 'Kassza — beváltás', 'pomodoro-gift-vouchers' ), __( 'Kassza', 'pomodoro-gift-vouchers' ), self::CAP, self::SLUG . '-cashier', array( $this, 'page_cashier' ) );
		add_submenu_page( self::SLUG, __( 'Képkészlet', 'pomodoro-gift-vouchers' ), __( 'Képkészlet', 'pomodoro-gift-vouchers' ), self::CAP, self::SLUG . '-images', array( $this, 'page_images' ) );
		add_submenu_page( self::SLUG, __( 'Import', 'pomodoro-gift-vouchers' ), __( 'Import', 'pomodoro-gift-vouchers' ), self::CAP, self::SLUG . '-import', array( $this, 'page_import' ) );
		add_submenu_page( self::SLUG, __( 'Beállítások', 'pomodoro-gift-vouchers' ), __( 'Beállítások', 'pomodoro-gift-vouchers' ), self::CAP, self::SLUG . '-settings', array( $this, 'page_settings' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'pgv-admin', PGV_PLUGIN_URL . 'assets/css/admin.css', array(), PGV_VERSION );
		wp_enqueue_script( 'pgv-admin', PGV_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), PGV_VERSION, true );
		wp_localize_script(
			'pgv-admin',
			'PGVAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pgv_admin' ),
				'i18n'    => array(
					'confirmRedeem' => __( 'Biztosan beváltod ezt az utalványt?', 'pomodoro-gift-vouchers' ),
					'pickImage'     => __( 'Kép kiválasztása', 'pomodoro-gift-vouchers' ),
				),
			)
		);
	}

	// ------------------------------------------------------------
	// POST műveletek (nonce-védve)
	// ------------------------------------------------------------
	public function handle_actions() {
		if ( ! current_user_can( self::CAP ) || empty( $_POST['pgv_action'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['pgv_action'] ) );

		switch ( $action ) {
			case 'save_settings':
				$this->save_settings();
				break;
			case 'add_image':
				$this->add_image();
				break;
			case 'update_images':
				$this->update_images();
				break;
			case 'import':
				$this->do_import();
				break;
			case 'regen_api_key':
				$this->regen_api_key();
				break;
			case 'save_cockpit':
				$this->save_cockpit();
				break;
			case 'sync_all':
				$this->sync_all();
				break;
			case 'edit_voucher':
				$this->save_voucher_edit();
				break;
			case 'regen_serial':
				$this->regen_serial();
				break;
		}
	}

	/**
	 * Utalvány-kód újragenerálása (teszteléshez). A belső sorszám nem változik.
	 */
	private function regen_serial() {
		check_admin_referer( 'pgv_regen_serial' );
		$in = wp_unslash( $_POST );
		$id = absint( $in['voucher_id'] ?? 0 );

		$res    = PGV_Vouchers::regenerate_serial( $id );
		$notice = is_wp_error( $res ) ? 'regen_error' : 'regen_ok';
		if ( is_wp_error( $res ) ) {
			set_transient( 'pgv_regen_msg', $res->get_error_message(), 60 );
		} else {
			set_transient( 'pgv_regen_msg', sprintf(
				/* translators: 1: régi kód, 2: új kód */
				__( '%1$s → %2$s', 'pomodoro-gift-vouchers' ),
				$res['old'],
				$res['new']
			), 60 );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::SLUG,
					's'          => sanitize_text_field( $in['ret_s'] ?? '' ),
					'status'     => sanitize_key( $in['ret_status'] ?? '' ),
					'paged'      => max( 1, (int) ( $in['ret_paged'] ?? 1 ) ),
					'pgv_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Egy utalvány szerkeszthető mezőinek mentése (opcionális újraküldéssel).
	 */
	private function save_voucher_edit() {
		check_admin_referer( 'pgv_edit_voucher' );
		$in = wp_unslash( $_POST );
		$id = absint( $in['voucher_id'] ?? 0 );

		$res = PGV_Vouchers::update_editable(
			$id,
			array(
				'recipient_name' => sanitize_text_field( $in['recipient_name'] ?? '' ),
				'giver_name'     => sanitize_text_field( $in['giver_name'] ?? '' ),
				'message'        => sanitize_textarea_field( $in['message'] ?? '' ),
				'delivery_email' => sanitize_email( $in['delivery_email'] ?? '' ),
			)
		);

		$notice = 'saved_voucher';
		if ( ! is_wp_error( $res ) && ! empty( $in['resend'] ) ) {
			$r      = PGV_Emails::resend( $id );
			$notice = is_wp_error( $r ) ? 'resend_error' : 'saved_resent';
		} elseif ( is_wp_error( $res ) ) {
			$notice = 'save_error';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::SLUG,
					's'          => sanitize_text_field( $in['ret_s'] ?? '' ),
					'status'     => sanitize_key( $in['ret_status'] ?? '' ),
					'paged'      => max( 1, (int) ( $in['ret_paged'] ?? 1 ) ),
					'pgv_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * A központi vezérlőpult (push) beállításai — külön űrlap, hogy a fő beállításokat ne írja felül.
	 */
	private function save_cockpit() {
		check_admin_referer( 'pgv_cockpit' );
		$in   = wp_unslash( $_POST );
		$data = array( 'cockpit_url' => esc_url_raw( trim( $in['cockpit_url'] ?? '' ) ) );
		// A titkot csak akkor frissítjük, ha ÚJ (nem üres, nem maszkolt) értéket kapunk.
		// Üres mező = változatlan (a maszkolt UI így nem írja felül a mentett titkot).
		$secret = sanitize_text_field( $in['cockpit_secret'] ?? '' );
		if ( '' !== $secret && false === strpos( $secret, '•' ) ) {
			$data['cockpit_secret'] = $secret;
		}
		PGV_Settings::save( $data );
		$this->redirect_with( self::SLUG . '-settings', 'saved' );
	}

	/**
	 * Az összes utalvány felküldése a központi vezérlőpultba (push, egyszeri teljes szinkron).
	 */
	private function sync_all() {
		check_admin_referer( 'pgv_sync_all' );
		if ( ! class_exists( 'PGV_Push' ) || ! PGV_Push::configured() ) {
			$this->redirect_with( self::SLUG . '-settings', 'sync_cfg' );
			return;
		}
		$res = PGV_Push::sync_all();
		set_transient( 'pgv_sync_result', $res, 60 );
		$this->redirect_with( self::SLUG . '-settings', empty( $res['errors'] ) ? 'sync_ok' : 'sync_partial' );
	}

	/**
	 * Új CRM API-kulcs generálása (a nyers kulcs csak egyszer jelenik meg).
	 */
	private function regen_api_key() {
		check_admin_referer( 'pgv_regen_key' );
		$raw = 'pk_' . bin2hex( random_bytes( 24 ) );
		update_option( 'pgv_api_key_hash', hash( 'sha256', $raw ) );
		update_option( 'pgv_api_key_preview', substr( $raw, 0, 10 ) . '…' );
		update_option( 'pgv_api_key', $raw ); // egyszeri megjelenítéshez
		delete_option( 'pgv_api_key_last_used' );
		$this->redirect_with( self::SLUG . '-settings', 'apikey' );
	}

	private function save_settings() {
		check_admin_referer( 'pgv_settings' );
		$in = wp_unslash( $_POST );

		PGV_Settings::save(
			array(
				'unit_slug'        => sanitize_key( $in['unit_slug'] ?? 'casa' ),
				'unit_name'        => sanitize_text_field( $in['unit_name'] ?? '' ),
				'serial_prefix'    => strtoupper( sanitize_text_field( $in['serial_prefix'] ?? '' ) ),
				'company_name'     => sanitize_text_field( $in['company_name'] ?? '' ),
				'tax_number'       => sanitize_text_field( $in['tax_number'] ?? '' ),
				'currency'         => sanitize_text_field( $in['currency'] ?? 'HUF' ),
				'validity_months'  => max( 1, (int) ( $in['validity_months'] ?? 12 ) ),
				'active'           => empty( $in['active'] ) ? 0 : 1,
				'logo_attachment_id' => absint( $in['logo_attachment_id'] ?? 0 ),
				'from_email'       => sanitize_email( $in['from_email'] ?? '' ),
				'from_name'        => sanitize_text_field( $in['from_name'] ?? '' ),
				'marketing_label'  => sanitize_text_field( $in['marketing_label'] ?? '' ),
				'corporate_warn'   => empty( $in['corporate_warn'] ) ? 0 : 1,
				'corporate_block'  => empty( $in['corporate_block'] ) ? 0 : 1,
				'serial_format'    => in_array( $in['serial_format'] ?? 'random', array( 'random', 'sequential' ), true ) ? $in['serial_format'] : 'random',
				'delivery_default' => in_array( $in['delivery_default'] ?? 'recipient', array( 'recipient', 'buyer' ), true ) ? $in['delivery_default'] : 'recipient',

				// E-mail sablon.
				'email_accent'            => sanitize_hex_color( $in['email_accent'] ?? '#1f1f1f' ) ?: '#1f1f1f',
				'email_heading'           => sanitize_text_field( $in['email_heading'] ?? '' ),
				'email_subject_recipient' => sanitize_text_field( $in['email_subject_recipient'] ?? '' ),
				'email_subject_buyer'     => sanitize_text_field( $in['email_subject_buyer'] ?? '' ),
				'email_intro_recipient'   => sanitize_textarea_field( $in['email_intro_recipient'] ?? '' ),
				'email_intro_buyer'       => sanitize_textarea_field( $in['email_intro_buyer'] ?? '' ),
				'email_footer'            => sanitize_text_field( $in['email_footer'] ?? '' ),
				'email_show_image'        => empty( $in['email_show_image'] ) ? 0 : 1,
				'suppress_wc_emails'      => empty( $in['suppress_wc_emails'] ) ? 0 : 1,
				'autocomplete_orders'     => empty( $in['autocomplete_orders'] ) ? 0 : 1,
				'hide_express_on_product' => empty( $in['hide_express_on_product'] ) ? 0 : 1,
				'preview_position'        => in_array( $in['preview_position'] ?? 'gallery', array( 'gallery', 'fields', 'off' ), true ) ? $in['preview_position'] : 'gallery',
			)
		);

		// A nyers CRM API-kulcsot az első mentés után eldobjuk — csak a hash marad.
		if ( get_option( 'pgv_api_key' ) ) {
			delete_option( 'pgv_api_key' );
		}

		$this->redirect_with( self::SLUG . '-settings', 'saved' );
	}

	private function add_image() {
		check_admin_referer( 'pgv_images' );
		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		$title         = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		if ( $attachment_id ) {
			PGV_Images::add( $attachment_id, $title );
		}
		$this->redirect_with( self::SLUG . '-images', 'image_added' );
	}

	private function update_images() {
		check_admin_referer( 'pgv_images' );
		$titles = isset( $_POST['image_title'] ) ? (array) wp_unslash( $_POST['image_title'] ) : array();
		$active = isset( $_POST['image_active'] ) ? array_map( 'absint', (array) $_POST['image_active'] ) : array();

		foreach ( $titles as $id => $title ) {
			PGV_Images::rename( (int) $id, sanitize_text_field( $title ) );
			PGV_Images::set_active( (int) $id, in_array( (int) $id, $active, true ) );
		}
		$this->redirect_with( self::SLUG . '-images', 'images_updated' );
	}

	private function do_import() {
		check_admin_referer( 'pgv_import' );
		if ( empty( $_FILES['pgv_csv']['tmp_name'] ) ) {
			$this->redirect_with( self::SLUG . '-import', 'import_error' );
			return;
		}
		$result = PGV_Export::import_file( $_FILES['pgv_csv']['tmp_name'] );
		set_transient( 'pgv_import_result', $result, 60 );
		$this->redirect_with( self::SLUG . '-import', 'imported' );
	}

	public function do_export() {
		if ( ! current_user_can( self::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pgv_export' ) ) {
			wp_die( esc_html__( 'Érvénytelen kérés.', 'pomodoro-gift-vouchers' ) );
		}
		$filters  = array(
			's'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
		);
		$vouchers = $this->query_vouchers( $filters, 0, 100000 );
		PGV_Export::download( $vouchers['rows'] );
	}

	/**
	 * Egy utalvány PDF-jének letöltése (frissen generálva).
	 */
	public function do_download_pdf() {
		if ( ! current_user_can( self::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pgv_pdf' ) ) {
			wp_die( esc_html__( 'Érvénytelen kérés.', 'pomodoro-gift-vouchers' ) );
		}
		$id = absint( $_GET['id'] ?? 0 );
		$v  = PGV_Vouchers::get( $id );
		if ( ! $v ) {
			wp_die( esc_html__( 'Nincs ilyen utalvány.', 'pomodoro-gift-vouchers' ) );
		}
		PGV_Voucher_PDF::stream( $v );
	}

	/**
	 * Teszt e-mail küldése a bejelentkezett felhasználónak (a jelenlegi sablonnal).
	 */
	public function do_test_email() {
		if ( ! current_user_can( self::CAP ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'pgv_test_email' ) ) {
			wp_die( esc_html__( 'Érvénytelen kérés.', 'pomodoro-gift-vouchers' ) );
		}
		$user = wp_get_current_user();
		$to   = $user ? $user->user_email : '';
		$res  = is_email( $to ) ? PGV_Emails::send_test( $to ) : new WP_Error( 'pgv_noemail', __( 'Nincs érvényes e-mail cím a fiókodhoz.', 'pomodoro-gift-vouchers' ) );

		$notice = is_wp_error( $res ) ? 'test_error' : 'test_sent';
		$this->redirect_with( self::SLUG . '-settings', $notice );
	}

	/**
	 * PDF letöltő URL egy utalványhoz (nonce-olt).
	 */
	public static function pdf_url( $voucher_id ) {
		return wp_nonce_url(
			add_query_arg(
				array( 'action' => 'pgv_download_pdf', 'id' => (int) $voucher_id ),
				admin_url( 'admin-post.php' )
			),
			'pgv_pdf'
		);
	}

	// ------------------------------------------------------------
	// AJAX
	// ------------------------------------------------------------
	public function ajax_redeem() {
		check_ajax_referer( 'pgv_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultság.', 'pomodoro-gift-vouchers' ) ) );
		}
		$id     = absint( $_POST['id'] ?? 0 );
		$result = PGV_Vouchers::redeem( $id, PGV_Vouchers::actor() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'message' => __( 'Beváltva.', 'pomodoro-gift-vouchers' ),
				'status'  => PGV_Vouchers::status_label( $result['status'] ),
			)
		);
	}

	public function ajax_resend() {
		check_ajax_referer( 'pgv_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultság.', 'pomodoro-gift-vouchers' ) ) );
		}
		$id     = absint( $_POST['id'] ?? 0 );
		$result = PGV_Emails::resend( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'E-mail újraküldve.', 'pomodoro-gift-vouchers' ) ) );
	}

	public function ajax_lookup() {
		check_ajax_referer( 'pgv_admin', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultság.', 'pomodoro-gift-vouchers' ) ) );
		}
		$needle = sanitize_text_field( wp_unslash( $_POST['needle'] ?? '' ) );
		$v      = PGV_Vouchers::get_by_serial_or_token( $needle );
		if ( ! $v ) {
			wp_send_json_error( array( 'message' => __( 'Nincs találat erre a sorszámra/QR-re.', 'pomodoro-gift-vouchers' ) ) );
		}
		$redeemable = ( PGV_Vouchers::STATUS_ACTIVE === $v['status'] )
			&& ( empty( $v['valid_until'] ) || $v['valid_until'] >= current_time( 'Y-m-d' ) );

		wp_send_json_success(
			array(
				'id'           => (int) $v['id'],
				'serial'       => $v['serial'],
				'amount'       => number_format_i18n( (int) $v['amount'] ) . ' Ft',
				'status'       => PGV_Vouchers::status_label( $v['status'] ),
				'status_key'   => $v['status'],
				'recipient'    => $v['recipient_name'],
				'valid_until'  => $v['valid_until'],
				'redeemable'   => $redeemable,
				'is_legacy'    => (bool) $v['is_legacy'],
			)
		);
	}

	// ------------------------------------------------------------
	// Segéd: lista-lekérdezés szűréssel
	// ------------------------------------------------------------
	private function query_vouchers( array $filters, $offset = 0, $per_page = 20 ) {
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$unit  = PGV_Settings::unit_slug();

		$where  = array( 'unit_slug = %s' );
		$params = array( $unit );

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['s'] ) ) {
			$like     = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
			$where[]  = '(serial LIKE %s OR recipient_name LIKE %s OR giver_name LIKE %s OR buyer_email LIKE %s OR delivery_email LIKE %s)';
			array_push( $params, $like, $like, $like, $like, $like );
		}
		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) // phpcs:ignore
		);

		$sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( $params, array( (int) $per_page, (int) $offset ) ) ), // phpcs:ignore
			ARRAY_A
		);

		return array( 'rows' => (array) $rows, 'total' => $total );
	}

	private function redirect_with( $page, $notice ) {
		wp_safe_redirect( add_query_arg( array( 'page' => $page, 'pgv_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * A legutóbbi teljes szinkron eredménye (átmeneti üzenet).
	 */
	private function sync_notice_text() {
		$res = get_transient( 'pgv_sync_result' );
		if ( ! is_array( $res ) ) {
			return __( 'Szinkron kész.', 'pomodoro-gift-vouchers' );
		}
		delete_transient( 'pgv_sync_result' );
		$sent = (int) ( $res['sent'] ?? 0 );
		if ( ! empty( $res['errors'] ) ) {
			return sprintf(
				/* translators: 1: felküldött darabszám, 2: hibaüzenetek */
				__( 'Felküldve: %1$d. Hiba: %2$s', 'pomodoro-gift-vouchers' ),
				$sent,
				implode( ' | ', array_map( 'wp_strip_all_tags', (array) $res['errors'] ) )
			);
		}
		return sprintf(
			/* translators: %d: felküldött utalványok száma */
			__( 'Teljes szinkron kész — %d utalvány felküldve a vezérlőpultba.', 'pomodoro-gift-vouchers' ),
			$sent
		);
	}

	private function notice() {
		if ( empty( $_GET['pgv_notice'] ) ) {
			return;
		}
		$key = sanitize_key( wp_unslash( $_GET['pgv_notice'] ) );

		// A szinkron-üzenetet csak a tényleges kulcshoz számoljuk ki (a tranzienst
		// egyszer olvassuk/töröljük), különben elveszne a hibaüzenet.
		if ( 'sync_ok' === $key || 'sync_partial' === $key ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				'sync_ok' === $key ? 'success' : 'error',
				esc_html( $this->sync_notice_text() )
			);
			return;
		}

		// A kód-csere üzenetébe beletesszük a régi és az új kódot.
		if ( 'regen_ok' === $key || 'regen_error' === $key ) {
			$detail = get_transient( 'pgv_regen_msg' );
			delete_transient( 'pgv_regen_msg' );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s%s</p></div>',
				'regen_ok' === $key ? 'success' : 'error',
				'regen_ok' === $key
					? esc_html__( 'Új utalvány-kód generálva: ', 'pomodoro-gift-vouchers' )
					: esc_html__( 'A kód cseréje nem sikerült: ', 'pomodoro-gift-vouchers' ),
				esc_html( (string) $detail )
			);
			return;
		}

		$map = array(
			'saved'          => __( 'Beállítások mentve.', 'pomodoro-gift-vouchers' ),
			'image_added'    => __( 'Kép hozzáadva a készlethez.', 'pomodoro-gift-vouchers' ),
			'images_updated' => __( 'Képek frissítve.', 'pomodoro-gift-vouchers' ),
			'imported'       => __( 'Import kész.', 'pomodoro-gift-vouchers' ),
			'import_error'   => __( 'Import hiba: nincs feltöltött fájl.', 'pomodoro-gift-vouchers' ),
			'apikey'         => __( 'Új API-kulcs generálva. Másold ki most — később már csak a hash marad!', 'pomodoro-gift-vouchers' ),
			'test_sent'      => __( 'Teszt e-mail elküldve a fiókod címére.', 'pomodoro-gift-vouchers' ),
			'test_error'     => __( 'A teszt e-mail küldése nem sikerült (ellenőrizd a levélküldést / feladó címet).', 'pomodoro-gift-vouchers' ),
			'sync_cfg'       => __( 'A vezérlőpult URL és/vagy titok nincs beállítva — előbb mentsd őket.', 'pomodoro-gift-vouchers' ),
			'saved_voucher'  => __( 'Utalvány mentve.', 'pomodoro-gift-vouchers' ),
			'saved_resent'   => __( 'Utalvány mentve, és a javított PDF újraküldve e-mailben.', 'pomodoro-gift-vouchers' ),
			'resend_error'   => __( 'Utalvány mentve, de az e-mail újraküldése nem sikerült.', 'pomodoro-gift-vouchers' ),
			'save_error'     => __( 'Az utalvány mentése nem sikerült.', 'pomodoro-gift-vouchers' ),
		);
		if ( isset( $map[ $key ] ) ) {
			$type = in_array( $key, array( 'import_error', 'test_error', 'sync_cfg', 'resend_error', 'save_error' ), true ) ? 'error' : 'success';
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $map[ $key ] ) );
		}
	}

	// ------------------------------------------------------------
	// Oldalak (a külön fájlokban lévő renderelőket hívják)
	// ------------------------------------------------------------
	public function page_vouchers() {
		$this->notice();
		require PGV_PLUGIN_DIR . 'includes/admin/views/vouchers.php';
	}

	public function page_cashier() {
		$this->notice();
		require PGV_PLUGIN_DIR . 'includes/admin/views/cashier.php';
	}

	public function page_images() {
		$this->notice();
		require PGV_PLUGIN_DIR . 'includes/admin/views/images.php';
	}

	public function page_import() {
		$this->notice();
		require PGV_PLUGIN_DIR . 'includes/admin/views/import.php';
	}

	public function page_settings() {
		$this->notice();
		require PGV_PLUGIN_DIR . 'includes/admin/views/settings.php';
	}

	/**
	 * A nézeteknek publikus wrapper a lekérdezéshez.
	 */
	public function get_vouchers( array $filters, $offset, $per_page ) {
		return $this->query_vouchers( $filters, $offset, $per_page );
	}
}
