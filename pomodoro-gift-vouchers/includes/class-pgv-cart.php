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

		// Az előnézet a termékkép helyén: a galéria kirajzolása előtt döntünk
		// (ekkor már ismert a $product, tehát csak utalvány-terméknél cseréljük).
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'maybe_swap_gallery' ), 1 );

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
					'preview'          => self::preview_data(),
				)
			);
		}
	}

	/**
	 * Az élő előnézet adatai a JS-nek. A méretek/tördelés a PDF-generátor
	 * konstansaiból jönnek, hogy a kettő ne csússzon el egymástól.
	 */
	private static function preview_data() {
		$images = array();
		foreach ( PGV_Images::get_active() as $img ) {
			$images[ (string) $img['id'] ] = $img['url'];
		}
		$logo_id  = (int) PGV_Settings::get( 'logo_attachment_id', 0 );
		$logo_url = $logo_id ? ( wp_get_attachment_image_url( $logo_id, 'medium' ) ?: '' ) : '';
		$months   = max( 1, (int) PGV_Settings::get( 'validity_months', 12 ) );

		return array(
			'images'      => $images,
			'logoUrl'     => $logo_url,
			'unitName'    => (string) PGV_Settings::get( 'unit_name', '' ),
			'heading'     => __( 'AJÁNDÉKUTALVÁNY', 'pomodoro-gift-vouchers' ),
			'ratio'       => PGV_PDF::CARD_W_MM . '/' . PGV_PDF::CARD_H_MM,
			'wrapChars'   => PGV_PDF::MSG_WRAP_CHARS,
			'maxLines'    => PGV_PDF::MSG_MAX_LINES,
			'greeting'    => __( 'Kedves %s!', 'pomodoro-gift-vouchers' ),
			'serialNote'  => __( 'Sorszám: a fizetés után kerül rá', 'pomodoro-gift-vouchers' ),
			/* translators: %d: hónapok száma */
			'validityNote' => sprintf( _n( 'Érvényes: a vásárlástól számított %d hónapig', 'Érvényes: a vásárlástól számított %d hónapig', $months, 'pomodoro-gift-vouchers' ), $months ),
			'watermark'   => __( 'MINTA', 'pomodoro-gift-vouchers' ),
			'tooLong'     => __( 'Ennél hosszabb üzenet nem fér rá az utalványra — a maradékot levágjuk.', 'pomodoro-gift-vouchers' ),
		);
	}

	/**
	 * Utalvány-terméknél a termékgaléria helyére tesszük az élő előnézetet.
	 * A WordPress a hook futása közben felvett, magasabb prioritású callbacket
	 * még meghívja, így a 20-as galéria helyére a sajátunk kerül.
	 */
	public function maybe_swap_gallery() {
		global $product;
		if ( 'gallery' !== PGV_Settings::get( 'preview_position', 'gallery' ) ) {
			return;
		}
		if ( ! PGV_Product::is_voucher_product( $product ) ) {
			return;
		}
		if ( ! remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 ) ) {
			// A sablon nem a szokásos módon rajzolja a galériát — ilyenkor nem
			// erőltetjük: marad a mezők fölötti (kisebb) előnézet.
			return;
		}
		self::$gallery_swapped = true;
		// Nincs galéria-kép, tehát a nagyító és a lightbox is felesleges.
		add_filter( 'woocommerce_single_product_zoom_enabled', '__return_false' );
		add_filter( 'woocommerce_single_product_photoswipe_enabled', '__return_false' );
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'render_preview_gallery' ), 20 );
	}

	/** @var bool Sikerült-e a galéria helyére tenni az előnézetet. */
	private static $gallery_swapped = false;

	/**
	 * A nagy előnézet a galéria helyén.
	 */
	public function render_preview_gallery() {
		// A sablonok a galéria-oszlop szélességét a WooCommerce saját osztályaira
		// kötik; ezeket megtartjuk, különben az előnézet kitörne az oszlopból és
		// szétesne a kétoszlopos termékoldal.
		echo '<div class="woocommerce-product-gallery images pgv-preview-gallery">';
		$this->render_preview( 'gallery' );
		echo '</div>';
	}

	/**
	 * Az előnézet-kártya váza. A tartalmat a JS tölti ki élőben; szerveroldalon
	 * csak a keret és a nem változó feliratok készülnek el, hogy JS nélkül se
	 * legyen félkész doboz (ilyenkor a kártya rejtve marad).
	 */
	private function render_preview( $where = 'fields' ) {
		$p = self::preview_data();
		?>
		<div class="pgv-preview pgv-preview--<?php echo esc_attr( $where ); ?>" data-pgv-preview hidden>
			<span class="pgv-preview-title"><?php esc_html_e( 'Így fog kinézni', 'pomodoro-gift-vouchers' ); ?></span>
			<div class="pgv-card" data-pgv-card>
				<div class="pgv-card-img"><img alt="" data-pgv-img></div>
				<div class="pgv-card-body">
					<?php if ( $p['logoUrl'] ) : ?>
						<img class="pgv-card-logo" src="<?php echo esc_url( $p['logoUrl'] ); ?>" alt="">
					<?php elseif ( $p['unitName'] ) : ?>
						<span class="pgv-card-unit"><?php echo esc_html( $p['unitName'] ); ?></span>
					<?php endif; ?>
					<span class="pgv-card-heading"><?php echo esc_html( $p['heading'] ); ?></span>
					<span class="pgv-card-amount" data-pgv-amount></span>
					<span class="pgv-card-greeting" data-pgv-greeting hidden></span>
					<span class="pgv-card-message" data-pgv-message hidden></span>
					<span class="pgv-card-foot">
						<span class="pgv-card-serial"><?php echo esc_html( $p['serialNote'] ); ?></span>
						<span class="pgv-card-valid"><?php echo esc_html( $p['validityNote'] ); ?></span>
					</span>
				</div>
				<span class="pgv-card-mark" aria-hidden="true"><?php echo esc_html( $p['watermark'] ); ?></span>
			</div>
			<p class="pgv-preview-note"><?php esc_html_e( 'Tájékoztató előnézet — a végleges utalványra egyedi sorszám és a pontos érvényességi dátum kerül.', 'pomodoro-gift-vouchers' ); ?></p>
			<p class="pgv-preview-warn" data-pgv-toolong hidden><?php echo esc_html( $p['tooLong'] ); ?></p>
		</div>
		<?php
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

		// --- Élő előnézet (a kiküldendő PDF kártya mása, sorszám nélkül) ---
		// Ha már a termékkép helyén megjelent, itt nem ismételjük meg.
		$pos = PGV_Settings::get( 'preview_position', 'gallery' );
		if ( 'off' !== $pos && ! self::$gallery_swapped ) {
			$this->render_preview( 'fields' );
		}

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
