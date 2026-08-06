<?php
/**
 * Önálló (függőség nélküli) PDF-generátor az utalványhoz.
 *
 * Egyoldalas A5-fekvő kártya: egység neve, „AJÁNDÉKUTALVÁNY”, választott kép,
 * összeg, megajándékozott + üzenet, sorszám, érvényesség és QR-kód (PGV_QR).
 * Beépített Helvetica (base-14, nincs betűágyazás); a magyar ő/ű a WinAnsi
 * kódolás Differences kiegészítésével jelenik meg helyesen. A QR modulok
 * vektoros téglalapok — nincs GD-függőség (a háttérkép opcionálisan, ha van GD).
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_PDF {

	/** mm → PDF pont. */
	const MM = 2.834645669;

	/** @var array Objektumok nyers tartalma (index+1 = objektumszám). */
	private $objects = array();

	/** @var string Tartalom-folyam (content stream) parancsai. */
	private $stream = '';

	private $width;
	private $height;

	/** @var array Beágyazott kép XObject-jei: [name => body]. */
	private $images = array();
	private $image_seq = 0;

	public function __construct( $width_mm = 210, $height_mm = 148 ) {
		$this->width  = $width_mm * self::MM;
		$this->height = $height_mm * self::MM;
	}

	// ------------------------------------------------------------
	// Magas szintű: utalvány-PDF előállítása
	// ------------------------------------------------------------

	/**
	 * @param array  $voucher  A voucher rekord (PGV_Vouchers::get).
	 * @param string $qr_data  A QR tartalma (pl. beváltó URL vagy a sorszám).
	 * @param string $unit_name
	 * @param string $image_path Opcionális háttér/illusztráció fájl elérési út.
	 * @return string PDF bájtok.
	 */
	public static function voucher_pdf( array $voucher, $qr_data, $unit_name = '', $image_path = '' ) {
		$pdf = new self();

		$w = $pdf->width;
		$h = $pdf->height;

		// Halvány keret.
		$pdf->rect( 8 * self::MM, 8 * self::MM, $w - 16 * self::MM, $h - 16 * self::MM, 0.85, 0.85, 0.85, false );

		// Illusztráció (bal oldali sáv), ha van és betölthető.
		$img_right = 12 * self::MM;
		if ( $image_path && function_exists( 'imagecreatefromstring' ) ) {
			$name = $pdf->add_image_from_file( $image_path );
			if ( $name ) {
				$box_w = 62 * self::MM;
				$box_h = $h - 40 * self::MM;
				$pdf->draw_image_fit( $name, 14 * self::MM, 20 * self::MM, $box_w, $box_h );
				$img_right = 14 * self::MM + $box_w + 8 * self::MM;
			}
		}

		$x   = $img_right;
		$top = $h - 22 * self::MM;

		// Fejléc.
		if ( $unit_name ) {
			$pdf->text( $x, $top, $unit_name, 12, true, 0.9, 0.34, 0.26 );
		}
		$pdf->text( $x, $top - 9 * self::MM, 'AJÁNDÉKUTALVÁNY', 20, true, 0.13, 0.13, 0.13 );

		// Összeg.
		$amount = number_format( (int) $voucher['amount'], 0, ',', ' ' ) . ' Ft';
		$pdf->text( $x, $top - 22 * self::MM, $amount, 30, true, 0.13, 0.13, 0.13 );

		$cursor = $top - 33 * self::MM;

		// Megajándékozott.
		if ( ! empty( $voucher['recipient_name'] ) ) {
			$pdf->text( $x, $cursor, 'Kedves ' . $voucher['recipient_name'] . '!', 12, false, 0.2, 0.2, 0.2 );
			$cursor -= 7 * self::MM;
		}

		// Üzenet (tördelve).
		if ( ! empty( $voucher['message'] ) ) {
			$lines = self::wrap( $voucher['message'], 46 );
			foreach ( array_slice( $lines, 0, 4 ) as $line ) {
				$pdf->text( $x, $cursor, $line, 10.5, false, 0.35, 0.35, 0.35 );
				$cursor -= 5.5 * self::MM;
			}
		}

		// Sorszám + érvényesség (alul).
		$pdf->text( $x, 20 * self::MM, 'Sorszám: ' . $voucher['serial'], 11, true, 0.13, 0.13, 0.13 );
		if ( ! empty( $voucher['valid_until'] ) ) {
			$pdf->text( $x, 14 * self::MM, 'Érvényes: ' . $voucher['valid_until'], 9, false, 0.45, 0.45, 0.45 );
		}

		// QR (jobb-alsó sarok).
		$qr = PGV_QR::matrix( $qr_data, PGV_QR::EC_M );
		if ( ! is_wp_error( $qr ) ) {
			$qr_size = 30 * self::MM;
			$pdf->draw_qr( $qr, $w - 14 * self::MM - $qr_size, 14 * self::MM, $qr_size );
		}

		return $pdf->build();
	}

	// ------------------------------------------------------------
	// Rajz-primitívek
	// ------------------------------------------------------------

	public function rect( $x, $y, $w, $h, $r, $g, $b, $fill = true ) {
		if ( $fill ) {
			$this->stream .= sprintf( "%.3F %.3F %.3F rg\n%.2F %.2F %.2F %.2F re f\n", $r, $g, $b, $x, $y, $w, $h );
		} else {
			$this->stream .= sprintf( "%.3F %.3F %.3F RG\n0.7 w\n%.2F %.2F %.2F %.2F re S\n", $r, $g, $b, $x, $y, $w, $h );
		}
	}

	public function text( $x, $y, $str, $size, $bold = false, $r = 0, $g = 0, $b = 0 ) {
		$font = $bold ? '/F2' : '/F1';
		$enc  = self::encode_text( $str );
		$this->stream .= sprintf(
			"BT %s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
			$font,
			$size,
			$r,
			$g,
			$b,
			$x,
			$y,
			$enc
		);
	}

	/**
	 * QR-mátrix rajzolása vektoros téglalapokként (soronkénti fekete futamok összevonva).
	 */
	public function draw_qr( $matrix, $x, $y, $size ) {
		$n      = count( $matrix );
		$module = $size / $n;

		// Fehér alap (csendes zóna nélkül is olvasható; adunk kis keretet).
		$this->stream .= sprintf( "1 1 1 rg %.2F %.2F %.2F %.2F re f\n", $x - $module, $y - $module, $size + 2 * $module, $size + 2 * $module );
		$this->stream .= "0 0 0 rg\n";

		for ( $row = 0; $row < $n; $row++ ) {
			$c = 0;
			while ( $c < $n ) {
				if ( $matrix[ $row ][ $c ] ) {
					$run = 1;
					while ( $c + $run < $n && $matrix[ $row ][ $c + $run ] ) {
						$run++;
					}
					// A PDF y felfelé nő; a mátrix fentről lefelé.
					$px = $x + $c * $module;
					$py = $y + $size - ( $row + 1 ) * $module;
					$this->stream .= sprintf( "%.2F %.2F %.2F %.2F re f\n", $px, $py, $run * $module, $module );
					$c += $run;
				} else {
					$c++;
				}
			}
		}
	}

	// ------------------------------------------------------------
	// Kép (JPEG XObject, GD-vel bármilyen formátumból)
	// ------------------------------------------------------------

	private function add_image_from_file( $path ) {
		$raw = @file_get_contents( $path ); // phpcs:ignore
		if ( false === $raw ) {
			return '';
		}
		$im = @imagecreatefromstring( $raw ); // phpcs:ignore
		if ( ! $im ) {
			return '';
		}
		$w = imagesx( $im );
		$h = imagesy( $im );

		// Fehér háttérre lapítás (átlátszóság kezelése), majd JPEG.
		$flat = imagecreatetruecolor( $w, $h );
		$white = imagecolorallocate( $flat, 255, 255, 255 );
		imagefill( $flat, 0, 0, $white );
		imagecopy( $flat, $im, 0, 0, 0, 0, $w, $h );
		ob_start();
		imagejpeg( $flat, null, 82 );
		$jpeg = ob_get_clean();
		imagedestroy( $im );
		imagedestroy( $flat );

		$this->image_seq++;
		$name = 'Im' . $this->image_seq;
		$this->images[ $name ] = array(
			'w'    => $w,
			'h'    => $h,
			'data' => $jpeg,
		);
		return $name;
	}

	private function draw_image_fit( $name, $x, $y, $box_w, $box_h ) {
		$img = $this->images[ $name ];
		$ar  = $img['w'] / $img['h'];
		$bar = $box_w / $box_h;
		if ( $ar > $bar ) {
			$dw = $box_w;
			$dh = $box_w / $ar;
		} else {
			$dh = $box_h;
			$dw = $box_h * $ar;
		}
		$dx = $x + ( $box_w - $dw ) / 2;
		$dy = $y + ( $box_h - $dh ) / 2;
		$this->stream .= sprintf( "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $dw, $dh, $dx, $dy, $name );
	}

	// ------------------------------------------------------------
	// PDF összeállítás
	// ------------------------------------------------------------

	private function add_object( $body ) {
		$this->objects[] = $body;
		return count( $this->objects );
	}

	private function build() {
		// Objektumszámok előre lefoglalása egy determinisztikus sorrendhez.
		// 1: Catalog, 2: Pages, 3: Page, 4: Contents, 5: F1, 6: F2, 7: Encoding, majd képek.
		$catalog_id  = 1;
		$pages_id    = 2;
		$page_id     = 3;
		$contents_id = 4;
		$f1_id       = 5;
		$f2_id       = 6;
		$enc_id      = 7;

		$image_ids = array();
		$next      = 8;
		foreach ( $this->images as $name => $img ) {
			$image_ids[ $name ] = $next++;
		}

		// XObject erőforrás-hivatkozások.
		$xobjects = '';
		foreach ( $image_ids as $name => $id ) {
			$xobjects .= sprintf( '/%s %d 0 R ', $name, $id );
		}
		$xobj_res = $xobjects ? ( '/XObject << ' . $xobjects . '>> ' ) : '';

		$this->objects = array();

		// 1 Catalog
		$this->add_object( "<< /Type /Catalog /Pages {$pages_id} 0 R >>" );
		// 2 Pages
		$this->add_object( "<< /Type /Pages /Kids [{$page_id} 0 R] /Count 1 >>" );
		// 3 Page
		$this->add_object(
			sprintf(
				'<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> %s>> /Contents %d 0 R >>',
				$pages_id,
				$this->width,
				$this->height,
				$f1_id,
				$f2_id,
				$xobj_res,
				$contents_id
			)
		);
		// 4 Contents
		$stream = $this->stream;
		$this->add_object( "<< /Length " . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream" );
		// 5,6 Fonts (Helvetica / Helvetica-Bold) egyedi Encodinggal
		$this->add_object( "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding {$enc_id} 0 R >>" );
		$this->add_object( "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding {$enc_id} 0 R >>" );
		// 7 Encoding: WinAnsi + magyar ő/ű Differences
		$this->add_object( '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [129 /odblacute 141 /udblacute 143 /Odblacute 144 /Udblacute] >>' );

		// Kép XObjectek
		foreach ( $this->images as $name => $img ) {
			$this->add_object(
				sprintf(
					"<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
					$img['w'],
					$img['h'],
					strlen( $img['data'] ),
					$img['data']
				)
			);
		}

		// Bájtsorozat + xref felépítése.
		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $this->objects as $i => $body ) {
			$offsets[ $i + 1 ] = strlen( $out );
			$out .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref_pos = strlen( $out );
		$count    = count( $this->objects ) + 1;
		$out     .= "xref\n0 {$count}\n";
		$out     .= "0000000000 65535 f \n";
		for ( $i = 1; $i < $count; $i++ ) {
			$out .= sprintf( "%010d 00000 n \n", $offsets[ $i ] );
		}
		$out .= "trailer\n<< /Size {$count} /Root {$catalog_id} 0 R >>\nstartxref\n{$xref_pos}\n%%EOF";

		return $out;
	}

	// ------------------------------------------------------------
	// Szöveg-kódolás (UTF-8 → WinAnsi + Differences) és tördelés
	// ------------------------------------------------------------

	/**
	 * UTF-8 → egybájtos (WinAnsi + magyar ő/ű) + PDF string escape.
	 */
	public static function encode_text( $str ) {
		$special = array(
			0x0151 => 0x81, // ő
			0x0171 => 0x8D, // ű
			0x0150 => 0x8F, // Ő
			0x0170 => 0x90, // Ű
			0x20AC => 0x80, // €
			0x2013 => 0x96, // –
			0x2014 => 0x97, // —
			0x2018 => 0x91,
			0x2019 => 0x92,
			0x201C => 0x93,
			0x201D => 0x94,
			0x2026 => 0x85, // …
		);

		$out  = '';
		$len  = strlen( $str );
		$i    = 0;
		while ( $i < $len ) {
			$c  = ord( $str[ $i ] );
			$cp = null;
			if ( $c < 0x80 ) {
				$cp = $c;
				$i += 1;
			} elseif ( $c >= 0xC0 && $c < 0xE0 && $i + 1 < $len ) {
				$cp = ( ( $c & 0x1F ) << 6 ) | ( ord( $str[ $i + 1 ] ) & 0x3F );
				$i += 2;
			} elseif ( $c >= 0xE0 && $c < 0xF0 && $i + 2 < $len ) {
				$cp = ( ( $c & 0x0F ) << 12 ) | ( ( ord( $str[ $i + 1 ] ) & 0x3F ) << 6 ) | ( ord( $str[ $i + 2 ] ) & 0x3F );
				$i += 3;
			} else {
				$i += 1;
				$cp = 0x3F; // '?'
			}

			if ( $cp < 0x80 ) {
				$byte = $cp;
			} elseif ( isset( $special[ $cp ] ) ) {
				$byte = $special[ $cp ];
			} elseif ( $cp >= 0xA0 && $cp <= 0xFF ) {
				$byte = $cp; // Latin-1 == WinAnsi ebben a tartományban.
			} else {
				$byte = 0x3F;
			}

			// PDF string escape.
			if ( 0x28 === $byte || 0x29 === $byte || 0x5C === $byte ) {
				$out .= '\\';
			}
			$out .= chr( $byte );
		}
		return $out;
	}

	/**
	 * Egyszerű szó-tördelés adott karakterszélességre.
	 *
	 * @return string[]
	 */
	private static function wrap( $text, $max_chars ) {
		$text  = preg_replace( '/\s+/u', ' ', trim( $text ) );
		$words = explode( ' ', $text );
		$lines = array();
		$cur   = '';
		foreach ( $words as $w ) {
			$try = '' === $cur ? $w : $cur . ' ' . $w;
			if ( mb_strlen( $try ) > $max_chars ) {
				if ( '' !== $cur ) {
					$lines[] = $cur;
				}
				$cur = $w;
			} else {
				$cur = $try;
			}
		}
		if ( '' !== $cur ) {
			$lines[] = $cur;
		}
		return $lines;
	}
}
