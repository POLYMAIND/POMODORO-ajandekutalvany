<?php
/**
 * Önálló (függőség nélküli) QR-kód generátor — byte mód.
 *
 * Csak a modul-mátrixot állítja elő (true = fekete). A megjelenítést (SVG/PDF/PNG)
 * a hívó végzi. Verziók 1–10, mind a négy hibajavító szint (L/M/Q/H), teljes
 * Reed–Solomon és maszk-választás.
 *
 * A helyességet a Python `qrcode` könyvtárral vetettük össze (azonos verzió/EC/maszk
 * mellett bitre azonos mátrix) — lásd a repó tesztjeit.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_QR {

	/** EC szint bitjei a formátum-információhoz. */
	const EC_L = 0;
	const EC_M = 1;
	const EC_Q = 2;
	const EC_H = 3;

	/** EC szint → 2 bites formátum-kód (L=01, M=00, Q=11, H=10). */
	private static $ec_format_bits = array( 0 => 1, 1 => 0, 2 => 3, 3 => 2 );

	/**
	 * EC-jellemzők: [version][ecLevel] => [ec_per_block, [[num_blocks, data_per_block], ...]].
	 */
	private static $ec_table = array(
		1  => array( array( 7, array( array( 1, 19 ) ) ), array( 10, array( array( 1, 16 ) ) ), array( 13, array( array( 1, 13 ) ) ), array( 17, array( array( 1, 9 ) ) ) ),
		2  => array( array( 10, array( array( 1, 34 ) ) ), array( 16, array( array( 1, 28 ) ) ), array( 22, array( array( 1, 22 ) ) ), array( 28, array( array( 1, 16 ) ) ) ),
		3  => array( array( 15, array( array( 1, 55 ) ) ), array( 26, array( array( 1, 44 ) ) ), array( 18, array( array( 2, 17 ) ) ), array( 22, array( array( 2, 13 ) ) ) ),
		4  => array( array( 20, array( array( 1, 80 ) ) ), array( 18, array( array( 2, 32 ) ) ), array( 26, array( array( 2, 24 ) ) ), array( 16, array( array( 4, 9 ) ) ) ),
		5  => array( array( 26, array( array( 1, 108 ) ) ), array( 24, array( array( 2, 43 ) ) ), array( 18, array( array( 2, 15 ), array( 2, 16 ) ) ), array( 22, array( array( 2, 11 ), array( 2, 12 ) ) ) ),
		6  => array( array( 18, array( array( 2, 68 ) ) ), array( 16, array( array( 4, 27 ) ) ), array( 24, array( array( 4, 19 ) ) ), array( 28, array( array( 4, 15 ) ) ) ),
		7  => array( array( 20, array( array( 2, 78 ) ) ), array( 18, array( array( 4, 31 ) ) ), array( 18, array( array( 2, 14 ), array( 4, 15 ) ) ), array( 26, array( array( 4, 13 ), array( 1, 14 ) ) ) ),
		8  => array( array( 24, array( array( 2, 97 ) ) ), array( 22, array( array( 2, 38 ), array( 2, 39 ) ) ), array( 22, array( array( 4, 18 ), array( 2, 19 ) ) ), array( 26, array( array( 4, 14 ), array( 2, 15 ) ) ) ),
		9  => array( array( 30, array( array( 2, 116 ) ) ), array( 22, array( array( 3, 36 ), array( 2, 37 ) ) ), array( 20, array( array( 4, 16 ), array( 4, 17 ) ) ), array( 24, array( array( 4, 12 ), array( 4, 13 ) ) ) ),
		10 => array( array( 18, array( array( 2, 68 ), array( 2, 69 ) ) ), array( 26, array( array( 4, 43 ), array( 1, 44 ) ) ), array( 24, array( array( 6, 19 ), array( 2, 20 ) ) ), array( 28, array( array( 6, 15 ), array( 2, 16 ) ) ) ),
	);

	/** Igazító (alignment) minta közepének koordinátái verziónként. */
	private static $align_pos = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	// GF(256) log/antilog táblák.
	private static $gf_exp = array();
	private static $gf_log = array();

	/**
	 * Publikus belépő: modul-mátrix előállítása.
	 *
	 * @param string   $data          A kódolandó adat.
	 * @param int      $ec_level      EC szint (self::EC_*). Alap: M.
	 * @param int|null $force_version Teszthez: kényszerített verzió.
	 * @param int|null $force_mask    Teszthez: kényszerített maszk (0–7).
	 * @return bool[][]|WP_Error A mátrix (true = fekete), vagy hiba.
	 */
	public static function matrix( $data, $ec_level = self::EC_M, $force_version = null, $force_mask = null ) {
		self::init_gf();

		$version = $force_version ?: self::pick_version( strlen( $data ), $ec_level );
		if ( ! $version ) {
			return new WP_Error( 'pgv_qr', 'Az adat túl hosszú a támogatott verziókhoz (1–10).' );
		}

		$codewords = self::encode_codewords( $data, $version, $ec_level );
		$size      = 17 + 4 * $version;

		// Mátrix + foglaltság.
		$m    = array_fill( 0, $size, array_fill( 0, $size, false ) );
		$rsvd = array_fill( 0, $size, array_fill( 0, $size, false ) );

		self::place_function_patterns( $m, $rsvd, $version );
		self::place_data( $m, $rsvd, $codewords );

		// Maszk-választás.
		if ( null !== $force_mask ) {
			$mask = $force_mask;
			self::apply_mask( $m, $rsvd, $mask );
			self::place_format( $m, $ec_level, $mask );
			if ( $version >= 7 ) {
				self::place_version( $m, $version );
			}
		} else {
			$best      = null;
			$best_pen  = PHP_INT_MAX;
			$best_mask = 0;
			for ( $k = 0; $k < 8; $k++ ) {
				$cand = $m;
				self::apply_mask( $cand, $rsvd, $k );
				self::place_format( $cand, $ec_level, $k );
				if ( $version >= 7 ) {
					self::place_version( $cand, $version );
				}
				$pen = self::penalty( $cand );
				if ( $pen < $best_pen ) {
					$best_pen  = $pen;
					$best      = $cand;
					$best_mask = $k;
				}
			}
			$m = $best;
			unset( $best_mask );
		}

		return $m;
	}

	// ------------------------------------------------------------
	// Adat-kódolás (byte mód) + Reed–Solomon
	// ------------------------------------------------------------

	private static function char_count_bits( $version ) {
		return ( $version <= 9 ) ? 8 : 16;
	}

	private static function total_data_codewords( $version, $ec_level ) {
		list( $ec_per_block, $groups ) = self::$ec_table[ $version ][ $ec_level ];
		$total = 0;
		foreach ( $groups as $g ) {
			$total += $g[0] * $g[1];
		}
		return $total;
	}

	private static function pick_version( $len, $ec_level ) {
		for ( $v = 1; $v <= 10; $v++ ) {
			$cap_bits    = self::total_data_codewords( $v, $ec_level ) * 8;
			$needed_bits = 4 + self::char_count_bits( $v ) + 8 * $len;
			if ( $needed_bits <= $cap_bits ) {
				return $v;
			}
		}
		return 0;
	}

	/**
	 * Adat → végleges (interleaved) kódszó-sorozat, EC-vel.
	 */
	private static function encode_codewords( $data, $version, $ec_level ) {
		$cc_bits = self::char_count_bits( $version );

		// Bitfolyam: mód (0100) + karakterszám + adatbájtok.
		$bits = array();
		self::push_bits( $bits, 0b0100, 4 );
		self::push_bits( $bits, strlen( $data ), $cc_bits );
		for ( $i = 0, $n = strlen( $data ); $i < $n; $i++ ) {
			self::push_bits( $bits, ord( $data[ $i ] ), 8 );
		}

		$total_data = self::total_data_codewords( $version, $ec_level );
		$cap_bits   = $total_data * 8;

		// Terminátor (max 4 bit).
		$term = min( 4, $cap_bits - count( $bits ) );
		for ( $i = 0; $i < $term; $i++ ) {
			$bits[] = 0;
		}
		// Byte-határra igazítás.
		while ( count( $bits ) % 8 !== 0 ) {
			$bits[] = 0;
		}
		// Kitöltő bájtok.
		$pad = array( 0xEC, 0x11 );
		$pi  = 0;
		while ( count( $bits ) < $cap_bits ) {
			self::push_bits( $bits, $pad[ $pi % 2 ], 8 );
			$pi++;
		}

		// Bitfolyam → adat-kódszavak.
		$data_cw = array();
		for ( $i = 0; $i < $cap_bits; $i += 8 ) {
			$byte = 0;
			for ( $b = 0; $b < 8; $b++ ) {
				$byte = ( $byte << 1 ) | $bits[ $i + $b ];
			}
			$data_cw[] = $byte;
		}

		// Blokkokra bontás + EC.
		list( $ec_per_block, $groups ) = self::$ec_table[ $version ][ $ec_level ];
		$blocks    = array();
		$ec_blocks = array();
		$offset    = 0;
		foreach ( $groups as $g ) {
			for ( $b = 0; $b < $g[0]; $b++ ) {
				$block       = array_slice( $data_cw, $offset, $g[1] );
				$offset     += $g[1];
				$blocks[]    = $block;
				$ec_blocks[] = self::rs_encode( $block, $ec_per_block );
			}
		}

		// Interleave: adat, majd EC.
		$result   = array();
		$max_data = 0;
		foreach ( $blocks as $bl ) {
			$max_data = max( $max_data, count( $bl ) );
		}
		for ( $i = 0; $i < $max_data; $i++ ) {
			foreach ( $blocks as $bl ) {
				if ( $i < count( $bl ) ) {
					$result[] = $bl[ $i ];
				}
			}
		}
		for ( $i = 0; $i < $ec_per_block; $i++ ) {
			foreach ( $ec_blocks as $eb ) {
				$result[] = $eb[ $i ];
			}
		}

		return $result;
	}

	private static function push_bits( &$bits, $value, $len ) {
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$bits[] = ( $value >> $i ) & 1;
		}
	}

	private static function init_gf() {
		if ( ! empty( self::$gf_exp ) ) {
			return;
		}
		$x = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			self::$gf_exp[ $i ] = $x;
			self::$gf_log[ $x ] = $i;
			$x <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11d;
			}
		}
		for ( $i = 255; $i < 512; $i++ ) {
			self::$gf_exp[ $i ] = self::$gf_exp[ $i - 255 ];
		}
	}

	private static function gf_mul( $a, $b ) {
		if ( 0 === $a || 0 === $b ) {
			return 0;
		}
		return self::$gf_exp[ self::$gf_log[ $a ] + self::$gf_log[ $b ] ];
	}

	private static function rs_generator( $degree ) {
		$poly = array( 1 );
		for ( $i = 0; $i < $degree; $i++ ) {
			$next = array_fill( 0, count( $poly ) + 1, 0 );
			foreach ( $poly as $j => $coef ) {
				$next[ $j ]     ^= self::gf_mul( $coef, 1 );
				$next[ $j + 1 ] ^= self::gf_mul( $coef, self::$gf_exp[ $i ] );
			}
			$poly = $next;
		}
		return $poly;
	}

	private static function rs_encode( $data, $ec_len ) {
		$gen = self::rs_generator( $ec_len );
		$res = array_merge( $data, array_fill( 0, $ec_len, 0 ) );
		for ( $i = 0; $i < count( $data ); $i++ ) {
			$coef = $res[ $i ];
			if ( 0 !== $coef ) {
				for ( $j = 0; $j < count( $gen ); $j++ ) {
					$res[ $i + $j ] ^= self::gf_mul( $gen[ $j ], $coef );
				}
			}
		}
		return array_slice( $res, count( $data ) );
	}

	// ------------------------------------------------------------
	// Mátrix-építés
	// ------------------------------------------------------------

	private static function set( &$m, &$rsvd, $r, $c, $val ) {
		$m[ $r ][ $c ]    = (bool) $val;
		$rsvd[ $r ][ $c ] = true;
	}

	private static function place_function_patterns( &$m, &$rsvd, $version ) {
		$size = count( $m );

		// Kereső minták + elválasztók a három sarokban.
		$finder = array( array( 0, 0 ), array( 0, $size - 7 ), array( $size - 7, 0 ) );
		foreach ( $finder as $f ) {
			list( $fr, $fc ) = $f;
			for ( $r = -1; $r <= 7; $r++ ) {
				for ( $c = -1; $c <= 7; $c++ ) {
					$rr = $fr + $r;
					$cc = $fc + $c;
					if ( $rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size ) {
						continue;
					}
					$in_ring = ( 0 === $r || 6 === $r || 0 === $c || 6 === $c ) && ( $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6 );
					$in_core = ( $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4 );
					self::set( $m, $rsvd, $rr, $cc, $in_ring || $in_core );
				}
			}
		}

		// Időzítő sorok.
		for ( $i = 8; $i < $size - 8; $i++ ) {
			self::set( $m, $rsvd, 6, $i, 0 === ( $i % 2 ) );
			self::set( $m, $rsvd, $i, 6, 0 === ( $i % 2 ) );
		}

		// Sötét modul.
		self::set( $m, $rsvd, $size - 8, 8, true );

		// Igazító minták.
		$pos = self::$align_pos[ $version ];
		foreach ( $pos as $pr ) {
			foreach ( $pos as $pc ) {
				// Kereső mintákkal átfedő sarkok kihagyása.
				if ( ( 6 === $pr && 6 === $pc ) || ( 6 === $pr && $pc === $size - 7 ) || ( $pr === $size - 7 && 6 === $pc ) ) {
					continue;
				}
				for ( $r = -2; $r <= 2; $r++ ) {
					for ( $c = -2; $c <= 2; $c++ ) {
						$is_dark = ( abs( $r ) === 2 || abs( $c ) === 2 || ( 0 === $r && 0 === $c ) );
						self::set( $m, $rsvd, $pr + $r, $pc + $c, $is_dark );
					}
				}
			}
		}

		// Formátum-információ helyeinek foglalása (értéket később).
		self::reserve_format( $rsvd, $size );

		// Verzió-információ helyeinek foglalása (v>=7).
		if ( $version >= 7 ) {
			self::reserve_version( $rsvd, $size );
		}
	}

	private static function reserve_format( &$rsvd, $size ) {
		for ( $i = 0; $i <= 8; $i++ ) {
			$rsvd[ 8 ][ $i ] = true;
			$rsvd[ $i ][ 8 ] = true;
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$rsvd[ 8 ][ $size - 1 - $i ] = true;
			$rsvd[ $size - 1 - $i ][ 8 ] = true;
		}
	}

	private static function reserve_version( &$rsvd, $size ) {
		for ( $r = 0; $r < 6; $r++ ) {
			for ( $c = 0; $c < 3; $c++ ) {
				$rsvd[ $r ][ $size - 11 + $c ] = true;
				$rsvd[ $size - 11 + $c ][ $r ] = true;
			}
		}
	}

	private static function place_data( &$m, &$rsvd, $codewords ) {
		$size = count( $m );
		$bits = array();
		foreach ( $codewords as $cw ) {
			for ( $b = 7; $b >= 0; $b-- ) {
				$bits[] = ( $cw >> $b ) & 1;
			}
		}

		$idx = 0;
		$up  = true;
		for ( $col = $size - 1; $col > 0; $col -= 2 ) {
			if ( 6 === $col ) {
				$col--; // Az időzítő oszlop kihagyása.
			}
			for ( $i = 0; $i < $size; $i++ ) {
				$row = $up ? ( $size - 1 - $i ) : $i;
				for ( $j = 0; $j < 2; $j++ ) {
					$c = $col - $j;
					if ( ! $rsvd[ $row ][ $c ] ) {
						$bit             = $idx < count( $bits ) ? $bits[ $idx ] : 0;
						$m[ $row ][ $c ] = (bool) $bit;
						$idx++;
					}
				}
			}
			$up = ! $up;
		}
	}

	private static function mask_condition( $mask, $r, $c ) {
		switch ( $mask ) {
			case 0: return 0 === ( ( $r + $c ) % 2 );
			case 1: return 0 === ( $r % 2 );
			case 2: return 0 === ( $c % 3 );
			case 3: return 0 === ( ( $r + $c ) % 3 );
			case 4: return 0 === ( ( intdiv( $r, 2 ) + intdiv( $c, 3 ) ) % 2 );
			case 5: return 0 === ( ( $r * $c ) % 2 ) + ( ( $r * $c ) % 3 );
			case 6: return 0 === ( ( ( ( $r * $c ) % 2 ) + ( ( $r * $c ) % 3 ) ) % 2 );
			case 7: return 0 === ( ( ( ( $r + $c ) % 2 ) + ( ( $r * $c ) % 3 ) ) % 2 );
		}
		return false;
	}

	private static function apply_mask( &$m, &$rsvd, $mask ) {
		$size = count( $m );
		for ( $r = 0; $r < $size; $r++ ) {
			for ( $c = 0; $c < $size; $c++ ) {
				if ( ! $rsvd[ $r ][ $c ] && self::mask_condition( $mask, $r, $c ) ) {
					$m[ $r ][ $c ] = ! $m[ $r ][ $c ];
				}
			}
		}
	}

	private static function bch15( $data ) {
		$rem = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		return ( ( $data << 10 ) | ( $rem & 0x3FF ) ) ^ 0x5412;
	}

	private static function get_bit( $x, $i ) {
		return ( $x >> $i ) & 1;
	}

	private static function place_format( &$m, $ec_level, $mask ) {
		$size = count( $m );
		$data = ( self::$ec_format_bits[ $ec_level ] << 3 ) | $mask;
		$bits = self::bch15( $data );

		// A bitek (i = 0 a legkisebb helyi értékű) — a nayuki referencia szerint,
		// (sor, oszlop) koordinátákkal.
		// 1. példány a bal-felső kereső körül.
		for ( $i = 0; $i <= 5; $i++ ) {
			$m[ $i ][ 8 ] = (bool) self::get_bit( $bits, $i );
		}
		$m[ 7 ][ 8 ] = (bool) self::get_bit( $bits, 6 );
		$m[ 8 ][ 8 ] = (bool) self::get_bit( $bits, 7 );
		$m[ 8 ][ 7 ] = (bool) self::get_bit( $bits, 8 );
		for ( $i = 9; $i < 15; $i++ ) {
			$m[ 8 ][ 14 - $i ] = (bool) self::get_bit( $bits, $i );
		}

		// 2. példány (jobb-felső + bal-alsó kereső mentén).
		for ( $i = 0; $i < 8; $i++ ) {
			$m[ 8 ][ $size - 1 - $i ] = (bool) self::get_bit( $bits, $i );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$m[ $size - 15 + $i ][ 8 ] = (bool) self::get_bit( $bits, $i );
		}

		// Mindig sötét modul.
		$m[ $size - 8 ][ 8 ] = true;
	}

	private static function bch18( $version ) {
		$rem = $version;
		for ( $i = 0; $i < 12; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
		}
		return ( $version << 12 ) | ( $rem & 0xFFF );
	}

	private static function place_version( &$m, $version ) {
		$size = count( $m );
		$bits = self::bch18( $version );
		for ( $i = 0; $i < 18; $i++ ) {
			$bit = ( $bits >> $i ) & 1;
			$r   = intdiv( $i, 3 );
			$c   = $i % 3;
			$m[ $r ][ $size - 11 + $c ] = (bool) $bit;
			$m[ $size - 11 + $c ][ $r ] = (bool) $bit;
		}
	}

	// ------------------------------------------------------------
	// Maszk-büntetés (a legjobb maszk kiválasztásához)
	// ------------------------------------------------------------
	private static function penalty( $m ) {
		$size = count( $m );
		$pen  = 0;

		// 1. szabály: azonos színű futamok soronként/oszloponként.
		for ( $r = 0; $r < $size; $r++ ) {
			$run = 1;
			for ( $c = 1; $c < $size; $c++ ) {
				if ( $m[ $r ][ $c ] === $m[ $r ][ $c - 1 ] ) {
					$run++;
				} else {
					if ( $run >= 5 ) {
						$pen += 3 + ( $run - 5 );
					}
					$run = 1;
				}
			}
			if ( $run >= 5 ) {
				$pen += 3 + ( $run - 5 );
			}
		}
		for ( $c = 0; $c < $size; $c++ ) {
			$run = 1;
			for ( $r = 1; $r < $size; $r++ ) {
				if ( $m[ $r ][ $c ] === $m[ $r - 1 ][ $c ] ) {
					$run++;
				} else {
					if ( $run >= 5 ) {
						$pen += 3 + ( $run - 5 );
					}
					$run = 1;
				}
			}
			if ( $run >= 5 ) {
				$pen += 3 + ( $run - 5 );
			}
		}

		// 2. szabály: 2×2 azonos blokkok.
		for ( $r = 0; $r < $size - 1; $r++ ) {
			for ( $c = 0; $c < $size - 1; $c++ ) {
				$v = $m[ $r ][ $c ];
				if ( $v === $m[ $r ][ $c + 1 ] && $v === $m[ $r + 1 ][ $c ] && $v === $m[ $r + 1 ][ $c + 1 ] ) {
					$pen += 3;
				}
			}
		}

		// 3. szabály: 1:1:3:1:1 minta (kereső-szerű).
		$patterns = array(
			array( true, false, true, true, true, false, true, false, false, false, false ),
			array( false, false, false, false, true, false, true, true, true, false, true ),
		);
		for ( $r = 0; $r < $size; $r++ ) {
			for ( $c = 0; $c <= $size - 11; $c++ ) {
				foreach ( $patterns as $pat ) {
					$ok = true;
					for ( $k = 0; $k < 11; $k++ ) {
						if ( $m[ $r ][ $c + $k ] !== $pat[ $k ] ) {
							$ok = false;
							break;
						}
					}
					if ( $ok ) {
						$pen += 40;
					}
				}
			}
		}
		for ( $c = 0; $c < $size; $c++ ) {
			for ( $r = 0; $r <= $size - 11; $r++ ) {
				foreach ( $patterns as $pat ) {
					$ok = true;
					for ( $k = 0; $k < 11; $k++ ) {
						if ( $m[ $r + $k ][ $c ] !== $pat[ $k ] ) {
							$ok = false;
							break;
						}
					}
					if ( $ok ) {
						$pen += 40;
					}
				}
			}
		}

		// 4. szabály: a fekete modulok aránya.
		$dark = 0;
		for ( $r = 0; $r < $size; $r++ ) {
			for ( $c = 0; $c < $size; $c++ ) {
				if ( $m[ $r ][ $c ] ) {
					$dark++;
				}
			}
		}
		$percent = ( $dark * 100 ) / ( $size * $size );
		$prev    = intval( floor( $percent / 5 ) ) * 5;
		$next    = $prev + 5;
		$pen    += min( abs( $prev - 50 ), abs( $next - 50 ) ) / 5 * 10;

		return $pen;
	}
}
