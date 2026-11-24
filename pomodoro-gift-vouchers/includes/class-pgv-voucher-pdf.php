<?php
/**
 * Utalvány-PDF szolgáltatás: a voucher rekordból determinisztikus PDF-et állít elő
 * (kép + szöveg + QR a sorszámból). A PDF nem tartalmaz időbélyeget/véletlent, így
 * bármikor bit-azonosan újragenerálható — ezért nem tároljuk, hanem igény szerint
 * (e-mail csatolás / admin letöltés) állítjuk elő.
 *
 * A `pgv_use_builtin_pdf` szűrővel kikapcsolható (ha a meglévő HTML→PDF rendszer
 * veszi át a kézbesítést a `pgv_voucher_issued` hookon).
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Voucher_PDF {

	/**
	 * Használjuk-e a beépített PDF-et?
	 */
	public static function enabled() {
		return (bool) apply_filters( 'pgv_use_builtin_pdf', true );
	}

	/**
	 * A QR tartalma (a HANDOVER szerint „QR a sorszámból”).
	 */
	public static function qr_data( array $voucher ) {
		return apply_filters( 'pgv_qr_data', $voucher['serial'], $voucher );
	}

	/**
	 * PDF bájtok egy voucherhez.
	 */
	public static function bytes( array $voucher ) {
		$unit_name = PGV_Settings::get( 'unit_name', get_bloginfo( 'name' ) );

		$image_path = '';
		if ( ! empty( $voucher['image_id'] ) ) {
			$img = PGV_Images::get( (int) $voucher['image_id'] );
			if ( $img ) {
				$path = get_attached_file( (int) $img['attachment_id'] );
				if ( $path && file_exists( $path ) ) {
					$image_path = $path;
				}
			}
		}

		// Logó (egységenként feltölthető a beállításokban).
		$logo_path = '';
		$logo_id   = (int) PGV_Settings::get( 'logo_attachment_id', 0 );
		if ( $logo_id ) {
			$lp = get_attached_file( $logo_id );
			if ( $lp && file_exists( $lp ) ) {
				$logo_path = $lp;
			}
		}

		return PGV_PDF::voucher_pdf( $voucher, self::qr_data( $voucher ), $unit_name, $image_path, $logo_path );
	}

	/**
	 * PDF ideiglenes fájlba írása (e-mail csatoláshoz). A hívó törölje utána.
	 *
	 * @return string|WP_Error Az abszolút fájl-útvonal.
	 */
	public static function to_temp_file( array $voucher ) {
		$bytes = self::bytes( $voucher );
		$name  = 'ajandekutalvany-' . sanitize_file_name( $voucher['serial'] ) . '.pdf';
		$path  = trailingslashit( get_temp_dir() ) . $name;

		$ok = file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $ok ) {
			return new WP_Error( 'pgv_pdf_tmp', __( 'A PDF ideiglenes mentése sikertelen.', 'pomodoro-gift-vouchers' ) );
		}
		return $path;
	}

	/**
	 * PDF streamelése letöltésként (admin).
	 */
	public static function stream( array $voucher ) {
		$bytes    = self::bytes( $voucher );
		$filename = 'ajandekutalvany-' . sanitize_file_name( $voucher['serial'] ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
