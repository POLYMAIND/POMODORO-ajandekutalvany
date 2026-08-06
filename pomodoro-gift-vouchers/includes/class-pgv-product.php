<?php
/**
 * Termék-oldal: mely WooCommerce termék minősül ajándékutalványnak.
 * (Egy store = egy egység; a termékek a címletek. Az ár a címlet összege,
 * a termék neve a címlet megnevezése, a termékkép a címlet képe — a témában.)
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Product {

	const META_IS_VOUCHER = '_pgv_is_voucher';

	public function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_field' ) );
	}

	/**
	 * Igaz, ha a termék ajándékutalvány.
	 */
	public static function is_voucher_product( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		}
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		return 'yes' === $product->get_meta( self::META_IS_VOUCHER );
	}

	public function render_field() {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_IS_VOUCHER,
				'label'       => __( 'Ajándékutalvány', 'pomodoro-gift-vouchers' ),
				'description' => __( 'A termékoldalon megjelenik a személyre szabás (kép, megajándékozott, üzenet, kézbesítés), fizetés után egyedi sorszámmal jön létre az utalvány.', 'pomodoro-gift-vouchers' ),
			)
		);
		echo '</div>';
	}

	public function save_field( $post_id ) {
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		$val = isset( $_POST[ self::META_IS_VOUCHER ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( self::META_IS_VOUCHER, $val );
		$product->save();
	}
}
