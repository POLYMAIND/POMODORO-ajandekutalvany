<?php
/**
 * Blokk-checkout integráció: a marketing-hozzájárulás mezője az Additional Checkout
 * Fields API-val (WooCommerce 8.9+). Ez a mező mind a blokk-, mind a klasszikus
 * checkouton megjelenik, JS-build nélkül. Az értéket a mi belső
 * `_pgv_marketing_opt_in` / `marketing_opt_in` metánkra normalizáljuk, hogy a
 * kibocsátás és a CRM változatlanul működjön.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Blocks {

	const FIELD_ID = 'pomodoro/marketing-opt-in';

	public function __construct() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return; // Régi WC — a klasszikus mezőt a PGV_Checkout adja.
		}

		add_action( 'woocommerce_init', array( $this, 'register_field' ) );

		// Érték normalizálása a rendelésre — klasszikus és blokk folyamatra egyaránt.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'normalize_from_id' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'normalize_from_order' ), 20, 1 );
	}

	public function register_field() {
		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::FIELD_ID,
					'label'    => PGV_Settings::get( 'marketing_label', __( 'Szeretnék értesülni akciókról, újdonságokról.', 'pomodoro-gift-vouchers' ) ),
					'location' => 'order',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( \Exception $e ) {
			// Már regisztrálva / nem támogatott — csendben tovább.
		}
	}

	public function normalize_from_id( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->normalize_from_order( $order );
		}
	}

	public function normalize_from_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$val = $this->read_field( $order );
		$yes = in_array( $val, array( '1', 1, true, 'yes', 'true', 'on' ), true );

		$order->update_meta_data( '_pgv_marketing_opt_in', $yes ? 'yes' : 'no' );
		$order->update_meta_data( 'marketing_opt_in', $yes ? 'yes' : 'no' );
		$order->save();
	}

	/**
	 * Az Additional Field értékének kiolvasása — a hivatalos szolgáltatáson át,
	 * több lehetséges meta-kulcs fallbackkel.
	 */
	private function read_field( $order ) {
		$svc = '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields';
		if ( function_exists( 'wc_get_container' ) && class_exists( $svc ) ) {
			try {
				$cf  = wc_get_container()->get( $svc );
				$val = $cf->get_field_from_object( self::FIELD_ID, $order, 'other' );
				if ( '' !== $val && null !== $val ) {
					return $val;
				}
			} catch ( \Exception $e ) {
				// Fallbackre lépünk.
			}
		}
		foreach ( array( '_wc_other/' . self::FIELD_ID, '_wc_order/' . self::FIELD_ID, self::FIELD_ID ) as $key ) {
			$val = $order->get_meta( $key );
			if ( '' !== $val && null !== $val ) {
				return $val;
			}
		}
		return '';
	}
}
