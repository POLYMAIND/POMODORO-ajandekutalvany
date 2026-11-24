<?php
/**
 * Elnevezett képkészlet egységenként (a WP média-könyvtárra épül).
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'ABSPATH' ) || exit;

class PGV_Images {

	/**
	 * Aktív képek az aktuális egységhez, sorrendben.
	 *
	 * @return array<int,array{id:int,attachment_id:int,title:string,url:string,thumb:string}>
	 */
	public static function get_active( $unit_slug = null ) {
		global $wpdb;
		$unit  = $unit_slug ? sanitize_key( $unit_slug ) : PGV_Settings::unit_slug();
		$table = PGV_Install::table( 'images' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, attachment_id, title FROM {$table} WHERE unit_slug = %s AND active = 1 ORDER BY sort_order ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$unit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$aid = (int) $r['attachment_id'];
			$out[] = array(
				'id'            => (int) $r['id'],
				'attachment_id' => $aid,
				'title'         => (string) $r['title'],
				'url'           => wp_get_attachment_image_url( $aid, 'large' ) ?: '',
				'thumb'         => wp_get_attachment_image_url( $aid, 'thumbnail' ) ?: '',
			);
		}
		return $out;
	}

	/**
	 * Egy kép rekord (id szerint).
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = PGV_Install::table( 'images' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Kép hozzáadása a készlethez.
	 */
	public static function add( $attachment_id, $title = '', $unit_slug = null ) {
		global $wpdb;
		$unit  = $unit_slug ? sanitize_key( $unit_slug ) : PGV_Settings::unit_slug();
		$table = PGV_Install::table( 'images' );

		$max = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(MAX(sort_order),0) FROM {$table} WHERE unit_slug = %s", $unit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$wpdb->insert(
			$table,
			array(
				'unit_slug'     => $unit,
				'attachment_id' => (int) $attachment_id,
				'title'         => sanitize_text_field( $title ),
				'sort_order'    => $max + 1,
				'active'        => 1,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%d', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Kép elnevezésének frissítése.
	 */
	public static function rename( $id, $title ) {
		global $wpdb;
		$wpdb->update(
			PGV_Install::table( 'images' ),
			array( 'title' => sanitize_text_field( $title ) ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Kép ki/be kapcsolása (nem törlünk — a hivatkozott utalványoknál a név megmarad).
	 */
	public static function set_active( $id, $active ) {
		global $wpdb;
		$wpdb->update(
			PGV_Install::table( 'images' ),
			array( 'active' => $active ? 1 : 0 ),
			array( 'id' => (int) $id ),
			array( '%d' ),
			array( '%d' )
		);
	}
}
