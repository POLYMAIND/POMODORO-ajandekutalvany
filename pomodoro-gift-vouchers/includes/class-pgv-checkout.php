<?php
/**
 * Checkout — cégnév/adószám szerveroldali ellenőrzés (klasszikus + blokk),
 * valamint a marketing-hozzájárulás a régi (klasszikus) checkouton.
 *
 * A modern (blokk) checkout marketing-mezőjét és a Store API integrációt a
 * PGV_Blocks kezeli (Additional Checkout Fields API). Ez az osztály a
 * cégnév-ellenőrzést mindkét folyamatra megadja, a marketing-checkboxot pedig
 * csak akkor rendereli klasszikusan, ha az Additional Fields API nem érhető el.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Checkout {

	public function __construct() {
		// Cégnév/adószám — klasszikus checkout.
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'flag_corporate_classic' ), 10, 2 );

		// Cégnév/adószám — blokk checkout (Store API).
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'corporate_block' ), 10, 2 );

		// Marketing — csak a régi WC-n (klasszikus), ha nincs Additional Fields API.
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			add_action( 'woocommerce_after_order_notes', array( $this, 'render_marketing_classic' ) );
			add_action( 'woocommerce_checkout_create_order', array( $this, 'save_marketing_classic' ), 10, 2 );
		}
	}

	/**
	 * Van-e utalvány a kosárban?
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

	/**
	 * Rendelésben van-e utalvány-tétel?
	 */
	private function order_has_voucher( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && $item->get_meta( '_pgv_delivery' ) ) {
				return true;
			}
			if ( $item instanceof WC_Order_Item_Product && PGV_Product::is_voucher_product( $item->get_product() ) ) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------
	// Marketing (klasszikus, régi WC)
	// ------------------------------------------------------------
	public function render_marketing_classic( $checkout ) {
		if ( ! $this->cart_has_voucher() ) {
			return;
		}
		woocommerce_form_field(
			'pgv_marketing_opt_in',
			array(
				'type'  => 'checkbox',
				'class' => array( 'pgv-marketing form-row-wide' ),
				'label' => PGV_Settings::get( 'marketing_label', __( 'Szeretnék értesülni akciókról, újdonságokról.', 'pomodoro-gift-vouchers' ) ),
			),
			$checkout ? $checkout->get_value( 'pgv_marketing_opt_in' ) : ''
		);
		wp_nonce_field( 'pgv_checkout', 'pgv_checkout_nonce' );
	}

	public function save_marketing_classic( $order, $data ) {
		if ( ! $this->order_has_voucher( $order ) && ! $this->cart_has_voucher() ) {
			return;
		}
		$opt_in = isset( $_POST['pgv_marketing_opt_in'] ) && '1' === (string) wp_unslash( $_POST['pgv_marketing_opt_in'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$order->update_meta_data( '_pgv_marketing_opt_in', $opt_in ? 'yes' : 'no' );
		$order->update_meta_data( 'marketing_opt_in', $opt_in ? 'yes' : 'no' );
	}

	// ------------------------------------------------------------
	// Cégnév / adószám — klasszikus
	// ------------------------------------------------------------
	public function validate_classic() {
		if ( ! $this->cart_has_voucher() ) {
			return;
		}
		$parts = array(
			isset( $_POST['billing_company'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_company'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '',
			isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '',
		);
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item['pgv']['recipient'] ) ) {
				$parts[] = $item['pgv']['recipient'];
			}
		}

		$flagged = PGV_Corporate::looks_corporate( ...$parts );
		WC()->session->set( 'pgv_corporate_flagged', $flagged ? 1 : 0 );

		if ( $flagged && PGV_Settings::get( 'corporate_block', 0 ) ) {
			wc_add_notice(
				__( 'Céges nevet vagy adószámot észleltünk. Áfás számla ezen a felületen nem igényelhető; kérjük, magánszemélyként add meg az adatokat.', 'pomodoro-gift-vouchers' ),
				'error'
			);
		}
	}

	public function flag_corporate_classic( $order, $data ) {
		if ( ! WC()->session ) {
			return;
		}
		$flagged = (int) WC()->session->get( 'pgv_corporate_flagged', 0 );
		$order->update_meta_data( '_pgv_corporate_flagged', $flagged ? 'yes' : 'no' );
	}

	// ------------------------------------------------------------
	// Cégnév / adószám — blokk (Store API)
	// ------------------------------------------------------------
	public function corporate_block( $order, $request ) {
		if ( ! $this->order_has_voucher( $order ) ) {
			return;
		}
		$parts = array(
			$order->get_billing_company(),
			$order->get_billing_first_name(),
			$order->get_billing_last_name(),
		);
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$rec = $item->get_meta( '_pgv_recipient' );
				if ( $rec ) {
					$parts[] = $rec;
				}
			}
		}

		$flagged = PGV_Corporate::looks_corporate( ...$parts );
		$order->update_meta_data( '_pgv_corporate_flagged', $flagged ? 'yes' : 'no' );

		if ( $flagged && PGV_Settings::get( 'corporate_block', 0 ) && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'pgv_corporate',
				esc_html__( 'Céges nevet vagy adószámot észleltünk. Áfás számla ezen a felületen nem igényelhető; kérjük, magánszemélyként add meg az adatokat.', 'pomodoro-gift-vouchers' ),
				400
			);
		}
	}
}
