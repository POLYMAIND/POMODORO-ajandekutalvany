<?php
/**
 * Plugin Name:       Pomo d'Oro — Ajándékutalvány
 * Plugin URI:        https://polymaind.hu/
 * Description:        Ajándékutalványok értékesítése WooCommerce termékként: személyre szabás a termék-/kosároldalon, egyedi sorszám a sikeres fizetés után, szigorú számadású audit napló, kasszás beváltás, NAV-formátumú CSV export/import és CRM olvasó API. Egységenként (Casa / Osteria / Pizzabar / Trattoria) külön store-ra telepítendő.
 * Version:           1.4.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Polymaind
 * Author URI:        https://polymaind.hu/
 * Text Domain:       pomodoro-gift-vouchers
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

define( 'PGV_VERSION', '1.4.0' );
define( 'PGV_PLUGIN_FILE', __FILE__ );
define( 'PGV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PGV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PGV_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * HPOS (High-Performance Order Storage) kompatibilitás jelzése.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			// HPOS (custom order tables): a plugin CRUD-on keresztül dolgozik, kompatibilis.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			// Kosár/checkout blokkok: a marketing-mező az Additional Checkout Fields
			// API-val, a cégnév-ellenőrzés a Store API-val — kompatibilis.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Aktiváláskor: táblák létrehozása, alap opciók, rewrite flush.
 */
function pgv_activate() {
	require_once PGV_PLUGIN_DIR . 'includes/class-pgv-install.php';
	PGV_Install::install();
}
register_activation_hook( __FILE__, 'pgv_activate' );

/**
 * Bootstrap — csak akkor, ha a WooCommerce aktív.
 */
function pgv_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p><strong>Pomo d\'Oro Ajándékutalvány</strong>: a plugin működéséhez a <strong>WooCommerce</strong> szükséges.</p></div>';
			}
		);
		return;
	}

	require_once PGV_PLUGIN_DIR . 'includes/class-pgv-plugin.php';
	PGV_Plugin::instance();
}
add_action( 'plugins_loaded', 'pgv_bootstrap' );
