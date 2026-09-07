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

	/** Kártya-méret (mm) — a termékoldali élő előnézet is ebből dolgozik. */
	const CARD_W_MM = 210;
	const CARD_H_MM = 148;

	/** Az üzenet tördelése: ennyi karakternél törünk, és ennyi sor fér ki. */
	const MSG_WRAP_CHARS = 34;
	const MSG_MAX_LINES  = 5;

	/** @var array Objektumok nyers tartalma (index+1 = objektumszám). */
	private $objects = array();

	/** @var string Tartalom-folyam (content stream) parancsai. */
	private $stream = '';

	private $width;
	private $height;

	/** @var array Már beágyazott képek útvonal → név (ugyanaz az emoji egyszer kerül be). */
	private $image_cache = array();

	/** @var array Beágyazott kép XObject-jei: [name => body]. */
	private $images = array();
	private $image_seq = 0;

	public function __construct( $width_mm = self::CARD_W_MM, $height_mm = self::CARD_H_MM ) {
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
	public static function voucher_pdf( array $voucher, $qr_data, $unit_name = '', $image_path = '', $logo_path = '' ) {
		$pdf = new self();

		$w    = $pdf->width;
		$h    = $pdf->height;
		$half = $w / 2;

		// --- Bal fél: álló, teljes magasságú, padding nélküli (full-bleed) kép ---
		if ( $image_path && function_exists( 'imagecreatefromstring' ) ) {
			$target_ar = $half / $h; // A bal fél (álló) képaránya — erre vágjuk középre.
			$name      = $pdf->add_image_from_file( $image_path, $target_ar );
			if ( $name ) {
				$pdf->draw_image_exact( $name, 0, 0, $half, $h );
			}
		} else {
			// Ha nincs kép: halvány placeholder a bal félen.
			$pdf->rect( 0, 0, $half, $h, 0.94, 0.94, 0.93, true );
		}

		// --- Jobb fél: szövegoszlop ---
		$x     = $half + 10 * self::MM;
		$right = $w - 10 * self::MM;
		$top   = $h - 16 * self::MM;

		// Logó (ha van), a szövegoszlop tetején — arányosan, nagyobb dobozban.
		$has_logo = false;
		if ( $logo_path && function_exists( 'imagecreatefromstring' ) ) {
			$logo = $pdf->add_image_from_file( $logo_path );
			if ( $logo ) {
				$logo_box_w = 52 * self::MM;
				$logo_box_h = 30 * self::MM;
				$pdf->draw_image_fit( $logo, $x, $top - $logo_box_h, $logo_box_w, $logo_box_h, 'left', 'top' );
				$top     -= $logo_box_h + 14 * self::MM;
				$has_logo = true;
			}
		}

		// Egység neve (akcent) — csak akkor, ha NINCS logó.
		if ( $unit_name && ! $has_logo ) {
			$pdf->text( $x, $top, $unit_name, 12, true, 0.9, 0.34, 0.26 );
			$top -= 9 * self::MM;
		}

		$pdf->text( $x, $top, 'AJÁNDÉKUTALVÁNY', 19, true, 0.13, 0.13, 0.13 );
		$top -= 13 * self::MM;

		// Összeg.
		$amount = number_format( (int) $voucher['amount'], 0, ',', ' ' ) . ' Ft';
		$pdf->text( $x, $top, $amount, 28, true, 0.13, 0.13, 0.13 );

		$cursor = $top - 12 * self::MM;

		// Megajándékozott.
		// Az emojik képként kerülnek a szövegbe (a base-14 betűkészlet nem
		// tartalmazza őket); amihez nincs képünk, azt kihagyjuk.
		$rec_name = self::strip_unsupported( $voucher['recipient_name'] ?? '' );
		$msg_text = self::strip_unsupported( $voucher['message'] ?? '' );
		if ( '' !== $rec_name ) {
			$pdf->text_rich( $x, $cursor, 'Kedves ' . $rec_name . '!', 12, false, 0.2, 0.2, 0.2 );
			$cursor -= 7 * self::MM;
		}

		// Üzenet (tördelve a keskenyebb oszlopra).
		if ( '' !== $msg_text ) {
			$lines = self::wrap( $msg_text, self::MSG_WRAP_CHARS );
			foreach ( array_slice( $lines, 0, self::MSG_MAX_LINES ) as $line ) {
				$pdf->text_rich( $x, $cursor, $line, 10.5, false, 0.35, 0.35, 0.35 );
				$cursor -= 5.5 * self::MM;
			}
		}

		// Sorszám + érvényesség (alul).
		$pdf->text( $x, 22 * self::MM, 'Sorszám: ' . $voucher['serial'], 12, true, 0.13, 0.13, 0.13 );
		if ( ! empty( $voucher['valid_until'] ) ) {
			$pdf->text( $x, 15 * self::MM, 'Érvényes: ' . $voucher['valid_until'], 9, false, 0.45, 0.45, 0.45 );
		}

		unset( $qr_data, $right ); // QR nincs — a beváltás a sorszám alapján történik.

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

	/**
	 * Kép betöltése JPEG XObjectként. Ha $target_ar > 0, a képet középre vágjuk erre a
	 * képarányra (cover-crop), hogy padding nélkül, teljesen kitöltsön egy dobozt.
	 */
	private function add_image_from_file( $path, $target_ar = 0 ) {
		$raw = @file_get_contents( $path ); // phpcs:ignore
		if ( false === $raw ) {
			return '';
		}
		$im = @imagecreatefromstring( $raw ); // phpcs:ignore
		if ( ! $im ) {
			return '';
		}
		$sw = imagesx( $im );
		$sh = imagesy( $im );

		// Forrás-kivágás középre a cél-képarányhoz (cover).
		$sx0 = 0;
		$sy0 = 0;
		$cw  = $sw;
		$ch  = $sh;
		if ( $target_ar > 0 && $sw > 0 && $sh > 0 ) {
			$src_ar = $sw / $sh;
			if ( $src_ar > $target_ar ) {
				// Túl széles → oldalt vágunk.
				$cw  = (int) round( $sh * $target_ar );
				$sx0 = (int) round( ( $sw - $cw ) / 2 );
			} else {
				// Túl magas → fent/lent vágunk.
				$ch  = (int) round( $sw / $target_ar );
				$sy0 = (int) round( ( $sh - $ch ) / 2 );
			}
		}

		// Fehér háttérre lapítás (átlátszóság kezelése), majd JPEG.
		$flat  = imagecreatetruecolor( $cw, $ch );
		$white = imagecolorallocate( $flat, 255, 255, 255 );
		imagefill( $flat, 0, 0, $white );
		imagecopy( $flat, $im, 0, 0, $sx0, $sy0, $cw, $ch );
		ob_start();
		imagejpeg( $flat, null, 92 );
		$jpeg = ob_get_clean();
		imagedestroy( $im );
		imagedestroy( $flat );

		$this->image_seq++;
		$name                  = 'Im' . $this->image_seq;
		$this->images[ $name ] = array(
			'w'    => $cw,
			'h'    => $ch,
			'data' => $jpeg,
		);
		return $name;
	}

	/**
	 * Átlátszó PNG (emoji) betöltése: nyers RGB + külön alfa-csatorna (SMask).
	 * A JPEG nem tud átlátszóságot, ezért itt Flate-tömörített nyers bájtokat
	 * ágyazunk be — így az emoji a háttértől függetlenül, élsimítva jelenik meg.
	 */
	private function add_image_png_alpha( $path ) {
		if ( isset( $this->image_cache[ $path ] ) ) {
			return $this->image_cache[ $path ];
		}
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return '';
		}
		$raw = @file_get_contents( $path ); // phpcs:ignore
		if ( false === $raw ) {
			return '';
		}
		$im = @imagecreatefromstring( $raw ); // phpcs:ignore
		if ( ! $im ) {
			return '';
		}
		// Paletta-PNG-nél az imagecolorat a paletta INDEXÉT adná vissza, nem a
		// színt — ezért előbb truecolorra alakítunk.
		if ( function_exists( 'imageistruecolor' ) && ! imageistruecolor( $im ) ) {
			if ( function_exists( 'imagepalettetotruecolor' ) ) {
				imagepalettetotruecolor( $im );
			}
		}
		$w   = imagesx( $im );
		$h   = imagesy( $im );
		$rgb = '';
		$a   = '';
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$c = imagecolorat( $im, $x, $y );
				// GD alfa: 0 = átlátszatlan, 127 = teljesen átlátszó.
				$alpha = ( $c >> 24 ) & 0x7F;
				$rgb  .= chr( ( $c >> 16 ) & 0xFF ) . chr( ( $c >> 8 ) & 0xFF ) . chr( $c & 0xFF );
				$a    .= chr( (int) round( ( 127 - $alpha ) * 255 / 127 ) );
			}
		}
		imagedestroy( $im );

		$this->image_seq++;
		$name                  = 'Im' . $this->image_seq;
		$this->images[ $name ] = array(
			'w'     => $w,
			'h'     => $h,
			'data'  => gzcompress( $rgb, 6 ),
			'flate' => true,
			'smask' => gzcompress( $a, 6 ),
		);
		$this->image_cache[ $path ] = $name;
		return $name;
	}

	/**
	 * Kép pontos kitöltése egy dobozba (a kép már a doboz képarányára van vágva).
	 */
	private function draw_image_exact( $name, $x, $y, $w, $h ) {
		$this->stream .= sprintf( "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $y, $name );
	}

	/**
	 * Kép arányos beillesztése egy dobozba (letterbox), igazítással.
	 *
	 * @param string $halign left|center|right
	 * @param string $valign top|middle|bottom
	 */
	private function draw_image_fit( $name, $x, $y, $box_w, $box_h, $halign = 'center', $valign = 'middle' ) {
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

		if ( 'left' === $halign ) {
			$dx = $x;
		} elseif ( 'right' === $halign ) {
			$dx = $x + ( $box_w - $dw );
		} else {
			$dx = $x + ( $box_w - $dw ) / 2;
		}

		if ( 'top' === $valign ) {
			$dy = $y + ( $box_h - $dh );
		} elseif ( 'bottom' === $valign ) {
			$dy = $y;
		} else {
			$dy = $y + ( $box_h - $dh ) / 2;
		}

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
		$smask_ids = array();
		$next      = 8;
		foreach ( $this->images as $name => $img ) {
			$image_ids[ $name ] = $next++;
			if ( ! empty( $img['smask'] ) ) {
				$smask_ids[ $name ] = $next++;
			}
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

		// Kép XObjectek (JPEG, vagy átlátszó emoji esetén Flate + SMask)
		foreach ( $this->images as $name => $img ) {
			if ( ! empty( $img['flate'] ) ) {
				$sm = isset( $smask_ids[ $name ] ) ? sprintf( '/SMask %d 0 R ', $smask_ids[ $name ] ) : '';
				$this->add_object(
					sprintf(
						"<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 %s/Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
						$img['w'], $img['h'], $sm, strlen( $img['data'] ), $img['data']
					)
				);
				if ( isset( $smask_ids[ $name ] ) ) {
					$this->add_object(
						sprintf(
							"<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
							$img['w'], $img['h'], strlen( $img['smask'] ), $img['smask']
						)
					);
				}
				continue;
			}
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
			} elseif ( $c >= 0xF0 && $c < 0xF8 && $i + 3 < $len ) {
				// 4 bájtos sorozat (emoji). Eddig ezt nem dekódoltuk, ezért egyetlen
				// emojiból NÉGY kérdőjel lett a kész utalványon.
				$cp = ( ( $c & 0x07 ) << 18 ) | ( ( ord( $str[ $i + 1 ] ) & 0x3F ) << 12 )
					| ( ( ord( $str[ $i + 2 ] ) & 0x3F ) << 6 ) | ( ord( $str[ $i + 3 ] ) & 0x3F );
				$i += 4;
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
			} elseif ( self::is_pictograph( $cp ) ) {
				continue; // Emoji/piktogram: a beépített betűtípus nem tartalmazza — kihagyjuk.
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


	// ------------------------------------------------------------
	// Emoji a szövegben (képként, mert a base-14 betűkészlet nem tartalmazza)
	// ------------------------------------------------------------

	/** Helvetica / Helvetica-Bold karakterszélességek (AFM, 1/1000 em). */
	private static function widths( $bold ) {
		static $reg = null, $bld = null;
		if ( null === $reg ) {
			$r = '278 278 355 556 556 889 667 191 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 278 278 584 584 584 556 1015 667 667 722 722 667 611 778 722 278 500 667 556 833 722 778 667 778 722 667 611 722 667 944 667 667 611 278 278 278 469 556 333 556 556 500 556 556 278 556 556 222 222 500 222 833 556 556 556 556 333 500 278 556 500 722 500 500 500 334 260 334 584';
			$b = '278 333 474 556 556 889 722 238 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 333 333 584 584 584 611 975 722 722 722 722 667 611 778 722 278 556 722 611 833 722 778 667 778 722 667 611 722 667 944 667 667 611 333 278 333 584 556 333 556 611 556 611 556 333 611 611 278 278 556 278 889 611 611 611 611 389 556 333 611 556 778 556 556 500 389 280 389 584';
			$reg = array_map( 'intval', explode( ' ', $r ) );
			$bld = array_map( 'intval', explode( ' ', $b ) );
		}
		return $bold ? $bld : $reg;
	}

	/**
	 * Egy UTF-8 szövegdarab szélessége pontban (emoji nélkül).
	 * Az ékezetes betűk a Helveticában az alapbetűvel azonos szélességűek.
	 */
	public static function text_width( $str, $size, $bold = false ) {
		$w     = self::widths( $bold );
		$fold  = array( 0x0151 => 0x6F, 0x0171 => 0x75, 0x0150 => 0x4F, 0x0170 => 0x55, 0x20AC => 0x45, 0x2013 => 0x2D, 0x2014 => 0x2D, 0x2026 => 0x2E, 0x2018 => 0x27, 0x2019 => 0x27, 0x201C => 0x22, 0x201D => 0x22 );
		$total = 0;
		foreach ( self::codepoints( $str ) as $cp ) {
			if ( isset( $fold[ $cp ] ) ) {
				$cp = $fold[ $cp ];
			} elseif ( $cp > 0xFF ) {
				$cp = 0x3F;
			} elseif ( $cp > 0x7E ) {
				// Latin-1 ékezetes: az alapbetű szélessége.
				$cp = self::deaccent( $cp );
			}
			$i      = $cp - 32;
			$total += ( $i >= 0 && isset( $w[ $i ] ) ) ? $w[ $i ] : 556;
		}
		return $total * $size / 1000;
	}

	/** Latin-1 ékezetes kódpont → alap ASCII betű (csak szélességméréshez). */
	private static function deaccent( $cp ) {
		$map = "AAAAAAACEEEEIIIIDNOOOOO*OUUUUYPsaaaaaaaceeeeiiiidnooooo/ouuuuypy";
		if ( $cp >= 0xC0 && $cp <= 0xFF ) {
			return ord( $map[ $cp - 0xC0 ] );
		}
		return 0x3F;
	}

	/** UTF-8 → kódpontok tömbje. */
	public static function codepoints( $str ) {
		$out = array();
		$len = strlen( (string) $str );
		$i   = 0;
		while ( $i < $len ) {
			$c = ord( $str[ $i ] );
			if ( $c < 0x80 ) { $n = 1; $cp = $c; }
			elseif ( $c >= 0xC0 && $c < 0xE0 ) { $n = 2; $cp = ( $c & 0x1F ) << 6; }
			elseif ( $c >= 0xE0 && $c < 0xF0 ) { $n = 3; $cp = ( $c & 0x0F ) << 12; }
			elseif ( $c >= 0xF0 && $c < 0xF8 ) { $n = 4; $cp = ( $c & 0x07 ) << 18; }
			else { $i++; continue; }
			if ( $i + $n > $len ) { break; }
			for ( $k = 1; $k < $n; $k++ ) {
				$cp |= ( ord( $str[ $i + $k ] ) & 0x3F ) << ( 6 * ( $n - 1 - $k ) );
			}
			$out[] = $cp;
			$i    += $n;
		}
		return $out;
	}

	/** Az emoji-képek könyvtára. */
	private static function emoji_dir() {
		if ( defined( 'PGV_PATH' ) ) {
			return rtrim( PGV_PATH, '/\\' ) . '/assets/emoji/';
		}
		return dirname( __DIR__ ) . '/assets/emoji/';
	}

	/**
	 * A rendelkezésre álló emoji-képek kódpontjai (hexában), az előnézetnek.
	 * A könyvtárat egyszer olvassuk be, az eredmény egy órára gyorsítótárazódik.
	 */
	public static function emoji_codepoints() {
		$cached = function_exists( 'get_transient' ) ? get_transient( 'pgv_emoji_cps' ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$list  = array();
		$files = @glob( self::emoji_dir() . '*.png' ); // phpcs:ignore
		if ( $files ) {
			foreach ( $files as $f ) {
				$list[] = basename( $f, '.png' );
			}
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'pgv_emoji_cps', $list, HOUR_IN_SECONDS );
		}
		return $list;
	}

	/** Van-e képünk ehhez a kódponthoz? */
	public static function emoji_file( $cp ) {
		$f = self::emoji_dir() . dechex( $cp ) . '.png';
		return is_readable( $f ) ? $f : '';
	}

	/**
	 * Szöveg darabolása [ 'text' => …] és [ 'emoji' => kódpont ] futamokra.
	 * A variánsjelölőket és a ZWJ-t eldobjuk: az összetett emojik (pl. család)
	 * helyett az első, önmagában is értelmes alap-emoji jelenik meg.
	 */
	public static function split_runs( $str ) {
		$runs = array();
		$buf  = '';
		$join = false; // igaz, ha ZWJ jött: a következő emoji ugyanannak a jelnek a része
		foreach ( self::codepoints( $str ) as $cp ) {
			if ( 0xFE0E === $cp || 0xFE0F === $cp ) {
				continue;
			}
			if ( 0x200D === $cp ) {
				$join = true;
				continue;
			}
			if ( self::is_pictograph( $cp ) || ( 0x1F3FB <= $cp && $cp <= 0x1F3FF ) ) {
				// Összetett emoji (család, foglalkozás) és bőrszín-módosító: csak az
				// első, önmagában is értelmes alap-emojit rajzoljuk ki.
				if ( $join || ( 0x1F3FB <= $cp && $cp <= 0x1F3FF ) ) {
					$join = false;
					continue;
				}
				$file = self::emoji_file( $cp );
				if ( '' === $file ) {
					continue; // nincs képünk hozzá — kihagyjuk
				}
				if ( '' !== $buf ) { $runs[] = array( 'text' => $buf ); $buf = ''; }
				$runs[] = array( 'emoji' => $file, 'cp' => $cp );
				continue;
			}
			$join = false;
			$buf .= self::utf8_chr( $cp );
		}
		if ( '' !== $buf ) { $runs[] = array( 'text' => $buf ); }
		return $runs;
	}

	private static function utf8_chr( $cp ) {
		if ( $cp < 0x80 ) { return chr( $cp ); }
		if ( $cp < 0x800 ) { return chr( 0xC0 | $cp >> 6 ) . chr( 0x80 | $cp & 0x3F ); }
		if ( $cp < 0x10000 ) { return chr( 0xE0 | $cp >> 12 ) . chr( 0x80 | ( $cp >> 6 & 0x3F ) ) . chr( 0x80 | $cp & 0x3F ); }
		return chr( 0xF0 | $cp >> 18 ) . chr( 0x80 | ( $cp >> 12 & 0x3F ) ) . chr( 0x80 | ( $cp >> 6 & 0x3F ) ) . chr( 0x80 | $cp & 0x3F );
	}

	/**
	 * Szöveg kirajzolása úgy, hogy a benne lévő emojik képként, a sorba
	 * illesztve jelenjenek meg. A visszatérési érték a sor teljes szélessége.
	 */
	public function text_rich( $x, $y, $str, $size, $bold = false, $r = 0, $g = 0, $b = 0 ) {
		$cur = $x;
		foreach ( self::split_runs( $str ) as $run ) {
			if ( isset( $run['text'] ) ) {
				$this->text( $cur, $y, $run['text'], $size, $bold, $r, $g, $b );
				$cur += self::text_width( $run['text'], $size, $bold );
				continue;
			}
			$name = $this->add_image_png_alpha( $run['emoji'] );
			if ( '' === $name ) { continue; }
			// Az emoji az írásvonalra ül, kicsit a betűméret fölé nyúlva.
			$box = $size * 1.05;
			$this->stream .= sprintf( "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $box, $box, $cur, $y - $size * 0.2, $name );
			$cur += $box * 1.06;
		}
		return $cur - $x;
	}

	/** Egy sor szélessége emojikkal együtt (a tördeléshez). */
	public static function rich_width( $str, $size, $bold = false ) {
		$w = 0;
		foreach ( self::split_runs( $str ) as $run ) {
			$w += isset( $run['text'] ) ? self::text_width( $run['text'], $size, $bold ) : $size * 1.05 * 1.06;
		}
		return $w;
	}

	/**
	 * Emoji / piktogram / variánsjelölő? A beépített Helvetica ezeket nem
	 * tartalmazza, és nincs betűágyazás, ezért kérdőjel helyett kihagyjuk őket:
	 * az üzenet így olvasható marad, nem lesz tele „?”-lel.
	 */
	public static function is_pictograph( $cp ) {
		return ( $cp >= 0x1F000 && $cp <= 0x1FAFF )   // emoji-blokkok
			|| ( $cp >= 0x2600 && $cp <= 0x27BF )     // Misc symbols + Dingbats
			|| ( $cp >= 0x2B00 && $cp <= 0x2BFF )     // nyilak, csillagok
			|| ( $cp >= 0x1F1E6 && $cp <= 0x1F1FF )   // zászló-betűk
			|| ( $cp >= 0xFE00 && $cp <= 0xFE0F )     // variánsjelölők
			|| ( $cp >= 0x2190 && $cp <= 0x21FF )     // nyilak
			|| 0x200D === $cp                          // zero-width joiner
			|| 0x20E3 === $cp;                         // billentyű-keret
	}

	/**
	 * Ugyanaz a szűrés szövegre: az élő előnézet és a beviteli mező ezzel tudja
	 * megmutatni, mi kerül majd ténylegesen az utalványra.
	 */
	public static function strip_unsupported( $str ) {
		// Egyetlen igazságforrás: ugyanaz a darabolás, mint a rajzoláskor — így az
		// előnézet, a tördelés és a kész PDF pontosan ugyanazt a szöveget látja.
		$out = '';
		foreach ( self::split_runs( $str ) as $run ) {
			$out .= isset( $run['text'] ) ? $run['text'] : self::utf8_chr( $run['cp'] );
		}
		$out = preg_replace( '/[ \t]{2,}/u', ' ', $out );
		$out = preg_replace( '/[ \t]+([\r\n])/u', '$1', $out );
		return trim( $out );
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
