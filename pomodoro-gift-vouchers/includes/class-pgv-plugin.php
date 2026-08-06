<?php
/**
 * Fő orchestrátor — az összes komponens betöltése és példányosítása.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

final class PGV_Plugin {

	/** @var PGV_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->includes();
		$this->init();
	}

	private function includes() {
		$dir = PGV_PLUGIN_DIR . 'includes/';

		require_once $dir . 'class-pgv-install.php';
		require_once $dir . 'class-pgv-settings.php';
		require_once $dir . 'class-pgv-corporate.php';
		require_once $dir . 'class-pgv-images.php';
		require_once $dir . 'class-pgv-vouchers.php';
		require_once $dir . 'class-pgv-product.php';
		require_once $dir . 'class-pgv-cart.php';
		require_once $dir . 'class-pgv-checkout.php';
		require_once $dir . 'class-pgv-blocks.php';
		require_once $dir . 'class-pgv-order.php';
		require_once $dir . 'class-pgv-emails.php';
		require_once $dir . 'class-pgv-export.php';
		require_once $dir . 'class-pgv-rest.php';
		require_once $dir . 'lib/class-pgv-qr.php';
		require_once $dir . 'class-pgv-pdf.php';
		require_once $dir . 'class-pgv-voucher-pdf.php';

		if ( is_admin() ) {
			require_once $dir . 'admin/class-pgv-admin.php';
		}
	}

	private function init() {
		// Adatbázis-verzió ellenőrzés (olcsó no-op, ha naprakész).
		add_action( 'init', array( 'PGV_Install', 'maybe_upgrade' ) );

		// Fordítások.
		add_action(
			'init',
			static function () {
				load_plugin_textdomain( 'pomodoro-gift-vouchers', false, dirname( PGV_PLUGIN_BASENAME ) . '/languages' );
			}
		);

		// Komponensek.
		new PGV_Product();
		new PGV_Cart();
		new PGV_Checkout();
		new PGV_Blocks();
		new PGV_Order();
		new PGV_Emails();
		new PGV_REST();

		if ( is_admin() ) {
			new PGV_Admin();
		}

		// Gyors beállítás-link a plugin listában.
		add_filter( 'plugin_action_links_' . PGV_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . PGV_Admin::SLUG . '-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Beállítások', 'pomodoro-gift-vouchers' ) . '</a>' );
		return $links;
	}
}
