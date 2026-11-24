<?php
/**
 * Uninstall.
 *
 * SZIGORÚ SZÁMADÁS: a plugin eltávolításakor SZÁNDÉKOSAN NEM töröljük az utalvány-,
 * audit- és sorszám-táblákat, sem a beállításokat. Az utalványok könyvelési/NAV
 * bizonylatok — törlés sehol. Ha valóban minden adatot törölni akarsz, azt kézzel,
 * tudatosan kell megtenni az adatbázisban.
 *
 * @package Pomodoro_Gift_Vouchers
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Csak a nyers (egyszer megjeleníthető) API-kulcs maradványát takarítjuk el, ha ott ragadt.
delete_option( 'pgv_api_key' );

// A tényleges adat (pgv_vouchers, pgv_audit, pgv_serial_counter, pgv_images) megmarad.
