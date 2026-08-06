<?php
/**
 * Tranzakciós e-mail + újraküldés + kiterjesztési pont a PDF/QR rendszernek.
 *
 * A tényleges PDF-generálást (kép + üdvözlő + megajándékozott + sorszám + QR) a
 * `pgv_voucher_issued` hookra kötött külső rendszer (meglévő HTML→PDF) vagy egy Woo
 * PDF-voucher plugin végzi. Itt egy egyszerű, testreszabható HTML-levél megy ki.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Emails {

	public function __construct() {
		add_action( 'pgv_vouchers_issued_for_order', array( $this, 'on_vouchers_issued' ), 10, 2 );
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

		// 1) Vevői visszaigazoló az összes kibocsátott utalványról.
		if ( is_email( $buyer_email ) ) {
			$subject = sprintf( __( 'Ajándékutalvány(ok) — %s', 'pomodoro-gift-vouchers' ), PGV_Settings::get( 'unit_name' ) );
			$body    = self::render_summary( $buyer_name, $issued, true );
			self::send( $buyer_email, $subject, $body );
		}

		// 2) A megajándékozottnak kézbesített utalványok külön levélben.
		foreach ( $issued as $v ) {
			if ( 'recipient' === $v['delivery_method'] && is_email( $v['delivery_email'] ) ) {
				$subject = __( 'Ajándékutalványt kaptál!', 'pomodoro-gift-vouchers' );
				$body    = self::render_single( $v );
				self::send( $v['delivery_email'], $subject, $body );
			}
		}
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
		$ok = self::send( $to, __( 'Ajándékutalvány', 'pomodoro-gift-vouchers' ), self::render_single( $v ) );
		return $ok ? true : new WP_Error( 'pgv_send', __( 'Az e-mail küldése sikertelen.', 'pomodoro-gift-vouchers' ) );
	}

	/**
	 * Levélküldés a beállított feladóval (per-egység).
	 */
	public static function send( $to, $subject, $html ) {
		$from_name  = PGV_Settings::get( 'from_name' ) ?: get_bloginfo( 'name' );
		$from_email = PGV_Settings::get( 'from_email' );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		$html = apply_filters( 'pgv_email_html', $html, $to, $subject );
		return wp_mail( $to, $subject, $html, $headers );
	}

	private static function render_summary( $buyer_name, array $vouchers, $for_buyer ) {
		$rows = '';
		foreach ( $vouchers as $v ) {
			$rows .= sprintf(
				'<tr><td style="padding:6px 10px;border-bottom:1px solid #eee"><code>%s</code></td><td style="padding:6px 10px;border-bottom:1px solid #eee">%s Ft</td><td style="padding:6px 10px;border-bottom:1px solid #eee">%s</td></tr>',
				esc_html( $v['serial'] ),
				esc_html( number_format_i18n( (int) $v['amount'] ) ),
				esc_html( $v['valid_until'] )
			);
		}
		$html  = '<div style="font-family:sans-serif;max-width:560px;margin:0 auto">';
		$html .= '<h2>' . esc_html__( 'Köszönjük a vásárlást!', 'pomodoro-gift-vouchers' ) . '</h2>';
		$html .= '<p>' . esc_html( sprintf( __( 'Kedves %s, az alábbi ajándékutalvány(oka)t bocsátottuk ki:', 'pomodoro-gift-vouchers' ), $buyer_name ) ) . '</p>';
		$html .= '<table style="border-collapse:collapse;width:100%"><thead><tr>';
		$html .= '<th style="text-align:left;padding:6px 10px">' . esc_html__( 'Sorszám', 'pomodoro-gift-vouchers' ) . '</th>';
		$html .= '<th style="text-align:left;padding:6px 10px">' . esc_html__( 'Érték', 'pomodoro-gift-vouchers' ) . '</th>';
		$html .= '<th style="text-align:left;padding:6px 10px">' . esc_html__( 'Érvényes', 'pomodoro-gift-vouchers' ) . '</th>';
		$html .= '</tr></thead><tbody>' . $rows . '</tbody></table>';
		$html .= '<p style="color:#666;font-size:13px">' . esc_html( PGV_Settings::get( 'unit_name' ) ) . '</p>';
		$html .= '</div>';
		return $html;
	}

	private static function render_single( $v ) {
		$html  = '<div style="font-family:sans-serif;max-width:560px;margin:0 auto;text-align:center">';
		if ( ! empty( $v['image_id'] ) ) {
			$img = PGV_Images::get( (int) $v['image_id'] );
			if ( $img ) {
				$url = wp_get_attachment_image_url( (int) $img['attachment_id'], 'large' );
				if ( $url ) {
					$html .= '<img src="' . esc_url( $url ) . '" alt="" style="max-width:100%;border-radius:8px">';
				}
			}
		}
		$html .= '<h2>' . esc_html__( 'Ajándékutalvány', 'pomodoro-gift-vouchers' ) . '</h2>';
		if ( ! empty( $v['recipient_name'] ) ) {
			$html .= '<p>' . esc_html( sprintf( __( 'Kedves %s!', 'pomodoro-gift-vouchers' ), $v['recipient_name'] ) ) . '</p>';
		}
		if ( ! empty( $v['message'] ) ) {
			$html .= '<p style="font-style:italic">' . esc_html( $v['message'] ) . '</p>';
		}
		$html .= '<p style="font-size:20px"><strong>' . esc_html( number_format_i18n( (int) $v['amount'] ) ) . ' Ft</strong></p>';
		$html .= '<p>' . esc_html__( 'Sorszám', 'pomodoro-gift-vouchers' ) . ': <code>' . esc_html( $v['serial'] ) . '</code></p>';
		$html .= '<p style="color:#666">' . esc_html( sprintf( __( 'Beváltható: %s', 'pomodoro-gift-vouchers' ), PGV_Settings::get( 'unit_name' ) ) ) . '</p>';
		$html .= '<p style="color:#666;font-size:13px">' . esc_html( sprintf( __( 'Érvényes: %s', 'pomodoro-gift-vouchers' ), $v['valid_until'] ) ) . '</p>';
		$html .= '</div>';
		return $html;
	}
}
