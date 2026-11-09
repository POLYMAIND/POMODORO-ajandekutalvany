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
		// A rendelés lezárása az utalványok kibocsátása ÉS az e-mailek kiküldése után
		// (a 99-es prioritás miatt fut a PGV_Emails 10-es kezelője mögött).
		add_action( 'pgv_vouchers_issued_for_order', array( $this, 'maybe_complete_order' ), 99, 2 );

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

		$giver_name = self::buyer_name( $order );
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
				$alloc = PGV_Vouchers::allocate_serial( $unit, $prefix );
				if ( is_wp_error( $alloc ) ) {
					$order->add_order_note( 'Utalvány sorszám-allokálás hiba: ' . $alloc->get_error_message() );
					do_action( 'pgv_error', 'sorszam_allokalas', 'Fizetett rendelésnél nem jött létre sorszám: ' . $alloc->get_error_message(), array( 'order_id' => (int) $order_id ), 'error' );
					continue;
				}
				$serial = $alloc['serial'];

				$vid = PGV_Vouchers::create(
					array(
						'unit_slug'          => $unit,
						'order_id'           => $order_id,
						'order_item_id'      => $item_id,
						'serial'             => $serial,
						'seq_no'             => $alloc['seq_no'],
						'seq_year'           => $alloc['seq_year'],
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

				if ( is_wp_error( $vid ) ) {
					do_action( 'pgv_error', 'utalvany_letrehozas', 'Fizetett rendelésnél nem jött létre utalvány: ' . $vid->get_error_message(), array( 'order_id' => (int) $order_id ), 'error' );
				}
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
	 * Beállítástól függően a fizetett, tiszta utalvány-rendelést „Teljesítve” állapotba tesszük.
	 * Vegyes rendeléshez (utalvány + más termék) nem nyúlunk: azt még csomagolni kell.
	 */
	public function maybe_complete_order( $order, $created_ids ) {
		if ( empty( PGV_Settings::get( 'autocomplete_orders' ) ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! PGV_Product::order_is_all_vouchers( $order ) ) {
			return;
		}
		// Csak fizetett rendelést zárunk le, és sztornó/visszatérítés után nem nyúlunk hozzá.
		if ( ! $order->is_paid() || $order->has_status( array( 'completed', 'cancelled', 'refunded', 'failed' ) ) ) {
			return;
		}
		// A státuszváltás újra meghívja az issue_for_order-t, de az idempotens
		// (a rendelésen ekkor már áll a „kibocsátva” jelölés), így nincs körbefutás.
		$order->update_status(
			'completed',
			__( 'Ajándékutalvány kibocsátva — a rendelés automatikusan lezárva.', 'pomodoro-gift-vouchers' )
		);
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
	/**
	 * A vásárló neve magyar sorrendben (vezetéknév + keresztnév), ha a számlázási ország
	 * Magyarország vagy a bolt magyar nyelvű — különben a nemzetközi sorrend.
	 * Pl. Vezetéknév: Nagy, Keresztnév: Béla → „Nagy Béla”, nem „Béla Nagy”.
	 */
	public static function buyer_name( $order ) {
		$first = trim( (string) $order->get_billing_first_name() );
		$last  = trim( (string) $order->get_billing_last_name() );
		$hu    = 'HU' === strtoupper( (string) $order->get_billing_country() ) || 0 === strpos( (string) get_locale(), 'hu' );
		$hu    = (bool) apply_filters( 'pgv_name_family_first', $hu, $order );
		return trim( $hu ? $last . ' ' . $first : $first . ' ' . $last );
	}

	public static function new_qr_token() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * A megajándékozott nevének átvezetése a WooCommerce-rendelésbe.
	 *
	 * A név a rendelés-tétel metájában is el van mentve (`_pgv_recipient` + a vevőnek
	 * látható „Megajándékozott” sor), ezért ha csak az utalvány-táblában írnánk át, a
	 * rendelés (visszaigazoló, admin, CRM a Woo REST-en át) a régi nevet mutatná.
	 *
	 * Ha a tételből egyetlen utalvány lett, a tétel neve egyszerűen átíródik. Ha több
	 * (mennyiség > 1, mind ugyanazzal a névvel), a közös tétel-metát nem írhatjuk át —
	 * az a többi utalvány nevét is megváltoztatná —, ezért sorszámra szóló külön sort
	 * kap: „Megajándékozott (CASA-7K3M9QP2)”. Mindkét esetben rendelés-jegyzet készül.
	 *
	 * ELŐBB hívandó, mint az utalvány-tábla írása: ha a rendelés mentése elhasal, a
	 * hívó nem módosít semmit, így a két rendszer nem csúszik szét.
	 *
	 * @param array  $v     Az utalvány (módosítás előtti) sora.
	 * @param string $old   Régi név.
	 * @param string $new   Új név.
	 * @param string $actor Ki módosította (a jegyzetbe).
	 * @return string|WP_Error 'no_order' | 'updated' | 'updated_per_serial'
	 */
	public static function sync_recipient( array $v, $old, $new, $actor = '' ) {
		if ( empty( $v['order_id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return 'no_order'; // importált / kézi utalvány: nincs mit szinkronizálni
		}
		$order = wc_get_order( (int) $v['order_id'] );
		if ( ! $order ) {
			return 'no_order';
		}

		try {
			$item = ! empty( $v['order_item_id'] ) ? $order->get_item( (int) $v['order_item_id'] ) : null;
			if ( ! $item ) {
				// Régebbi sor tétel-azonosító nélkül: az első utalvány-tétel, amelyen a régi név áll.
				foreach ( $order->get_items() as $it ) {
					if ( $it instanceof WC_Order_Item_Product && '' !== (string) $it->get_meta( '_pgv_image_id' )
						&& (string) $it->get_meta( '_pgv_recipient' ) === (string) $old ) {
						$item = $it;
						break;
					}
				}
			}

			$mode  = 'updated';
			$label = __( 'Megajándékozott', 'pomodoro-gift-vouchers' );
			if ( $item ) {
				if ( self::vouchers_on_item( $v, $item ) > 1 ) {
					$mode = 'updated_per_serial';
					$map  = $item->get_meta( '_pgv_recipient_by_serial' );
					$map  = is_array( $map ) ? $map : array();
					$map[ (string) $v['serial'] ] = (string) $new;
					$item->update_meta_data( '_pgv_recipient_by_serial', $map );
					$item->update_meta_data( $label . ' (' . $v['serial'] . ')', (string) $new );
				} else {
					$item->update_meta_data( '_pgv_recipient', (string) $new );
					if ( '' === (string) $new ) {
						$item->delete_meta_data( $label );
					} else {
						$item->update_meta_data( $label, (string) $new );
					}
				}
				$item->save();
			}

			$order->add_order_note(
				sprintf(
					/* translators: 1: serial, 2: old name, 3: new name, 4: actor */
					__( 'Megajándékozott neve módosítva (%1$s): „%2$s” → „%3$s”. Módosította: %4$s', 'pomodoro-gift-vouchers' ),
					$v['serial'],
					'' !== (string) $old ? $old : '—',
					'' !== (string) $new ? $new : '—',
					$actor ? $actor : PGV_Vouchers::actor()
				)
			);
			$order->save();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'pgv_order_sync', sprintf( __( 'A WooCommerce-rendelés frissítése nem sikerült: %s', 'pomodoro-gift-vouchers' ), $e->getMessage() ) );
		}
		return $mode;
	}

	/**
	 * Hány utalvány született ugyanabból a rendelés-tételből.
	 */
	private static function vouchers_on_item( array $v, $item ) {
		$qty = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
		if ( empty( $v['order_item_id'] ) ) {
			return max( 1, $qty );
		}
		global $wpdb;
		$table = PGV_Install::table( 'vouchers' );
		$n     = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE order_item_id = %d", (int) $v['order_item_id'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return max( $n, 1 );
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
				'<li><code>%s</code> — %s Ft — <strong>%s</strong> — <a href="%s" target="_blank" rel="noopener">PDF</a></li>',
				esc_html( $v['serial'] ),
				esc_html( number_format_i18n( (int) $v['amount'] ) ),
				esc_html( PGV_Vouchers::status_label( $v['status'] ) ),
				esc_url( PGV_Admin::pdf_url( (int) $v['id'] ) )
			);
		}
		echo '</ul></div>';
	}
}
