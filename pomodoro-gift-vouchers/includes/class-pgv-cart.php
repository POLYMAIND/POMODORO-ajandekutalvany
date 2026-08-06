<?php
/**
 * Frontend: a termékoldal személyre szabó mezői + a kosár/checkout felülírása.
 *
 * A megjelenést a téma adja; itt csak az adatbekérés és -megjelenítés történik.
 * A mezők a kosár-tételhez (cart item meta), majd a rendelés-tételhez (order item
 * meta) kerülnek, végül fizetés után az egyedi utalványokba.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Cart {

	public function __construct() {
		// Termékoldali mezők a kosárba tétel gomb előtt.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_fields' ) );

		// Kosárba tétel validáció + adatok csatolása.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );

		// Megjelenítés a kosárban / checkouton.
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'cart_item_thumbnail' ), 10, 3 );

		// Rendelés-tétel metába mentés.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_to_order_item' ), 10, 4 );

		// Frontend eszközök.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		if ( ! function_exists( 'is_product' ) ) {
			return;
		}
		if ( is_product() || is_cart() || is_checkout() ) {
			wp_enqueue_style( 'pgv-frontend', PGV_PLUGIN_URL . 'assets/css/frontend.css', array(), PGV_VERSION );
			wp_enqueue_script( 'pgv-frontend', PGV_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), PGV_VERSION, true );
			wp_localize_script(
				'pgv-frontend',
				'PGV',
				array(
					'corporateWarn'    => (bool) PGV_Settings::get( 'corporate_warn', 1 ),
					'corporateMessage' => __( 'Úgy tűnik, céges nevet vagy adószámot adtál meg. Áfás számla ezen a felületen nem igényelhető — az ajándékutalvány magánvásárlásként állítható ki.', 'pomodoro-gift-vouchers' ),
				)
			);
		}
	}

	/**
	 * Személyre szabó mezők a termékoldalon (csak utalvány-termékeknél).
	 */
	public function render_fields() {
		global $product;
		if ( ! PGV_Product::is_voucher_product( $product ) ) {
			return;
		}

		$images           = PGV_Images::get_active();
		$delivery_default = PGV_Settings::get( 'delivery_default', 'recipient' );

		echo '<div class="pgv-fields" data-pgv>';

		// --- Utalványkép választása ---
		if ( ! empty( $images ) ) {
			echo '<div class="pgv-field pgv-field-image">';
			echo '<label class="pgv-label">' . esc_html__( 'Utalvány képe', 'pomodoro-gift-vouchers' ) . '</label>';
			echo '<div class="pgv-images" role="radiogroup">';
			foreach ( $images as $i => $img ) {
				$checked = 0 === $i ? 'checked' : '';
				printf(
					'<label class="pgv-image-choice">
						<input type="radio" name="pgv_image_id" value="%1$d" %2$s>
						<span class="pgv-image-thumb">%3$s</span>
						<span class="pgv-image-name">%4$s</span>
					</label>',
					(int) $img['id'],
					esc_attr( $checked ),
					$img['thumb'] ? '<img src="' . esc_url( $img['thumb'] ) . '" alt="' . esc_attr( $img['title'] ) . '">' : '',
					esc_html( $img['title'] )
				);
			}
			echo '</div></div>';
		}

		// --- Megajándékozott neve ---
		echo '<p class="pgv-field pgv-field-recipient">';
		echo '<label class="pgv-label" for="pgv_recipient">' . esc_html__( 'Megajándékozott neve', 'pomodoro-gift-vouchers' ) . ' <span class="pgv-opt">(' . esc_html__( 'opcionális', 'pomodoro-gift-vouchers' ) . ')</span></label>';
		echo '<input type="text" id="pgv_recipient" name="pgv_recipient" class="pgv-input" maxlength="120" placeholder="' . esc_attr__( 'pl. Nagy Béla', 'pomodoro-gift-vouchers' ) . '">';
		echo '</p>';

		// --- Üdvözlő üzenet ---
		echo '<p class="pgv-field pgv-field-message">';
		echo '<label class="pgv-label" for="pgv_message">' . esc_html__( 'Üdvözlő üzenet', 'pomodoro-gift-vouchers' ) . ' <span class="pgv-opt">(' . esc_html__( 'opcionális', 'pomodoro-gift-vouchers' ) . ')</span></label>';
		echo '<textarea id="pgv_message" name="pgv_message" class="pgv-input" rows="3" maxlength="500" placeholder="' . esc_attr__( 'Boldog születésnapot! Élvezd a vacsorát…', 'pomodoro-gift-vouchers' ) . '"></textarea>';
		echo '</p>';

		// --- Kézbesítés módja ---
		echo '<div class="pgv-field pgv-field-delivery">';
		echo '<label class="pgv-label">' . esc_html__( 'Kézbesítés', 'pomodoro-gift-vouchers' ) . '</label>';
		printf(
			'<label class="pgv-radio"><input type="radio" name="pgv_delivery" value="recipient" %s> %s</label>',
			checked( 'recipient', $delivery_default, false ),
			esc_html__( 'A megajándékozottnak küldjük (e-mailben, közvetlenül neki)', 'pomodoro-gift-vouchers' )
		);
		printf(
			'<label class="pgv-radio"><input type="radio" name="pgv_delivery" value="buyer" %s> %s</label>',
			checked( 'buyer', $delivery_default, false ),
			esc_html__( 'Nekem küldjétek (én adom át)', 'pomodoro-gift-vouchers' )
		);
		echo '</div>';

		// --- Megajándékozott e-mail címe (feltételes) ---
		echo '<p class="pgv-field pgv-field-delivery-email">';
		echo '<label class="pgv-label" for="pgv_delivery_email">' . esc_html__( 'Megajándékozott e-mail címe', 'pomodoro-gift-vouchers' ) . '</label>';
		echo '<input type="email" id="pgv_delivery_email" name="pgv_delivery_email" class="pgv-input" placeholder="megajandekozott@example.com">';
		echo '</p>';

		echo '</div>';

		wp_nonce_field( 'pgv_add_to_cart', 'pgv_nonce' );
	}

	/**
	 * Kosárba tétel validáció.
	 */
	public function validate( $passed, $product_id, $quantity ) {
		if ( ! PGV_Product::is_voucher_product( $product_id ) ) {
			return $passed;
		}

		$delivery = isset( $_POST['pgv_delivery'] ) ? sanitize_key( wp_unslash( $_POST['pgv_delivery'] ) ) : 'recipient'; // phpcs:ignore WordPress.Security.NonceVerification
		$email    = isset( $_POST['pgv_delivery_email'] ) ? sanitize_email( wp_unslash( $_POST['pgv_delivery_email'] ) ) : '';

		if ( 'recipient' === $delivery && ! is_email( $email ) ) {
			wc_add_notice(
				__( 'Add meg a megajándékozott e-mail címét, hogy közvetlenül neki küldhessük az utalványt (vagy válaszd a „Nekem küldjétek” opciót).', 'pomodoro-gift-vouchers' ),
				'error'
			);
			return false;
		}
		return $passed;
	}

	/**
	 * Adatok csatolása a kosár-tételhez.
	 */
	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( ! PGV_Product::is_voucher_product( $product_id ) ) {
			return $cart_item_data;
		}

		$delivery = isset( $_POST['pgv_delivery'] ) ? sanitize_key( wp_unslash( $_POST['pgv_delivery'] ) ) : 'recipient'; // phpcs:ignore WordPress.Security.NonceVerification
		$delivery = in_array( $delivery, array( 'recipient', 'buyer' ), true ) ? $delivery : 'recipient';

		$image_id   = isset( $_POST['pgv_image_id'] ) ? absint( wp_unslash( $_POST['pgv_image_id'] ) ) : 0;
		$image_name = '';
		if ( $image_id ) {
			$img        = PGV_Images::get( $image_id );
			$image_name = $img ? (string) $img['title'] : '';
		}

		$cart_item_data['pgv'] = array(
			'image_id'       => $image_id,
			'image_name'     => $image_name,
			'recipient'      => isset( $_POST['pgv_recipient'] ) ? sanitize_text_field( wp_unslash( $_POST['pgv_recipient'] ) ) : '',
			'message'        => isset( $_POST['pgv_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pgv_message'] ) ) : '',
			'delivery'       => $delivery,
			'delivery_email' => ( 'recipient' === $delivery && isset( $_POST['pgv_delivery_email'] ) ) ? sanitize_email( wp_unslash( $_POST['pgv_delivery_email'] ) ) : '',
		);

		return $cart_item_data;
	}

	/**
	 * Megjelenítés a kosárban / checkouton (a téma stílusával).
	 */
	public function display_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['pgv'] ) ) {
			return $item_data;
		}
		$pgv = $cart_item['pgv'];

		if ( ! empty( $pgv['image_name'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Utalványkép', 'pomodoro-gift-vouchers' ),
				'value' => $pgv['image_name'],
			);
		}
		if ( ! empty( $pgv['recipient'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Megajándékozott', 'pomodoro-gift-vouchers' ),
				'value' => $pgv['recipient'],
			);
		}
		if ( ! empty( $pgv['message'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Üzenet', 'pomodoro-gift-vouchers' ),
				'value' => wp_kses_post( $pgv['message'] ),
			);
		}
		if ( 'recipient' === $pgv['delivery'] ) {
			$item_data[] = array(
				'key'   => __( 'Kézbesítés', 'pomodoro-gift-vouchers' ),
				'value' => sprintf(
					/* translators: %s: recipient email */
					__( 'A megajándékozottnak: %s', 'pomodoro-gift-vouchers' ),
					$pgv['delivery_email']
				),
			);
		} else {
			$item_data[] = array(
				'key'   => __( 'Kézbesítés', 'pomodoro-gift-vouchers' ),
				'value' => __( 'A vásárlónak', 'pomodoro-gift-vouchers' ),
			);
		}
		return $item_data;
	}

	/**
	 * A kosárban a kiválasztott utalványkép jelenjen meg (ha van).
	 */
	public function cart_item_thumbnail( $thumbnail, $cart_item, $cart_item_key ) {
		if ( empty( $cart_item['pgv']['image_id'] ) ) {
			return $thumbnail;
		}
		$img = PGV_Images::get( (int) $cart_item['pgv']['image_id'] );
		if ( $img ) {
			$html = wp_get_attachment_image( (int) $img['attachment_id'], 'woocommerce_thumbnail' );
			if ( $html ) {
				return $html;
			}
		}
		return $thumbnail;
	}

	/**
	 * Rendelés-tétel metába mentés (checkout).
	 */
	public function save_to_order_item( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['pgv'] ) ) {
			return;
		}
		$pgv = $values['pgv'];

		// Belső (aláhúzásos) meták — nem jelennek meg a vevőnek, de a rendelésben tárolódnak.
		$item->add_meta_data( '_pgv_image_id', (int) $pgv['image_id'], true );
		$item->add_meta_data( '_pgv_image_name', $pgv['image_name'], true );
		$item->add_meta_data( '_pgv_recipient', $pgv['recipient'], true );
		$item->add_meta_data( '_pgv_message', $pgv['message'], true );
		$item->add_meta_data( '_pgv_delivery', $pgv['delivery'], true );
		$item->add_meta_data( '_pgv_delivery_email', $pgv['delivery_email'], true );

		// Vevőnek is látható összefoglaló a rendelés-visszaigazolóban.
		if ( ! empty( $pgv['recipient'] ) ) {
			$item->add_meta_data( __( 'Megajándékozott', 'pomodoro-gift-vouchers' ), $pgv['recipient'] );
		}
		if ( ! empty( $pgv['image_name'] ) ) {
			$item->add_meta_data( __( 'Utalványkép', 'pomodoro-gift-vouchers' ), $pgv['image_name'] );
		}
	}
}
