<?php
/**
 * Checkout: marketing-hozzájárulás + cégnév/adószám szerveroldali ellenőrzés.
 * (A kliensoldali, élő figyelmeztetést a frontend.js adja.)
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Checkout {

	public function __construct() {
		add_action( 'woocommerce_after_order_notes', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Csak akkor jelenjen meg, ha van utalvány a kosárban.
	 */
	private function cart_has_voucher() {
		if ( ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['pgv'] ) ) {
				return true;
			}
		}
		return false;
	}

	public function render_fields( $checkout ) {
		if ( ! $this->cart_has_voucher() ) {
			return;
		}

		echo '<div class="pgv-checkout-fields">';

		woocommerce_form_field(
			'pgv_marketing_opt_in',
			array(
				'type'  => 'checkbox',
				'class' => array( 'pgv-marketing form-row-wide' ),
				'label' => PGV_Settings::get( 'marketing_label', __( 'Szeretnék értesülni akciókról, újdonságokról.', 'pomodoro-gift-vouchers' ) ),
			),
			$checkout ? $checkout->get_value( 'pgv_marketing_opt_in' ) : ''
		);

		echo '</div>';

		wp_nonce_field( 'pgv_checkout', 'pgv_checkout_nonce' );
	}

	/**
	 * Szerveroldali cégnév/adószám ellenőrzés.
	 */
	public function validate() {
		if ( ! $this->cart_has_voucher() ) {
			return;
		}

		$parts = array(
			isset( $_POST['billing_company'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_company'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '',
			isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '',
		);

		// A kosárban lévő megajándékozott-nevek is számítanak.
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( isset( $item['pgv']['recipient'] ) ) {
					$parts[] = $item['pgv']['recipient'];
				}
			}
		}

		if ( PGV_Corporate::looks_corporate( ...$parts ) ) {
			if ( PGV_Settings::get( 'corporate_block', 0 ) ) {
				wc_add_notice(
					__( 'Céges nevet vagy adószámot észleltünk. Áfás számla ezen a felületen nem igényelhető; kérjük, magánszemélyként add meg az adatokat.', 'pomodoro-gift-vouchers' ),
					'error'
				);
			}
			// A jelölést a rendelésre az adatok mentésekor tesszük rá (lásd save()).
			WC()->session->set( 'pgv_corporate_flagged', 1 );
		} else {
			WC()->session->set( 'pgv_corporate_flagged', 0 );
		}
	}

	/**
	 * Marketing-hozzájárulás + céges jelölés mentése a rendelésre.
	 */
	public function save( $order, $data ) {
		if ( ! $this->cart_has_voucher() ) {
			return;
		}

		$opt_in = isset( $_POST['pgv_marketing_opt_in'] ) && '1' === (string) wp_unslash( $_POST['pgv_marketing_opt_in'] ); // phpcs:ignore WordPress.Security.NonceVerification

		// Belső meta + REST-ben látható meta (a CRM-nek az aláhúzás nélküli kulcs jön).
		$order->update_meta_data( '_pgv_marketing_opt_in', $opt_in ? 'yes' : 'no' );
		$order->update_meta_data( 'marketing_opt_in', $opt_in ? 'yes' : 'no' );

		$flagged = WC()->session ? (int) WC()->session->get( 'pgv_corporate_flagged', 0 ) : 0;
		$order->update_meta_data( '_pgv_corporate_flagged', $flagged ? 'yes' : 'no' );
	}
}
