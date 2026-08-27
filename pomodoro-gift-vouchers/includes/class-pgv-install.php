<?php
/**
 * Telepítés / adatbázis-séma.
 *
 * A referencia adatmodell (supabase/migrations/001_init_schema.sql) WooCommerce-re
 * leképezve: az egyedi utalványok, az audit napló, a hézagmentes sorszám-számláló és
 * az elnevezett képkészlet egyedi táblákban élnek; a vevő/rendelés adatai a Woo
 * rendelésben (HPOS) maradnak.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Install {

	const DB_VERSION_OPTION = 'pgv_db_version';
	const DB_VERSION        = '1.0.0';

	/**
	 * Táblanevek.
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'vouchers' => $wpdb->prefix . 'pgv_vouchers',
			'audit'    => $wpdb->prefix . 'pgv_audit',
			'serial'   => $wpdb->prefix . 'pgv_serial_counter',
			'images'   => $wpdb->prefix . 'pgv_images',
		);
	}

	public static function table( $key ) {
		$t = self::tables();
		return isset( $t[ $key ] ) ? $t[ $key ] : '';
	}

	/**
	 * Aktiváláskor és verzióváltáskor futtatandó.
	 */
	public static function install() {
		self::create_tables();
		self::seed_default_settings();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Verzió-ellenőrzés minden betöltéskor (olcsó), migráció ha kell.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$t               = self::tables();

		// ---- Egyedi utalványok (szigorú számadású egység) ----
		$sql_vouchers = "CREATE TABLE {$t['vouchers']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			unit_slug VARCHAR(64) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NULL,
			order_item_id BIGINT UNSIGNED NULL,
			serial VARCHAR(64) NOT NULL,
			is_legacy TINYINT(1) NOT NULL DEFAULT 0,
			amount BIGINT NOT NULL DEFAULT 0,
			denomination_label VARCHAR(191) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			giver_name VARCHAR(191) NULL,
			recipient_name VARCHAR(191) NULL,
			message TEXT NULL,
			image_id BIGINT UNSIGNED NULL,
			image_name VARCHAR(191) NULL,
			delivery_method VARCHAR(20) NOT NULL DEFAULT 'buyer',
			delivery_email VARCHAR(191) NULL,
			buyer_email VARCHAR(191) NULL,
			marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
			qr_token VARCHAR(64) NULL,
			valid_from DATE NULL,
			valid_until DATE NULL,
			paid_at DATETIME NULL,
			redeemed_at DATETIME NULL,
			redeemed_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY serial_unit (unit_slug, serial),
			UNIQUE KEY qr_token (qr_token),
			KEY status (unit_slug, status),
			KEY order_id (order_id),
			KEY recipient_name (recipient_name),
			KEY buyer_email (buyer_email),
			KEY updated_at (updated_at)
		) $charset_collate;";

		// ---- Audit napló (törlés sehol; minden státuszváltás naplózva) ----
		$sql_audit = "CREATE TABLE {$t['audit']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			unit_slug VARCHAR(64) NOT NULL DEFAULT '',
			voucher_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(20) NOT NULL,
			from_status VARCHAR(20) NULL,
			to_status VARCHAR(20) NULL,
			actor VARCHAR(191) NOT NULL DEFAULT 'system',
			detail LONGTEXT NULL,
			occurred_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY voucher_id (voucher_id),
			KEY unit_time (unit_slug, occurred_at)
		) $charset_collate;";

		// ---- Hézagmentes per-unit + év sorszám-számláló ----
		$sql_serial = "CREATE TABLE {$t['serial']} (
			unit_slug VARCHAR(64) NOT NULL,
			year SMALLINT NOT NULL,
			last_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (unit_slug, year)
		) $charset_collate;";

		// ---- Elnevezett képkészlet egységenként ----
		$sql_images = "CREATE TABLE {$t['images']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			unit_slug VARCHAR(64) NOT NULL DEFAULT '',
			attachment_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(191) NULL,
			sort_order INT NOT NULL DEFAULT 0,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY unit_slug (unit_slug, active)
		) $charset_collate;";

		dbDelta( $sql_vouchers );
		dbDelta( $sql_audit );
		dbDelta( $sql_serial );
		dbDelta( $sql_images );
	}

	/**
	 * Alap egység-beállítások + CRM API-kulcs, ha még nincs.
	 */
	private static function seed_default_settings() {
		if ( false === get_option( 'pgv_settings', false ) ) {
			add_option( 'pgv_settings', PGV_Settings_Defaults() );
		}
		// Megj.: a korábbi CRM olvasó API-kulcsot már nem hozzuk létre (a vezérlőpult
		// push-szinkronnal dolgozik). A plaintext kulcs sehol nem tárolódik.
	}
}

/**
 * Alap beállítások (segédfüggvény, hogy a telepítő és a Settings osztály is elérje).
 */
function PGV_Settings_Defaults() {
	return array(
		'unit_slug'           => 'casa',
		'unit_name'           => "Casa Pomo d'Oro",
		'serial_prefix'       => 'CASA',
		'company_name'        => '',
		'tax_number'          => '',
		'currency'            => 'HUF',
		'validity_months'     => 12,
		'active'              => 1,
		'logo_attachment_id'  => 0,
		'from_email'          => '',
		'from_name'           => "Casa Pomo d'Oro",
		'marketing_label'     => 'Szeretnék értesülni akciókról, újdonságokról.',
		'corporate_warn'      => 1,
		'corporate_block'     => 0,
		'gapless_serial'      => 1,
		'delivery_default'    => 'recipient',

		// E-mail sablon (szerkeszthető az adminból).
		'email_accent'            => '#1f1f1f',
		'email_heading'           => 'Ajándékutalvány',
		'email_subject_recipient' => 'Ajándékutalványt kaptál!',
		'email_subject_buyer'     => 'Köszönjük a vásárlást — {egyseg}',
		'email_intro_recipient'   => "Kedves {megajandekozott}!\n\nAjándékutalványt kaptál a(z) {egyseg} egységbe. Az utalványt PDF-ben mellékeltük, helyben beváltható.\n\n{uzenet}",
		'email_intro_buyer'       => "Kedves {vasarlo}!\n\nKöszönjük a vásárlást! Az alábbi ajándékutalvány(oka)t bocsátottuk ki, a PDF(ek) mellékelve.",
		'email_footer'            => '{egyseg}',
		'email_show_image'        => 1,

		// A WooCommerce saját rendelés-visszaigazolójának elnémítása tiszta utalvány-rendelésnél.
		'suppress_wc_emails'      => 1,

		// Fizetés után a rendelés automatikus „Teljesítve” állapotba tétele
		// (tiszta utalvány-rendelésnél nincs mit csomagolni/szállítani).
		'autocomplete_orders'     => 0,

		// Az élő előnézet helye a termékoldalon:
		// 'gallery' = a termékkép helyén (nagy), 'fields' = a mezők fölött, 'off' = nincs.
		'preview_position'        => 'gallery',

		// Az Apple Pay / Google Pay gyorsfizetés-gombok elrejtése az utalvány
		// termékoldalán: megkerülnék a személyre szabó űrlapot.
		'hide_express_on_product' => 1,

		// Központi vezérlőpult (push szinkron): minden mentett utalvány kimenő
		// HTTP-hívással felkerül a vezérlőpult /api/ingest végpontjára.
		'cockpit_url'             => '',
		'cockpit_secret'          => '',
	);
}
