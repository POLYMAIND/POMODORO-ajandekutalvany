<?php
/**
 * Cégnév / adószám észlelés (kliens + szerver kétszintű ellenőrzés szerveroldali fele).
 * A kliensoldali regex a frontend.js-ben ugyanezt tükrözi.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Corporate {

	/** Cégforma-jelölők. */
	const RE_CORP = '/\b(kft|bt|zrt|nyrt|kkt|e\.?\s?v\.?)\b/iu';

	/** Adószám-szerű számsor (magyar 8-1-2, illetve laza 8+ jegy). */
	const RE_TAX = '/\d{8}[-\s]?\d?[-\s]?\d{0,2}/';

	/**
	 * Céges tartalomra utal-e a megadott szöveg(ek)?
	 *
	 * @param string ...$parts Ellenőrzendő szövegrészek.
	 */
	public static function looks_corporate( ...$parts ) {
		$corp = trim( implode( ' ', array_filter( $parts ) ) );
		if ( '' === $corp ) {
			return false;
		}
		if ( preg_match( self::RE_CORP, $corp ) ) {
			return true;
		}
		if ( preg_match( self::RE_TAX, $corp ) ) {
			return true;
		}
		return false;
	}
}
