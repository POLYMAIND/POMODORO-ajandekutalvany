<?php
/**
 * Legacy CSV import nézet.
 *
 * @package Pomodoro_Gift_Vouchers
 * @var PGV_Admin $this
 */

defined( 'ABSPATH' ) || exit;

$result = get_transient( 'pgv_import_result' );
if ( $result ) {
	delete_transient( 'pgv_import_result' );
}
?>
<div class="wrap pgv-wrap">
	<h1 class="pgv-title"><?php esc_html_e( 'Import (RESnWEB CSV)', 'pomodoro-gift-vouchers' ); ?></h1>

	<?php if ( is_array( $result ) ) : ?>
		<div class="pgv-card">
			<h2><?php esc_html_e( 'Import eredménye', 'pomodoro-gift-vouchers' ); ?></h2>
			<p>
				<?php echo esc_html( sprintf( __( 'Importálva: %1$d, kihagyva (már létező): %2$d', 'pomodoro-gift-vouchers' ), (int) $result['imported'], (int) $result['skipped'] ) ); ?>
			</p>
			<?php if ( ! empty( $result['errors'] ) ) : ?>
				<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Hibák:', 'pomodoro-gift-vouchers' ); ?></strong></p>
				<ul><?php foreach ( $result['errors'] as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="pgv-card">
		<h2><?php esc_html_e( 'CSV feltöltése', 'pomodoro-gift-vouchers' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Idempotens: a már meglévő „Azonosító"-t kihagyja, a legacy ID-t megtartja (is_legacy). Az aktuális egységbe importál. Státusz: fizetve → aktív, felhasználva → beváltva.', 'pomodoro-gift-vouchers' ); ?></p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'pgv_import' ); ?>
			<input type="hidden" name="pgv_action" value="import">
			<p><input type="file" name="pgv_csv" accept=".csv,text/csv" required></p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Import indítása', 'pomodoro-gift-vouchers' ); ?></button></p>
		</form>
	</div>

	<div class="pgv-card">
		<h2><?php esc_html_e( 'Elvárt formátum', 'pomodoro-gift-vouchers' ); ?></h2>
		<p><?php esc_html_e( 'Elválasztó „;”, minden mező idézőjelben, UTF-8 (BOM engedett). Az oszlopokat a fejléc neve alapján azonosítjuk (a régi 10 oszlopos és az új 12 oszlopos export is jó).', 'pomodoro-gift-vouchers' ); ?></p>
		<p><code><?php echo esc_html( implode( '; ', PGV_Export::columns() ) ); ?></code></p>
	</div>
</div>
