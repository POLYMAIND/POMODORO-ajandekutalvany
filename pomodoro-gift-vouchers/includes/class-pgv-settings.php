<?php
/**
 * Egység-beállítások olvasása / írása (egy store = egy egység).
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Settings {

	const OPTION = 'pgv_settings';

	/**
	 * Teljes beállítás-tömb (alapértékekkel kiegészítve).
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, PGV_Settings_Defaults() );
	}

	/**
	 * Egy kulcs értéke.
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Beállítások mentése (részleges is lehet).
	 */
	public static function save( array $values ) {
		$current = self::all();
		$merged  = array_merge( $current, $values );
		update_option( self::OPTION, $merged );
		return $merged;
	}

	/**
	 * Aktuális egység slug (kényelmi).
	 */
	public static function unit_slug() {
		$slug = (string) self::get( 'unit_slug', 'casa' );
		return sanitize_key( $slug );
	}

	public static function currency() {
		return (string) self::get( 'currency', get_woocommerce_currency() );
	}
}
