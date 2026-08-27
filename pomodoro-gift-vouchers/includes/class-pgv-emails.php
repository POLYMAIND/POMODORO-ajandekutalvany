<?php
/**
 * Tranzakciós e-mail (brandelt, szerkeszthető sablon) + újraküldés + PDF-csatolás.
 *
 * A szövegek (tárgy, bevezető, lábléc), az akcentszín és a kép megjelenítése az
 * adminból szerkeszthető, helykitöltőkkel. A tényleges PDF-generálást lásd:
 * PGV_Voucher_PDF; a külső HTML→PDF rendszer a `pgv_voucher_issued` hookkal köthető be.
 *
 * Ezen kívül: a WooCommerce saját (vevői) rendelés-visszaigazolóit elnémítja a tiszta
 * utalvány-rendeléseknél, hogy ne menjen félrevezető „rendelés/jegy” levél.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Emails {

	public function __construct() {
		add_action( 'pgv_vouchers_issued_for_order', array( $this, 'on_vouchers_issued' ), 10, 2 );

		// A WC vevői rendelés-emailjeinek elnémítása tiszta utalvány-rendelésnél.
		foreach ( array( 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_invoice' ) as $id ) {
			add_filter( "woocommerce_email_enabled_{$id}", array( $this, 'maybe_disable_wc_email' ), 10, 2 );
		}
	}

	/**
	 * Rendelés utalványai kibocsátva: vevői visszaigazoló + (ha kell) megajándékozotti levél.
	 */
	public function on_vouchers_issued( $order, $voucher_ids ) {
		$buyer_email = $order->get_billing_email();
		$buyer_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		$issued = array();
		foreach ( (array) $voucher_ids as $id ) {
			$v = PGV_Vouchers::get( $id );
			if ( $v ) {
				$issued[] = $v;
			}
		}
		if ( empty( $issued ) ) {
			return;
		}

		// 1) Vevői visszaigazoló az összes kibocsátott utalványról (minden PDF csatolva).
		if ( is_email( $buyer_email ) ) {
			$subject     = self::ph( PGV_Settings::get( 'email_subject_buyer' ), $issued[0], $buyer_name );
			$body        = self::render_summary( $buyer_name, $issued );
			$attachments = self::pdf_attachments( $issued );
			self::send( $buyer_email, $subject, $body, $attachments );
			self::cleanup( $attachments );
		}

		// 2) A megajándékozottnak kézbesített utalványok külön levélben (saját PDF-fel).
		foreach ( $issued as $v ) {
			if ( 'recipient' === $v['delivery_method'] && is_email( $v['delivery_email'] ) ) {
				$subject     = self::ph( PGV_Settings::get( 'email_subject_recipient' ), $v, $buyer_name );
				$body        = self::render_single( $v, $buyer_name );
				$attachments = self::pdf_attachments( array( $v ) );
				self::send( $v['delivery_email'], $subject, $body, $attachments );
				self::cleanup( $attachments );
			}
		}
	}

	/**
	 * A WC saját vevői rendelés-emailjének letiltása, ha a rendelés csak utalvány(oka)t tartalmaz.
	 */
	public function maybe_disable_wc_email( $enabled, $order ) {
		if ( ! $enabled || ! PGV_Settings::get( 'suppress_wc_emails', 1 ) ) {
			return $enabled;
		}
		if ( $order instanceof WC_Order && self::order_is_all_vouchers( $order ) ) {
			return false;
		}
		return $enabled;
	}

	private static function order_is_all_vouchers( $order ) {
		return PGV_Product::order_is_all_vouchers( $order );
	}

	/**
	 * Utalvány e-mail újraküldése az adminból.
	 */
	public static function resend( $voucher_id ) {
		$v = PGV_Vouchers::get( $voucher_id );
		if ( ! $v ) {
			return new WP_Error( 'pgv_notfound', __( 'Nincs ilyen utalvány.', 'pomodoro-gift-vouchers' ) );
		}
		$to = ( 'recipient' === $v['delivery_method'] && is_email( $v['delivery_email'] ) ) ? $v['delivery_email'] : $v['buyer_email'];
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'pgv_noemail', __( 'Nincs érvényes címzett e-mail cím.', 'pomodoro-gift-vouchers' ) );
		}
		$subject     = self::ph( PGV_Settings::get( 'email_subject_recipient' ), $v, $v['giver_name'] );
		$attachments = self::pdf_attachments( array( $v ) );
		$ok          = self::send( $to, $subject, self::render_single( $v, $v['giver_name'] ), $attachments );
		self::cleanup( $attachments );
		return $ok ? true : new WP_Error( 'pgv_send', __( 'Az e-mail küldése sikertelen.', 'pomodoro-gift-vouchers' ) );
	}

	/**
	 * Teszt-e-mail küldése (admin), a mostani sablonnal.
	 */
	public static function send_test( $to ) {
		$sample = array(
			'amount'          => 25000,
			'recipient_name'  => 'Teszt Elek',
			'message'         => 'Boldog születésnapot kívánunk! Élvezd a vacsorát nálunk.',
			'serial'          => strtoupper( PGV_Settings::get( 'serial_prefix', 'CASA' ) ) . '-' . gmdate( 'Y' ) . '-000001',
			'valid_until'     => gmdate( 'Y-m-d', strtotime( '+1 year' ) ),
			'delivery_method' => 'recipient',
			'delivery_email'  => $to,
			'buyer_email'     => $to,
			'image_id'        => 0,
		);
		$subject = self::ph( PGV_Settings::get( 'email_subject_recipient' ), $sample, 'Minta Vásárló' );
		$ok      = self::send( $to, '[TESZT] ' . $subject, self::render_single( $sample, 'Minta Vásárló' ) );
		return $ok ? true : new WP_Error( 'pgv_send', __( 'A teszt e-mail küldése sikertelen.', 'pomodoro-gift-vouchers' ) );
	}

	// ------------------------------------------------------------
	// Küldés
	// ------------------------------------------------------------
	public static function send( $to, $subject, $html, $attachments = array() ) {
		$from_name  = PGV_Settings::get( 'from_name' ) ?: get_bloginfo( 'name' );
		$from_email = PGV_Settings::get( 'from_email' );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		$html = apply_filters( 'pgv_email_html', $html, $to, $subject );
		return wp_mail( $to, $subject, $html, $headers, $attachments );
	}

	private static function pdf_attachments( array $vouchers ) {
		if ( ! class_exists( 'PGV_Voucher_PDF' ) || ! PGV_Voucher_PDF::enabled() ) {
			return array();
		}
		$paths = array();
		foreach ( $vouchers as $v ) {
			if ( empty( $v['serial'] ) ) {
				continue;
			}
			$p = PGV_Voucher_PDF::to_temp_file( $v );
			if ( ! is_wp_error( $p ) ) {
				$paths[] = $p;
			}
		}
		return $paths;
	}

	private static function cleanup( array $paths ) {
		foreach ( $paths as $p ) {
			if ( $p && file_exists( $p ) ) {
				@unlink( $p ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
		}
	}

	// ------------------------------------------------------------
	// Helykitöltők
	// ------------------------------------------------------------
	/**
	 * Helykitöltők behelyettesítése egy sablon-szövegbe.
	 */
	private static function ph( $template, $voucher, $buyer_name = '' ) {
		$map = array(
			'{megajandekozott}' => isset( $voucher['recipient_name'] ) ? $voucher['recipient_name'] : '',
			'{uzenet}'          => isset( $voucher['message'] ) ? $voucher['message'] : '',
			'{osszeg}'          => isset( $voucher['amount'] ) ? number_format( (int) $voucher['amount'], 0, ',', ' ' ) . ' Ft' : '',
			'{sorszam}'         => isset( $voucher['serial'] ) ? $voucher['serial'] : '',
			'{ervenyesseg}'     => isset( $voucher['valid_until'] ) ? $voucher['valid_until'] : '',
			'{egyseg}'          => PGV_Settings::get( 'unit_name' ),
			'{vasarlo}'         => $buyer_name,
		);
		return strtr( (string) $template, $map );
	}

	// ------------------------------------------------------------
	// Renderelés (brandelt HTML)
	// ------------------------------------------------------------

	/**
	 * E-mail váz (fejléc-sáv, kártya, lábléc) — e-mail-biztos, táblás elrendezés.
	 */
	private static function wrapper( $inner, $image_url = '' ) {
		$accent    = PGV_Settings::get( 'email_accent', '#1f1f1f' );
		$unit      = PGV_Settings::get( 'unit_name' );
		$footer    = self::ph( PGV_Settings::get( 'email_footer' ), array(), '' );
		$image_row = '';

		if ( PGV_Settings::get( 'email_show_image', 1 ) && $image_url ) {
			$image_row = '<tr><td align="center" style="padding:24px 32px 0">'
				. '<img src="' . esc_url( $image_url ) . '" alt="" width="300" style="max-width:300px;width:100%;height:auto;border-radius:8px">'
				. '</td></tr>';
		}

		return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f4f2">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f2">'
			. '<tr><td align="center" style="padding:24px">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif">'
			. '<tr><td style="background:' . esc_attr( $accent ) . ';padding:26px 32px">'
			. '<span style="color:#ffffff;font-size:19px;font-weight:bold;letter-spacing:.02em">' . esc_html( $unit ) . '</span>'
			. '</td></tr>'
			. $image_row
			. '<tr><td style="padding:28px 32px">' . $inner . '</td></tr>'
			. '<tr><td style="padding:16px 32px;border-top:1px solid #eee;color:#999;font-size:12px">' . esc_html( $footer ) . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	private static function amount_box( $amount, $serial ) {
		return '<div style="margin:22px 0;padding:18px 20px;background:#faf7f5;border-radius:10px;text-align:center">'
			. '<div style="font-size:30px;font-weight:bold;color:#1a1a1a">' . esc_html( number_format( (int) $amount, 0, ',', ' ' ) ) . ' Ft</div>'
			. '<div style="font-size:13px;color:#777;margin-top:8px">' . esc_html__( 'Sorszám', 'pomodoro-gift-vouchers' ) . ': <b style="color:#444">' . esc_html( $serial ) . '</b></div>'
			. '</div>';
	}

	private static function render_single( $v, $buyer_name = '' ) {
		$heading = PGV_Settings::get( 'email_heading', 'Ajándékutalvány' );
		$intro   = nl2br( esc_html( self::ph( PGV_Settings::get( 'email_intro_recipient' ), $v, $buyer_name ) ) );

		$image_url = '';
		if ( ! empty( $v['image_id'] ) && function_exists( 'wp_get_attachment_image_url' ) ) {
			$img = PGV_Images::get( (int) $v['image_id'] );
			if ( $img ) {
				$image_url = wp_get_attachment_image_url( (int) $img['attachment_id'], 'large' ) ?: '';
			}
		}

		$inner  = '<h1 style="margin:0 0 14px;font-size:22px;color:#1a1a1a">' . esc_html( $heading ) . '</h1>';
		$inner .= '<div style="font-size:15px;color:#444;line-height:1.6">' . $intro . '</div>';
		$inner .= self::amount_box( $v['amount'], $v['serial'] );
		$inner .= '<div style="font-size:13px;color:#777;line-height:1.6">'
			. esc_html__( 'Beváltható', 'pomodoro-gift-vouchers' ) . ': ' . esc_html( PGV_Settings::get( 'unit_name' ) );
		if ( ! empty( $v['valid_until'] ) ) {
			$inner .= ' &middot; ' . esc_html__( 'Érvényes', 'pomodoro-gift-vouchers' ) . ': ' . esc_html( $v['valid_until'] );
		}
		$inner .= '</div>';

		return self::wrapper( $inner, $image_url );
	}

	private static function render_summary( $buyer_name, array $vouchers ) {
		$intro = nl2br( esc_html( self::ph( PGV_Settings::get( 'email_intro_buyer' ), $vouchers[0], $buyer_name ) ) );

		$rows = '';
		foreach ( $vouchers as $v ) {
			$rows .= '<tr>'
				. '<td style="padding:8px 10px;border-bottom:1px solid #eee;font-family:monospace">' . esc_html( $v['serial'] ) . '</td>'
				. '<td style="padding:8px 10px;border-bottom:1px solid #eee;text-align:right">' . esc_html( number_format( (int) $v['amount'], 0, ',', ' ' ) ) . ' Ft</td>'
				. '<td style="padding:8px 10px;border-bottom:1px solid #eee;color:#777">' . esc_html( $v['valid_until'] ) . '</td>'
				. '</tr>';
		}

		$inner  = '<div style="font-size:15px;color:#444;line-height:1.6">' . $intro . '</div>';
		$inner .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:18px;border-collapse:collapse;font-size:14px">'
			. '<tr style="text-align:left;color:#999;font-size:12px;text-transform:uppercase">'
			. '<th style="padding:6px 10px">' . esc_html__( 'Sorszám', 'pomodoro-gift-vouchers' ) . '</th>'
			. '<th style="padding:6px 10px;text-align:right">' . esc_html__( 'Érték', 'pomodoro-gift-vouchers' ) . '</th>'
			. '<th style="padding:6px 10px">' . esc_html__( 'Érvényes', 'pomodoro-gift-vouchers' ) . '</th>'
			. '</tr>' . $rows . '</table>';

		return self::wrapper( $inner );
	}
}
