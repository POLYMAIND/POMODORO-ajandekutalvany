<?php
/**
 * Beágyazott betűtípus adatai (Liberation Sans, SIL Open Font License 1.1).
 *
 * A beépített (base-14) Helvetica nem tartalmazza a magyar ő/ű-t, és a
 * megjelenítők a helyettesítő betűtípusban sem találták meg — Chrome és
 * Android alatt egyszerűen kimaradtak a szövegből, miközben macOS Preview
 * még megjelenítette. Ezért a betűtípust beágyazzuk: a szükséges ~220
 * karakterre szűkített változat mindössze ~40 kB súlyonként.
 *
 * A szélességek 1/1000 em-ben, a 32..255 kódokra (WinAnsi + magyar ő/ű a
 * 129/141/143/144 helyen). Generált fájl — kézzel ne szerkeszd.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Font {

	const FIRST_CHAR = 32;
	const LAST_CHAR  = 255;

	/** A betűtípus-fájl útvonala súly szerint. */
	public static function file( $bold ) {
		$dir = defined( 'PGV_PLUGIN_DIR' ) ? rtrim( PGV_PLUGIN_DIR, '/\\' ) : dirname( __DIR__ );
		return $dir . '/assets/fonts/pgv-sans-' . ( $bold ? 'bold' : 'regular' ) . '.ttf';
	}

	/** Karakterszélességek (1/1000 em) a FIRST_CHAR..LAST_CHAR kódokra. */
	public static function widths( $bold ) {
		static $c = array();
		$k = $bold ? 'b' : 'r';
		if ( ! isset( $c[ $k ] ) ) {
			$c[ $k ] = array_map( 'intval', preg_split( '/\s+/', trim( $bold ? self::W_BOLD : self::W_REGULAR ) ) );
		}
		return $c[ $k ];
	}

	/** A FontDescriptor mezői (1/1000 em). */
	public static function meta( $bold ) {
		return $bold
			? array( 'bbox' => '[-184 -222 1000 896]', 'ascent' => 905, 'descent' => -212, 'cap' => 688, 'stemv' => 160 )
			: array( 'bbox' => '[-203 -212 1000 878]', 'ascent' => 905, 'descent' => -212, 'cap' => 688, 'stemv' => 80 );
	}

	const W_REGULAR = '
		278 278 355 556 556 889 667 191 333 333 389 584 278 333 278 278 556 556 556 556
		556 556 556 556 556 556 278 278 584 584 584 556 1015 667 667 722 722 667 611 778
		722 278 500 667 556 833 722 778 667 778 722 667 611 722 667 944 667 667 611 278
		278 278 469 556 333 556 556 500 556 556 278 556 556 222 222 500 222 833 556 556
		556 556 333 500 278 556 500 722 500 500 500 334 260 334 584 0 556 556 222 556
		333 1000 556 556 333 1000 667 333 1000 556 611 778 722 222 222 333 333 350 556 1000
		333 1000 500 333 944 0 500 667 278 333 556 556 556 556 260 556 333 737 370 556
		584 333 737 552 400 549 333 333 333 576 537 333 333 333 365 556 834 834 834 611
		667 667 667 667 667 667 1000 722 667 667 667 667 278 278 278 278 722 722 778 778
		778 778 778 584 778 722 722 722 722 667 667 611 556 556 556 556 556 556 889 500
		556 556 556 556 278 278 278 278 556 556 556 556 556 556 556 549 611 556 556 556
		556 500 556 500';

	const W_BOLD = '
		278 333 474 556 556 889 722 238 333 333 389 584 278 333 278 278 556 556 556 556
		556 556 556 556 556 556 333 333 584 584 584 611 975 722 722 722 722 667 611 778
		722 278 556 722 611 833 722 778 667 778 722 667 611 722 667 944 667 667 611 333
		278 333 584 556 333 556 611 556 611 556 333 611 611 278 278 556 278 889 611 611
		611 611 389 556 333 611 556 778 556 556 500 389 280 389 584 0 556 611 278 556
		500 1000 556 556 333 1000 667 333 1000 611 611 778 722 278 278 500 500 350 556 1000
		333 1000 556 333 944 0 500 667 278 333 556 556 556 556 280 556 333 737 370 556
		584 333 737 552 400 549 333 333 333 576 556 333 333 333 365 556 834 834 834 611
		722 722 722 722 722 722 1000 722 667 667 667 667 278 278 278 278 722 722 778 778
		778 778 778 584 778 722 722 722 722 667 667 611 556 556 556 556 556 556 889 556
		556 556 556 556 278 278 278 278 611 611 611 611 611 611 611 549 611 611 611 611
		611 556 611 556';
}
