<?php
/**
 * Kasszás nézet — sorszám / QR keresés → egy kattintásos beváltás.
 *
 * @package Pomodoro_Gift_Vouchers
 * @var PGV_Admin $this
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap pgv-wrap pgv-cashier">
	<h1 class="pgv-title"><?php esc_html_e( 'Kassza — beváltás', 'pomodoro-gift-vouchers' ); ?></h1>

	<div class="pgv-card pgv-cashier-card">
		<p class="description"><?php echo esc_html( sprintf( __( 'Egység: %s. Írd be vagy olvasd be (QR) a sorszámot, majd Enter.', 'pomodoro-gift-vouchers' ), PGV_Settings::get( 'unit_name' ) ) ); ?></p>
		<div class="pgv-cashier-search">
			<input type="text" id="pgv-cashier-input" class="pgv-cashier-input" autofocus placeholder="<?php esc_attr_e( 'CASA-2026-000042 vagy QR token', 'pomodoro-gift-vouchers' ); ?>">
			<button type="button" class="button button-primary" id="pgv-cashier-lookup"><?php esc_html_e( 'Keresés', 'pomodoro-gift-vouchers' ); ?></button>
		</div>

		<div id="pgv-cashier-result" class="pgv-cashier-result" style="display:none">
			<div class="pgv-cashier-fields">
				<div><span class="pgv-k"><?php esc_html_e( 'Sorszám', 'pomodoro-gift-vouchers' ); ?></span><span class="pgv-v" data-field="serial"></span></div>
				<div><span class="pgv-k"><?php esc_html_e( 'Érték', 'pomodoro-gift-vouchers' ); ?></span><span class="pgv-v" data-field="amount"></span></div>
				<div><span class="pgv-k"><?php esc_html_e( 'Megajándékozott', 'pomodoro-gift-vouchers' ); ?></span><span class="pgv-v" data-field="recipient"></span></div>
				<div><span class="pgv-k"><?php esc_html_e( 'Lejárat', 'pomodoro-gift-vouchers' ); ?></span><span class="pgv-v" data-field="valid_until"></span></div>
				<div><span class="pgv-k"><?php esc_html_e( 'Státusz', 'pomodoro-gift-vouchers' ); ?></span><span class="pgv-v pgv-status-badge" data-field="status"></span></div>
			</div>
			<button type="button" class="button button-primary button-hero" id="pgv-cashier-redeem" data-id=""><?php esc_html_e( 'Beváltás', 'pomodoro-gift-vouchers' ); ?></button>
			<p class="pgv-cashier-msg" id="pgv-cashier-msg"></p>
		</div>
	</div>
</div>
