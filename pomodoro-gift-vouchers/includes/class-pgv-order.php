<?php
/**
 * Rendelés-kezelés: az egyedi utalványok (sorszámmal) a SIKERES FIZETÉS után
 * jönnek létre — soha nem a kliens-redirectből. Sztornó/visszatérítés → cancelled.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Order {

	const ORDER_ISSUED_META = '_pgv_vouchers_issued';

	public function __construct() {
		// A sorszám a fizetés visszaigazolásakor születik.
		add_action( 'woocommerce_payment_complete', array( $this, 'issue_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'issue_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'issue_for_order' ), 10, 1 );

		// Sztornó / visszatérítés → utalványok érvénytelenítése (nem törlés!).
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_for_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_for_order' ), 10, 1 );

		// Admin: kibocsátott utalványok listája a rendelés-oldalon.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_admin_box' ) );
	}

	/**
	 * Utalványok kibocsátása egy rendeléshez (idempotens).
	 */
	public function issue_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Idempotencia: csak egyszer bocsátunk ki.
		if ( 'yes' === $order->get_meta( self::ORDER_ISSUED_META ) ) {
			return;
		}
		if ( ! empty( PGV_Vouchers::get_by_order( $order_id ) ) ) {
			$order->update_meta_data( self::ORDER_ISSUED_META, 'yes' );
			$order->save();
			return;
		}

		$settings = PGV_Settings::all();
		$unit     = PGV_Settings::unit_slug();
		$prefix   = (string) $settings['serial_prefix'];
		$months   = (int) $settings['validity_months'];

		$paid_at   = $order->get_date_paid() ? $order->get_date_paid() : new WC_DateTime();
		$paid_mysql  = $paid_at->format( 'Y-m-d H:i:s' );
		$valid_from  = $paid_at->format( 'Y-m-d' );
		$valid_until = ( clone $paid_at )->modify( '+' . max( 1, $months ) . ' months' )->format( 'Y-m-d' );

		$giver_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$buyer_email = $order->get_billing_email();
		$marketing   = 'yes' === $order->get_meta( '_pgv_marketing_opt_in' ) ? 1 : 0;

		$created_ids = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! PGV_Product::is_voucher_product( $product ) ) {
				continue;
			}

			$qty = max( 1, (int) $item->get_quantity() );

			// Az utalvány NÉVÉRTÉKE a címlet (termékár) = kedvezmény előtti tétel-részösszeg / db.
			// (Többcélú utalvány: a névérték független a kosár-kedvezménytől.)
			$unit_price = (float) $item->get_subtotal() / $qty;
			if ( $unit_price <= 0 && $product ) {
				$unit_price = (float) $product->get_price();
			}
			$amount = (int) round( $unit_price );

			$delivery       = $item->get_meta( '_pgv_delivery' ) ?: 'buyer';
			$delivery_email = $item->get_meta( '_pgv_delivery_email' ) ?: '';
			$recipient      = $item->get_meta( '_pgv_recipient' ) ?: '';
			$message        = $item->get_meta( '_pgv_message' ) ?: '';
			$image_id       = (int) ( $item->get_meta( '_pgv_image_id' ) ?: 0 );
			$image_name     = $item->get_meta( '_pgv_image_name' ) ?: '';

			// Mennyiség szerint külön sorszámú utalvány.
			for ( $n = 0; $n < $qty; $n++ ) {
				$serial = PGV_Vouchers::allocate_serial( $unit, $prefix );
				if ( is_wp_error( $serial ) ) {
					$order->add_order_note( 'Utalvány sorszám-allokálás hiba: ' . $serial->get_error_message() );
					continue;
				}

				$vid = PGV_Vouchers::create(
					array(
						'unit_slug'          => $unit,
						'order_id'           => $order_id,
						'order_item_id'      => $item_id,
						'serial'             => $serial,
						'is_legacy'          => 0,
						'amount'             => $amount,
						'denomination_label' => $item->get_name(),
						'status'             => PGV_Vouchers::STATUS_ACTIVE,
						'giver_name'         => $giver_name,
						'recipient_name'     => $recipient,
						'message'            => $message,
						'image_id'           => $image_id ?: null,
						'image_name'         => $image_name,
						'delivery_method'    => $delivery,
						'delivery_email'     => $delivery_email,
						'buyer_email'        => $buyer_email,
						'marketing_opt_in'   => $marketing,
						'qr_token'           => self::new_qr_token(),
						'valid_from'         => $valid_from,
						'valid_until'        => $valid_until,
						'paid_at'            => $paid_mysql,
					)
				);

				if ( ! is_wp_error( $vid ) ) {
					$created_ids[] = $vid;
					/**
					 * Egy utalvány kibocsátása után — PDF+QR generálás / email a külső
					 * (meglévő HTML→PDF) rendszernek, vagy Woo PDF-voucher pluginnek.
					 *
					 * @param int   $vid   Az utalvány rekord ID-je.
					 * @param array $voucher A teljes rekord.
					 * @param WC_Order $order A rendelés.
					 */
					do_action( 'pgv_voucher_issued', $vid, PGV_Vouchers::get( $vid ), $order );
				}
			}
		}

		if ( ! empty( $created_ids ) ) {
			$order->update_meta_data( self::ORDER_ISSUED_META, 'yes' );
			$serials = array_map(
				static function ( $id ) {
					$v = PGV_Vouchers::get( $id );
					return $v ? $v['serial'] : '';
				},
				$created_ids
			);
			$order->add_order_note(
				sprintf( __( 'Ajándékutalvány(ok) kibocsátva: %s', 'pomodoro-gift-vouchers' ), implode( ', ', array_filter( $serials ) ) )
			);
			$order->save();

			/**
			 * Egy rendelés összes utalványa kibocsátva — tranzakciós email küldése.
			 *
			 * @param WC_Order $order
			 * @param int[]    $created_ids
			 */
			do_action( 'pgv_vouchers_issued_for_order', $order, $created_ids );
		}
	}

	/**
	 * Sztornó/visszatérítés: a rendelés utalványai cancelled státuszba (audit), törlés nélkül.
	 */
	public function cancel_for_order( $order_id ) {
		$vouchers = PGV_Vouchers::get_by_order( $order_id );
		foreach ( (array) $vouchers as $v ) {
			if ( in_array( $v['status'], array( PGV_Vouchers::STATUS_ACTIVE, PGV_Vouchers::STATUS_PENDING ), true ) ) {
				PGV_Vouchers::set_status( (int) $v['id'], PGV_Vouchers::STATUS_CANCELLED, array(), 'order:' . $order_id );
			}
		}
	}

	/**
	 * Új, egyedi QR token.
	 */
	public static function new_qr_token() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Admin rendelés-oldali doboz a kibocsátott utalványokról.
	 */
	public function render_admin_box( $order ) {
		$vouchers = PGV_Vouchers::get_by_order( $order->get_id() );
		if ( empty( $vouchers ) ) {
			return;
		}
		echo '<div class="pgv-order-vouchers"><h3>' . esc_html__( 'Ajándékutalványok', 'pomodoro-gift-vouchers' ) . '</h3><ul>';
		foreach ( $vouchers as $v ) {
			printf(
				'<li><code>%s</code> — %s Ft — <strong>%s</strong></li>',
				esc_html( $v['serial'] ),
				esc_html( number_format_i18n( (int) $v['amount'] ) ),
				esc_html( PGV_Vouchers::status_label( $v['status'] ) )
			);
		}
		echo '</ul></div>';
	}
}
